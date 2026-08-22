# Large-Value Storage, Single-Trie & Multi-Worker Optimization Shootout

A standalone headless CLI benchmark demonstrating the architectural optimizations from **[Issue #11](https://github.com/orieg/judy-cache/issues/11)** and **[Issue #13](https://github.com/orieg/judy-cache/issues/13)**:
* **Single-Trie vs. Dual-Trie Index Packing** (50% reduction in key index structure memory and faster write/prune throughput)
* **Transparent Adaptive Compression** (`gzip`, `deflate`, `zstd`, `lz4`)
* **Content-Addressable Interning** (hash-based payload deduplication)
* **Zero-Allocation Cursor TTL Pruning**
* **Multi-Worker Memory Amplification Modeling** ($W \times \text{Size}$ in FrankenPHP, Octane, Swoole, RoadRunner)

---

## Quickstart

Run directly from this repository (no Docker required):

```sh
# Default: 20,000 keys, ~2 KB payload, 50 shared templates, 8 workers
php examples/large-values/demo.php

# Custom arguments: php demo.php [keys] [payload_bytes] [unique_templates] [workers]
php examples/large-values/demo.php 50000 4096 100 16
```

---

## Output Metrics & Storage Modes

The benchmark evaluates both Single-Trie and Dual-Trie storage configurations under identical workloads:

| Storage Engine / Mode | Key Mechanism | RAM Footprint | Best Used For |
| :--- | :--- | :--- | :--- |
| **Native PHP Array** | Standard Zend `Bucket` hash table | Baseline ($1.0\times$) | Legacy FPM scripts |
| **Dual-Trie ($values + $expiries)** | 2 independent Judy radix tries | 2x index overhead | Backward-compatible cache |
| **Single-Trie (Packed Storage)** | Single Judy trie with 4-byte packed TTL header | **~50% index cut** | Modern high-throughput caches |
| **Single-Trie (Packed + Gzip)** | Packed single trie + adaptive compression | **~85% RAM savings** | Large JSON docs, rendered HTML |
| **Single-Trie (Packed + Interned)** | Packed single trie + content-addressable pool | **~95% RAM savings** | High-redundancy API templates |
| **Single-Trie (Packed + Intern + Gzip)** | Packed single trie + deduplication + gzip | **~97% RAM savings** | Multi-tenant heavy payloads |

---

## Single-Trie vs. Dual-Trie Architecture

In legacy dual-trie architectures, two separate Judy digital tries (`$values` and `$expiries`) are allocated per cache instance. Every key insertion requires two separate trie traversals and memory allocations.

With **Single-Trie packed storage**:
1. The 4-byte big-endian TTL timestamp is packed as a binary prefix directly with the payload: `pack('N', $expiry) . $payload`.
2. A single `Judy::STRING_TO_MIXED` trie indexes the key space.
3. **Key index structure memory is reduced by ~50%** (cutting JudySL branch and leaf node allocations in half).
4. **Write & prune throughput increases** because each operation performs a single trie lookup and mutation instead of two.

---

## Multi-Worker Scaling ($W \times \text{Size}$)

In persistent PHP worker runtimes (FrankenPHP, Laravel Octane, Swoole, RoadRunner), every worker process keeps its own in-memory cache segment. Without compression or interning, a 40 MB cache replicated across 16 workers consumes **640 MB of host RAM**.

With `judy-cache` single-trie packed storage, adaptive compression, and interning enabled, that 16-worker memory footprint collapses to **< 20 MB**.
