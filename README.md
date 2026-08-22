# judy-cache

[![CI](https://github.com/orieg/judy-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/orieg/judy-cache/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/orieg/judy-cache)](https://packagist.org/packages/orieg/judy-cache)
[![License](https://img.shields.io/packagist/l/orieg/judy-cache)](LICENSE)

High-performance PSR-16 and PSR-6 in-memory cache backed by [Judy arrays](https://github.com/orieg/php-judy), built for **long-running PHP**: Laravel Octane, Swoole, RoadRunner, FrankenPHP worker mode, queue workers, and CLI daemons — anywhere process memory survives across requests. (In classic FPM the process dies with the request; use APCu there.)

- **O(range) Prefix Invalidation**: Invalidate key hierarchies instantly without scanning the whole cache.
- **Up to 70% Less Memory**: Radix-trie storage compresses key/bucket overhead at scale (172 MB vs 495 MB at 1M keys).
- **Works Out of the Box**: Uses [orieg/judy-polyfill](https://github.com/orieg/judy-polyfill) by default; automatically upgrades to native C [ext-judy >= 2.6.0](https://github.com/orieg/php-judy) when loaded.

```sh
composer require orieg/judy-cache

# Optional for native C performance:
pie install orieg/judy
```

> **Note:** If you install the C extension, use **ext-judy >= 2.6.0** for memory-safe teardown on mixed-type arrays ([php-judy#162](https://github.com/orieg/php-judy/issues/162)).

---

## Quickstart (PSR-16)

```php
use Orieg\JudyCache\JudySimpleCache;

$cache = new JudySimpleCache();
$cache->set('user.42.profile', $profile, ttl: 300);
$profile = $cache->get('user.42.profile');
```

---

## What Judy Adds: Prefix Invalidation & Introspection

Because the trie maintains keys in lexicographic order, range operations walk only matching keys ($O(\text{matching keys})$ instead of scanning all $N$ entries):

```php
$cache->deletePrefix('user.42.');       // O(matching keys), not O(all keys)
$cache->keysByPrefix('report.', 100);   // ordered prefix exploration
$cache->prune();                        // eager eviction of expired entries
```

Array-backed caches (plain arrays, Symfony `ArrayAdapter`, APCu) have no fast path for range eviction and must scan every key.

---

## Symfony Cache / PSR-6

With `symfony/cache` installed:

```php
use Orieg\JudyCache\JudyAdapter;
use Orieg\JudyCache\JudySimpleCache;

$judy = new JudySimpleCache();
$pool = new JudyAdapter(cache: $judy);          // PSR-6 cache pool
$report = $pool->get('report.7', fn () => computeReport(7));
$judy->deletePrefix('report.');                 // range invalidation underneath
```

---

## Examples & Interactive Testbeds

Explore runnable implementations in [`examples/`](examples/):

* **[Large-Value Storage Shootout](examples/large-values/)**: Standalone headless CLI benchmark evaluating transparent adaptive compression, content-addressable interning, zero-alloc cursor pruning, and multi-worker memory models ($W \times \text{Size}$).
* **[FrankenPHP Worker Mode Testbed](examples/frankenphp/)**: Interactive web dashboard with real-time SSE telemetry, live process VmRSS metrics, CRC lossless integrity verification, and side-by-side shootouts ($10\text{k} \dots 10\text{M}$ keys).
* **[Multi-Worker Owner Process](examples/owner-process/)**: Reference implementation of an IPC/Unix-socket cache daemon providing a single-writer cache across multi-worker pools.

---

## Semantics & Configuration

- **Keys**: Standard PSR-16 rules (`{}()/\@:` reserved). Use `.` as your hierarchy separator (`user.42.profile`).
- **Values**: Serialized snapshots by default (like Symfony's `ArrayAdapter`), ensuring fetched objects are safe from mutation. Pass `storeSerialized: false` for faster by-reference storage.
- **TTL & Pruning**: `int` seconds or `DateInterval`. Expired entries are evicted lazily on access, or eagerly via zero-allocation cursor `prune()`. The single-trie metadata envelope packs expiry and flags directly, eliminating secondary lookup arrays.
- **Adaptive Compression**: Pass `compressionThreshold: 1024` (bytes) and `compressionCodec: 'gzip'` (`'gzip'`, `'deflate'`, `'zstd'`, `'lz4'`) to auto-compress payloads exceeding the threshold. Automatically skips compression if the compressed size exceeds original payload size.
- **Content-Addressable Interning**: Pass `enableInterning: true` (and optional `internThreshold: 256`) to deduplicate identical payloads across distinct keys into a shared, reference-counted pool.
- **Chunked Slab Arena**: Pass `slabArena: new SlabArena()` and `slabThreshold: 1024` to route large byte payloads (JSON docs, HTML fragments) into pre-allocated contiguous memory blocks to prevent Zend Memory Manager (ZMM) heap fragmentation.
- **Shared Memory Pool (shmop)**: Pass `shmPool: new SharedMemoryPool()` and `shmThreshold: 1024` for zero-copy shared memory payload segments across multi-worker pools (FrankenPHP, Octane, Swoole).
- **Clock**: Injectable (`new JudySimpleCache(clock: fn() => $timestamp)`) for deterministic testing.
- **Backend Choice**: Defaults to `Judy::STRING_TO_MIXED`. Alternate trie backends can be selected via the constructor (e.g. `backend: Judy::STRING_TO_MIXED_ADAPTIVE`).

---

## Performance & Memory

Measured on CI (PHP 8.4, `ext-judy 2.6.0`, median of 5, small serialized array values):

| Metric / Workload | `judy-cache` (ext-judy) | Native PHP Array | Symfony `ArrayAdapter` | Symfony `TagAwareAdapter` |
| :--- | :--- | :--- | :--- | :--- |
| **RAM (1M entries)** | **172 MB** | 495 MB | 921 MB | 1,407 MB |
| **Prefix Prune (50k)** | **50 µs** | 12.4 ms | 13.1 ms | 14.0 µs *(deferred)* |
| **Prefix Prune (1M)** | **58 µs** | 252.0 ms | 268.0 ms | 14.0 µs *(deferred)* |
| **Write Ops/s (set)** | **~1.6M ops/s** | ~830k ops/s | ~790k ops/s | ~85k ops/s *(10x slower)* |

> **Process Scope**: Like PHP arrays, `judy-cache` is per-process. For data shared read-hot across many worker processes without invalidation needs, APCu remains optimal. For per-worker state, single-process daemons, and $O(\text{range})$ invalidation, `judy-cache` provides unmatched memory density and eviction speed.

See [`BENCHMARK.md`](BENCHMARK.md) for full benchmarks, methodology, and vendoring analyses.

---

## Validation

Four independent layers executed across PHP 8.1–8.5 on both `ext-judy` and `judy-polyfill`:

1. **Spec Compliance**: `tests/simplecache.php` verifies every testable clause of PSR-16.
2. **Behavior Tests**: Clock/TTL edge cases, prefix operations, and serialization semantics.
3. **Model-Based Fuzzing**: `tests/fuzz.php` tests randomized operations diffed step-by-step against an oracle.
4. **Backend Parity**: Fully verified against `php-judy` C extension via `judy-polyfill`'s differential test suite.

---

## License

MIT.
