# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the major version is `0`, a minor bump may carry a breaking change; each
one is listed under Changed with what to do about it.

## [0.3.0] - 2026-08-22

### Added

- **`Judy::STRING_TO_ENTRY` Primary Storage Architecture**: Refactored `JudySimpleCache` to use `Judy::STRING_TO_ENTRY` natively in C. Expiry timestamps and 16-bit bitfield metadata flags (`FLAG_RAW`, `FLAG_COMPRESSED`, `FLAG_INTERNED`, `FLAG_SLAB`, `FLAG_SHMOP`, and codec shift/mask) are stored directly inside native C struct entries (`judy_cache_entry_t`), completely removing userland payload envelope headers (`\x00JE\x01`) and substr/unpack overhead. ([#11])
- **In-C Single-Pass Batch Eviction (`pruneExpired()`)**: Hybrid-safe `prune()` invokes native in-C `pruneExpired()` directly when no external allocations exist, delivering maximal throughput eviction in a single trie pass without userland loops, and cursor-walks when external slab/shmop blocks must be reclaimed. ([#11])
- **Chunked Slab Arena Allocator (`SlabArena`)**: Dedicated contiguous buffer slab allocator (`src/Storage/SlabArena.php`) managing pre-allocated chunk blocks with bitmap tracking and 8-byte uint64 binary chunk offsets (`pack('P', $offset)`) for large byte payloads (JSON documents, HTML fragments), preventing Zend Memory Manager (ZMM) heap fragmentation. ([#11])
- **Shared Memory Pool Driver (`SharedMemoryPool`)**: Zero-copy shared memory payload segment (`src/Storage/SharedMemoryPool.php`) using PHP's `shmop` and Unix shared memory across multi-worker pools (FrankenPHP, Octane, Swoole) with 8-byte uint64 binary chunk offsets. ([#11])
- **Raw Binary XXH3 Content-Addressable Interning**: Storing raw 8-byte binary XXH3 digests (`hash('xxh3', $payload, true)`) in an integer-keyed `INT_TO_MIXED` / `INT_TO_INT` deduplication pool, cutting hash overhead by 50% compared to hex strings. ([#11])
- **Transparent Adaptive Compression**: Optional compression (`compressionThreshold`, `compressionCodec`: `'gzip'`, `'deflate'`, `'zstd'`, `'lz4'`) with codec metadata encoded directly into 16-bit storage flags (`CODEC_MASK = 0x00F0`), adaptively bypassing compression if compressed payload is not strictly smaller. ([#11])

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
