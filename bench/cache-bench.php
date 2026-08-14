<?php
/**
 * Benchmark: JudySimpleCache vs plain-array cache vs Symfony ArrayAdapter.
 *
 * Measures, per backend, in a separate child process each:
 *   - peak RSS holding N entries (structured keys, small array values)
 *   - set + get throughput (ops/s)
 *   - prefix invalidation of one "user" out of N/10 (Judy: range walk;
 *     others: full key scan) — the operation ArrayAdapter has no fast path for
 *
 * Honest-measurement notes:
 *   - Judy allocates outside PHP's memory manager; peak RSS via getrusage()
 *     is the only fair memory comparison, hence child processes.
 *   - Run on an idle machine or CI runner; co-resident load invalidates
 *     throughput numbers.
 *
 * Run: php bench/cache-bench.php [n]
 */

$n    = (int)($argv[1] ?? 200_000);
$mode = $argv[2] ?? null;

if ($mode !== null) {
    require __DIR__ . '/../vendor/autoload.php';
    $key = fn(int $i) => sprintf('user.%d.item.%d', intdiv($i, 10), $i % 10);
    $value = fn(int $i) => ['id' => $i, 'score' => $i * 3];

    $set = null; $get = null; $delPrefix = null;
    switch ($mode) {
        case 'judy':
            $c = new \Orieg\JudyCache\JudySimpleCache();
            $set = fn($k, $v) => $c->set($k, $v);
            $get = fn($k) => $c->get($k);
            $delPrefix = fn($p) => $c->deletePrefix($p);
            break;
        case 'array':
            $c = [];
            $set = function ($k, $v) use (&$c) { $c[$k] = serialize($v); };
            $get = function ($k) use (&$c) { return isset($c[$k]) ? unserialize($c[$k]) : null; };
            $delPrefix = function ($p) use (&$c) {
                $d = 0;
                foreach (array_keys($c) as $k) {         // full scan
                    if (str_starts_with($k, $p)) { unset($c[$k]); $d++; }
                }
                return $d;
            };
            break;
        case 'symfony':
            $c = new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: true);
            $set = function ($k, $v) use ($c) { $i = $c->getItem($k); $i->set($v); $c->save($i); };
            $get = fn($k) => $c->getItem($k)->get();
            $delPrefix = function ($p) use ($c) {
                $d = 0;
                foreach ($c->getValues() as $k => $_) {  // full scan
                    if (str_starts_with($k, $p)) { $c->deleteItem($k); $d++; }
                }
                return $d;
            };
            break;
        default:
            fwrite(STDERR, "unknown mode $mode\n");
            exit(1);
    }

    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $set($key($i), $value($i)); }
    $tSet = hrtime(true) - $t0;

    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $n; $i++) { $sink += $get($key($i))['id']; }
    $tGet = hrtime(true) - $t0;

    // Invalidate one user's 10 entries out of n/10 users.
    $t0 = hrtime(true);
    $deleted = $delPrefix('user.' . intdiv($n, 20) . '.');
    $tDel = hrtime(true) - $t0;

    $peak = getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
    echo json_encode([
        'peak_rss' => $peak,
        'set_ops_s' => (int) ($n / ($tSet / 1e9)),
        'get_ops_s' => (int) ($n / ($tGet / 1e9)),
        'prefix_delete_us' => (int) ($tDel / 1e3),
        'prefix_deleted' => $deleted,
        'checksum' => $sink,
    ]), "\n";
    exit(0);
}

// Parent: one child per backend.
echo "n=$n entries, keys user.<uid>.item.<i>, values ['id'=>..,'score'=>..]\n\n";
printf("%-8s %12s %12s %12s %18s\n", 'backend', 'peak RSS', 'set ops/s', 'get ops/s', 'prefix-del (µs)');
foreach (['array', 'symfony', 'judy'] as $impl) {
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . " $n $impl 2>/dev/null") ?? '';
    $r = json_decode(trim($out), true);
    if (!is_array($r)) {
        printf("%-8s %s\n", $impl, 'FAILED (is composer install done? symfony/cache present?)');
        continue;
    }
    printf("%-8s %9.1f MB %12s %12s %15s µs\n",
        $impl, $r['peak_rss'] / 1048576,
        number_format($r['set_ops_s']), number_format($r['get_ops_s']),
        number_format($r['prefix_delete_us']));
}
echo "\nPrefix-delete removes 10 keys: Judy walks only the matching range;\n";
echo "array/ArrayAdapter must scan all $n keys.\n";
