<?php

namespace Orieg\JudyCache;

use Orieg\JudyCache\Storage\SharedMemoryPool;
use Orieg\JudyCache\Storage\SlabArena;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache backed by Judy arrays.
 *
 * Designed for long-running PHP processes (CLI daemons, queue workers,
 * Octane/Swoole/RoadRunner/FrankenPHP workers) where the cache lives in
 * process memory across requests. In classic FPM, the cache dies with the
 * request — use APCu there instead.
 *
 * The default backend is the sorted trie type (STRING_TO_MIXED), which keeps
 * keys in lexicographic order and enables the extra range operations this
 * class exposes beyond PSR-16: deletePrefix() and keysByPrefix() run on the
 * key order directly instead of scanning every entry.
 *
 * Expiry timestamps and storage flags are packed directly into the single
 * Judy trie entry envelope (MAGIC_ENTRY: \x00JE\x01), eliminating the secondary
 * expiries Judy array.
 */
class JudySimpleCache implements CacheInterface, \Countable
{
    private const MAGIC_ENTRY = "\x00JE\x01";

    private const FLAG_RAW = 0x00;
    private const FLAG_COMPRESSED = 0x01;
    private const FLAG_INTERNED = 0x02;
    private const FLAG_SLAB = 0x04;
    private const FLAG_SHMOP = 0x08;

    private const CODEC_ZSTD = 1;
    private const CODEC_LZ4 = 2;
    private const CODEC_GZIP = 3;
    private const CODEC_DEFLATE = 4;

    private \Judy $values;
    private ?\Judy $internPool = null;
    private ?\Judy $internRefs = null;

    /**
     * @param bool $storeSerialized Serialize values on set() and unserialize
     *   on get() (like Symfony's ArrayAdapter): stored objects are snapshots,
     *   immune to later mutation. Set to false to store values by reference —
     *   faster, but mutating a fetched object mutates the cached one.
     * @param ?callable(): int $clock Returns the current Unix timestamp;
     *   injectable for tests.
     * @param ?int $backend Judy type constant for the value store. Defaults
     *   to Judy::STRING_TO_MIXED (sorted trie). STRING_TO_MIXED_HASH and
     *   STRING_TO_MIXED_ADAPTIVE are also valid; all three support the
     *   prefix operations. See the README for the trade-offs.
     * @param ?int $compressionThreshold Minimum byte length for transparent
     *   adaptive compression (e.g. 1024 for 1 KB). Null disables compression.
     * @param string $compressionCodec Compression algorithm: 'gzip', 'deflate',
     *   'zstd', or 'lz4'. Defaults to 'gzip'.
     * @param bool $enableInterning Enable content-addressable payload deduplication
     *   to store shared payloads only once across duplicate keys.
     * @param int $internThreshold Minimum payload size in bytes to trigger
     *   content-addressable interning. Defaults to 256 bytes.
     * @param ?SlabArena $slabArena Optional chunked slab arena allocator for large payloads.
     * @param ?int $slabThreshold Minimum byte length to route payload to SlabArena.
     * @param ?SharedMemoryPool $shmPool Optional shared memory pool driver for multi-worker zero-copy payloads.
     * @param ?int $shmThreshold Minimum byte length to route payload to SharedMemoryPool.
     */
    public function __construct(
        private readonly bool $storeSerialized = true,
        private $clock = null,
        ?int $backend = null,
        private readonly ?int $compressionThreshold = null,
        private readonly string $compressionCodec = 'gzip',
        private readonly bool $enableInterning = false,
        private readonly int $internThreshold = 256,
        private readonly ?SlabArena $slabArena = null,
        private readonly ?int $slabThreshold = null,
        private readonly ?SharedMemoryPool $shmPool = null,
        private readonly ?int $shmThreshold = null,
    ) {
        // orieg/judy-polyfill guarantees the global Judy class exists,
        // aliasing itself when ext-judy is absent.
        $backend ??= \Judy::STRING_TO_MIXED;
        if (!\in_array($backend, [\Judy::STRING_TO_MIXED, \Judy::STRING_TO_MIXED_HASH, \Judy::STRING_TO_MIXED_ADAPTIVE], true)) {
            throw new InvalidArgumentException('backend must be a string-to-mixed Judy type constant');
        }

        if ($this->compressionThreshold !== null) {
            if ($this->compressionThreshold < 0) {
                throw new InvalidArgumentException('compressionThreshold must be non-negative');
            }
            $codec = \strtolower($this->compressionCodec);
            match ($codec) {
                'gzip' => \function_exists('gzencode') || throw new InvalidArgumentException("Compression codec 'gzip' requires ext-zlib"),
                'deflate' => \function_exists('gzdeflate') || throw new InvalidArgumentException("Compression codec 'deflate' requires ext-zlib"),
                'zstd' => \function_exists('zstd_compress') || throw new InvalidArgumentException("Compression codec 'zstd' requires ext-zstd"),
                'lz4' => \function_exists('lz4_compress') || throw new InvalidArgumentException("Compression codec 'lz4' requires ext-lz4"),
                default => throw new InvalidArgumentException("Unsupported compression codec '$this->compressionCodec' (supported: gzip, deflate, zstd, lz4)"),
            };
        }

        if ($this->internThreshold < 0) {
            throw new InvalidArgumentException('internThreshold must be non-negative');
        }

        if ($this->slabThreshold !== null && $this->slabThreshold < 0) {
            throw new InvalidArgumentException('slabThreshold must be non-negative');
        }

        if ($this->shmThreshold !== null && $this->shmThreshold < 0) {
            throw new InvalidArgumentException('shmThreshold must be non-negative');
        }

        self::warnIfTeardownUnsafe($storeSerialized);
        $this->values = new \Judy($backend);

        if ($this->enableInterning) {
            $this->internPool = new \Judy($backend);
            $this->internRefs = new \Judy(match ($backend) {
                \Judy::STRING_TO_MIXED => \Judy::STRING_TO_INT,
                \Judy::STRING_TO_MIXED_HASH => \Judy::STRING_TO_INT_HASH,
                default => \Judy::STRING_TO_INT_ADAPTIVE,
            });
        }
    }

