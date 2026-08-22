# Large-Value Storage & Multi-Worker Optimization Shootout

A standalone headless CLI benchmark demonstrating the architectural optimizations from **[Issue #11](https://github.com/orieg/judy-cache/issues/11)**:
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

The benchmark evaluates six storage configurations under identical workloads:

| Storage Engine / Mode | Key Mechanism | RAM Footprint | Best Used For |
| :--- | :--- | :--- | :--- |
| **Native PHP Array** | Standard Zend `Bucket` hash table | Baseline ($1.0\times$) | Legacy FPM scripts |
| **judy-cache (Uncompressed)** | Radix-trie key indexing | Compressed key space | Small scalar / session keys |
| **judy-cache (Adaptive Gzip)** | Thresholded payload compression (`\x00JC\x01`) | **~85% RAM savings** | Large JSON docs, rendered HTML |
| **judy-cache (Adaptive Deflate)** | Raw deflate compression framing | **~85% RAM savings** | Fast decompression pipelines |
| **judy-cache (Interned Dedup)** | Content-addressable reference pool (`\x00JI\x01`) | **~95% RAM savings** | High-redundancy API templates |
| **judy-cache (Interned + Gzip)** | Dual interning + compression pipeline | **~97% RAM savings** | Multi-tenant heavy payloads |

---

## Multi-Worker Scaling ($W \times \text{Size}$)

In persistent PHP worker runtimes (FrankenPHP, Laravel Octane, Swoole, RoadRunner), every worker process keeps its own in-memory cache segment. Without compression or interning, a 40 MB cache replicated across 16 workers consumes **640 MB of host RAM**.

With `judy-cache` adaptive compression and interning enabled, that 16-worker memory footprint collapses to **< 20 MB**.
