<?php

declare(strict_types=1);

// Prevent memory limits or timeouts during heavy benchmarks
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
 * Benchmark runner for different backends and workloads
 */
function executeBenchmark(string $backend, string $workload, int $count, array $params = []): array
{
    // Force garbage collection before measurement
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
                // Native PHP Array Cache
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
                // Prefix Invalidation on tenant.1.*
                $tPrefix0 = hrtime(true);
                $deletedCount = $cache->deletePrefix("tenant.1.");
                $tPrefix1 = hrtime(true);
                
                $metrics['populate_ms'] = round(($tPopulate - $t0) / 1e6, 2);
                $metrics['prefix_invalidation_ms'] = round(($tPrefix1 - $tPrefix0) / 1e6, 4);
                $metrics['deleted_keys'] = $deletedCount;
                $metrics['remaining_keys'] = $cache->count();
                $metrics['algo_complexity'] = 'O(range) Sub-trie leaf splice';
            } else {
                // Array full-scan simulation
                $arrayCache = [];
                for ($t = 1; $t <= $tenants; $t++) {
                    for ($k = 1; $k <= $keysPerTenant; $k++) {
                        $arrayCache["tenant.{$t}.order.{$k}"] = $k;
                    }
                }
                $tPopulate = hrtime(true);
                // Linear scan to match prefix
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
                $metrics['algo_complexity'] = 'O(N) Full hashtable keyspace scan';
            }
            break;

        case 'int_counter':
            // High-speed integer counters (e.g. rate-limiting, IP hits, metric counters)
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
            // Pure footprint test
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

    // CORS & Headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        return;
    }

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

    if ($uri === '/api/benchmark' && $method === 'POST') {
        header('Content-Type: application/json');
        try {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?? [];
            $count = max(1000, min(10000000, (int)($body['count'] ?? 100000)));
            $backend = $body['backend'] ?? 'all';
            $workload = $body['workload'] ?? 'memory_shootout';

            $results = [];

            if ($backend === 'all') {
                if (extension_loaded('judy')) {
                    $results['judy'] = executeBenchmark('judy', $workload, $count, $body);
                }
                // Array can run up to 10M with unconstrained memory_limit
                $results['array'] = executeBenchmark('array', $workload, $count, $body);
                // Polyfill is pure PHP; skip when > 200k in "all" mode to prevent 30s UI hang
                if ($count <= 200000) {
                    $results['polyfill'] = executeBenchmark('polyfill', $workload, $count, $body);
                }
            } else {
                $results[$backend] = executeBenchmark($backend, $workload, $count, $body);
            }

            echo json_encode([
                'workload' => $workload,
                'count' => $count,
                'results' => $results,
                'worker_pid' => getmypid(),
                'requests_served' => $requestsServed,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
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
