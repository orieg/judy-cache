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

> **If you install the extension, use ext-judy >= 2.6.0.** Every backend this
> package offers is one of the `STRING_TO_MIXED` types, and ext-judy before
> 2.6.0 has a use-after-free in the teardown of exactly those types
> ([php-judy#162](https://github.com/orieg/php-judy/issues/162)) — it reaches
> this package through both `clear()` and ordinary destruction, and aborts the
> process with `zend_mm_heap corrupted`. The defect predates the 2.6.0
> vendoring work and is present in every earlier release. The **default**
> `storeSerialized: true` is not affected, because it stores serialized
> strings and the bug needs values the garbage collector can own; see
> [Semantics](#semantics) for what that means if you turn it off.

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
  On **ext-judy < 2.6.0** that option is also the one that exposes
  [php-judy#162](https://github.com/orieg/php-judy/issues/162): storing values
  by reference means the cache holds arrays and objects the caller still
  references, which is precisely the shared-GC-collectable case that turns the
  teardown walk into a use-after-free. Measured against a locally built 2.5.2,
  20k shared objects abort the process in 8 of 20 trials; the same script on
  2.6.0 aborts in 0 of 20. The constructor raises one `E_USER_WARNING` if it
  sees that combination. The default keeps you clear of it on any version.
- **TTL**: `int` seconds or `DateInterval`; expired entries are evicted lazily
  on access, or eagerly via `prune()`.
- **Clock**: injectable (`new JudySimpleCache(clock: fn() => $t)`) for tests.

## Honest performance notes

- The headline benefit is **functional** (prefix invalidation, ordered key
  introspection) plus **bounded, GC-light key storage** at large entry counts.
- Measured on CI (PHP 8.4, ext-judy 2.6.0, median of 5, small serialized
  array values): at 1M entries the trie backend holds **172 MB vs 495 MB**
  for a plain PHP array cache, 921 MB for Symfony ArrayAdapter, and 1407 MB
  for TagAwareAdapter; group invalidation stays **flat (~50-58 µs)** from 50k
  to 1M entries while scan-based backends grow linearly (array: 12.4 ms ->
  252 ms). The memory ratio shrinks as values grow larger — the savings
  are in key/bucket overhead, not in your data. TagAwareAdapter's
  invalidateTags() call itself is ~14 µs because its cost is deferred:
  it pays with ~10x slower writes and the highest memory of any backend.
  For integer-keyed workloads, skip the cache layer and use a `Judy`
  array directly ([php-judy benchmarks](https://github.com/orieg/php-judy/blob/main/BENCHMARK.md)).
- **Scope caveat**: this cache is per-process, like the array/Symfony
  comparisons — at W workers, total footprint is W × RSS. APCu is shared
  across workers and stays flat as workers scale; for data every worker
  can share, it wins total memory at high worker counts. judy-cache's
  case is per-worker state, single-process daemons, and O(range)
  invalidation.
- **Which libJudy the extension links matters, and more than expected.**
  `pie install orieg/judy` gives you ext-judy's bundled, patched libJudy and
  nothing here needs doing. But an extension built `--with-judy=/usr` against a
  distro libJudy — as some packaged builds are — measures **−23% on `set()`**,
  −20 to −27% on `deletePrefix()` and −16 to −22% on `keysByPrefix()` against
  the bundled default, on the *default* `storeSerialized: true` path. About 9 of
  those 23 points are php-judy's patches and about 15 are Debian's build of the
  same upstream sources; linkage itself contributes nothing measurable. The one
  reversal: random-order `get()`/`has()` over a working set far beyond L3 is
  ~2-3% *slower* on the bundled build. Memory is unchanged either way. Full
  four-arm measurement, controls and caveats in
  [BENCHMARK.md](BENCHMARK.md#does-the-extensions-bundled-libjudy-reach-this-package).
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

`php bench/vendoring-probe.php` answers a different question: whether the
libJudy the extension is linked against is visible at this layer. It compares
builds of one ext-judy version differing only in `--with-judy`, across a ladder
of configurations, with paired per-round ratios, bootstrap CIs, claim floors, a
rebuild control and a per-child assertion of which `.so` was loaded. It needs
several extension builds on one quiet host, so it is a host-run instrument
rather than a CI job; `bench/build-vendoring-arms.sh` builds the arms.

## Sharing across workers

This cache is per-process. If several workers need one logical cache, the
supported pattern today is an **owner process**: one worker holds the
`JudySimpleCache`, the others reach it over your runtime's IPC (a Swoole
channel, unix socket, or RoadRunner RPC). That keeps a single writer — no
locking — and preserves O(range) invalidation, at the cost of a message
hop on reads (tens of µs, vs sub-µs for a shared-memory read). A runnable
reference implementation — pure-PHP unix-socket server, client, and a
latency/concurrency demo, smoke-tested in CI — lives in
[examples/owner-process/](examples/owner-process/). For data
every worker can share and read hot, APCu's shared segment remains the
right tool; see the scope caveat above.

A true shared-memory backend ("APCu with ordered keys") is a research
item on the extension side, not a promise — tracked in
[php-judy#83](https://github.com/orieg/php-judy/issues/83).

## Backend choice

The default backend is the sorted trie (`Judy::STRING_TO_MIXED`). All three
string-keyed backends support the prefix operations; pick via the
constructor:

```php
new JudySimpleCache(backend: Judy::STRING_TO_MIXED_ADAPTIVE);
```

The CI benchmark compares them; if one dominates across workloads, it will
become the default in a minor release.

## Versioning and releases

Semantic versioning, with the `0.x` caveat that a minor bump may carry a
breaking change — [CHANGELOG.md](CHANGELOG.md) lists each one and what to do
about it.

Two dependencies move together and are worth stating explicitly:

- **`orieg/judy-polyfill` `^2.6`.** Its version number tracks the *ext-judy API
  level* it implements, not its own feature count, so `^2.6` means "the 2.6
  Judy contract" on either backend.
- **ext-judy `>= 2.6.0`** when the extension is installed. Below that there is
  a use-after-free in `STRING_TO_MIXED` teardown
  ([php-judy#162](https://github.com/orieg/php-judy/issues/162)) that
  `storeSerialized: false` can trigger. Composer cannot express "optional, but
  at least this version", so this is enforced two other ways: `JudySimpleCache`
  warns once at runtime when it sees an older extension, and CI fails if PIE
  resolves below 2.6.0.

Releasing: update `CHANGELOG.md` in the PR, merge it, then push the tag. The
release workflow publishes a GitHub Release from that version's changelog
section and fails if the section is missing.

## License

MIT.
