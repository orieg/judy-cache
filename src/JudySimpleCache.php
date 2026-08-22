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
 * The backend is Judy::STRING_TO_ENTRY, which natively stores expiry timestamps
 * and 16-bit metadata flags directly with entries in C, enabling in-C single-pass
 * batch eviction via pruneExpired() and zero userland packing overhead.
 */
class JudySimpleCache implements CacheInterface, \Countable
{
    public const FLAG_RAW = 0x0000;
    public const FLAG_COMPRESSED = 0x0001;
    public const FLAG_INTERNED = 0x0002;
    public const FLAG_SLAB = 0x0004;
    public const FLAG_SHMOP = 0x0008;

    public const CODEC_SHIFT = 4;
    public const CODEC_MASK = 0x00F0;
    public const CODEC_ZSTD = 1 << 4;
    public const CODEC_LZ4 = 2 << 4;
    public const CODEC_GZIP = 3 << 4;
    public const CODEC_DEFLATE = 4 << 4;

    private \Judy $values;
    private ?\Judy $internPool = null;
    private ?\Judy $internRefs = null;
    private int $externalAllocations = 0;
    private int $clockOffset = 0;

    /**
     * @param bool $storeSerialized Serialize values on set() and unserialize
     *   on get() (like Symfony's ArrayAdapter): stored objects are snapshots,
     *   immune to later mutation. Set to false to store values by reference —
     *   faster, but mutating a fetched object mutates the cached one.
     * @param ?callable(): int $clock Returns the current Unix timestamp;
     *   injectable for tests.
     * @param ?int $backend Judy type constant for the value store. Defaults
     *   to Judy::STRING_TO_ENTRY.
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
        $backend ??= \Judy::STRING_TO_ENTRY;
        if (!\in_array($backend, [\Judy::STRING_TO_ENTRY, \Judy::STRING_TO_MIXED, \Judy::STRING_TO_MIXED_HASH, \Judy::STRING_TO_MIXED_ADAPTIVE], true)) {
            throw new InvalidArgumentException('backend must be a string-to-mixed or string-to-entry Judy type constant');
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
        $this->values = new \Judy(\Judy::STRING_TO_ENTRY);

        if ($this->enableInterning) {
            $this->internPool = new \Judy(\Judy::INT_TO_MIXED);
            $this->internRefs = new \Judy(\Judy::INT_TO_INT);
        }
    }

    /**
     * ext-judy before 2.6.0 has a use-after-free in the teardown of the
     * STRING_TO_MIXED family (php-judy#162, fixed in 2.6.0).
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

        if (!$this->storeSerialized) {
            if ($this->clock === null) {
                $raw = $this->values->get($key);
                return $raw ?? $default;
            }
            $entry = $this->values->getEntry($key);
            if ($entry === null) {
                return $default;
            }
            if ($entry['expires_at'] !== 0 && $entry['expires_at'] <= $this->now()) {
                $this->releaseValue($key);
                unset($this->values[$key]);
                return $default;
            }
            return $entry['value'];
        }

        $flags = 0;
        $expiry = 0;
        if ($this->clock === null) {
            $raw = $this->values->get($key, $expiry, $flags);
            if ($raw === null) {
                return $default;
            }
        } else {
            $entry = $this->values->getEntry($key);
            if ($entry === null) {
                return $default;
            }
            if ($entry['expires_at'] !== 0 && $entry['expires_at'] <= $this->now()) {
                $this->releaseValue($key);
                unset($this->values[$key]);
                return $default;
            }
            $raw = $entry['value'];
            $flags = $entry['flags'];
        }

        if (($flags & self::FLAG_SHMOP) !== 0) {
            if ($this->shmPool === null) {
                return $default;
            }
            $offset = \unpack('P', $raw)[1];
            $raw = $this->shmPool->read($offset);
        } elseif (($flags & self::FLAG_SLAB) !== 0) {
            if ($this->slabArena === null) {
                return $default;
            }
            $offset = \unpack('P', $raw)[1];
            $raw = $this->slabArena->read($offset);
        } elseif (($flags & self::FLAG_INTERNED) !== 0) {
            if ($this->internPool === null) {
                return $default;
            }
            $hashKey = \unpack('P', $raw)[1];
            $raw = $this->internPool[$hashKey] ?? null;
            if ($raw === null) {
                return $default;
            }
        }

        if (($flags & self::FLAG_COMPRESSED) !== 0) {
            $raw = $this->decompress($raw, $flags);
        }

        return \unserialize($raw);
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
        $ttlSeconds = $this->ttlSeconds($expiry);

        if (!$this->storeSerialized) {
            $this->values->set($key, $value, $ttlSeconds, self::FLAG_RAW);
            return true;
        }

        $payload = \serialize($value);
        $flags = self::FLAG_RAW;

        if ($this->compressionThreshold !== null && \strlen($payload) >= $this->compressionThreshold) {
            $payload = $this->compress($payload, $flags);
        }

        if ($this->enableInterning && \strlen($payload) >= $this->internThreshold) {
            $payload = $this->internPayload($payload);
            $flags |= self::FLAG_INTERNED;
            $this->externalAllocations++;
        } elseif ($this->shmPool !== null && ($this->shmThreshold === null || \strlen($payload) >= $this->shmThreshold)) {
            $offset = $this->shmPool->allocate($payload);
            $payload = \pack('P', $offset);
            $flags |= self::FLAG_SHMOP;
            $this->externalAllocations++;
        } elseif ($this->slabArena !== null && ($this->slabThreshold === null || \strlen($payload) >= $this->slabThreshold)) {
            $offset = $this->slabArena->allocate($payload);
            $payload = \pack('P', $offset);
            $flags |= self::FLAG_SLAB;
            $this->externalAllocations++;
        }

        $this->values->set($key, $payload, $ttlSeconds, $flags);
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
        if ($this->externalAllocations > 0) {
            for ($key = $this->values->first(); $key !== null; $key = $this->values->searchNext($key)) {
                $this->releaseValue((string) $key);
            }
        }
        $this->values->free();
        if ($this->enableInterning && $this->internPool !== null && $this->internRefs !== null) {
            $this->internPool->free();
            $this->internRefs->free();
        }
        $this->externalAllocations = 0;
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
        if ($this->externalAllocations === 0) {
            return $this->values->pruneExpired($now);
        }

        $evicted = 0;
        $key = $this->values->first();
        while ($key !== null) {
            $next = $this->values->searchNext($key);
            $entry = $this->values->getEntry((string) $key);
            if ($entry !== null && $entry['expires_at'] !== 0 && $entry['expires_at'] <= $now) {
                $this->releaseValue((string) $key);
                unset($this->values[$key]);
                $evicted++;
            }
            $key = $next;
        }
        return $evicted;
    }

    /* ── Internals ────────────────────────────────────────────── */

