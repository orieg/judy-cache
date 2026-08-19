<?php
/**
 * Benchmark: judy-cache (trie/hash/adaptive backends) vs plain-array cache,
 * Symfony ArrayAdapter, Symfony TagAwareAdapter, and APCu (when loaded).
 *
 * Scenario per backend, in a fresh child process per (backend, size, run):
 *   - store N entries with structured keys user.<uid>.item.<i> (10 per uid)
 *   - read all N back
 *   - invalidate ONE uid's group of 10 entries:
 *       judy*    -> deletePrefix('user.<uid>.')      (range walk)
 *       tagaware -> invalidateTags(['user<uid>'])    (tag bookkeeping)
 *       apcu     -> APCuIterator regex + apcu_delete (scan)
 *       array/symfony -> key scan                    (scan)
 *   - report peak RSS
 *
 * Runs R times per cell; reports median [min..max]. Numbers are only
 * meaningful from an idle machine or CI runner.
 *
 * Run: php bench/cache-bench.php [sizes=50000,200000] [runs=3]
 */

$sizesArg = $argv[1] ?? '50000,200000';
$runs     = (int) ($argv[2] ?? 3);
$mode     = $argv[3] ?? null;
$n        = (int) ($argv[4] ?? 0);

const KEY_FMT = 'user.%d.item.%d';

function backends(): array
{
    $b = ['array', 'symfony', 'tagaware', 'judy', 'judy-hash', 'judy-adaptive'];
    if (\extension_loaded('apcu') && \ini_get('apc.enable_cli')) {
        $b[] = 'apcu';
    }
    return $b;
}

