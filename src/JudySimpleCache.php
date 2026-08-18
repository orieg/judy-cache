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
class JudySimpleCache implements CacheInterface
{
    private \Judy $values;
    private \Judy $expiries;

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
     */
    public function __construct(
        private readonly bool $storeSerialized = true,
        private $clock = null,
        ?int $backend = null,
    ) {
        // orieg/judy-polyfill guarantees the global Judy class exists,
        // aliasing itself when ext-judy is absent.
        $backend ??= \Judy::STRING_TO_MIXED;
        if (!\in_array($backend, [\Judy::STRING_TO_MIXED, \Judy::STRING_TO_MIXED_HASH, \Judy::STRING_TO_MIXED_ADAPTIVE], true)) {
            throw new InvalidArgumentException('backend must be a string-to-mixed Judy type constant');
        }
        $this->values = new \Judy($backend);
        $this->expiries = new \Judy(match ($backend) {
            \Judy::STRING_TO_MIXED => \Judy::STRING_TO_INT,
            \Judy::STRING_TO_MIXED_HASH => \Judy::STRING_TO_INT_HASH,
            default => \Judy::STRING_TO_INT_ADAPTIVE,
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        if (!$this->live($key)) {
            return $default;
        }
        $value = $this->values[$key];
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
        $this->values[$key] = $this->storeSerialized ? \serialize($value) : $value;
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
        unset($this->values[$key], $this->expiries[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values->free();
        $this->expiries->free();
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

    /** Drop every expired entry now; returns the number evicted. */
    public function prune(): int
    {
        $now = $this->now();
        $evicted = 0;
        foreach ($this->expiries->toArray() as $key => $expiry) {
            // toArray() returns a PHP array, and PHP array keys coerce
            // canonical decimal strings to int: the legal PSR-16 key "42"
            // comes back as int 42. ext-judy rejects a non-string offset on
            // a string-keyed array with a TypeError, so cast it back.
            $key = (string) $key;
            if ($expiry <= $now) {
                unset($this->values[$key], $this->expiries[$key]);
                $evicted++;
            }
        }
        return $evicted;
    }

    /* ── Internals ────────────────────────────────────────────── */

    private function live(string $key): bool
    {
        if (!isset($this->values[$key])) {
            return false;
        }
        if (isset($this->expiries[$key]) && $this->expiries[$key] <= $this->now()) {
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
