# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the major version is `0`, a minor bump may carry a breaking change; each
one is listed under Changed with what to do about it.

## [Unreleased]

- **Single-Trie Metadata Packing**: Packed 32-bit Unix expiry timestamp + 8-bit storage flags directly into the single entry payload envelope (`\x00JE\x01` framing), completely eliminating the secondary `$this->expiries` Judy array (`STRING_TO_INT`). Saves ~50% Judy array allocation overhead and streamlines `prune()`, `set()`, `get()`, and `delete()` down to single-trie operations. ([#11])
- **Chunked Slab Arena Allocator (`SlabArena`)**: Dedicated contiguous buffer slab allocator (`src/Storage/SlabArena.php`) managing pre-allocated chunk blocks with bitmap tracking for large byte payloads (JSON documents, HTML fragments), preventing Zend Memory Manager (ZMM) heap fragmentation. ([#11])
- **Shared Memory Pool Driver (`SharedMemoryPool`)**: Zero-copy shared memory payload segment (`src/Storage/SharedMemoryPool.php`) using PHP's `shmop` and Unix shared memory across multi-worker pools (FrankenPHP, Octane, Swoole). ([#11])
- **Zero-allocation cursor pruning**: `prune()` now iterates the single value trie directly using `first()` and `searchNext()` cursor traversal instead of materializing full array copies with `toArray()`. This eliminates $O(N)$ memory spikes during maintenance sweeps and removes numeric key coercion risks at source. ([#11])
- **Transparent adaptive compression**: Optional compression (`compressionThreshold`, `compressionCodec`: `'gzip'`, `'deflate'`, `'zstd'`, `'lz4'`) for large payloads. Binary framing header enables automatic decompression on `get()`, adaptively bypassing compression if compressed payload size is not strictly smaller. ([#11])
- **Content-addressable interning**: Optional payload deduplication pool (`enableInterning`, `internThreshold`) mapping duplicate payloads across distinct keys to a single shared reference-counted pool. ([#11])

## [0.2.0] - 2026-08-19

### Changed

- **Requires `orieg/judy-polyfill` `^2.6.0`** (was `^2.5.2`). The polyfill's
  2.6.0 stops coercing ArrayAccess offsets on string-keyed types, so
  `$judy[42]` now raises a `TypeError` there instead of storing the key `"42"`
  — matching what the extension has always done. This package is unaffected: it
  normalises every cache key to a string before it reaches Judy
  (`validateKey(string $key)`, and an explicit cast in `setMultiple()` and
  `prune()`), which its own suites confirm on both backends. Code that reaches
  past this package to the underlying `Judy` object with non-string offsets
  will see the new error, on the polyfill as it already would on the extension.
- **ext-judy 2.6.0 is the supported floor** when the extension is used.
  Earlier versions have a use-after-free in the teardown of the
  `STRING_TO_MIXED` types this package is built on ([php-judy#162]): 9 of 20
  runs crashed with `zend_mm_heap corrupted` on 2.5.x, 0 of 20 on 2.6.0.
  `JudySimpleCache` warns once at runtime when it sees an older extension with
  `storeSerialized: false`, and CI now fails if PIE resolves below 2.6.0
  instead of only printing the version. The polyfill path is unaffected.

### Fixed

- `prune()` now casts `toArray()` keys back to string. `toArray()` returns a
  PHP array, and PHP array keys coerce canonical decimal strings to int, so the
  legal PSR-16 key `"42"` came back as int `42` and was then used as an offset
  on a string-keyed Judy — a `TypeError` on ext-judy, which rejects non-string
  offsets there. Pruning a cache holding any numeric key threw. ([#1])

  This is also why the polyfill bump below is safe: the fix landed before it,
  and it is exactly the case the polyfill's 2.6.0 now reports the same way.

### Added

- `BENCHMARK.md`: reference run, methodology and analysis, with the memory
  framing switched from estimates to measured CI numbers, plus the caveat that
  Judy rows are per-process where an APCu segment is shared.
- A measured answer to whether ext-judy's bundled, patched libJudy reaches this
  layer — it does, including on the default `storeSerialized: true` path, where
  `set()` is -23.3% [-23.5, -23.0] against Debian's libJudy. Decomposed into
  the vendored patches (-9.3%), Debian's build options (-15.4%) and linkage
  (+1.3%, null), because a "bundled beats system" number quoted without a
  same-flags control credits the patches with someone else's flags. Random-order
  `get()`/`has()` past L3 is 2-3% *slower* on the bundled build — a measured
  result in the other direction, not an absence. ([#3], [#4])
- An owner-process reference implementation for the Tier-1 IPC sharing pattern,
  and documentation of the memory scope that makes it necessary.
- A PSR-16 spec-clause test suite, replacing the abandoned
  `cache/integration-tests` dependency.
- A release workflow: pushing a `v*` tag publishes a GitHub Release from this
  file's matching section, and fails if that section is missing.

## [0.1.0] - 2026-08-14

### Added

- Initial release: PSR-16 `JudySimpleCache` backed by Judy arrays, with
  O(range) prefix invalidation (`deletePrefix()`, `keysByPrefix()`) built on the
  sorted trie types, a Symfony Cache / PSR-6 `JudyAdapter`, and TTL support with
  lazy eviction. Runs on ext-judy when present and on `orieg/judy-polyfill`
  otherwise.

[0.2.0]: https://github.com/orieg/judy-cache/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/orieg/judy-cache/releases/tag/v0.1.0
[#1]: https://github.com/orieg/judy-cache/pull/1
[#3]: https://github.com/orieg/judy-cache/pull/3
[#4]: https://github.com/orieg/judy-cache/pull/4
[#11]: https://github.com/orieg/judy-cache/issues/11
[php-judy#162]: https://github.com/orieg/php-judy/issues/162
