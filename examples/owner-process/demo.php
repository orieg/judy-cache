<?php
/**
 * Owner-process pattern demo + micro-benchmark.
 *
 * Starts the cache server, measures single-client IPC round-trip latency
 * against an in-process baseline, then runs W concurrent workers and a
 * prefix invalidation through the socket.
 *
 * Run: php examples/owner-process/demo.php [workers=4] [ops-per-worker=5000]
 * CI smoke: php examples/owner-process/demo.php 2 500
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/CacheClient.php';

use Orieg\JudyCache\JudySimpleCache;

$workers = (int) ($argv[1] ?? 4);
$opsPerWorker = (int) ($argv[2] ?? 5000);
$sockPath = sys_get_temp_dir() . '/judy-cache-demo-' . getmypid() . '.sock';

// 1. Start the owner process.
$server = proc_open(
    [PHP_BINARY, __DIR__ . '/CacheServer.php', $sockPath],
    [2 => ['pipe', 'w']],
    $pipes
);
$deadline = microtime(true) + 5;
while (!file_exists($sockPath) && microtime(true) < $deadline) {
    usleep(20_000);
}
if (!file_exists($sockPath)) {
    fwrite(STDERR, "server did not come up\n");
    exit(1);
}

$client = new CacheClient($sockPath);

// 2. Latency: IPC round-trip vs in-process call, same operations.
$inproc = new JudySimpleCache();
$K = min(2000, $opsPerWorker);
$lat = function (callable $set, callable $get) use ($K): array {
    for ($i = 0; $i < $K; $i++) {
        $set("lat.$i", $i);
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < $K; $i++) {
        $get("lat.$i");
    }
    return [(hrtime(true) - $t0) / 1e3 / $K];
};
[$usInproc] = $lat(fn($k, $v) => $inproc->set($k, $v), fn($k) => $inproc->get($k));
[$usIpc]    = $lat(fn($k, $v) => $client->set($k, $v), fn($k) => $client->get($k));
printf("get latency: in-process %.2f µs/op — over IPC %.2f µs/op (%.0fx hop cost)\n",
    $usInproc, $usIpc, $usIpc / max($usInproc, 0.01));

// 3. Concurrent workers against the single owner.
$procs = [];
$t0 = hrtime(true);
for ($w = 0; $w < $workers; $w++) {
    $procs[$w] = proc_open(
        [PHP_BINARY, __DIR__ . '/worker.php', $sockPath, (string) $w, (string) $opsPerWorker],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $wpipes[$w]
    );
}
$totalOps = 0;
foreach ($procs as $w => $p) {
    $out = stream_get_contents($wpipes[$w][1]);
    proc_close($p);
    $r = json_decode(trim($out), true);
    if (is_array($r)) {
        $totalOps += $r['ops'];
    }
}
$elapsed = (hrtime(true) - $t0) / 1e9;
printf("%d workers x %d ops: %s ops/s aggregate through one owner (single writer, no locks)\n",
    $workers, $opsPerWorker, number_format((int) ($totalOps / $elapsed)));

// 4. Range invalidation through the socket — still O(matching keys).
$client->set('user.42.a', 1);
$client->set('user.42.b', 2);
$before = $client->count();
$t0 = hrtime(true);
$deleted = $client->deletePrefix('user.42.');
$us = (hrtime(true) - $t0) / 1e3;
printf("deletePrefix('user.42.') via IPC: %d keys in %.0f µs (cache size %d)\n", $deleted, $us, $before);

// 5. Shut down.
$client->shutdownServer();
proc_close($server);
@unlink($sockPath);
echo "demo ok\n";