    private function compress(string $data, int &$flags): string
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
            default => \function_exists('gzencode') ? \gzencode($data, 6) : false,
        };

        if ($compressed === false || \strlen($compressed) >= \strlen($data)) {
            return $data;
        }

        $flags |= self::FLAG_COMPRESSED | $codecId;
        return $compressed;
    }

    private function decompress(string $data, int $flags): string
    {
        $codecId = ($flags & self::CODEC_MASK);

        $decompressed = match ($codecId) {
            self::CODEC_ZSTD => \function_exists('zstd_uncompress') ? \zstd_uncompress($data) : false,
            self::CODEC_LZ4 => \function_exists('lz4_uncompress') ? \lz4_uncompress($data) : false,
            self::CODEC_DEFLATE => \function_exists('gzinflate') ? \gzinflate($data) : false,
            default => \function_exists('gzdecode') ? \gzdecode($data) : false,
        };

        return $decompressed === false ? $data : $decompressed;
    }

    private function internPayload(string $payload): string
    {
        $digest = \hash('xxh3', $payload, true);
        $hashKey = \unpack('P', $digest)[1];
        if (!isset($this->internPool[$hashKey])) {
            $this->internPool[$hashKey] = $payload;
            $this->internRefs[$hashKey] = 1;
        } else {
            $this->internRefs[$hashKey] = ($this->internRefs[$hashKey] ?? 0) + 1;
        }

        return $digest;
    }

    private function releaseValue(string $key): void
    {
        if ($this->externalAllocations === 0 || $this->values === null) {
            return;
        }

        $entry = $this->values->getEntry($key);
        if ($entry === null) {
            return;
        }

        $flags = $entry['flags'];
        $raw = $entry['value'];

        if (($flags & self::FLAG_SHMOP) !== 0 && $this->shmPool !== null && \is_string($raw) && \strlen($raw) === 8) {
            $offset = \unpack('P', $raw)[1];
            $this->shmPool->free($offset);
            $this->externalAllocations--;
        } elseif (($flags & self::FLAG_SLAB) !== 0 && $this->slabArena !== null && \is_string($raw) && \strlen($raw) === 8) {
            $offset = \unpack('P', $raw)[1];
            $this->slabArena->free($offset);
            $this->externalAllocations--;
        } elseif (($flags & self::FLAG_INTERNED) !== 0 && $this->internPool !== null && $this->internRefs !== null && \is_string($raw) && \strlen($raw) === 8) {
            $hashKey = \unpack('P', $raw)[1];
            if (isset($this->internRefs[$hashKey])) {
                $refs = $this->internRefs[$hashKey] - 1;
                if ($refs <= 0) {
                    unset($this->internPool[$hashKey], $this->internRefs[$hashKey]);
                } else {
                    $this->internRefs[$hashKey] = $refs;
                }
            }
            $this->externalAllocations--;
        }
    }

    private function live(string $key): bool
    {
        if ($this->clock === null) {
            return isset($this->values[$key]);
        }
        $entry = $this->values->getEntry($key);
        if ($entry === null) {
            return false;
        }
        if ($entry['expires_at'] !== 0 && $entry['expires_at'] <= $this->now()) {
            $this->releaseValue($key);
            unset($this->values[$key]); // lazy eviction
            return false;
        }
        return true;
    }

    private function ttlSeconds(?int $expiry): int
    {
        if ($expiry === null) {
            return 0;
        }
        if ($this->clock === null) {
            return \max(1, $expiry - \time());
        }
        return $expiry - \time();
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
