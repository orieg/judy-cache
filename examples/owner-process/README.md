# Owner-process pattern (single writer over IPC)

Reference implementation of the multi-worker sharing pattern from the main
README: one process owns the `JudySimpleCache`; workers talk to it over a
unix socket. Single writer by construction (no locking), and
`deletePrefix()` stays an O(matching keys) range walk.

- `CacheServer.php` — the owner: `stream_select` loop, newline-delimited
  JSON protocol, values as base64(serialize()) for exact type round-trips
- `CacheClient.php` — worker-side client (get/set/has/delete/deletePrefix/
  keysByPrefix/count)
- `worker.php` — load generator (80/20 get/set mix)
- `demo.php` — spawns everything, measures in-process vs IPC latency,
  aggregate multi-worker throughput, and a prefix invalidation

```sh
php examples/owner-process/demo.php 4 5000
```

Trade-off to expect: an IPC round-trip costs tens of µs versus sub-µs for
in-process or APCu shared-memory reads — this pattern buys single-copy
memory and range invalidation, not read latency. For production use behind
Swoole/RoadRunner, replace the raw socket with the runtime's native IPC.
This is example code: no auth, no reconnect logic, one event loop.
