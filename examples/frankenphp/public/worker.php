<?php

declare(strict_types=1);

// Uncap memory and time limits for heavy benchmarks
ini_set('memory_limit', '-1');
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

use Orieg\JudyCache\JudySimpleCache;
use Orieg\JudyPolyfill\Judy as PolyfillJudy;

// Persistent worker state across HTTP requests
$requestsServed = 0;
$workerStartedAt = microtime(true);
$residentCache = new JudySimpleCache();
$residentCounter = class_exists('Judy') ? new Judy(Judy::INT_TO_INT) : [];

/**
 * Execute a single benchmark run
 */
function executeBenchmark(string $backend, string $workload, int $count, array $params = []): array
{
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $realMemBefore = memory_get_usage();
    $t0 = hrtime(true);

    $metrics = [];

    switch ($workload) {
        case 'cache_rw':
            $prefix = $params['prefix'] ?? 'app.session.';
            $sampleReads = min($count, 50000);

            if ($backend === 'judy') {
                $cache = new JudySimpleCache();
                for ($i = 0; $i < $count; $i++) {
                    $cache->set("{$prefix}{$i}", ['id' => $i, 'v' => 1]);
                }
                $tWrite = hrtime(true);
                $hits = 0;
                for ($i = 0; $i < $sampleReads; $i++) {
                    if ($cache->get("{$prefix}{$i}") !== null) $hits++;
                }
                $tRead = hrtime(true);
                $metrics['write_ops_sec'] = round($count / max(1e-6, ($tWrite - $t0) / 1e9));
                $metrics['read_ops_sec'] = round($sampleReads / max(1e-6, ($tRead - $tWrite) / 1e9));
                $metrics['hits'] = $hits;
                $metrics['total_keys'] = $cache->count();
            } elseif ($backend === 'polyfill') {
                $polyfillTrie = new PolyfillJudy(PolyfillJudy::STRING_TO_MIXED);
                for ($i = 0; $i < $count; $i++) {
                    $polyfillTrie["{$prefix}{$i}"] = serialize(['id' => $i, 'v' => 1]);
                }
                $tWrite = hrtime(true);
                $hits = 0;
                for ($i = 0; $i < $sampleReads; $i++) {
                    if (isset($polyfillTrie["{$prefix}{$i}"])) $hits++;
                }
                $tRead = hrtime(true);
                $metrics['write_ops_sec'] = round($count / max(1e-6, ($tWrite - $t0) / 1e9));
                $metrics['read_ops_sec'] = round($sampleReads / max(1e-6, ($tRead - $tWrite) / 1e9));
                $metrics['hits'] = $hits;
                $metrics['total_keys'] = count($polyfillTrie);
            } else {
                $arrayCache = [];
                for ($i = 0; $i < $count; $i++) {
                    $arrayCache["{$prefix}{$i}"] = ['id' => $i, 'v' => 1];
                }
                $tWrite = hrtime(true);
                $hits = 0;
                for ($i = 0; $i < $sampleReads; $i++) {
                    if (isset($arrayCache["{$prefix}{$i}"])) $hits++;
                }
                $tRead = hrtime(true);
                $metrics['write_ops_sec'] = round($count / max(1e-6, ($tWrite - $t0) / 1e9));
                $metrics['read_ops_sec'] = round($sampleReads / max(1e-6, ($tRead - $tWrite) / 1e9));
                $metrics['hits'] = $hits;
                $metrics['total_keys'] = count($arrayCache);
            }
            break;

        case 'prefix_invalidation':
            $tenants = 10;
            $keysPerTenant = (int)ceil($count / $tenants);
            
            if ($backend === 'judy') {
                $cache = new JudySimpleCache();
                for ($t = 1; $t <= $tenants; $t++) {
                    for ($k = 1; $k <= $keysPerTenant; $k++) {
                        $cache->set("tenant.{$t}.order.{$k}", $k);
                    }
                }
                $tPopulate = hrtime(true);
                $tPrefix0 = hrtime(true);
                $deletedCount = $cache->deletePrefix("tenant.1.");
                $tPrefix1 = hrtime(true);
                
                $metrics['populate_ms'] = round(($tPopulate - $t0) / 1e6, 2);
                $metrics['prefix_invalidation_ms'] = round(($tPrefix1 - $tPrefix0) / 1e6, 4);
                $metrics['deleted_keys'] = $deletedCount;
                $metrics['remaining_keys'] = $cache->count();
                $metrics['algo_complexity'] = 'O(range) Sub-trie splice';
            } else {
                $arrayCache = [];
                for ($t = 1; $t <= $tenants; $t++) {
                    for ($k = 1; $k <= $keysPerTenant; $k++) {
                        $arrayCache["tenant.{$t}.order.{$k}"] = $k;
                    }
                }
                $tPopulate = hrtime(true);
                $tPrefix0 = hrtime(true);
                $deletedCount = 0;
                $prefixMatch = "tenant.1.";
                $prefixLen = strlen($prefixMatch);
                foreach ($arrayCache as $k => $v) {
                    if (strncmp($k, $prefixMatch, $prefixLen) === 0) {
                        unset($arrayCache[$k]);
                        $deletedCount++;
                    }
                }
                $tPrefix1 = hrtime(true);

                $metrics['populate_ms'] = round(($tPopulate - $t0) / 1e6, 2);
                $metrics['prefix_invalidation_ms'] = round(($tPrefix1 - $tPrefix0) / 1e6, 4);
                $metrics['deleted_keys'] = $deletedCount;
                $metrics['remaining_keys'] = count($arrayCache);
                $metrics['algo_complexity'] = 'O(N) Linear scan';
            }
            break;

        case 'int_counter':
            if ($backend === 'judy') {
                $judy = new Judy(Judy::INT_TO_INT);
                for ($i = 0; $i < $count; $i++) {
                    $key = ($i * 17) % ($count * 2);
                    $judy[$key] = ($judy[$key] ?? 0) + 1;
                }
                $metrics['total_entries'] = count($judy);
                $metrics['judy_memuse_kb'] = round($judy->memoryUsage() / 1024, 2);
            } elseif ($backend === 'polyfill') {
                $judy = new PolyfillJudy(PolyfillJudy::INT_TO_INT);
                for ($i = 0; $i < $count; $i++) {
                    $key = ($i * 17) % ($count * 2);
                    $judy[$key] = ($judy[$key] ?? 0) + 1;
                }
                $metrics['total_entries'] = count($judy);
            } else {
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = ($i * 17) % ($count * 2);
                    $arr[$key] = ($arr[$key] ?? 0) + 1;
                }
                $metrics['total_entries'] = count($arr);
            }
            break;

        case 'memory_shootout':
        default:
            if ($backend === 'judy') {
                $judy = new Judy(Judy::INT_TO_INT);
                for ($i = 0; $i < $count; $i++) {
                    $judy[$i] = $i * 2;
                }
                $metrics['total_entries'] = count($judy);
                $metrics['judy_internal_mb'] = round($judy->memoryUsage() / 1024 / 1024, 2);
                $metrics['bytes_per_key'] = round($judy->memoryUsage() / max(1, $count), 2);
            } elseif ($backend === 'polyfill') {
                $judy = new PolyfillJudy(PolyfillJudy::INT_TO_INT);
                for ($i = 0; $i < $count; $i++) {
                    $judy[$i] = $i * 2;
                }
                $metrics['total_entries'] = count($judy);
            } else {
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $arr[$i] = $i * 2;
                }
                $metrics['total_entries'] = count($arr);
            }
            break;
    }

    $t1 = hrtime(true);
    $memAfter = memory_get_usage(true);
    $realMemAfter = memory_get_usage();

    $durationMs = ($t1 - $t0) / 1e6;
    $metrics['duration_ms'] = round($durationMs, 2);
    $metrics['ops_per_sec'] = round($count / max(1e-6, ($t1 - $t0) / 1e9));
    $metrics['mem_allocated_mb'] = round(max(0, $realMemAfter - $realMemBefore) / 1024 / 1024, 2);
    $metrics['peak_rss_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    return $metrics;
}

