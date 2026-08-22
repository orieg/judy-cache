<?php
/**
 * Test suite for SharedMemoryPool and JudySimpleCache SharedMemory integration.
 */

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/shims/psr-simple-cache.php';
    $polyfill = \getenv('JUDY_POLYFILL_PATH') ?: __DIR__ . '/../../judy-polyfill';
    require $polyfill . '/src/Judy.php';
    require $polyfill . '/src/bootstrap.php';
    require __DIR__ . '/../src/InvalidArgumentException.php';
    require __DIR__ . '/../src/Storage/SlabArena.php';
    require __DIR__ . '/../src/Storage/SharedMemoryPool.php';
    require __DIR__ . '/../src/JudySimpleCache.php';
}

use Orieg\JudyCache\JudySimpleCache;
use Orieg\JudyCache\Storage\SharedMemoryPool;

$failures = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL $label\n  expected: ", json_encode($expected), "\n  actual:   ", json_encode($actual), "\n";
    }
}

function throws(string $label, string $class, callable $fn): void
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL $label: expected $class, nothing thrown\n";
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            $failures++;
            echo "FAIL $label: expected $class, got ", get_class($e), ": ", $e->getMessage(), "\n";
        }
    }
}

if (!extension_loaded('shmop')) {
    echo "shmop: skipped (ext-shmop not loaded)\n";
    exit(0);
}

$testIpcKey = 0x53484D00 + mt_rand(1, 0xFF);
$pool = new SharedMemoryPool(key: $testIpcKey, size: 2 * 1024 * 1024, chunkSize: 128);

/* ── 1. Unit Tests for SharedMemoryPool ───────────────────────── */

check('shmop: getChunkSize', 128, $pool->getChunkSize());
check('shmop: getSize', 2 * 1024 * 1024, $pool->getSize());
check('shmop: getAllocatedChunks initially 0', 0, $pool->getAllocatedChunks());
check('shmop: getFreeChunks > 0', true, $pool->getFreeChunks() > 0);

// Single chunk allocate and read
$data1 = 'Hello Shared Memory!';
$off1 = $pool->allocate($data1);
check('shmop: off1 is 0', 0, $off1);
check('shmop: read off1', $data1, $pool->read($off1));
check('shmop: allocated 1 chunk', 1, $pool->getAllocatedChunks());

// Multi chunk allocate and read (250 bytes -> (4 + 250) / 128 = 2 chunks)
$data2 = str_repeat('0123456789', 25);
$off2 = $pool->allocate($data2);
check('shmop: off2 is 1', 1, $off2);
check('shmop: read off2', $data2, $pool->read($off2));
check('shmop: allocated 1 + 2 = 3 chunks', 3, $pool->getAllocatedChunks());

// Overwrite with write()
$data1Updated = 'Hello Updated SHM!';
$pool->write($off1, $data1Updated);
check('shmop: read updated off1', $data1Updated, $pool->read($off1));

// Free and verify chunk count
$pool->free($off1);
check('shmop: allocated chunks after free off1', 2, $pool->getAllocatedChunks());

// Reuse freed slot
$off3 = $pool->allocate('reused slot in shm');
check('shmop: off3 reused chunk 0', 0, $off3);
check('shmop: read reused slot', 'reused slot in shm', $pool->read($off3));

// Multi-worker / Multi-instance test: another pool attached to the same IPC key reads the data
$pool2 = new SharedMemoryPool(key: $testIpcKey, size: 2 * 1024 * 1024, chunkSize: 128);
check('shmop multi-instance: read off2 from secondary pool handle', $data2, $pool2->read($off2));
check('shmop multi-instance: read off3 from secondary pool handle', 'reused slot in shm', $pool2->read($off3));

// Clear shared memory
$pool->clear();
check('shmop: clear resets allocated chunks to 0', 0, $pool->getAllocatedChunks());

/* ── 2. JudySimpleCache Integration with SharedMemoryPool ──────── */

$now = 1_000_000;
$cache = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    shmPool: $pool,
    shmThreshold: 50,
);

// Inline value (< 50 bytes)
$cache->set('shm.inline', 'short string');
check('cache+shm: inline get', 'short string', $cache->get('shm.inline'));
check('cache+shm: 0 shm chunks for inline', 0, $pool->getAllocatedChunks());

// Shared large value (>= 50 bytes)
$heavyPayload = ['content' => str_repeat('Shared worker response payload. ', 10), 'meta' => ['version' => 1]];
$cache->set('shm.heavy', $heavyPayload);
check('cache+shm: get heavy payload', $heavyPayload, $cache->get('shm.heavy'));
check('cache+shm: chunks allocated in shmPool', true, $pool->getAllocatedChunks() > 0);

// Multi-worker cross-read: create a second Judy cache instance with the second pool handle
$cacheWorker2 = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    shmPool: $pool2,
    shmThreshold: 50,
);
// Simulate copying the routing entry or reading directly
$cacheWorker2->set('shm.heavy2', $heavyPayload);
check('cache+shm worker2: get heavy payload', $heavyPayload, $cacheWorker2->get('shm.heavy2'));
$cacheWorker2->delete('shm.heavy2');

// Overwrite large key frees previous shm allocation
$chunksBefore = $pool->getAllocatedChunks();
$cache->set('shm.heavy', 'small replaced');
check('cache+shm: get overwritten', 'small replaced', $cache->get('shm.heavy'));
check('cache+shm: chunks decreased after small overwrite', true, $pool->getAllocatedChunks() < $chunksBefore);

// Delete large key
$cache->set('shm.heavy3', $heavyPayload);
$chunksBeforeDel = $pool->getAllocatedChunks();
$cache->delete('shm.heavy3');
check('cache+shm: delete reduces chunks', true, $pool->getAllocatedChunks() < $chunksBeforeDel);

// Expiry and Prune
$cache->set('shm.exp', $heavyPayload, 10);
$now += 15;
check('cache+shm: expired item get returns default', 'MISS', $cache->get('shm.exp', 'MISS'));

for ($i = 0; $i < 10; $i++) {
    $cache->set("bulk.shm.$i", str_repeat("Bulk SHM Data $i ", 10), ($i % 2 === 0) ? 10 : 1000);
}
$now += 15;
$evicted = $cache->prune();
check('cache+shm: prune evicts expired shm entries', 5, $evicted);

// deletePrefix
$cache->set('shmpfx.1', $heavyPayload);
$cache->set('shmpfx.2', $heavyPayload);
check('cache+shm: deletePrefix count', 2, $cache->deletePrefix('shmpfx.'));
check('cache+shm: deletePrefix removed entry', false, $cache->has('shmpfx.1'));

// Clear
$cache->clear();
check('cache+shm: clear frees shm allocations', 0, $pool->getAllocatedChunks());
check('cache+shm: cache count 0', 0, $cache->count());

// Cleanup shm segment from OS
$pool->delete();

if ($failures === 0) {
    echo "shmop: all checks passed\n";
    exit(0);
}
echo "shmop: $failures failure(s)\n";
exit(1);
