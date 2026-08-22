<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Orieg\JudyCache\JudySimpleCache;
use Orieg\JudyCache\Storage\SlabArena;
use Orieg\JudyCache\Storage\SharedMemoryPool;

ini_set('memory_limit', '-1');

[$_, $mode, $itemCount, $payloadBytes, $uniqueTemplates, $workerId, $shmKey] = $argv + [null, 'array', '10000', '2048', '25', '0', '0'];

$itemCount = (int)$itemCount;
$payloadBytes = (int)$payloadBytes;
$uniqueTemplates = (int)$uniqueTemplates;
$workerId = (int)$workerId;
$shmKeyInt = (int)$shmKey;

// Generate synthetic templates
$templates = [];
for ($t = 0; $t < $uniqueTemplates; $t++) {
    $items = [];
    for ($j = 0; $j < 15; $j++) {
        $items[] = [
            'id' => "item_{$t}_{$j}",
            'sku' => "SKU-" . str_pad((string)($t * 100 + $j), 6, '0', STR_PAD_LEFT),
            'title' => "Component Model #{$t}-{$j}",
            'description' => str_repeat("Radix trie memory engine for persistent worker runtimes. ", 2),
            'price' => round(19.99 + ($t * 1.5) + $j, 2),
            'in_stock' => ($j % 2 === 0),
        ];
    }
    $templates[$t] = [
        'template_id' => $t,
        'status' => 'success',
        'tenant' => "tenant_{$t}",
        'data' => $items,
    ];
}

gc_collect_cycles();
$memBase = memory_get_usage(true);
$realBase = memory_get_usage(false);

// Signal ready and output baseline to stdout
echo json_encode([
    'event' => 'ready',
    'pid' => getmypid(),
    'base_mem_bytes' => $memBase,
]), "\n";
flush();

// Wait for start command from parent via stdin
$line = fgets(STDIN);
if (!$line) exit(0);

$t0 = hrtime(true);
$cache = null;

// Populate according to mode
if ($mode === 'array') {
    $cache = [];
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache["w{$workerId}.doc.{$tplIdx}.{$i}"] = serialize($templates[$tplIdx]);
    }
} elseif ($mode === 'judy_uncompressed') {
    $cache = new JudySimpleCache(compressionThreshold: null, enableInterning: false);
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
    }
} elseif ($mode === 'judy_gzip') {
    $cache = new JudySimpleCache(compressionThreshold: 256, compressionCodec: 'gzip', enableInterning: false);
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
    }
} elseif ($mode === 'judy_interned') {
    $cache = new JudySimpleCache(compressionThreshold: null, enableInterning: true, internThreshold: 256);
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
    }
} elseif ($mode === 'judy_intern_gzip') {
    $cache = new JudySimpleCache(compressionThreshold: 256, compressionCodec: 'gzip', enableInterning: true, internThreshold: 100);
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
    }
} elseif ($mode === 'judy_slab') {
    $arena = new SlabArena(chunkSize: 1024, initialChunks: 1000);
    $cache = new JudySimpleCache(slabArena: $arena, slabThreshold: 256);
    for ($i = 0; $i < $itemCount; $i++) {
        $tplIdx = $i % $uniqueTemplates;
        $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
    }
} elseif ($mode === 'judy_shm') {
    if ($shmKeyInt > 0 && function_exists('shmop_open')) {
        $pool = new SharedMemoryPool(key: $shmKeyInt, size: 1024 * 1024, chunkSize: 1024);
        $cache = new JudySimpleCache(shmPool: $pool, shmThreshold: 256);
        for ($i = 0; $i < $itemCount; $i++) {
            $tplIdx = $i % $uniqueTemplates;
            $cache->set("w{$workerId}.doc.{$tplIdx}.{$i}", $templates[$tplIdx], 3600);
        }
    }
}

$t1 = hrtime(true);
$durationMs = ($t1 - $t0) / 1e6;
$opsSec = round($itemCount / max(1e-6, ($t1 - $t0) / 1e9));

$memPop = memory_get_usage(true);
$realPop = memory_get_usage(false);

echo json_encode([
    'event' => 'populated',
    'pid' => getmypid(),
    'duration_ms' => round($durationMs, 2),
    'ops_sec' => $opsSec,
    'allocated_mb' => round(($realPop - $realBase) / 1024 / 1024, 2),
    'worker_mem_bytes' => $memPop,
]), "\n";
flush();

// Keep running until parent sends quit signal
fgets(STDIN);
exit(0);