    /**
     * ext-judy before 2.6.0 has a use-after-free in the teardown of the
     * STRING_TO_MIXED family — the three types this class is built on
     * (php-judy#162, fixed in 2.6.0 by unlinking the container before the
     * walk that frees its zvals).
     */
    private static function warnIfTeardownUnsafe(bool $storeSerialized): void
    {
        static $warned = false;
        if ($warned || $storeSerialized || !\extension_loaded('judy')) {
            return;
        }
        if (\version_compare(\judy_version(), '2.6.0', '>=')) {
            return;
        }
        $warned = true;
        \trigger_error(
            'judy-cache: ext-judy ' . \judy_version() . ' has a use-after-free in '
            . 'STRING_TO_MIXED teardown (php-judy#162) that storeSerialized: false '
            . 'can trigger, aborting the process with "zend_mm_heap corrupted". '
            . 'Upgrade to ext-judy >= 2.6.0, or use the default storeSerialized: true.',
            \E_USER_WARNING
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        if (!isset($this->values[$key])) {
            return $default;
        }

        $raw = $this->values[$key];

        if (!$this->storeSerialized) {
            if (!\is_array($raw) || \count($raw) < 3) {
                return $default;
            }
            $expiry = $raw[0];
            if ($expiry !== 0 && $expiry <= $this->now()) {
                unset($this->values[$key]);
                return $default;
            }
            return $raw[2];
        }

        if (!\is_string($raw) || !\str_starts_with($raw, self::MAGIC_ENTRY) || \strlen($raw) < 9) {
            return $default;
        }

        $meta = \unpack('Nexpiry/Cflags', \substr($raw, 4, 5));
        $expiry = $meta['expiry'];
        if ($expiry !== 0 && $expiry <= $this->now()) {
            $this->releaseValue($key);
            unset($this->values[$key]);
            return $default;
        }

        $flags = $meta['flags'];
        $payload = \substr($raw, 9);

        if (($flags & self::FLAG_SHMOP) !== 0) {
            if ($this->shmPool === null) {
                return $default;
            }
            $offset = \unpack('P', $payload)[1];
            $payload = $this->shmPool->read($offset);
        } elseif (($flags & self::FLAG_SLAB) !== 0) {
            if ($this->slabArena === null) {
                return $default;
            }
            $offset = \unpack('P', $payload)[1];
            $payload = $this->slabArena->read($offset);
        } elseif (($flags & self::FLAG_INTERNED) !== 0) {
            if ($this->internPool === null) {
                return $default;
            }
            $payload = $this->internPool[$payload] ?? null;
            if ($payload === null) {
                return $default;
            }
        }

        if (($flags & self::FLAG_COMPRESSED) !== 0) {
            $payload = $this->decompress($payload);
        }

        return \unserialize($payload);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $expiry = $this->expiryAt($ttl);
        if ($expiry !== null && $expiry <= $this->now()) {
            // Already expired: PSR-16 semantics are "delete".
            $this->delete($key);
            return true;
        }

        $this->releaseValue($key);
        $expiryTs = $expiry ?? 0;

        if (!$this->storeSerialized) {
            $this->values[$key] = [$expiryTs, self::FLAG_RAW, $value];
            return true;
        }

        $payload = \serialize($value);
        $flags = self::FLAG_RAW;

        if ($this->compressionThreshold !== null && \strlen($payload) >= $this->compressionThreshold) {
            $compressed = $this->compress($payload);
            if ($compressed !== $payload) {
                $payload = $compressed;
                $flags |= self::FLAG_COMPRESSED;
            }
        }

        if ($this->enableInterning && \strlen($payload) >= $this->internThreshold) {
            $payload = $this->internPayload($payload);
            $flags |= self::FLAG_INTERNED;
        } elseif ($this->shmPool !== null && ($this->shmThreshold === null || \strlen($payload) >= $this->shmThreshold)) {
            $offset = $this->shmPool->allocate($payload);
            $payload = \pack('P', $offset);
            $flags |= self::FLAG_SHMOP;
        } elseif ($this->slabArena !== null && ($this->slabThreshold === null || \strlen($payload) >= $this->slabThreshold)) {
            $offset = $this->slabArena->allocate($payload);
            $payload = \pack('P', $offset);
            $flags |= self::FLAG_SLAB;
        }

        $envelope = self::MAGIC_ENTRY . \pack('NC', $expiryTs, $flags) . $payload;
        $this->values[$key] = $envelope;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->releaseValue($key);
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        if ($this->slabArena !== null || $this->shmPool !== null || $this->enableInterning) {
            for ($key = $this->values->first(); $key !== null; $key = $this->values->searchNext($key)) {
                $this->releaseValue((string) $key);
            }
        }
        $this->values->free();
        if ($this->enableInterning && $this->internPool !== null && $this->internRefs !== null) {
            $this->internPool->free();
            $this->internRefs->free();
        }
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return $this->live($key);
    }

    /* ── Beyond PSR-16: range operations on the sorted key space ── */

    /**
     * Delete every entry whose key starts with $prefix, walking only the
     * matching key range (the backend keeps keys sorted).
     *
     * @return int Number of entries deleted.
     */
    public function deletePrefix(string $prefix): int
    {
        if ($prefix === '') {
            $n = $this->values->count();
            $this->clear();
            return $n;
        }
        $deleted = 0;
        for ($key = $this->values->first($prefix);
             $key !== null && \str_starts_with((string) $key, $prefix);
             $key = $this->values->searchNext($key)) {
            $this->releaseValue((string) $key);
            unset($this->values[$key]);
            $deleted++;
        }
        return $deleted;
    }

    /**
     * Keys currently stored under a prefix (expired entries excluded).
     *
     * @return list<string>
     */
    public function keysByPrefix(string $prefix, int $limit = PHP_INT_MAX): array
    {
        $keys = [];
        for ($key = $prefix === '' ? $this->values->first() : $this->values->first($prefix);
             $key !== null && ($prefix === '' || \str_starts_with((string) $key, $prefix)) && \count($keys) < $limit;
             $key = $this->values->searchNext($key)) {
            if ($this->live((string) $key)) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /** Entries currently stored, including not-yet-evicted expired ones. */
    public function count(): int
    {
        return $this->values->count();
    }

    /** Number of unique deduplicated payload entries in the intern pool (if enabled). */
    public function internCount(): int
    {
        return $this->enableInterning && $this->internPool !== null ? $this->internPool->count() : 0;
    }

    /** Drop every expired entry now; returns the number evicted. */
    public function prune(): int
    {
        $now = $this->now();
        $evicted = 0;
        $key = $this->values->first();
        while ($key !== null) {
            $next = $this->values->searchNext($key);
            $raw = $this->values[$key];
            $expiry = $this->extractExpiry($raw);
            if ($expiry !== 0 && $expiry <= $now) {
                $this->releaseValue((string) $key);
                unset($this->values[$key]);
                $evicted++;
            }
            $key = $next;
        }
        return $evicted;
    }

    /* ── Internals ────────────────────────────────────────────── */

    private function compress(string $data): string
    {
        $codecId = match (\strtolower($this->compressionCodec)) {
            'zstd' => self::CODEC_ZSTD,
            'lz4' => self::CODEC_LZ4,
            'deflate' => self::CODEC_DEFLATE,
            default => self::CODEC_GZIP,
        };

        $compressed = match ($codecId) {
            self::CODEC_ZSTD => \function_exists('zstd_compress') ? \zstd_compress($data) : false,
            self::CODEC_LZ4 => \function_exists('lz4_compress') ? \lz4_compress($data) : false,
            self::CODEC_DEFLATE => \function_exists('gzdeflate') ? \gzdeflate($data, 6) : false,
            self::CODEC_GZIP => \function_exists('gzencode') ? \gzencode($data, 6) : false,
            default => false,
        };

        if ($compressed === false) {
            return $data;
        }

        $framed = \chr($codecId) . $compressed;
        // Adaptive: only store compressed if strictly smaller than original data
        return \strlen($framed) < \strlen($data) ? $framed : $data;
    }

    private function decompress(string $data): string
    {
        if (\strlen($data) < 2) {
            return $data;
        }

        $codecId = \ord($data[0]);
        $payload = \substr($data, 1);

        $decompressed = match ($codecId) {
            self::CODEC_ZSTD => \function_exists('zstd_uncompress') ? \zstd_uncompress($payload) : false,
            self::CODEC_LZ4 => \function_exists('lz4_uncompress') ? \lz4_uncompress($payload) : false,
            self::CODEC_DEFLATE => \function_exists('gzinflate') ? \gzinflate($payload) : false,
            self::CODEC_GZIP => \function_exists('gzdecode') ? \gzdecode($payload) : false,
            default => false,
        };

        return $decompressed === false ? $data : $decompressed;
    }

    private function internPayload(string $payload): string
    {
        $hash = \hash('xxh3', $payload);
        if (!isset($this->internPool[$hash])) {
            $this->internPool[$hash] = $payload;
            $this->internRefs[$hash] = 1;
        } else {
            $this->internRefs[$hash] = ($this->internRefs[$hash] ?? 0) + 1;
        }

        return $hash;
    }

    private function releaseValue(string $key): void
    {
        if (!isset($this->values[$key])) {
            return;
        }
        $raw = $this->values[$key];
        if (!$this->storeSerialized || !\is_string($raw) || !\str_starts_with($raw, self::MAGIC_ENTRY) || \strlen($raw) < 9) {
            return;
        }

        $flags = \ord($raw[8]);
        $payload = \substr($raw, 9);

        if (($flags & self::FLAG_SHMOP) !== 0 && $this->shmPool !== null) {
            $offset = \unpack('P', $payload)[1];
            $this->shmPool->free($offset);
        } elseif (($flags & self::FLAG_SLAB) !== 0 && $this->slabArena !== null) {
            $offset = \unpack('P', $payload)[1];
            $this->slabArena->free($offset);
        } elseif (($flags & self::FLAG_INTERNED) !== 0 && $this->enableInterning && $this->internRefs !== null && $this->internPool !== null) {
            $hash = $payload;
            if (isset($this->internRefs[$hash])) {
                $refs = $this->internRefs[$hash] - 1;
                if ($refs <= 0) {
                    unset($this->internPool[$hash], $this->internRefs[$hash]);
                } else {
                    $this->internRefs[$hash] = $refs;
                }
            }
        }
    }

    private function extractExpiry(mixed $raw): int
    {
        if ($this->storeSerialized) {
            if (\is_string($raw) && \str_starts_with($raw, self::MAGIC_ENTRY) && \strlen($raw) >= 9) {
                return \unpack('N', \substr($raw, 4, 4))[1];
            }
            return 0;
        }
        return \is_array($raw) ? ($raw[0] ?? 0) : 0;
    }

    private function live(string $key): bool
    {
        if (!isset($this->values[$key])) {
            return false;
        }
        $raw = $this->values[$key];
        $expiry = $this->extractExpiry($raw);
        if ($expiry !== 0 && $expiry <= $this->now()) {
            $this->releaseValue($key);
            unset($this->values[$key]); // lazy eviction
            return false;
        }
        return true;
    }

    private function expiryAt(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable('@' . $this->now()))->add($ttl)->getTimestamp();
        }
        return $this->now() + $ttl;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : \time();
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty');
        }
        if (\strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidArgumentException(
                "Cache key \"$key\" contains reserved characters {}()/\\@:"
            );
        }
    }
}
