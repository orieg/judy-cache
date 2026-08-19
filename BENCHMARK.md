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
  PHP 8.4.24, **ext-judy 2.6.0** on its default bundled libJudy, APCu enabled
  (`apc.shm_size=512M`), 2026-08-19, judy-cache v0.1.x. The benchmark job
  installs the extension with PIE, which takes the latest release, so this
  table refreshes onto each new ext-judy without a change here.

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
PHP 8.4.24, ext-judy 2.6.0, apcu yes

### n=50,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |   79.2 [78.9..79.5] |    510 [502..511] |    592 [584..593] |    12350 [12181..12687] |
| symfony       |  101.8 [101.7..102.0] |    124 [121..125] |    208 [203..209] |    43822 [43253..44787] |
| tagaware      |  122.2 [122.0..122.2] |     29 [28..29] |     78 [76..79] |       13 [12..13] |
| judy          |   63.7 [63.4..63.7] |    275 [269..276] |    310 [297..314] |       50 [47..50] |
| judy-hash     |   65.9 [65.8..66.3] |    256 [251..259] |    313 [303..317] |       54 [53..58] |
| judy-adaptive |   66.0 [65.9..66.3] |    259 [256..259] |    310 [305..315] |       55 [54..65] |
| apcu          |   69.1 [68.5..69.2] |    425 [394..425] |    529 [518..531] |     3602 [3337..3823] |

### n=200,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |  147.2 [147.1..147.5] |    507 [504..511] |    586 [583..589] |    50230 [49302..50677] |
| symfony       |  236.2 [235.6..236.4] |    124 [122..125] |    206 [201..208] |   176741 [176005..182004] |
| tagaware      |  322.2 [322.1..322.3] |     28 [28..28] |     75 [72..75] |       13 [13..14] |
| judy          |   82.2 [82.1..82.4] |    272 [263..276] |    307 [301..314] |       51 [50..58] |
| judy-hash     |   94.1 [94.1..94.2] |    258 [254..258] |    311 [307..314] |       58 [57..62] |
| judy-adaptive |   94.2 [94.0..94.3] |    255 [247..259] |    309 [299..315] |       58 [57..59] |
| apcu          |  104.6 [104.4..104.8] |    421 [402..423] |    526 [519..526] |    16053 [15532..16355] |

### n=1,000,000 entries (invalidate one 10-key group)

| backend       | peak RSS (MB)  | set kops/s    | get kops/s    | group-invalidate (µs) |
|---------------|----------------|---------------|---------------|------------------------|
| array         |  495.1 [495.1..495.4] |    509 [499..509] |    585 [574..591] |   251813 [250740..253994] |
| symfony       |  921.2 [921.0..921.4] |    121 [121..122] |    198 [197..200] |   907771 [882052..958605] |
| tagaware      | 1406.8 [1406.7..1407.0] |     28 [27..28] |     70 [68..70] |       14 [13..15] |
| judy          |  172.4 [172.0..172.5] |    274 [271..277] |    306 [303..312] |       53 [50..63] |
| judy-hash     |  231.3 [231.1..231.5] |    257 [253..260] |    311 [305..313] |       58 [57..59] |
| judy-adaptive |  231.2 [230.8..231.4] |    256 [246..259] |    308 [304..313] |       58 [57..60] |
| apcu          |  293.7 [293.3..293.9] |    404 [396..407] |    505 [498..504] |    81182 [77053..82179] |

Invalidation semantics per backend: judy* = deletePrefix (range walk);
tagaware = invalidateTags (per-entry tag bookkeeping); apcu = APCuIterator
regex scan; array/symfony = full key scan. APCu peak RSS includes its
shared-memory segment as mapped pages; treat its memory column as approximate.

## Reading the results

- **Group invalidation is flat for judy-cache** (~50–58 µs at every size)
  because it walks only the matching key range; scan-based backends grow
  linearly with cache size (array: 12.4 ms → 252 ms; ArrayAdapter:
  44 ms → 908 ms across 50k → 1M).
- **TagAwareAdapter's ~14 µs `invalidateTags()` is deferred bookkeeping**,
  not comparable work: it pays with the slowest writes in the table (~28
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
  regex-scan invalidation is 81 ms at 1M entries and grows linearly).
- **Raw throughput**: a plain PHP array is fastest at set/get (509/585
  kops/s vs judy-cache's 274/306). You buy bounded memory and O(range)
  invalidation, not raw speed.
- **Backend choice**: the trie default beats the hash/adaptive backends on
  memory (172 vs 231 MB at 1M) with equal invalidation latency, which is
  why it stays the default.

## Did 2.6.0 change judy-cache's numbers?

Two independent readings, and they agree: **no measurable change on this
workload.**

**1. Reference run 2.4.2 → 2.6.0 (both claim-grade, both idle CI).** The table
above is 2.6.0; the run it replaced was ext-judy 2.4.2 on the same runner
image. Memory is identical to the tenth of a megabyte at every cell (1M trie:
172.4 MB both times; array 495.3 → 495.1; ArrayAdapter 921.2 both). Throughput
rose ~10%, but **it rose for every backend at once** — the plain PHP array
went 447 → 509 kops/s set and APCu 378 → 404, neither of which contains a line
of Judy code. That is a runner/PHP-build difference, not an extension gain, and
attributing it to 2.6.0 would be the classic uniform-shift error.

**2. A direct 2.5.2-vs-2.6.0 A/B** (below) — directional only, and it also
finds nothing outside the noise.

This is the expected result rather than a disappointment. 2.6.0's performance
work landed on integer-keyed paths and the string layer; this wrapper's hot
path is dominated by `serialize()`/`unserialize()` and key formatting on the
PHP side, so there is little room for it to show through. **The reason to
upgrade is correctness, not speed** — see the end of this section.

### ext-judy 2.5.2 vs 2.6.0 — directional, NOT claim-grade

This arm exists because the reference-run comparison above spans 2.4.2 to
2.6.0 and a whole runner-image change with it. A same-host A/B isolates the
extension.

**Confidence: directional only.** Host was a shared 8-core M1 MacBook Pro
carrying a load average of 5.0–6.3 throughout (the project's hygiene gate is
`load < N/2`, i.e. `< 4`), with a VM and a browser each near 100% CPU. Arms
were interleaved and the two extension sweeps were run sequentially rather
than concurrently, but neither fixes contention this heavy. **These numbers
must not be quoted as a measured claim** — the reference run above is the
claim-grade instrument.

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
