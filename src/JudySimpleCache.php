<?php

namespace Orieg\JudyCache;

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
 */
class JudySimpleCache implements CacheInterface, \Countable
{
    private const MAGIC_COMPRESSED = "\x00JC\x01";
    private const MAGIC_INTERNED = "\x00JI\x01";

    private const CODEC_ZSTD = 1;
    private const CODEC_LZ4 = 2;
    private const CODEC_GZIP = 3;
    private const CODEC_DEFLATE = 4;

    private \Judy $values;
    private \Judy $expiries;
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
     */
    public function __construct(
        private readonly bool $storeSerialized = true,
        private $clock = null,
        ?int $backend = null,
        private readonly ?int $compressionThreshold = null,
        private readonly string $compressionCodec = 'gzip',
        private readonly bool $enableInterning = false,
        private readonly int $internThreshold = 256,
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

        self::warnIfTeardownUnsafe($storeSerialized);
        $this->values = new \Judy($backend);
        $this->expiries = new \Judy(match ($backend) {
            \Judy::STRING_TO_MIXED => \Judy::STRING_TO_INT,
            \Judy::STRING_TO_MIXED_HASH => \Judy::STRING_TO_INT_HASH,
            default => \Judy::STRING_TO_INT_ADAPTIVE,
        });

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
     * walk that frees its zvals). Teardown walks the Judy calling
     * zval_ptr_dtor() on every value while the freed pointers are still
     * reachable in it; dropping a *shared* GC-collectable value roots it
     * instead of freeing it, and once the root buffer fills the collector runs
     * synchronously inside that loop and re-walks the half-freed container.
     * It surfaces as a "zend_mm_heap corrupted" abort, and it reaches this
     * class through both `clear()` (which calls Judy::free()) and ordinary
     * destruction.
     *
     * The trigger needs the stored values to BE GC-collectable, so it is
     * gated on $storeSerialized rather than announced to everyone:
     *
     *   - storeSerialized: true (the default) stores serialize() strings.
     *     Strings are refcounted but not GC-collectable, so zval_ptr_dtor
     *     never roots one and the collector cannot fire inside teardown. Not
     *     affected — verified across 20 trials on 2.5.2 with no abort.
     *   - storeSerialized: false stores the caller's arrays and objects by
     *     reference, which is exactly the shared-collectable case. Verified
     *     against a locally built 2.5.2: 8 of 20 trials abort with
     *     "zend_mm_heap corrupted"; the same script on 2.6.0 is 0 of 20.
     *
     * Warning rather than throwing: the abort is probabilistic and needs
     * scale, so a hard failure would break deployments that are running, and
     * a documented floor alone would not reach someone who opted into
     * by-reference storage for the speed. Once per process, not per instance.
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
        if (!$this->live($key)) {
            return $default;
        }

        $value = $this->values[$key];

        if ($this->enableInterning && \is_string($value) && \str_starts_with($value, self::MAGIC_INTERNED)) {
            $hash = \substr($value, 4);
            $value = $this->internPool[$hash] ?? null;
            if ($value === null) {
                return $default;
            }
        }

        if (\is_string($value) && \str_starts_with($value, self::MAGIC_COMPRESSED)) {
            $value = $this->decompress($value);
        }

        return $this->storeSerialized ? \unserialize($value) : $value;
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

        $payload = $this->storeSerialized ? \serialize($value) : $value;
        if ($this->compressionThreshold !== null && \is_string($payload) && \strlen($payload) >= $this->compressionThreshold) {
            $payload = $this->compress($payload);
        }
        if ($this->enableInterning) {
            $payload = $this->internPayload($payload);
        }

        $this->values[$key] = $payload;
        if ($expiry === null) {
            unset($this->expiries[$key]);
        } else {
            $this->expiries[$key] = $expiry;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->releaseValue($key);
        unset($this->values[$key], $this->expiries[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values->free();
        $this->expiries->free();
        if ($this->enableInterning) {
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
             $key !== null && \str_starts_with($key, $prefix);
             $key = $this->values->searchNext($key)) {
            $this->releaseValue($key);
            unset($this->values[$key], $this->expiries[$key]);
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
             $key !== null && ($prefix === '' || \str_starts_with($key, $prefix)) && \count($keys) < $limit;
             $key = $this->values->searchNext($key)) {
            if ($this->live($key)) {
                $keys[] = $key;
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
        return $this->enableInterning ? $this->internPool->count() : 0;
    }

    /** Drop every expired entry now; returns the number evicted. */
    public function prune(): int
    {
        $now = $this->now();
        $evicted = 0;
        $key = $this->expiries->first();
        while ($key !== null) {
            $next = $this->expiries->searchNext($key);
            $expiry = $this->expiries[$key];
            if ($expiry !== null && $expiry <= $now) {
                $this->releaseValue($key);
                unset($this->values[$key], $this->expiries[$key]);
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

        $framed = self::MAGIC_COMPRESSED . \chr($codecId) . $compressed;
        // Adaptive: only store framed compression if strictly smaller than original data
        return \strlen($framed) < \strlen($data) ? $framed : $data;
    }

    private function decompress(string $data): string
    {
        if (!\str_starts_with($data, self::MAGIC_COMPRESSED) || \strlen($data) < 6) {
            return $data;
        }

        $codecId = \ord($data[4]);
        $payload = \substr($data, 5);

        $decompressed = match ($codecId) {
            self::CODEC_ZSTD => \function_exists('zstd_uncompress') ? \zstd_uncompress($payload) : false,
            self::CODEC_LZ4 => \function_exists('lz4_uncompress') ? \lz4_uncompress($payload) : false,
            self::CODEC_DEFLATE => \function_exists('gzinflate') ? \gzinflate($payload) : false,
            self::CODEC_GZIP => \function_exists('gzdecode') ? \gzdecode($payload) : false,
            default => false,
        };

        return $decompressed === false ? $data : $decompressed;
    }

    private function internPayload(mixed $payload): mixed
    {
        if (!$this->enableInterning || !\is_string($payload) || \strlen($payload) < $this->internThreshold) {
            return $payload;
        }

        $hash = \hash('xxh3', $payload);
        if (!isset($this->internPool[$hash])) {
            $this->internPool[$hash] = $payload;
            $this->internRefs[$hash] = 1;
        } else {
            $this->internRefs[$hash] = ($this->internRefs[$hash] ?? 0) + 1;
        }

        return self::MAGIC_INTERNED . $hash;
    }

    private function releaseValue(string $key): void
    {
        if (!$this->enableInterning || !isset($this->values[$key])) {
            return;
        }
        $val = $this->values[$key];
        if (\is_string($val) && \str_starts_with($val, self::MAGIC_INTERNED)) {
            $hash = \substr($val, 4);
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

    private function live(string $key): bool
    {
        if (!isset($this->values[$key])) {
            return false;
        }
        if (isset($this->expiries[$key]) && $this->expiries[$key] <= $this->now()) {
            $this->releaseValue($key);
            unset($this->values[$key], $this->expiries[$key]); // lazy eviction
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
