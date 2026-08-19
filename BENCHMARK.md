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

> **Partly superseded.** This section compares ext-judy *releases* and finds
> nothing; that conclusion still stands for 2.5.2-vs-2.6.0 as a release pair.
> But its closing explanation — that this wrapper's `serialize()`-dominated hot
> path leaves "little room" for extension-level gains to show through — is
> **refuted** by [Does the extension's bundled libJudy reach this
> package?](#does-the-extensions-bundled-libjudy-reach-this-package) below,
> which isolates the one variable this section could not (which libJudy is
> linked) on a quiet host and measures −23.3% on `set()` on the *default*
> serialized path. Read the two together: the release delta is null, the
> library delta is not.

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

This is the expected result rather than a disappointment: 2.5.2 and 2.6.0 were
both measured here against a *system* libJudy on a contended laptop, so the
comparison isolates the release and nothing else. **The reason to upgrade from
2.5.2 is correctness, not speed** — see the end of this section.

The reasoning that used to close this paragraph — that a
`serialize()`-dominated hot path leaves little room for extension-level gains —
turned out to be wrong, and is corrected below. The wrapper's share of an
operation is real, but it is far from the whole operation: `deletePrefix()` is
96% libJudy and even the default `set()` moves 23% when the underlying library
changes.

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

## Does the extension's bundled libJudy reach this package?

The section above compares **releases** (2.5.2 vs 2.6.0). That cannot isolate
vendoring: it carries every other change in the release *and* a
static-vs-shared linkage change, and it was run on a contended laptop. This
section replaces that framing with a controlled measurement of the one
variable that matters — **which libJudy the extension is linked against** —
and answers the question a user actually has: *does the vendored library reach
a PSR-16 cache, and in which configuration?*

The instrument is [`bench/vendoring-probe.php`](bench/vendoring-probe.php).
`cache-bench.php` cannot answer this: it compares cache *backends*, and its
operations are dominated by `serialize()`/`unserialize()`, so a large relative
gain inside libJudy is a small end-to-end one there. That is a fact about the
workload, not a defect in the benchmark.

### The arms are three builds of one version

All three arms are **php-judy 2.6.0 built from one source tree with one
toolchain**, differing only in `--with-judy`; the driver refuses to run if the
arms disagree on `judy_version()`.

| arm | `--with-judy` | libJudy |
|---|---|---|
| `bundled` | `bundled` — the default, and what `pie install orieg/judy` gives you | vendored + patched, **static** inside `judy.so` |
| `system` | `/usr` | the distro's **shared** `libJudy.so` |
| `pristine` | a directory holding an unpatched upstream build | upstream Judy 1.0.5, **static** inside `judy.so` |

`system` is the comparison a distro user experiences, but it confounds the
patches with static-vs-shared linkage. `pristine` is static like `bundled`, so
`bundled`-vs-`pristine` isolates the php-judy patches alone; reading the two
together separates the effects. "System libJudy" is also not one thing —
Debian and Fedora ship 1.0.5 *with* the Baskins `jp_1Index` fix, Alpine and
Homebrew ship it pristine — so each run records its arms' provenance verbatim.

### The ladder, and Amdahl arithmetic that is measured rather than assumed

Every family stores the same keys and differs only in how much non-Judy work
surrounds each Judy call: the default serialized path, `storeSerialized: false`,
int values, and the operations that serialize on neither side (`has`,
`delete`, `keysByPrefix`, `deletePrefix`).

Each PSR-16 family has a **bare-Judy mirror** issuing exactly the Judy calls
the wrapper issues and nothing else — `get` is *three* Judy operations
(`isset($values[$k])`, `isset($expiries[$k])`, `$values[$k]`), because that is
what `JudySimpleCache::get()` does through `live()`. So

```
judy_share = t(mirror) / t(PSR-16 row)
```

is a measured decomposition, and the expected end-to-end movement is
`judy_share × (the mirror's own delta)`. The tables print predicted and
measured side by side, so the arithmetic behind every number's *size* is
visible.

Two caveats, stated with their direction rather than as generic uncertainty:

- The mirror also pays the extension's own `ArrayAccess` dispatch, which is
  not libJudy. `judy_share` is therefore an **upper bound**, and so is every
  predicted end-to-end delta. A measured gain falling short of prediction is
  expected, not anomalous.
- Mirror payloads are built **before** the timer. If `jser` serialized inside
  its own timed loop it would pay the same `serialize()` its host pays, and
  `judy_share` would silently become "the fraction that is not PSR-16 wrapper
  overhead" — a much larger and entirely wrong number (29% vs 60% on `set`).

### Statistics

Same discipline as php-judy's `scripts/bench-threearm.php`, reimplemented in
the probe so this package depends on nothing:

- arms **interleaved**, order reversed on odd rounds; all statistics are
  **paired per-round ratios**, so between-round drift divides out
- 95% **percentile-bootstrap CI** of the median ratio
- a cell claims a direction only when the **whole CI clears the claim floor** —
  a point estimate past the floor with a straddling CI is null, not a small win
- per-residency floors from php-judy's pooled controls: **3% cache-resident,
  1.3% out-of-cache**
- **three independently linked builds per arm**, rotated across rounds; a delta
  no larger than the spread between build pairs is vetoed as layout, not libJudy
- a **PHP-array control** executing no libJudy re-centres every row, and its own
  scatter is that run's measurement of the noise floor
- **hygiene gated** at phase boundaries on both load average and foreign CPU;
  over the threshold every verdict is suppressed
- **peak RSS reported per arm** — the patches changed code, not data
  structures, so identical footprints are a checked prediction
- **every child proves which `.so` it loaded** from `/proc/self/maps`, by path
  equality. All arms report the same `judy_version()`, so the version string
  cannot tell them apart. The extension is selected with `PHP_INI_SCAN_DIR`,
  because `-d extension=` is a silent no-op on an image whose `conf.d` already
  enables judy.

This is a **host-run instrument, not a CI job**: it needs several builds of the
extension on one machine and a quiet box, neither of which a shared runner
provides.

### Host and hygiene

honeycomb, idle 24-core i9-12900F (Alder Lake), 62 GB, 30 MiB L3, Debian 13
trixie in the php-judy bench image, gcc 14.2.0, PHP 8.4.24, ext-judy **2.6.0 in
every arm**, system libJudy `libjudy-dev 1.0.5-5.1`. Exclusive use of the host
via php-judy's `tools/bench-lock.sh`.

Evidence the arms are what they claim, all machine-checked rather than assumed:

| check | `bundled` | `system` | `pristine` |
|---|---|---|---|
| `popcnt` / `bswap` in `judy.so` | 89 / 985 | 0 / 0 | 0 / 12 |
| undefined `Judy*` symbols | 0 | **38** | 0 |
| libJudy mapped at runtime | none (static) | `/usr/lib/x86_64-linux-gnu/libJudy.so.1.0.3` | none (static) |
| compiler warnings | 0 | 0 | 0 |

- **Hygiene: clean** at every phase boundary — load never above 1.1 against a
  threshold of 12, no foreign CPU.
- **Baseline stability: clean.** Each arm's absolute per-round series was gated
  with php-judy's `tools/bench-stability.py`: all cells stable within 15%,
  worst 0.4% at n=3M.
- **Rebuild control: 42/42 cells null**, median |Δ| 0.13%, worst 0.64% — two
  independently linked *bundled* builds declared as two arms. The `.so` files
  are not byte-identical, which is the point: it measures link-layout noise,
  which a byte-identical control cannot see. Nothing here can produce a
  double-digit delta from layout.
- **PHP-array control** (executes no libJudy): +0.17% / −0.03% / +0.07%.
- **Peak RSS identical across arms to 0.2 MB** (296.7 / 296.8 / 296.6 MB at
  n=300k; 2643.7 / 2643.8 / 2643.6 MB at n=3M). A useful negative result: the
  vendoring changed timing without changing footprint, exactly what patches to
  descend and byte-order paths should do, and independent corroboration that
  the arms differ in the intended way.

### Results

Negative Δ = `bundled` is faster. **Bold** = a claim (whole CI clears the floor
*and* the delta exceeds the per-build spread); `·null` = inside demonstrated
noise.

#### n=300,000 — small working set, 3% floor

| operation | bundled ms | vs `system` (Debian) Δ% [95% CI] | vs `pristine` (upstream) Δ% [95% CI] |
|---|---|---|---|
| ***default** — `storeSerialized: true`, array value* | | | |
| `set()` | 163.6 | **-23.3 [-23.5, -23.0]** | **-9.3 [-9.3, -9.1]** |
| `get()` key order | 82.6 | **-8.7 [-8.9, -8.1]** | **-4.6 [-4.8, -4.3]** |
| `get()` random | 177.0 | -0.8 [-1.3, -0.6] ·null | -1.0 [-1.2, -0.6] ·null |
| `has()` random | 83.9 | -0.8 [-0.9, -0.6] ·null | +1.9 [+1.8, +2.2] ·null |
| `keysByPrefix()` x2000 | 3.2 | **-21.3 [-21.6, -19.9]** | **-7.5 [-7.7, -6.1]** |
| `deletePrefix()` x2000 | 3.7 | **-26.4 [-26.9, -23.8]** | **-10.3 [-11.0, -7.3]** |
| `delete()` | 40.1 | **-9.8 [-9.9, -8.3]** | -4.0 [-4.7, -2.5] ·null |
| *`storeSerialized: false`, array value* | | | |
| `set()` | 105.9 | **-25.4 [-26.0, -25.3]** | **-9.2 [-10.0, -9.0]** |
| `get()` key order | 128.9 | **-23.1 [-23.6, -22.8]** | **-9.9 [-9.9, -9.6]** |
| `get()` random | 196.7 | **-8.2 [-8.4, -8.2]** | -1.3 [-1.6, -1.2] ·null |
| `has()` random | 84.2 | -0.7 [-0.8, +0.0] ·null | +2.0 [+1.8, +2.2] ·null |
| `keysByPrefix()` x2000 | 3.2 | **-21.7 [-22.0, -21.3]** | **-7.4 [-8.4, -6.5]** |
| `deletePrefix()` x2000 | 4.0 | **-24.3 [-24.6, -23.8]** | **-9.7 [-10.7, -8.9]** |
| `delete()` | 44.2 | **-9.6 [-10.2, -9.1]** | **-3.8 [-4.1, -3.6]** |
| *`storeSerialized: false`, int value* | | | |
| `set()` | 41.3 | **-13.9 [-14.2, -13.6]** | -2.3 [-2.9, -2.1] ·null |
| `get()` key order | 50.5 | **-12.8 [-12.8, -12.5]** | **-5.7 [-5.8, -5.6]** |
| `get()` random | 118.5 | -0.9 [-1.1, -0.8] ·null | +3.5 [+1.5, +4.1] ·null |
| `has()` random | 84.0 | -0.8 [-1.0, -0.2] ·null | +1.7 [+1.5, +2.0] ·null |
| `keysByPrefix()` x2000 | 3.2 | **-21.8 [-21.9, -21.1]** | **-7.6 [-8.3, -6.1]** |
| `deletePrefix()` x2000 | 3.6 | **-26.9 [-27.1, -26.5]** | **-10.4 [-10.5, -9.9]** |
| `delete()` | 39.4 | **-9.7 [-10.0, -9.3]** | **-3.9 [-4.2, -3.5]** |

#### n=3,000,000 — out of cache, 1.3% floor

| operation | bundled ms | vs `system` (Debian) Δ% [95% CI] | vs `pristine` (upstream) Δ% [95% CI] |
|---|---|---|---|
| ***default** — `storeSerialized: true`, array value* | | | |
| `set()` | 3450.7 | **-28.1 [-28.2, -28.0]** | **-12.3 [-12.6, -12.1]** |
| `get()` key order | 828.3 | **-8.4 [-8.8, -8.1]** | **-5.7 [-5.8, -5.1]** |
| `get()` random | 2422.3 | +1.3 [+1.0, +1.6] ·null | -0.9 [-1.1, -0.6] ·null |
| `has()` random | 1372.1 | **+2.2 [+2.1, +2.2]** | **+3.0 [+2.9, +3.1]** |
| `keysByPrefix()` x2000 | 3.4 | **-16.4 [-17.6, -16.3]** | **-5.8 [-8.1, -5.4]** |
| `deletePrefix()` x2000 | 3.8 | **-20.6 [-20.7, -20.3]** | **-9.4 [-9.9, -8.8]** |
| `delete()` | 421.1 | **-12.4 [-12.7, -12.2]** | **-4.2 [-4.8, -4.1]** |
| *`storeSerialized: false`, int value* | | | |
| `set()` | 425.3 | **-13.6 [-14.0, -13.2]** | **-2.5 [-2.6, -2.1]** |
| `get()` key order | 503.2 | **-12.5 [-12.7, -12.5]** | **-7.4 [-7.6, -7.1]** |
| `get()` random | 1903.2 | **+3.0 [+2.7, +3.2]** | +1.2 [+1.0, +1.4] ·null |
| `has()` random | 1374.9 | **+2.3 [+1.8, +2.5]** | **+3.2 [+2.8, +3.4]** |
| `keysByPrefix()` x2000 | 3.4 | **-17.4 [-19.5, -16.7]** | **-6.6 [-8.2, -6.1]** |
| `deletePrefix()` x2000 | 3.7 | **-20.8 [-20.9, -20.2]** | **-8.9 [-10.0, -8.7]** |
| `delete()` | 415.1 | **-12.1 [-12.3, -11.9]** | **-4.2 [-4.5, -4.0]** |

### Reading the results

**The vendored libJudy is plainly visible at this layer, including on the
default configuration.** That was not the expected answer. The prior estimate
put Judy's C code at 60–100 ns of a ~770 ns `set()` and predicted ~1.5–2.5%
end-to-end, below the floor; `set()` on the default serialized path measures
**−23.3%** against Debian's libJudy. The estimate was wrong by an order of
magnitude, and wrong about which configuration would show the effect —
`storeSerialized: false` and huge working sets were expected to be necessary
and are not.

**Where the gain is:** `set()`, key-order `get()`, `delete()`, and the two
range operations `deletePrefix()` / `keysByPrefix()` — every one of them a
claim at both sizes, with CIs far outside the floor.

**Where it is not — and this is a genuine reversal, not an absence.**
Random-order `get()` and `has()` are null at n=300k and, out of cache at n=3M,
the bundled build is **slower**: `has()` random +2.2% vs system and +3.0% vs
pristine, `get()` random +3.0% vs system. Both CIs clear the 1.3%
out-of-cache floor, so these are claims in the other direction. A cache whose
hot path is uniformly-random point lookups over a working set far larger than
L3 is the one shape where the vendored build costs a little rather than saves.
The ordering across arms tracks extension image size (bundled 1.87 MB >
pristine 0.96 MB > system 0.66 MB + shared object), which is consistent with a
TLB/instruction-footprint effect, but this host has no PMU (`perf_event_paranoid=4`,
no passwordless sudo) so that is an untested hypothesis, not a finding.

#### "System libJudy" is three differences, not one

Attributing the −23.3% to "vendoring" would be wrong: the `system` arm is
*Debian's package*, which differs from a plain build of the same upstream
sources in linkage, hardening flags (`-D_FORTIFY_SOURCE`,
`-fstack-protector-strong`, full RELRO), compiler and optimisation flags, and
package configuration — all at once. A fourth arm (upstream sources, **our**
flags, built **shared**) separates them, and the answer is not what the
"shared library is slower" intuition predicts:

| comparison | what it isolates | `set()` Δ% (n=300k) |
|---|---|---|
| `bundled` vs `pristine-static` | **php-judy's vendored patches** | **−9.3** |
| `pristine-static` vs `pristine-shared` | **linkage alone** (identical sources *and* flags) | +1.3 ·null |
| `pristine-static` vs `system` | **Debian's build** of the same sources | **−15.4** |
| composed | | **−23.2** |
| `bundled` vs `system`, measured directly | | **−23.3** |

The composition lands 0.06 points from the directly measured value, which is a
strong internal consistency check on all four arms.

So of the 23 points: **about 9 are php-judy's patches**, about 15 are Debian's
build of libJudy being slower than an `-O2` build of the identical upstream
sources, and **linkage contributes nothing measurable** (25 of 28 cells null).
Any "bundled beats system" figure quoted without a same-flags control is
crediting the patches with someone else's build options.

#### The Amdahl model works, except where it doesn't — and it says where

Predicted Δ = `judy_share × (mirror's own Δ)`, both measured on the `bundled`
arm (so Debian's hardening overhead is *not* inside the share). Against
`pristine` at n=300k the model tracks measurement closely on reads, deletes and
range operations:

| operation | libJudy share | predicted Δ% | measured Δ% |
|---|---|---|---|
| `get()` key order (default) | 39% | −3.70 | −4.63 |
| `keysByPrefix()` | 78% | −6.87 | −7.53 |
| `deletePrefix()` | 96% | −10.82 | −10.27 |
| `delete()` | 69% | −3.12 | −4.01 |
| `get()` key order (int, no serialize) | 64% | −5.70 | −5.69 |
| **`set()` (default)** | **13%** | **−0.71** | **−9.28** |
| **`set()` (array, no serialize)** | **21%** | **−1.02** | **−9.20** |
| **`has()` random** | **81%** | **−1.12** | **+1.92** |

Two families of rows break it, and the failures are informative rather than
diffuse:

1. **The serialize-heavy `set()` rows under-predict by ~10×.** The mirror
   stores payloads that already exist; the host allocates them inside the timed
   loop, and it runs *first* in the process while its mirror runs later, on a
   heap the host has already grown. The mirror's Judy inserts are therefore
   cheaper than the host's, which makes `judy_share` a **lower** bound on these
   rows rather than the upper bound it is elsewhere. That is a candidate, not a
   conclusion; measuring each mirror in its own fresh process would settle it.
2. **The random-order rows come out with the wrong sign** — see the reversal
   above. The model has no term for cache residency, so it cannot express an
   effect whose sign depends on it.

The honest summary is that an instruction-time share predicts this well when
the operation is Judy-dominated and mispredicts when it is not; it is a useful
sanity check on the *size* of a number, not a substitute for measuring it.

### When should a judy-cache user expect the bundled extension to matter?

- **Installing with `pie install orieg/judy` already gives you the bundled
  build.** This section is only about the alternative: an extension configured
  `--with-judy=/usr` against a distro libJudy, which is what some packaged
  builds do. If that is your situation, you are giving up roughly 10–25% on
  writes, ordered reads and range invalidation.
- **The gain is largest exactly where this package's value is**: `deletePrefix()`
  (−20 to −27%) and `keysByPrefix()` (−16 to −22%) are the operations judy-cache
  exists for, and they are the most Judy-dominated things it does (96% and 78%
  of the operation).
- **You do not need to give up `storeSerialized: true`.** The default path shows
  the effect. Turning serialization off raises libJudy's share and makes reads
  move more, but it is not the precondition it was assumed to be.
- **The one case to think about** is a cache whose hot path is random point
  lookups (`get()`/`has()` on unpredictable keys) over a working set far beyond
  L3 — several million entries. There the bundled build is a couple of percent
  *slower*, and it is a measured claim, not noise.
- **Memory is unchanged** by any of this, at any size.

**Scope.** One host, one CPU family (x86-64 Alder Lake), one distro's libJudy,
one PHP build. The `keysByPrefix()` / `deletePrefix()` rows touch 2,000 groups
(20,000 entries) even in the n=3M run, so they stay partly cache-resident there
and their out-of-cache figures should be read as "range operations on a large
tree", not "range operations over 3M entries". Reproduce with
`bench/vendoring-probe.php`; raw JSON, CSV and console output for every run are
committed under [`bench/results/`](bench/results/).
