# judy-cache

[![CI](https://github.com/orieg/judy-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/orieg/judy-cache/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/orieg/judy-cache)](https://packagist.org/packages/orieg/judy-cache)
[![License](https://img.shields.io/packagist/l/orieg/judy-cache)](LICENSE)

PSR-16 in-memory cache backed by [Judy arrays](https://github.com/orieg/php-judy),
built for **long-running PHP**: Laravel Octane, Swoole, RoadRunner, FrankenPHP
worker mode, queue workers, and CLI daemons — anywhere process memory survives
across requests. (In classic FPM the process dies with the request; use APCu
there.)

```sh
composer require orieg/judy-cache
# optional, for native performance:
pie install orieg/judy
```

Works everywhere out of the box via
[orieg/judy-polyfill](https://github.com/orieg/judy-polyfill); installs of the
[judy extension](https://github.com/orieg/php-judy) are picked up
transparently.

## PSR-16

```php
use Orieg\JudyCache\JudySimpleCache;

$cache = new JudySimpleCache();
$cache->set('user.42.profile', $profile, ttl: 300);
$profile = $cache->get('user.42.profile');
```

## What Judy adds: sorted keys → prefix invalidation

The backend keeps keys in lexicographic order (Judy trie), so invalidating a
key *range* walks only the matching keys instead of scanning the whole cache:

```php
$cache->deletePrefix('user.42.');       // O(matching keys), not O(all keys)
$cache->keysByPrefix('report.', 100);   // introspection, same property
$cache->prune();                        // evict expired entries eagerly
```

Array-backed caches (plain arrays, Symfony's `ArrayAdapter`, APCu) have no
fast path for this — they scan every key. `bench/cache-bench.php` measures the
difference; CI publishes results on each run.

## Symfony Cache / PSR-6

With `symfony/cache` installed:

```php
use Orieg\JudyCache\JudyAdapter;
use Orieg\JudyCache\JudySimpleCache;

$judy = new JudySimpleCache();
$pool = new JudyAdapter(cache: $judy);          // a PSR-6 pool
$report = $pool->get('report.7', fn () => computeReport(7));
$judy->deletePrefix('report.');                 // range invalidation underneath
```

## Semantics

- **Keys**: PSR-16 rules — `{}()/\@:` are reserved and rejected. Use `.` as
  your hierarchy separator (`user.42.profile`).
- **Values**: serialized snapshots by default (like Symfony's ArrayAdapter),
  so mutating a fetched object does not mutate the cache. Pass
  `storeSerialized: false` for by-reference storage (faster, aliasing caveat).
- **TTL**: `int` seconds or `DateInterval`; expired entries are evicted lazily
  on access, or eagerly via `prune()`.
- **Clock**: injectable (`new JudySimpleCache(clock: fn() => $t)`) for tests.

## Honest performance notes

- The headline benefit is **functional** (prefix invalidation, ordered key
  introspection) plus **bounded, GC-light key storage** at large entry counts.
- Measured on CI (PHP 8.4, median of 5, small serialized array values):
  at 1M entries the trie backend holds **172 MB vs 495 MB** for a plain
  PHP array cache, 921 MB for Symfony ArrayAdapter, and 1407 MB for
  TagAwareAdapter; group invalidation stays **flat (~40-57 µs)** from 50k
  to 1M entries while scan-based backends grow linearly (array: 13.6 ms ->
  280 ms). The memory ratio shrinks as values grow larger — the savings
  are in key/bucket overhead, not in your data. TagAwareAdapter's
  invalidateTags() call itself is ~10 µs because its cost is deferred:
  it pays with ~10x slower writes and the highest memory of any backend.
  For integer-keyed workloads, skip the cache layer and use a `Judy`
  array directly ([php-judy benchmarks](https://github.com/orieg/php-judy/blob/main/BENCHMARK.md)).
- **Scope caveat**: this cache is per-process, like the array/Symfony
  comparisons — at W workers, total footprint is W × RSS. APCu is shared
  across workers and stays flat as workers scale; for data every worker
  can share, it wins total memory at high worker counts. judy-cache's
  case is per-worker state, single-process daemons, and O(range)
  invalidation.
- Benchmark numbers should come from an idle machine or CI, never a loaded
  laptop.

## Validation

Four independent layers, all in CI on PHP 8.1–8.5 × {ext-judy, polyfill}:

1. **Spec-clause compliance**: `tests/simplecache.php` maps each testable
   MUST clause of PSR-16 to an explicit check (legal key charset + 64-char
   length, reserved-character rejection on every method, type fidelity,
   stored-null vs miss, iterables/Generators in the multi ops, TTL-expiry
   as miss, clear semantics). The historical
   [cache/integration-tests](https://github.com/php-cache/integration-tests)
   suite requires `psr/cache ~1.0` and predates the typed
   `psr/simple-cache` v3 interface, so it cannot run against modern
   implementations — the clause mapping replaces it.
2. **Behavior tests**: same file — TTL/clock edge cases, prefix ops,
   snapshot-serialization semantics, `prune()`, backend selection.
3. **Model-based fuzzing**: `php tests/fuzz.php` — random op sequences
   (set/get/delete/TTL-advance/prefix-delete) diffed step-by-step against a
   trivially-correct reference implementation, across all three backends
   and multiple seeds.
4. **Backend parity**: the underlying Judy API is itself parity-verified
   against the C extension by
   [judy-polyfill's 249-check suite](https://github.com/orieg/judy-polyfill).

## Benchmarks

`php bench/cache-bench.php` compares judy-cache (all three backends) against
a plain-array cache, Symfony `ArrayAdapter`, Symfony **`TagAwareAdapter`**
(the ecosystem's standard group-invalidation mechanism — the fair
comparison), and **APCu** when loaded. Each cell runs in a fresh child
process, multiple runs, reported as median [min..max], across a size sweep
(50k / 200k / 1M). CI publishes the table in the run summary weekly and on
every push; the current reference run with full methodology and analysis is
committed in [BENCHMARK.md](BENCHMARK.md). Trust those numbers, not laptop
runs.

## Backend choice

The default backend is the sorted trie (`Judy::STRING_TO_MIXED`). All three
string-keyed backends support the prefix operations; pick via the
constructor:

```php
new JudySimpleCache(backend: Judy::STRING_TO_MIXED_ADAPTIVE);
```

The CI benchmark compares them; if one dominates across workloads, it will
become the default in a minor release.

## License

MIT.
