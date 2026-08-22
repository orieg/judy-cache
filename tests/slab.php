<?php
/**
 * Test suite for SlabArena and JudySimpleCache Slab integration.
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
use Orieg\JudyCache\Storage\SlabArena;

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

/* ── 1. Unit Tests for SlabArena ─────────────────────────────── */

// Parameter validation
throws('slab: chunkSize 0 throws', \InvalidArgumentException::class, fn() => new SlabArena(chunkSize: 0));
throws('slab: initialChunks 0 throws', \InvalidArgumentException::class, fn() => new SlabArena(initialChunks: 0));
throws('slab: maxChunks < initialChunks throws', \InvalidArgumentException::class, fn() => new SlabArena(initialChunks: 10, maxChunks: 5));

$arena = new SlabArena(chunkSize: 64, initialChunks: 10, maxChunks: 20);
check('slab: getChunkSize', 64, $arena->getChunkSize());
check('slab: getTotalChunks', 10, $arena->getTotalChunks());
check('slab: getAllocatedChunks initially 0', 0, $arena->getAllocatedChunks());
check('slab: getFreeChunks initially 10', 10, $arena->getFreeChunks());

// Allocate single chunk payload
$small = 'hello world';
$off1 = $arena->allocate($small);
check('slab: off1 is 0', 0, $off1);
check('slab: read off1', $small, $arena->read($off1));
check('slab: allocated chunks 1', 1, $arena->getAllocatedChunks());

// Allocate multi-chunk payload (e.g. 150 bytes needs (4 + 150) / 64 = 3 chunks)
$multi = str_repeat('ABCDEFGHIJ', 15); // 150 bytes
$off2 = $arena->allocate($multi);
check('slab: off2 starts after off1', 1, $off2);
check('slab: read off2', $multi, $arena->read($off2));
check('slab: allocated chunks 1 + 3 = 4', 4, $arena->getAllocatedChunks());

// Free single chunk and allocate again (should reuse freed chunks)
$arena->free($off1);
check('slab: allocated chunks after free off1', 3, $arena->getAllocatedChunks());
throws('slab: read freed off1 throws', \InvalidArgumentException::class, fn() => $arena->read($off1));
throws('slab: double free off1 throws', \InvalidArgumentException::class, fn() => $arena->free($off1));

$off3 = $arena->allocate('reused slot');
check('slab: reused first chunk offset 0', 0, $off3);
check('slab: read reused slot', 'reused slot', $arena->read($off3));

// Growth up to maxChunks
$big = str_repeat('X', 500); // 504 bytes -> 8 chunks
$off4 = $arena->allocate($big);
check('slab: growth happened', true, $arena->getTotalChunks() > 10);
check('slab: read big', $big, $arena->read($off4));

// Out of memory when maxChunks reached
throws('slab: out of memory throws OverflowException', \OverflowException::class, function () use ($arena) {
    while (true) {
        $arena->allocate(str_repeat('Y', 200));
    }
});

// Clear resets arena
$arena->clear();
check('slab: clear resets allocated count', 0, $arena->getAllocatedChunks());
$offFresh = $arena->allocate('fresh start');
check('slab: allocate after clear starts at 0', 0, $offFresh);
check('slab: read after clear', 'fresh start', $arena->read($offFresh));

/* ── 2. JudySimpleCache Integration with SlabArena ─────────────── */

$now = 1_000_000;
$slab = new SlabArena(chunkSize: 128, initialChunks: 100, maxChunks: 1000);
$cache = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    slabArena: $slab,
    slabThreshold: 50,
);

// Payload < 50 bytes (stored inline in Judy)
$inline = 'small payload';
$cache->set('k.inline', $inline);
check('cache+slab: get inline', $inline, $cache->get('k.inline'));
check('cache+slab: slab has 0 chunks for inline', 0, $slab->getAllocatedChunks());

// Payload >= 50 bytes (routed to SlabArena)
$large1 = str_repeat('Large JSON payload chunk. ', 10); // ~260 bytes
$cache->set('k.large1', $large1);
check('cache+slab: get large1', $large1, $cache->get('k.large1'));
check('cache+slab: slab has chunks allocated', true, $slab->getAllocatedChunks() > 0);

$large2 = ['data' => str_repeat('Array item ', 30), 'count' => 30];
$cache->set('k.large2', $large2);
check('cache+slab: get large2 structured array', $large2, $cache->get('k.large2'));

// Overwrite large key frees old slab chunks and allocates new
$chunksBefore = $slab->getAllocatedChunks();
$cache->set('k.large1', 'short');
check('cache+slab: overwrite with short works', 'short', $cache->get('k.large1'));
check('cache+slab: chunk count decreased on short overwrite', true, $slab->getAllocatedChunks() < $chunksBefore);

// Delete large key
$cache->set('k.large3', $large1);
$chunksBeforeDel = $slab->getAllocatedChunks();
$cache->delete('k.large3');
check('cache+slab: delete large key reduces chunks', true, $slab->getAllocatedChunks() < $chunksBeforeDel);
check('cache+slab: deleted is gone', null, $cache->get('k.large3'));

// TTL & Expire with Prune
$cache->set('k.exp', $large1, 10);
$chunksWithExp = $slab->getAllocatedChunks();
$now += 15;
check('cache+slab: expired item get returns default', 'MISS', $cache->get('k.exp', 'MISS'));
check('cache+slab: lazy eviction on get freed chunks', true, $slab->getAllocatedChunks() < $chunksWithExp);

// Bulk prune with slab entries
for ($i = 0; $i < 20; $i++) {
    $cache->set("bulk.slab.$i", str_repeat("Data for entry $i ", 10), ($i % 2 === 0) ? 10 : 1000);
}
$now += 15;
$evicted = $cache->prune();
check('cache+slab: prune evicts expired slab entries', 10, $evicted);

// Prefix delete with slab entries
$cache->set('pref.1', $large1);
$cache->set('pref.2', $large1);
check('cache+slab: deletePrefix returns 2', 2, $cache->deletePrefix('pref.'));
check('cache+slab: prefix deleted', false, $cache->has('pref.1'));

// Clear cache frees all slab chunks
$cache->clear();
check('cache+slab: clear frees all slab chunks', 0, $slab->getAllocatedChunks());
check('cache+slab: cache count is 0', 0, $cache->count());

if ($failures === 0) {
    echo "slab: all checks passed\n";
    exit(0);
}
echo "slab: $failures failure(s)\n";
exit(1);
