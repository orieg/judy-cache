<?php
/**
 * Load-generator worker: connects to the owner process and runs a mixed
 * workload (80% get / 20% set) against a shared key space.
 *
 * Usage: php worker.php <sock> <worker-id> <ops>
 */

require __DIR__ . '/CacheClient.php';

[$_, $sockPath, $workerId, $ops] = $argv + [null, '/tmp/judy-cache.sock', '0', '5000'];
$client = new CacheClient($sockPath);
$ops = (int) $ops;

mt_srand(1000 + (int) $workerId);
$t0 = hrtime(true);
$hits = 0;
for ($i = 0; $i < $ops; $i++) {
    $key = sprintf('user.%d.item.%d', mt_rand(0, 999), mt_rand(0, 9));
    if (mt_rand(0, 4) === 0) {
        $client->set($key, ['w' => (int) $workerId, 'i' => $i], 300);
    } else {
        if ($client->get($key) !== null) {
            $hits++;
        }
    }
}
$elapsed = (hrtime(true) - $t0) / 1e9;
echo json_encode(['worker' => (int) $workerId, 'ops' => $ops, 'seconds' => $elapsed, 'hits' => $hits]), "\n";