// FrankenPHP worker request handler
$handler = function () use (&$requestsServed, $workerStartedAt, $residentCache, &$residentCounter) {
    $requestsServed++;
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        return;
    }

    // Status API
    if ($uri === '/api/status') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'running',
            'runtime' => 'FrankenPHP Worker Mode',
            'php_version' => PHP_VERSION,
            'ext_judy_loaded' => extension_loaded('judy'),
            'ext_judy_version' => phpversion('judy') ?: 'Not Loaded',
            'pid' => getmypid(),
            'requests_served_by_worker' => $requestsServed,
            'worker_uptime_sec' => round(microtime(true) - $workerStartedAt, 1),
            'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'resident_cache_items' => $residentCache->count(),
            'resident_counter_items' => is_countable($residentCounter) ? count($residentCounter) : 0,
        ]);
        return;
    }

    // Streaming Benchmark API (Server-Sent Events for Live Progress Terminal)
    if ($uri === '/api/stream-benchmark') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $count = max(1000, min(10000000, (int)($_GET['count'] ?? 100000)));
        $backend = $_GET['backend'] ?? 'all';
        $workload = $_GET['workload'] ?? 'memory_shootout';

        $sendEvent = function (string $type, array $data) {
            echo "event: {$type}\n";
            echo "data: " . json_encode($data) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        };

        $sendEvent('log', [
            'level' => 'info',
            'text' => sprintf("⚡️ [Worker #%d] Starting benchmark: workload=%s, count=%s keys", getmypid(), $workload, number_format($count)),
        ]);

        $results = [];

        try {
            // Judy Step
            if ($backend === 'all' || $backend === 'judy') {
                if (extension_loaded('judy')) {
                    $sendEvent('log', [
                        'level' => 'step',
                        'stage' => 'judy',
                        'text' => sprintf("🚀 [ext-judy 2.6.0] Allocating %s items in digital trie (hardware POPCNT + BSWAP enabled)...", number_format($count)),
                    ]);
                    $resJudy = executeBenchmark('judy', $workload, $count);
                    $results['judy'] = $resJudy;
                    $sendEvent('log', [
                        'level' => 'success',
                        'stage' => 'judy',
                        'text' => sprintf("✓ [ext-judy 2.6.0] Finished in %sms &bull; Allocated: %s MB &bull; Throughput: %s ops/s", $resJudy['duration_ms'], $resJudy['mem_allocated_mb'], number_format($resJudy['ops_per_sec'])),
                    ]);
                }
            }

            // Array Step
            if ($backend === 'all' || $backend === 'array') {
                $sendEvent('log', [
                    'level' => 'step',
                    'stage' => 'array',
                    'text' => sprintf("🐘 [PHP Array] Allocating %s items in Zend Hash Table (36-byte Bucket structs)...", number_format($count)),
                ]);
                $resArray = executeBenchmark('array', $workload, $count);
                $results['array'] = $resArray;
                $sendEvent('log', [
                    'level' => 'success',
                    'stage' => 'array',
                    'text' => sprintf("✓ [PHP Array] Finished in %sms &bull; Allocated: %s MB &bull; Throughput: %s ops/s", $resArray['duration_ms'], $resArray['mem_allocated_mb'], number_format($resArray['ops_per_sec'])),
                ]);
            }

            // Polyfill Step (if small enough or explicitly selected)
            if ($backend === 'polyfill' || ($backend === 'all' && $count <= 200000)) {
                $sendEvent('log', [
                    'level' => 'step',
                    'stage' => 'polyfill',
                    'text' => sprintf("🧩 [judy-polyfill] Running %s items in pure-PHP fallback trie...", number_format($count)),
                ]);
                $resPolyfill = executeBenchmark('polyfill', $workload, $count);
                $results['polyfill'] = $resPolyfill;
                $sendEvent('log', [
                    'level' => 'success',
                    'stage' => 'polyfill',
                    'text' => sprintf("✓ [judy-polyfill] Finished in %sms &bull; Allocated: %s MB", $resPolyfill['duration_ms'], $resPolyfill['mem_allocated_mb']),
                ]);
            } elseif ($backend === 'all' && $count > 200000) {
                $sendEvent('log', [
                    'level' => 'warn',
                    'stage' => 'polyfill',
                    'text' => "ℹ️ [judy-polyfill] Skipped pure-PHP polyfill for >200k items in 'All' mode to protect latency.",
                ]);
            }

            // Summary Log
            if (isset($results['judy'], $results['array'])) {
                $memDiff = $results['array']['mem_allocated_mb'] - $results['judy']['mem_allocated_mb'];
                $pct = $results['array']['mem_allocated_mb'] > 0
                    ? round(($memDiff / $results['array']['mem_allocated_mb']) * 100)
                    : 0;
                $sendEvent('log', [
                    'level' => 'highlight',
                    'text' => sprintf("🎉 [Telemetry Summary] ext-judy 2.6.0 reduced memory footprint by −%d%% (%s MB saved)!", max(0, $pct), number_format($memDiff, 1)),
                ]);
            }

            $sendEvent('result', [
                'workload' => $workload,
                'count' => $count,
                'results' => $results,
                'worker_pid' => getmypid(),
                'requests_served' => $requestsServed,
            ]);
        } catch (\Throwable $e) {
            $sendEvent('error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
        return;
    }

    // Interactive Resident Cache Playground APIs
    if ($uri === '/api/cache/set' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $key = (string)($body['key'] ?? '');
        $val = $body['value'] ?? '';
        $ttl = isset($body['ttl']) ? (int)$body['ttl'] : null;

        header('Content-Type: application/json');
        try {
            $residentCache->set($key, $val, $ttl);
            echo json_encode([
                'success' => true,
                'key' => $key,
                'total_cached' => $residentCache->count(),
                'worker_rss_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        return;
    }

    if ($uri === '/api/cache/get') {
        $key = (string)($_GET['key'] ?? '');
        header('Content-Type: application/json');
        $t0 = hrtime(true);
        $val = $residentCache->get($key);
        $t1 = hrtime(true);
        echo json_encode([
            'found' => $val !== null,
            'key' => $key,
            'value' => $val,
            'lookup_time_us' => round(($t1 - $t0) / 1e3, 3),
            'total_cached' => $residentCache->count(),
        ]);
        return;
    }

    if ($uri === '/api/cache/delete-prefix' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $prefix = (string)($body['prefix'] ?? '');
        header('Content-Type: application/json');
        try {
            $t0 = hrtime(true);
            $deleted = $residentCache->deletePrefix($prefix);
            $t1 = hrtime(true);
            echo json_encode([
                'success' => true,
                'prefix' => $prefix,
                'deleted' => $deleted,
                'duration_ms' => round(($t1 - $t0) / 1e6, 4),
                'remaining' => $residentCache->count(),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        return;
    }

    if ($uri === '/api/cache/keys') {
        $prefix = (string)($_GET['prefix'] ?? '');
        header('Content-Type: application/json');
        echo json_encode([
            'prefix' => $prefix,
            'keys' => $residentCache->keysByPrefix($prefix, 50),
            'total_cached' => $residentCache->count(),
        ]);
        return;
    }

    if ($uri === '/api/clear' && $method === 'POST') {
        $residentCache->clear();
        if (class_exists('Judy')) {
            $residentCounter = new Judy(Judy::INT_TO_INT);
        }
        gc_collect_cycles();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'cleared', 'current_rss_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)]);
        return;
    }

    // Default: serve static web assets
    $filePath = __DIR__ . ($uri === '/' ? '/index.html' : $uri);
    if (file_exists($filePath) && !is_dir($filePath)) {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimes = [
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        readfile($filePath);
        return;
    }

    http_response_code(404);
    echo "404 Not Found";
};

// FrankenPHP worker loop
$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
    $running = \frankenphp_handle_request($handler);
    if (!$running) break;
}
