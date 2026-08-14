# judy-cache benchmarks

Full results from the CI benchmark job (fresh table published in every
[CI run summary](https://github.com/orieg/judy-cache/actions/workflows/ci.yml),
refreshed weekly by schedule). This file freezes the current reference run
so results stay browsable in the repo; it is updated when the numbers
meaningfully change (new release, methodology change), not per commit.

## Methodology

- Each cell = fresh child process per (backend, size, run); **5 runs,
  reported as median [min..max]**.
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
- Environment of the reference run below: GitHub Actions `ubuntu-latest`,
  PHP 8.4.24, ext-judy 2.4.2, APCu enabled (`apc.shm_size=512M`),
  2026-08-14, judy-cache v0.1.x.

Reproduce: `composer install && php bench/cache-bench.php 50000,200000,1000000 5`
(idle machine or CI runner only — co-resident load invalidates the numbers).

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
- **Raw throughput**: a plain PHP array is fastest at set/get (447/537
  kops/s vs judy-cache's 249/281). You buy bounded memory and O(range)
  invalidation, not raw speed.
- **Backend choice**: the trie default beats the hash/adaptive backends on
  memory (172 vs 231 MB at 1M) with equal invalidation latency, which is
  why it stays the default.
