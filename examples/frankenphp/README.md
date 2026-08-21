# FrankenPHP Live Worker Demo: judy-cache & php-judy 2.6.0

An interactive testbed running **FrankenPHP in persistent Worker Mode** with `php-judy 2.6.0`, `orieg/judy-cache`, and `orieg/judy-polyfill`.

## Features
- **FrankenPHP Worker Mode**: Shows true resident memory retention across HTTP requests.
- **Interactive Web UI**: Adjust workload parameters via live sliders ($10\text{k} \dots 1\text{M}$ items).
- **Benchmarking & Validation**:
  - **Memory Footprint**: Direct peak RSS and bytes/key shootout (Native Judy vs. Polyfill vs. Standard PHP Arrays).
  - **$\mathcal{O}(\text{range})$ Prefix Invalidation**: Benchmark `deleteByPrefix("tenant:1:*")` vs linear hashtable key scans.
  - **PSR-16 Cache Throughput**: Sustained read/write throughput tests.
  - **Integer Counters / Rate Limiting**: High-volume integer increment workloads.

## Quickstart

From this directory:

```bash
docker compose up --build
```

Then open **[http://localhost:8080](http://localhost:8080)** in your browser.

## API Endpoints

- `GET /api/status`: Returns current worker PID, memory usage, request counts, and loaded extension versions.
- `POST /api/benchmark`: Runs workload with JSON payload `{"count": 100000, "backend": "all", "workload": "memory_shootout"}`.
- `POST /api/clear`: Flushes resident worker cache and forces `gc_collect_cycles()`.