if ($mode !== null) {
    require __DIR__ . '/../vendor/autoload.php';
    $key = fn(int $i) => sprintf(KEY_FMT, intdiv($i, 10), $i % 10);
    $value = fn(int $i) => ['id' => $i, 'score' => $i * 3];
    $targetUid = intdiv($n, 20);

    $set = null; $get = null; $invalidate = null;
    switch ($mode) {
        case 'judy':
        case 'judy-hash':
        case 'judy-adaptive':
            $type = match ($mode) {
                'judy' => \Judy::STRING_TO_MIXED,
                'judy-hash' => \Judy::STRING_TO_MIXED_HASH,
                'judy-adaptive' => \Judy::STRING_TO_MIXED_ADAPTIVE,
            };
            $c = new \Orieg\JudyCache\JudySimpleCache(backend: $type);
            $set = fn($k, $v) => $c->set($k, $v);
            $get = fn($k) => $c->get($k);
            $invalidate = fn() => $c->deletePrefix("user.$targetUid.");
            break;
        case 'array':
            $c = [];
            $set = function ($k, $v) use (&$c) { $c[$k] = serialize($v); };
            $get = function ($k) use (&$c) { return isset($c[$k]) ? unserialize($c[$k]) : null; };
            $invalidate = function () use (&$c, $targetUid) {
                $d = 0;
                foreach (array_keys($c) as $k) {
                    if (str_starts_with($k, "user.$targetUid.")) { unset($c[$k]); $d++; }
                }
                return $d;
            };
            break;
        case 'symfony':
            $c = new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: true);
            $set = function ($k, $v) use ($c) { $i = $c->getItem($k); $i->set($v); $c->save($i); };
            $get = fn($k) => $c->getItem($k)->get();
            $invalidate = function () use ($c, $targetUid) {
                $d = 0;
                foreach ($c->getValues() as $k => $_) {
                    if (str_starts_with($k, "user.$targetUid.")) { $c->deleteItem($k); $d++; }
                }
                return $d;
            };
            break;
        case 'tagaware':
            $c = new \Symfony\Component\Cache\Adapter\TagAwareAdapter(
                new \Symfony\Component\Cache\Adapter\ArrayAdapter(storeSerialized: true)
            );
            $set = function ($k, $v) use ($c) {
                $i = $c->getItem($k);
                $i->set($v);
                // tag = the invalidation group (one uid)
                $i->tag('user' . explode('.', $k)[1]);
                $c->save($i);
            };
            $get = fn($k) => $c->getItem($k)->get();
            $invalidate = function () use ($c, $targetUid) {
                $c->invalidateTags(["user$targetUid"]);
                return 10;
            };
            break;
        case 'apcu':
            $set = fn($k, $v) => apcu_store($k, serialize($v));
            $get = function ($k) { $r = apcu_fetch($k, $ok); return $ok ? unserialize($r) : null; };
            $invalidate = function () use ($targetUid) {
                $it = new \APCuIterator('/^user\.' . $targetUid . '\./');
                return apcu_delete($it);
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

    $t0 = hrtime(true);
    $deleted = $invalidate();
    $tInv = hrtime(true) - $t0;

    $peak = getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
    echo json_encode([
        // The child reports the backend it ACTUALLY ran under, and the parent
        // refuses to publish a table whose children disagree with it. A child
        // does not inherit the parent's `-d extension=`, so before this was
        // asserted a run driven as `php -d extension=…/judy.so bench/…` would
        // print "ext-judy 2.6.0" in the header while every measured child
        // silently used the pure-PHP polyfill.
        'ext' => \extension_loaded('judy') ? \judy_version() : 'polyfill',
        'peak_rss' => $peak,
        'set_ops_s' => (int) ($n / ($tSet / 1e9)),
        'get_ops_s' => (int) ($n / ($tGet / 1e9)),
        'invalidate_us' => (int) ($tInv / 1e3),
        'deleted' => $deleted,
        'checksum' => $sink,
    ]), "\n";
    exit(0);
}

/* ── Parent: sweep sizes x backends x runs, report median [min..max] ── */

$sizes = array_map('intval', explode(',', $sizesArg));
$ini = '';
if (extension_loaded('apcu')) {
    $ini = '-d apc.enable_cli=1 -d apc.shm_size=512M';
}

/* A child process does not inherit the parent's `-d extension=…`. When the
   extension comes from php.ini (CI installs it with PIE) that is invisible,
   but comparing two ext-judy builds means loading each by path — and then
   every child would silently fall back to the polyfill while the header
   claimed the extension. Set JUDY_EXT_SO to the .so being measured and it is
   forwarded to the children; the version assertion below is what actually
   guarantees it worked. */
$extSo = getenv('JUDY_EXT_SO') ?: '';
if ($extSo !== '') {
    if (!is_file($extSo)) {
        fwrite(STDERR, "JUDY_EXT_SO does not exist: $extSo\n");
        exit(1);
    }
    $ini .= ' -d extension=' . escapeshellarg($extSo);
}
$self = escapeshellarg(__FILE__);

function med(array $xs): float
{
    sort($xs);
    $c = count($xs);
    return $c % 2 ? $xs[intdiv($c, 2)] : ($xs[$c / 2 - 1] + $xs[$c / 2]) / 2;
}

echo "judy-cache benchmark — keys user.<uid>.item.<i>, $runs runs per cell, median [min..max]\n";
echo "PHP " . PHP_VERSION . ", ext-judy " . (extension_loaded('judy') ? judy_version() : 'ABSENT (polyfill)') . ", apcu " . (extension_loaded('apcu') ? 'yes' : 'no') . "\n";

$childExts = [];

foreach ($sizes as $size) {
    echo "\n### n=" . number_format($size) . " entries (invalidate one 10-key group)\n\n";
    printf("| %-13s | %-14s | %-13s | %-13s | %-22s |\n", 'backend', 'peak RSS (MB)', 'set kops/s', 'get kops/s', 'group-invalidate (µs)');
    echo "|---------------|----------------|---------------|---------------|------------------------|\n";

    /* ARMS ARE INTERLEAVED: run r of every backend, then run r+1 of every
       backend. Draining one backend's runs before starting the next makes the
       comparison hostage to anything that drifts over the sweep — a thermal
       ramp, another job starting, the page cache warming — because each
       backend then occupies its own contiguous slice of wall-clock and any
       drift is charged entirely to whichever arm held that slice. Interleaving
       spreads a drift across all arms instead of biasing one. */
    $samples = [];
    $failed = [];
    for ($r = 0; $r < $runs; $r++) {
        foreach (backends() as $impl) {
            if (isset($failed[$impl])) {
                continue;
            }
            $out = shell_exec(PHP_BINARY . " $ini $self _ _ $impl $size 2>/dev/null") ?? '';
            $j = json_decode(trim($out), true);
            if (!is_array($j)) {
                $failed[$impl] = true;
                continue;
            }
            $childExts[$j['ext'] ?? 'unreported'] = true;
            $samples[$impl]['rss'][]  = $j['peak_rss'] / 1048576;
            $samples[$impl]['set'][]  = $j['set_ops_s'] / 1000;
            $samples[$impl]['get'][]  = $j['get_ops_s'] / 1000;
            $samples[$impl]['inv'][]  = $j['invalidate_us'];
        }
    }

    foreach (backends() as $impl) {
        if (isset($failed[$impl]) || !isset($samples[$impl])) {
            printf("| %-13s | %-71s |\n", $impl, 'FAILED (composer install? extension?)');
            continue;
        }
        ['rss' => $rss, 'set' => $setr, 'get' => $getr, 'inv' => $inv] = $samples[$impl];
        printf("| %-13s | %6.1f [%.1f..%.1f] | %6.0f [%d..%d] | %6.0f [%d..%d] | %8.0f [%d..%d] |\n",
            $impl,
            med($rss), min($rss), max($rss),
            med($setr), (int) min($setr), (int) max($setr),
            med($getr), (int) min($getr), (int) max($getr),
            med($inv), (int) min($inv), (int) max($inv));
    }
}

/* The header above describes the PARENT. These numbers came from children.
   Refuse to publish a table where those are not the same thing. */
$parentExt = extension_loaded('judy') ? judy_version() : 'polyfill';
$seen = array_keys($childExts);
if ($seen !== [] && $seen !== [$parentExt]) {
    fwrite(STDERR, "\nBACKEND MISMATCH — results discarded.\n"
        . "  header/parent: $parentExt\n"
        . "  children ran:  " . implode(', ', $seen) . "\n"
        . "A child does not inherit `-d extension=`; pass JUDY_EXT_SO=/path/to/judy.so\n"
        . "so the parent forwards it, or install the extension into php.ini.\n");
    exit(1);
}

echo "\nInvalidation semantics per backend: judy* = deletePrefix (range walk);\n";
echo "tagaware = invalidateTags (per-entry tag bookkeeping); apcu = APCuIterator\n";
echo "regex scan; array/symfony = full key scan. APCu peak RSS includes its\n";
echo "shared-memory segment as mapped pages; treat its memory column as approximate.\n";
