# judy-cache benchmarks

Full results from the CI benchmark job (fresh table published in every
[CI run summary](https://github.com/orieg/judy-cache/actions/workflows/ci.yml),
refreshed weekly by schedule). This file freezes the current reference run
so results stay browsable in the repo; it is updated when the numbers
meaningfully change (new release, methodology change), not per commit.

## Methodology

- Each cell = fresh child process per (backend, size, run); **5 runs,
  reported as median [min..max]**.
- **Arms are interleaved**: run *r* of every backend, then run *r+1* of every
  backend. Draining one backend's runs before starting the next gives each arm
  its own contiguous slice of wall-clock, so anything that drifts over the
  sweep — a thermal ramp, another job starting, the page cache warming — is
  charged entirely to whichever arm held that slice. Interleaving spreads a
  drift across all arms instead of biasing one.
- **The child asserts its own backend.** A child process does not inherit the
  parent's `-d extension=…`, so a run driven that way used to print the
  extension in the header while every measured child silently fell back to the
  polyfill. Each child now reports the backend it actually ran under and the
  parent refuses to print a table whose children disagree with it. To measure
  a specific build, set `JUDY_EXT_SO` to its `.so` and the parent forwards it.
- Keys `user.<uid>.item.<i>` (10 entries per uid), values
  `['id' => int, 'score' => int]`, serialized snapshots where the backend
  serializes (judy-cache default, ArrayAdapter, plain-array harness).
- "Group-invalidate" removes one uid's 10 entries out of n/10 groups:
  - `judy*` — `deletePrefix('user.<uid>.')`, a range walk over sorted keys
  - `tagaware` — `invalidateTags(['user<uid>'])`, per-entry tag bookkeeping
  - `apcu` — `APCuIterator` regex scan + `apcu_delete`
  - `array` / `symfony` — full key scan
- Peak RSS via `getrusage()` per child; Judy allocates outside PHP's
  memory manager, so RSS is the only fair memory comparison. APCu's column
  includes its shared-memory segment as mapped pages (approximate).
- **Memory scope**: the array/symfony/tagaware/judy rows are **per-process**
  — in a multi-worker deployment each worker holds its own copy, so total
  footprint scales with worker count (W × RSS). APCu is **shared across
  all workers** — its footprint stays flat as workers scale. The rows are
  comparable as printed only for a single process.
- Environment of the reference run below: GitHub Actions `ubuntu-latest`,
  PHP 8.4.24, ext-judy 2.4.2, APCu enabled (`apc.shm_size=512M`),
  2026-08-14, judy-cache v0.1.x. The benchmark job installs the extension with
  PIE, which takes the latest release, so the next scheduled run refreshes
  this table onto ext-judy 2.6.0 (and onto its default bundled libJudy) with
  no change needed here.

Reproduce: `composer install && php bench/cache-bench.php 50000,200000,1000000 5`
(idle machine or CI runner only — co-resident load invalidates the numbers).

To compare two ext-judy builds, load each in the parent *and* pass it through
to the children:

```sh
JUDY_EXT_SO=/path/to/judy.so php -d extension=/path/to/judy.so \
  bench/cache-bench.php 50000,200000 5
```

## Reference run

judy-cache benchmark — keys user.<uid>.item.<i>, 5 runs per cell, median [min..max]
PHP 8.4.24, ext-judy 2.4.2, apcu yes

### n=50,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |   79.3 [79.2..79.4] |    448 [436..453] |    535 [529..540] |    13633 [13511..13939] |
| symfony       |  102.0 [101.7..102.1] |    115 [111..116] |    192 [187..194] |    49470 [48468..50255] |
| tagaware      |  122.2 [122.1..122.3] |     23 [22..23] |     67 [65..68] |       10 [9..10] |
| judy          |   63.5 [63.2..63.6] |    249 [243..252] |    286 [272..287] |       38 [33..40] |
| judy-hash     |   66.0 [65.7..66.1] |    234 [232..235] |    287 [282..287] |       43 [41..51] |
| judy-adaptive |   66.0 [65.8..66.3] |    235 [232..238] |    285 [278..286] |       48 [42..52] |
| apcu          |   69.0 [68.9..69.2] |    385 [384..393] |    474 [471..480] |     3366 [3337..3525] |

### n=200,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |  147.2 [147.0..147.4] |    445 [443..448] |    536 [531..540] |    54265 [54063..55891] |
| symfony       |  236.3 [236.0..236.4] |    115 [114..115] |    195 [194..195] |   204625 [203243..215073] |
| tagaware      |  320.4 [320.0..322.4] |     23 [22..23] |     64 [62..67] |       10 [10..10] |
| judy          |   82.3 [82.0..82.5] |    247 [242..250] |    281 [273..284] |       41 [40..41] |
| judy-hash     |   94.2 [94.2..94.4] |    235 [231..237] |    286 [284..288] |       52 [46..61] |
| judy-adaptive |   94.2 [93.9..94.3] |    236 [234..237] |    286 [285..288] |       48 [47..59] |
| apcu          |  104.6 [104.3..104.8] |    389 [384..393] |    475 [468..477] |    12844 [12265..13800] |

### n=1,000,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |  495.3 [494.9..495.4] |    447 [443..456] |    537 [525..540] |   279554 [276635..279674] |
| symfony       |  921.2 [921.1..921.4] |    112 [110..113] |    188 [184..188] |  1082692 [1076056..1116746] |
| tagaware      | 1407.0 [1406.9..1407.1] |     22 [22..22] |     65 [64..65] |       10 [10..11] |
| judy          |  172.4 [172.1..172.5] |    249 [245..251] |    281 [273..283] |       52 [43..54] |
| judy-hash     |  231.0 [230.8..231.3] |    235 [233..236] |    287 [286..288] |       51 [47..58] |
| judy-adaptive |  231.1 [230.9..231.3] |    236 [233..237] |    287 [284..291] |       57 [51..58] |
| apcu          |  293.8 [293.6..294.0] |    378 [366..381] |    457 [437..460] |    62847 [62542..64390] |

Invalidation semantics per backend: judy* = deletePrefix (range walk);
tagaware = invalidateTags (per-entry tag bookkeeping); apcu = APCuIterator
regex scan; array/symfony = full key scan. APCu peak RSS includes its
shared-memory segment as mapped pages; treat its memory column as approximate.

## Reading the results

- **Group invalidation is flat for judy-cache** (~40–57 µs at every size)
  because it walks only the matching key range; scan-based backends grow
  linearly with cache size (array: 13.6 ms → 280 ms; ArrayAdapter:
  49 ms → 1.08 s across 50k → 1M).
- **TagAwareAdapter's ~10 µs `invalidateTags()` is deferred bookkeeping**,
  not comparable work: it pays with the slowest writes in the table (~22
  kops/s, ~10x slower than judy-cache) and the highest memory (1.4 GB at
  1M entries, 8.2x judy-cache).
- **Memory at 1M entries**: judy-cache (trie) 172 MB vs 495 MB plain
  array, 921 MB ArrayAdapter, 294 MB APCu. The ratio shrinks as values
  grow — the savings are in key/bucket overhead, not in your data.
- **APCu is shared across workers; the other four rows are per-process.**
  At W workers the per-process backends cost W × RSS while APCu stays
  ~flat: at 16 workers, 16 × 172 MB far exceeds APCu's 294 MB. For data
  every worker can share, APCu (or Redis) wins total memory at high worker
  counts; judy-cache's memory case is per-worker state, single-process
  daemons, and workloads that need its O(range) invalidation (APCu's
  regex-scan invalidation is 63 ms at 1M entries and grows linearly).
- **Raw throughput**: a plain PHP array is fastest at set/get (447/537
  kops/s vs judy-cache's 249/281). You buy bounded memory and O(range)
  invalidation, not raw speed.
- **Backend choice**: the trie default beats the hash/adaptive backends on
  memory (172 vs 231 MB at 1M) with equal invalidation latency, which is
  why it stays the default.

## ext-judy 2.5.2 vs 2.6.0 — directional, NOT claim-grade

The question this answers is narrow: does upgrading the extension to 2.6.0
move judy-cache's own numbers? Separating that from the backend comparison
above is the point — 2.6.0's performance work landed on integer-keyed paths
and the string layer, and it is not obvious how much of it survives a PSR-16
wrapper whose hot path is dominated by `serialize()`/`unserialize()` and key
formatting on the PHP side.

**Confidence: directional only.** Host was a shared 8-core M1 MacBook Pro
carrying a load average of 5.0–6.3 throughout (the project's hygiene gate is
`load < N/2`, i.e. `< 4`), with a VM and a browser each near 100% CPU. Arms
were interleaved and the two extension sweeps were run sequentially rather
than concurrently, but neither fixes contention this heavy. **These numbers
must not be quoted as a measured claim** — the CI benchmark job on an idle
runner is the claim-grade instrument, and it refreshes onto 2.6.0 by itself.

Environment: macOS arm64 (M1, 8 cores), PHP 8.5.8, 5 runs per cell,
interleaved, child-asserted backend. 2.6.0 was its default **bundled static**
libJudy; 2.5.2 was linked against a **system** libJudy 1.0.5 from Homebrew —
so this A/B carries the linkage change as a confound, not just the patches.
Symfony and APCu arms are absent: `symfony/cache` needs a `composer install`
and no Composer is present on that host, and APCu is not built for that PHP.

| n | backend | peak RSS MB, 2.5.2 → 2.6.0 | set kops/s, 2.5.2 → 2.6.0 | get kops/s, 2.5.2 → 2.6.0 |
|---|---|---|---|---|
| 50k | judy | 32.4 → 32.3 | 2030 → 1972 | 1978 → 1979 |
| 50k | judy-hash | 34.9 → 34.9 | 1198 → 1599 | 2100 → 2193 |
| 50k | judy-adaptive | 34.9 → 34.9 | 1570 → 1610 | 2192 → 2202 |
| 200k | judy | 50.3 → 50.2 | 1898 → 1878 | 1752 → 1812 |
| 200k | judy-hash | 60.8 → 60.8 | 1382 → 1507 | 2006 → 2140 |
| 200k | judy-adaptive | 60.8 → 60.9 | 1554 → 1550 | 2120 → 2193 |

**Reading: no detectable difference on this workload.** Every throughput
delta above sits inside its own run-to-run spread — `judy-hash` set at 50k
spanned 1065..1643 kops/s on 2.5.2 alone, a 54% range, which is wider than
any median gap in the table. Nothing here supports a performance claim in
either direction, and the honest summary is that 2.6.0's gains do not show
through this wrapper at this noise level, not that they are absent.

**Peak RSS is the exception and is worth reading.** It varied by under 1%
across runs — it is a footprint, not a timing, so co-resident CPU load barely
touches it — and it is flat to within 0.1 MB across the two versions at every
cell. That is the expected result: 2.6.0 changed teardown order, build
configuration and inner-loop code, not the data structures, so the memory
footprint should be identical. It is.

**The reason to upgrade is not on this table.** It is
[php-judy#162](https://github.com/orieg/php-judy/issues/162): every backend
here is a `STRING_TO_MIXED` type, and before 2.6.0 their teardown is a
use-after-free. With `storeSerialized: false` and 20k shared objects, 2.5.2
aborts with `zend_mm_heap corrupted` in **8 of 20 trials** and 2.6.0 in
**0 of 20** (same host; a correctness observation, not a timing, so the
contaminated host does not weaken it).
