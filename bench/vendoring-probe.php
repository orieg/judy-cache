<?php
/**
 * vendoring-probe.php — does php-judy's bundled libJudy reach a cache workload?
 *
 * THE QUESTION
 * ------------
 * php-judy vendors a patched libJudy under `libjudy/` and compiles it in by
 * default. On php-judy's own micro-benchmarks that vendored tree beats an
 * unpatched libJudy by double digits. `bench/cache-bench.php` — this package's
 * headline benchmark — cannot see any of it, and that is not a defect in the
 * benchmark: its measured operations are dominated by `serialize()`,
 * `unserialize()` and PSR-16 wrapper work, so libJudy is a small minority of
 * each operation and a large relative gain there is a small end-to-end one.
 *
 * This script exists to answer the question that follows: is there ANY cache
 * configuration where the vendored library is visible above the noise floor,
 * and if so which? It is a diagnostic instrument, not a backend comparison —
 * `cache-bench.php` answers "which cache should I use", this answers "does the
 * extension's bundled library matter for the cache I already chose". They are
 * kept separate because the arms differ (builds of one extension, not
 * different cache implementations), the statistics differ (paired per-round
 * ratios against a claim floor, not medians of absolutes) and merging them
 * would turn one readable table into a backend x arm matrix.
 *
 * WHAT IS COMPARED
 * ----------------
 * Arms are BUILDS OF THE SAME php-judy SOURCE AT THE SAME VERSION, differing
 * only in `--with-judy`. Comparing two php-judy RELEASES instead would confound
 * the vendored library with every other change in the release, which is exactly
 * the mislabelling php-judy had to fix in its own CI. Pass each arm's `.so`:
 *
 *   --arm bundled=/builds/judy-C-1.so   (configure: --with-judy=bundled)
 *   --arm system=/builds/judy-B-1.so    (configure: --with-judy=/usr)
 *   --arm pristine=/builds/judy-P-1.so  (configure: --with-judy=<unpatched>)
 *
 * Repeat a name to register several independently linked builds of that arm;
 * they are rotated across rounds and the per-build spread is reported, so a
 * binary-layout artifact in one link cannot masquerade as a library effect.
 *
 * "system libJudy" is not one thing. Debian and Fedora ship 1.0.5 WITH the
 * Baskins `jp_1Index` fix; Alpine and Homebrew ship it pristine. A
 * bundled-vs-system delta is only interpretable next to `--provenance`, which
 * is recorded in the JSON verbatim.
 *
 * THE LADDER — why one number is never the answer
 * -----------------------------------------------
 * Every family below stores the same keys (`user.<uid>.item.<i>`, 10 per uid)
 * and differs only in how much non-Judy work surrounds each Judy call:
 *
 *   ser     PSR-16, storeSerialized: true, array value   (THE DEFAULT)
 *   raw     PSR-16, storeSerialized: false, array value  (no serialize at all)
 *   rawint  PSR-16, storeSerialized: false, int value    (smallest payload)
 *   jser    bare Judy, payload = serialize(array)        (mirror of `ser`)
 *   jarr    bare Judy, payload = the array zval          (mirror of `raw`)
 *   jint    bare Judy, payload = int                     (mirror of `rawint`)
 *   ctl     plain PHP array                              (executes no libJudy)
 *
 * The `j*` families are not "a Judy benchmark" bolted on for flavour. Each one
 * issues EXACTLY the Judy calls its PSR-16 counterpart issues and nothing else
 * — `get` is `isset($values[$k]); isset($expiries[$k]); $values[$k];` because
 * that is what `JudySimpleCache::get()` does through `live()` — so the ratio
 *
 *     judy_share = t(mirror) / t(PSR-16 row)
 *
 * is a measured decomposition of the operation, not an estimate. It turns the
 * result into arithmetic a reader can check: if libJudy gets X% faster and it
 * is judy_share of the operation, the end-to-end row should move about
 * judy_share x X%. The report prints predicted and measured side by side, and
 * a large disagreement is itself a finding.
 *
 * Two caveats on judy_share, both stated because they set the direction of the
 * error rather than merely acknowledging uncertainty:
 *
 *   - The mirror also pays the extension's own ArrayAccess dispatch, which is
 *     not libJudy. The share is therefore an UPPER bound, and so is every
 *     predicted end-to-end delta derived from it. A measured gain falling
 *     short of prediction is expected, not anomalous.
 *   - Mirror payloads are built before the timer starts. If they were not —
 *     if `jser` serialized inside its own timed loop — the mirror would pay
 *     the same serialize() its host pays, and judy_share would silently become
 *     "the fraction that is not PSR-16 wrapper overhead", a much larger and
 *     entirely wrong number.
 *
 * `ctl` executes not one instruction of libJudy, so any arm-to-arm difference
 * on it is runner movement. Its median re-centres every other row and its
 * scatter is this run's own measurement of the noise floor.
 *
 * MEASUREMENT DISCIPLINE (inherited from php-judy's scripts/bench-threearm.php
 * and bench-lib.php, reimplemented here so this package depends on nothing)
 * ---------------------------------------------------------------------------
 *  - ARMS ARE INTERLEAVED, never drained one after another. Round r runs every
 *    arm, and the arm order reverses on odd rounds (ABBA). Whatever the machine
 *    was doing during round r therefore hits both members of that round's pair.
 *  - ALL STATISTICS ARE PAIRED PER-ROUND RATIOS, so between-round drift divides
 *    out. The CI is a 95% percentile bootstrap of the median ratio.
 *  - A CELL CLAIMS A DIRECTION ONLY IF THE WHOLE CI CLEARS THE CLAIM FLOOR.
 *    A point estimate past the floor with a CI straddling it is null. Floors
 *    are per-residency, from php-judy's pooled controls: ~3% cache-resident,
 *    ~1.3% out-of-cache.
 *  - A DELTA NO LARGER THAN THE SPREAD BETWEEN BUILD PAIRS IS NOT A LIBRARY
 *    EFFECT, whatever its CI says.
 *  - HOST HYGIENE IS GATED. Load and foreign CPU are sampled at phase
 *    boundaries, when none of this driver's own children are running, so
 *    anything busy then is by construction somebody else's job. Over the
 *    threshold the run is marked contaminated and every verdict is suppressed.
 *  - EVERY CHILD PROVES WHICH .so IT LOADED. The extension is selected with
 *    PHP_INI_SCAN_DIR, not `-d extension=` (which is a silent no-op on an image
 *    whose conf.d already enables judy), and each child returns the judy object
 *    paths mapped into its own address space. Both arms report the same
 *    `judy_version()`, so the version string cannot tell them apart — only the
 *    mapped path can.
 *
 * USAGE
 * -----
 *   php bench/vendoring-probe.php \
 *       --arm bundled=/builds/judy-C-1.so --arm bundled=/builds/judy-C-2.so \
 *       --arm system=/builds/judy-B-1.so  --arm system=/builds/judy-B-2.so \
 *       --reference bundled \
 *       --rounds 7 --n 300000 --iterations 3 \
 *       --provenance "system=Debian 13, libjudy-dev 1.0.5-5.1, patch 04 applied" \
 *       --label linux-x86_64-cache-resident --out results.json
 *
 * The rebuild control is the same driver with two builds of ONE arm declared as
 * two arms — every cell must read null, and any that does not is measuring
 * binary layout rather than libJudy:
 *
 *   php bench/vendoring-probe.php --arm bundled=C1.so --arm ctlarm=C2.so ...
 *
 * Requires ext-judy in every arm; the polyfill cannot answer this question.
 */

declare(strict_types=1);

const VP_KEY_FMT = 'user.%d.item.%d';
const VP_SEED    = 20260819;

/** Families in measurement order. `mirror` names the bare-Judy counterpart. */
const VP_FAMILIES = [
    'ser'    => ['label' => 'PSR-16 storeSerialized:true, array value', 'mirror' => 'jser'],
    'raw'    => ['label' => 'PSR-16 storeSerialized:false, array value', 'mirror' => 'jarr'],
    'rawint' => ['label' => 'PSR-16 storeSerialized:false, int value', 'mirror' => 'jint'],
    'jser'   => ['label' => 'bare Judy, serialized-string payload', 'mirror' => null],
    'jarr'   => ['label' => 'bare Judy, array payload', 'mirror' => null],
    'jint'   => ['label' => 'bare Judy, int payload', 'mirror' => null],
    'ctl'    => ['label' => 'plain PHP array (executes no libJudy)', 'mirror' => null],
];

/** Ops in the order a family measures them; `read` ops are repeated. */
const VP_OPS = [
    'set'    => ['read' => false, 'label' => 'set (populate)'],
    'get'    => ['read' => true,  'label' => 'get, key order'],
    'getr'   => ['read' => true,  'label' => 'get, random order'],
    'hasr'   => ['read' => true,  'label' => 'has, random order'],
    'keys'   => ['read' => true,  'label' => 'keysByPrefix x G'],
    'delpfx' => ['read' => false, 'label' => 'deletePrefix x G'],
    'del'    => ['read' => false, 'label' => 'delete, remainder'],
];

// ═══════════════════════════════════════════════════════════════════════════
// CHILD
// ═══════════════════════════════════════════════════════════════════════════

/**
 * One measurement child: runs the requested families in a fresh process and
 * prints a JSON row map. Families are built, measured and freed one at a time
 * so peak RSS reflects the largest single family rather than their sum.
 */
function vp_child_main(array $opt): void
{
    require __DIR__ . '/../vendor/autoload.php';

    if (!\extension_loaded('judy')) {
        echo json_encode(['ok' => 0, 'why' => 'ext-judy not loaded in this child']), "\n";
        exit(1);
    }

    $n      = (int) $opt['n'];
    $iters  = max(1, (int) ($opt['iterations'] ?? 3));
    $fams   = explode(',', (string) $opt['families']);
    $groups = (int) ($opt['groups'] ?? 2000);   // prefix groups touched by keys/delpfx

    // Keys and payloads are materialised BEFORE any timer starts. sprintf() is
    // not part of a cache operation and charging it to every row would inflate
    // the denominator of judy_share, understating libJudy's real share.
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $keys[$i] = sprintf(VP_KEY_FMT, intdiv($i, 10), $i % 10);
    }
    mt_srand(VP_SEED);
    $order = range(0, $n - 1);
    shuffle($order);

    $groups = max(1, min($groups, intdiv($n, 10)));
    $prefixes = [];
    for ($g = 0; $g < $groups; $g++) {
        $prefixes[] = "user.$g.";
    }

    $rows = [];
    foreach ($fams as $fam) {
        if (!isset(VP_FAMILIES[$fam])) {
            continue;
        }
        foreach (vp_run_family($fam, $n, $iters, $keys, $order, $prefixes) as $op => $ms) {
            $rows["$fam.$op"] = round($ms, 5);
        }
        gc_collect_cycles();
    }

    // Which judy objects are actually mapped into THIS process. Both arms
    // report the same judy_version(), so this is the only thing that can tell
    // them apart — including the external libJudy.so a system-linked arm pulls
    // in, which is itself part of the arm's identity.
    $mapped = [];
    if (@is_readable('/proc/self/maps')) {
        preg_match_all('#/\S*[Jj]udy\S*\.so[.0-9]*#', (string) file_get_contents('/proc/self/maps'), $m);
        $mapped = array_values(array_unique($m[0]));
    }

    echo json_encode([
        'ok'        => 1,
        'ext'       => judy_version(),
        'mapped'    => $mapped,
        'inis'      => array_values(array_filter(
            array_map('trim', explode(',', (string) php_ini_scanned_files())),
            'strlen'
        )),
        'peak_rss'  => getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024),
        'rows'      => $rows,
    ]), "\n";
    exit(0);
}

/**
 * Measure one family end to end and return op => milliseconds.
 *
 * The bare-Judy families issue exactly the Judy calls their PSR-16 counterpart
 * issues, in the same order, and nothing else. `JudySimpleCache::get()` calls
 * `live()` (isset on values, then isset on expiries) and then reads values, so
 * the mirror is three Judy operations — not one. Getting that wrong would
 * silently understate Judy's share by a factor of three.
 *
 * With no TTL the wrapper still touches the expiry array on every set
 * (`unset($expiries[$key])`) and on every read, so the mirrors keep a second,
 * empty Judy and touch it identically.
 */
function vp_run_family(string $fam, int $n, int $iters, array $keys, array $order, array $prefixes): array
{
    $t = [];
    $bare = str_starts_with($fam, 'j');

    // Payload shape per family. A `j*` mirror stores what its PSR-16
    // counterpart ends up storing: `jser` holds serialized strings because
    // `ser` does, `jarr` holds the array zval because `raw` does.
    $mk = match ($fam) {
        'rawint', 'jint' => static fn(int $i) => $i * 3,
        'jser'           => static fn(int $i) => serialize(['id' => $i, 'score' => $i * 3]),
        default          => static fn(int $i) => ['id' => $i, 'score' => $i * 3],
    };

    if ($fam === 'ctl') {
        return vp_run_ctl($n, $iters, $keys, $order, $mk);
    }

    // Payloads are BUILT BEFORE THE TIMER, and this is the single most
    // important detail in the whole decomposition. `jser`'s payload is a
    // serialized string; if it were produced inside the timed loop, the mirror
    // would pay the same serialize() its host pays, judy_share would come out
    // as "the fraction that is not PSR-16 wrapper overhead" instead of "the
    // fraction that is libJudy", and every predicted end-to-end delta would be
    // inflated. The host families still serialize inside their own timers,
    // because that is genuinely what JudySimpleCache::set() does — they just
    // receive a caller value that already exists, as a real caller's would.
    $payload = [];
    for ($i = 0; $i < $n; $i++) {
        $payload[$i] = $mk($i);
    }

    // Declared before the branch: the timed closures below capture all three,
    // and capturing an undefined variable is a warning even when the $bare
    // guard means it is never dereferenced.
    $v = null;
    $e = null;
    $c = null;

    if ($bare) {
        $v = new \Judy(\Judy::STRING_TO_MIXED);
        $e = new \Judy(\Judy::STRING_TO_INT);
    } else {
        $c = new \Orieg\JudyCache\JudySimpleCache(
            storeSerialized: $fam === 'ser',
            backend: \Judy::STRING_TO_MIXED,
        );
    }

    // ── set ────────────────────────────────────────────────────────────────
    $t0 = hrtime(true);
    if ($bare) {
        for ($i = 0; $i < $n; $i++) {
            $k = $keys[$i];
            $v[$k] = $payload[$i];
            unset($e[$k]);
        }
    } else {
        for ($i = 0; $i < $n; $i++) {
            $c->set($keys[$i], $payload[$i]);
        }
    }
    $t['set'] = (hrtime(true) - $t0) / 1e6;

    // The store now holds its own references, so dropping the pool only
    // decrements refcounts. It is released before the read rows so the
    // measured process is not carrying a second copy of the working set
    // through them.
    unset($payload);

    // ── get, key order ─────────────────────────────────────────────────────
    $t['get'] = vp_best($iters, static function () use ($bare, $n, $keys, &$v, &$e, $c) {
        $sink = 0;
        if ($bare) {
            for ($i = 0; $i < $n; $i++) {
                $k = $keys[$i];
                if (isset($v[$k]) && !isset($e[$k])) {
                    $x = $v[$k];
                    $sink += is_int($x) ? 1 : 1;
                }
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                $sink += $c->get($keys[$i]) === null ? 0 : 1;
            }
        }
        return $sink;
    });

    // ── get, random order ──────────────────────────────────────────────────
    $t['getr'] = vp_best($iters, static function () use ($bare, $n, $keys, $order, &$v, &$e, $c) {
        $sink = 0;
        if ($bare) {
            for ($i = 0; $i < $n; $i++) {
                $k = $keys[$order[$i]];
                if (isset($v[$k]) && !isset($e[$k])) {
                    $x = $v[$k];
                    $sink += is_int($x) ? 1 : 1;
                }
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                $sink += $c->get($keys[$order[$i]]) === null ? 0 : 1;
            }
        }
        return $sink;
    });

    // ── has, random order (no serialization on either side of the wrapper) ─
    $t['hasr'] = vp_best($iters, static function () use ($bare, $n, $keys, $order, &$v, &$e, $c) {
        $sink = 0;
        if ($bare) {
            for ($i = 0; $i < $n; $i++) {
                $k = $keys[$order[$i]];
                if (isset($v[$k]) && !isset($e[$k])) {
                    $sink++;
                }
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                $sink += $c->has($keys[$order[$i]]) ? 1 : 0;
            }
        }
        return $sink;
    });

    // ── keysByPrefix over G groups (ordered range walk, no serialization) ──
    $t['keys'] = vp_best($iters, static function () use ($bare, $prefixes, &$v, &$e, $c) {
        $sink = 0;
        foreach ($prefixes as $p) {
            if ($bare) {
                for ($k = $v->first($p); $k !== null && str_starts_with($k, $p); $k = $v->searchNext($k)) {
                    if (isset($v[$k]) && !isset($e[$k])) {
                        $sink++;
                    }
                }
            } else {
                $sink += count($c->keysByPrefix($p));
            }
        }
        return $sink;
    });

    // ── deletePrefix over the same G groups ────────────────────────────────
    // One group is ~10 entries and far too short to time; G groups in one
    // timed loop is the same operation at a measurable duration.
    $t0 = hrtime(true);
    $del = 0;
    foreach ($prefixes as $p) {
        if ($bare) {
            for ($k = $v->first($p); $k !== null && str_starts_with($k, $p); $k = $v->searchNext($k)) {
                unset($v[$k], $e[$k]);
                $del++;
            }
        } else {
            $del += $c->deletePrefix($p);
        }
    }
    $t['delpfx'] = (hrtime(true) - $t0) / 1e6;

    // ── delete the remainder, one key at a time ────────────────────────────
    $from = count($prefixes) * 10;
    $t0 = hrtime(true);
    if ($bare) {
        for ($i = $from; $i < $n; $i++) {
            $k = $keys[$i];
            unset($v[$k], $e[$k]);
        }
    } else {
        for ($i = $from; $i < $n; $i++) {
            $c->delete($keys[$i]);
        }
    }
    $t['del'] = (hrtime(true) - $t0) / 1e6;

    if ($bare) {
        $v->free();
        $e->free();
        unset($v, $e);
    } else {
        $c->clear();
        unset($c);
    }
    return $t;
}

/** The PHP-array control: same keys, same payloads, zero libJudy. */
function vp_run_ctl(int $n, int $iters, array $keys, array $order, callable $mk): array
{
    $t = [];
    $a = [];

    $payload = [];
    for ($i = 0; $i < $n; $i++) {
        $payload[$i] = $mk($i);
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $a[$keys[$i]] = serialize($payload[$i]);
    }
    $t['set'] = (hrtime(true) - $t0) / 1e6;
    unset($payload);

    $t['get'] = vp_best($iters, static function () use ($n, $keys, &$a) {
        $sink = 0;
        for ($i = 0; $i < $n; $i++) {
            $k = $keys[$i];
            if (isset($a[$k])) {
                $x = unserialize($a[$k]);
                $sink += is_array($x) ? 1 : 1;
            }
        }
        return $sink;
    });

    $t['getr'] = vp_best($iters, static function () use ($n, $keys, $order, &$a) {
        $sink = 0;
        for ($i = 0; $i < $n; $i++) {
            $k = $keys[$order[$i]];
            if (isset($a[$k])) {
                $x = unserialize($a[$k]);
                $sink += is_array($x) ? 1 : 1;
            }
        }
        return $sink;
    });

    $t['hasr'] = vp_best($iters, static function () use ($n, $keys, $order, &$a) {
        $sink = 0;
        for ($i = 0; $i < $n; $i++) {
            if (isset($a[$keys[$order[$i]]])) {
                $sink++;
            }
        }
        return $sink;
    });

    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        unset($a[$keys[$i]]);
    }
    $t['del'] = (hrtime(true) - $t0) / 1e6;

    return $t;
}

/**
 * Median of `$iters` timings of a read-only closure, in milliseconds.
 *
 * The median rather than the minimum: the minimum is the luckiest sample and
 * systematically under-reports variance, which matters when the whole run is a
 * ratio of two such numbers.
 */
function vp_best(int $iters, callable $fn): float
{
    $ms = [];
    for ($i = 0; $i < $iters; $i++) {
        $t0 = hrtime(true);
        $fn();
        $ms[] = (hrtime(true) - $t0) / 1e6;
    }
    return vp_median($ms);
}

// ═══════════════════════════════════════════════════════════════════════════
// STATISTICS
// ═══════════════════════════════════════════════════════════════════════════

function vp_median(array $xs): float
{
    sort($xs);
    $c = count($xs);
    if ($c === 0) {
        return 0.0;
    }
    return $c % 2 ? (float) $xs[intdiv($c, 2)] : ((float) $xs[$c / 2 - 1] + (float) $xs[$c / 2]) / 2;
}

/** 95% percentile-bootstrap CI for the median. Deterministic seed. */
function vp_median_ci(array $xs, int $resamples = 4000): array
{
    $n = count($xs);
    if ($n === 0) {
        return [0.0, 0.0];
    }
    if ($n < 3) {
        return [(float) min($xs), (float) max($xs)];
    }
    mt_srand(VP_SEED);
    $meds = [];
    for ($b = 0; $b < $resamples; $b++) {
        $s = [];
        for ($i = 0; $i < $n; $i++) {
            $s[] = $xs[mt_rand(0, $n - 1)];
        }
        $meds[] = vp_median($s);
    }
    sort($meds);
    return [$meds[(int) floor(0.025 * $resamples)], $meds[(int) ceil(0.975 * $resamples) - 1]];
}

/**
 * Verdict for a paired-ratio series against a per-residency claim floor.
 *
 * A cell may only claim a direction when the WHOLE confidence interval clears
 * the floor. A point estimate past the floor with a CI that straddles it is
 * "null" — inside demonstrated noise — not a small win.
 */
function vp_verdict(array $ratios, float $floor_pct): array
{
    if (count($ratios) < 3) {
        return ['status' => 'null', 'reason' => 'too few paired rounds'];
    }
    $ratio = vp_median($ratios);
    $ci    = vp_median_ci($ratios);
    $lo    = 1.0 - $floor_pct / 100.0;
    $hi    = 1.0 + $floor_pct / 100.0;

    if ($ci[1] < $lo) {
        $status = 'FASTER';
        $reason = null;
    } elseif ($ci[0] > $hi) {
        $status = 'SLOWER';
        $reason = null;
    } else {
        $status = 'null';
        $reason = sprintf('inside the %.1f%% claim floor (CI straddles it)', $floor_pct);
    }
    return [
        'status'       => $status,
        'reason'       => $reason,
        'ratio'        => round($ratio, 5),
        'delta_pct'    => round(($ratio - 1.0) * 100.0, 2),
        'ci_delta_pct' => [round(($ci[0] - 1.0) * 100.0, 2), round(($ci[1] - 1.0) * 100.0, 2)],
        'rounds'       => count($ratios),
    ];
}

/**
 * Per-build breakdown of a paired-ratio series.
 *
 * Round r used reference build r % nRef against comparison build r % nCmp.
 * Grouping the ratios by that pair shows whether the effect survives every
 * pairing or lives in one link.
 */
function vp_per_build(array $ratios, int $n_ref, int $n_cmp): array
{
    $by = [];
    foreach ($ratios as $r => $ratio) {
        $by[($r % $n_ref) . 'x' . ($r % $n_cmp)][] = $ratio;
    }
    $meds = [];
    foreach ($by as $k => $vals) {
        $meds[$k] = round((vp_median($vals) - 1.0) * 100.0, 2);
    }
    $vals = array_values($meds);
    return [
        'per_build_delta_pct' => $meds,
        'build_spread_pct'    => count($vals) > 1 ? round(max($vals) - min($vals), 2) : 0.0,
        'builds'              => count($meds),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// HOST HYGIENE
// ═══════════════════════════════════════════════════════════════════════════

function vp_cpu_count(): int
{
    static $n = null;
    if ($n !== null) {
        return $n;
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        return $n = max(1, (int) trim((string) @shell_exec('sysctl -n hw.ncpu 2>/dev/null')));
    }
    // A container image without `nproc` must not fall back to 1: that drops the
    // threshold to 0.5 and condemns a perfectly idle 24-core host.
    if (is_readable('/proc/cpuinfo')) {
        $c = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
        if ($c > 0) {
            return $n = $c;
        }
    }
    if (is_readable('/proc/self/status')
        && preg_match('/^Cpus_allowed_list:\s*(\S+)/m', (string) @file_get_contents('/proc/self/status'), $m)) {
        $w = 0;
        foreach (explode(',', $m[1]) as $part) {
            if (str_contains($part, '-')) {
                [$a, $b] = array_map('intval', explode('-', $part, 2));
                $w += max(0, $b - $a + 1);
            } elseif ($part !== '') {
                $w++;
            }
        }
        if ($w > 0) {
            return $n = $w;
        }
    }
    $c = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
    // Unknown rather than 1: refuse to invent a threshold.
    return $n = $c > 0 ? $c : 0;
}

/**
 * Load and foreign-CPU snapshot, taken at a phase boundary.
 *
 * Load average alone is NOT sufficient on a wide box: two memory-bound
 * benchmarks contend for last-level cache and memory bandwidth at a load that
 * clears any per-core threshold, and a PHP-array control does not notice
 * because array operations are neither pointer-chasing nor DRAM-bound. Taking
 * the sample when none of this driver's own children are running makes any
 * process above the floor a foreign tenant by construction.
 */
function vp_load_snapshot(string $phase): array
{
    $la    = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $load1 = $la === false ? null : round((float) $la[0], 2);

    $top  = [];
    $self = getmypid();
    $raw  = PHP_OS_FAMILY === 'Darwin'
        ? (string) shell_exec('ps -A -o pid,pcpu,rss,comm -r 2>/dev/null | head -12')
        : (string) shell_exec('ps -A -o pid,pcpu,rss,comm --sort=-pcpu 2>/dev/null | head -12');

    foreach (array_slice(array_filter(explode("\n", trim($raw))), 1) as $line) {
        $parts = preg_split('/\s+/', trim($line), 4);
        if (count($parts) !== 4 || (int) $parts[0] === $self) {
            continue;
        }
        if ((float) $parts[1] > 5.0) {
            $top[] = ['pid' => (int) $parts[0], 'cpu_pct' => (float) $parts[1], 'cmd' => $parts[3]];
        }
    }
    $foreign = array_sum(array_column($top, 'cpu_pct'));
    $cpus    = vp_cpu_count();

    return [
        'phase'           => $phase,
        'at'              => date('Y-m-d\TH:i:sP'),
        'load1'           => $load1,
        'cpus'            => $cpus ?: null,
        'threshold'       => $cpus ? $cpus / 2 : null,
        'over'            => $cpus > 0 && $load1 !== null && $load1 > $cpus / 2,
        'cpus_known'      => $cpus > 0,
        // Half a core of foreign work is enough to move a DRAM-bound cell.
        'foreign_cpu_pct' => round($foreign, 1),
        'foreign_busy'    => $foreign > 50.0,
        'heavy'           => $top,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ARM REGISTRATION
// ═══════════════════════════════════════════════════════════════════════════

$vp_tmp = sys_get_temp_dir() . '/vp-' . getmypid();

register_shutdown_function(static function () use ($vp_tmp) {
    foreach (glob("$vp_tmp/*/*") ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob("$vp_tmp/*") ?: [] as $d) {
        @is_dir($d) ? @rmdir($d) : @unlink($d);
    }
    @rmdir($vp_tmp);
});

/**
 * Judy objects mapped that are neither the requested extension nor a shared
 * libJudy. A system-linked arm legitimately maps /usr/lib/.../libJudy.so.1;
 * anything else is a second extension copy winning the load, which is the
 * failure this whole registration dance exists to prevent.
 *
 * @param list<string> $mapped
 * @return list<string>
 */
function vp_foreign_objects(array $mapped, string $want): array
{
    return array_values(array_filter($mapped, static fn(string $p) =>
        $p !== $want && !preg_match('#/libJudy[^/]*$#i', $p)));
}

/**
 * Command prefix that loads exactly one judy build.
 *
 * PHP_INI_SCAN_DIR, not `-d extension=`: on an image whose conf.d already
 * enables judy — php-judy's own bench image does — `-d extension=` is a silent
 * no-op ("Module already loaded") and the run measures the pre-installed copy
 * while reporting the path that was passed.
 */
function vp_php(string $dir): string
{
    // display_errors=stderr is load-bearing, not tidiness: the CLI SAPI writes
    // diagnostics to STDOUT by default, so a single deprecation or warning
    // (JudySimpleCache emits one on ext-judy < 2.6.0) lands in front of the
    // child's JSON and the parent reads an unparseable document. Keeping stdout
    // pure JSON and stderr separately captured means a warning is reported
    // rather than silently turned into "child failed".
    return 'PHP_INI_SCAN_DIR=' . escapeshellarg($dir) . ' '
        . escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 -d display_errors=stderr ';
}

// ═══════════════════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════════════════

$opts = getopt('', [
    'child', 'arm:', 'reference:', 'rounds:', 'n:', 'iterations:', 'groups:',
    'families:', 'floor:', 'dram-n:', 'label:', 'provenance:', 'out:', 'csv:', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, "See the docblock at the top of " . __FILE__ . "\n");
    exit(0);
}

if (isset($opts['child'])) {
    vp_child_main($opts + ['families' => implode(',', array_keys(VP_FAMILIES)), 'n' => 300000]);
    exit(0);
}

/** @return list<string> */
function vp_multi(array $opts, string $key): array
{
    $v = $opts[$key] ?? [];
    return is_array($v) ? $v : [$v];
}

$arms = [];
foreach (vp_multi($opts, 'arm') as $spec) {
    if (!str_contains($spec, '=')) {
        fwrite(STDERR, "--arm needs NAME=/path/to/judy.so, got: $spec\n");
        exit(2);
    }
    [$name, $so] = explode('=', $spec, 2);
    $real = realpath($so);
    if ($real === false) {
        fwrite(STDERR, "--arm $name: no such file: $so\n");
        exit(2);
    }
    $arms[$name][] = $real;
}
if (count($arms) < 2) {
    fwrite(STDERR, "need at least two --arm NAME=path arms (see --help)\n");
    exit(2);
}

$provenance = [];
foreach (vp_multi($opts, 'provenance') as $spec) {
    [$name, $text] = array_pad(explode('=', $spec, 2), 2, '');
    $provenance[$name] = $text;
}

$names     = array_keys($arms);
$reference = (string) ($opts['reference'] ?? (in_array('bundled', $names, true) ? 'bundled' : $names[0]));
if (!isset($arms[$reference])) {
    fwrite(STDERR, "--reference $reference is not one of: " . implode(', ', $names) . "\n");
    exit(2);
}

$rounds  = max(3, (int) ($opts['rounds'] ?? 7));
$n       = (int) ($opts['n'] ?? 300000);
$iters   = max(1, (int) ($opts['iterations'] ?? 3));
$groups  = (int) ($opts['groups'] ?? 2000);
$fams    = (string) ($opts['families'] ?? implode(',', array_keys(VP_FAMILIES)));
$dram_n  = (int) ($opts['dram-n'] ?? 2000000);
// Per-residency floors from php-judy's pooled controls: ~3% cache-resident,
// ~1.3% out-of-cache. Which one applies follows the working-set size.
$floor   = (float) ($opts['floor'] ?? ($n >= $dram_n ? 1.3 : 3.0));
$label   = (string) ($opts['label'] ?? 'unlabelled');

// ── register + verify every build ──────────────────────────────────────────
$builds = [];   // name => list of [so, iniDir]
$verify = [];
@mkdir($vp_tmp, 0700, true);
$bi = 0;
foreach ($arms as $name => $sos) {
    foreach ($sos as $so) {
        $dir = "$vp_tmp/ini$bi";
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/judy.ini", "extension=$so\n");
        $builds[$name][] = ['so' => $so, 'ini' => $dir];
        $bi++;
    }
}

foreach ($builds as $name => $list) {
    foreach ($list as $b) {
        $probe = "$vp_tmp/verify.php";
        file_put_contents($probe, <<<'PHP'
<?php
$paths = [];
if (@is_readable('/proc/self/maps')) {
    preg_match_all('#/\S*[Jj]udy\S*\.so[.0-9]*#', (string) file_get_contents('/proc/self/maps'), $m);
    $paths = array_values(array_unique($m[0]));
}
$main = php_ini_loaded_file();
echo json_encode([
    'loaded'  => (int) extension_loaded('judy'),
    'version' => extension_loaded('judy') ? judy_version() : null,
    'paths'   => $paths,
    'inis'    => array_values(array_filter(array_map('trim', explode(',', (string) php_ini_scanned_files())), 'strlen')),
    'main_loads_judy' => $main && preg_match('#^\s*(zend_)?extension\s*=.*judy#mi', (string) @file_get_contents($main)) ? 1 : 0,
]);
PHP);
        $err = "$vp_tmp/verify.err";
        $out = shell_exec(vp_php($b['ini']) . escapeshellarg($probe) . ' 2> ' . escapeshellarg($err));
        $stderr = (string) @file_get_contents($err);
        $j = json_decode((string) $out, true);

        if (stripos($stderr, 'already loaded') !== false) {
            fwrite(STDERR, "arm $name ({$b['so']}): 'already loaded' — extension selection is not under our control:\n$stderr\n");
            exit(1);
        }
        if (!is_array($j) || empty($j['loaded'])) {
            fwrite(STDERR, "arm $name ({$b['so']}): judy did not load. stdout=" . var_export($out, true) . "\n$stderr\n");
            exit(1);
        }
        if (!empty($j['main_loads_judy'])) {
            fwrite(STDERR, "arm $name: the main php.ini itself loads a judy extension; arms cannot be isolated\n");
            exit(1);
        }
        if (count($j['inis']) !== 1) {
            fwrite(STDERR, "arm $name: expected exactly one scanned ini, got: " . implode(', ', $j['inis']) . "\n");
            exit(1);
        }
        // The extension object mapped must be ours, and nothing else judy-ish
        // may be mapped except the shared libJudy a system-linked arm pulls in.
        //
        // Identity is decided by EQUALITY WITH THE REQUESTED PATH, not by a
        // name pattern. An earlier version filtered for a basename containing
        // "judy.so"; the builds are named judy-C-1.so, judy-B-1.so, … so the
        // filter matched nothing, the comparison had nothing to compare, and
        // the assertion silently passed for every arm. A check that cannot
        // fail is worse than no check, because it is reported as a check.
        if ($j['paths'] !== [] && !in_array($b['so'], $j['paths'], true)) {
            fwrite(STDERR, "arm $name: {$b['so']} is not among the mapped objects ("
                . implode(', ', $j['paths']) . ") — a pre-installed copy is winning\n");
            exit(1);
        }
        $foreign = vp_foreign_objects($j['paths'], $b['so']);
        if ($foreign) {
            fwrite(STDERR, "arm $name: unexpected judy objects mapped alongside {$b['so']}: "
                . implode(', ', $foreign) . "\n");
            exit(1);
        }
        $verify[$name][] = [
            'so'      => $b['so'],
            'version' => $j['version'],
            'mapped'  => $j['paths'],
            'sha256'  => hash_file('sha256', $b['so']),
            'size'    => filesize($b['so']),
        ];
    }
}

// Every arm must be the same php-judy version — otherwise the run is comparing
// releases, not libJudy, which is the exact confound this instrument exists to
// avoid.
$versions = [];
foreach ($verify as $name => $list) {
    foreach ($list as $vinfo) {
        $versions[$vinfo['version']][] = $name;
    }
}
if (count($versions) > 1) {
    fwrite(STDERR, "ARMS DISAGREE ON php-judy VERSION — this would confound the library with the release:\n");
    foreach ($versions as $ver => $who) {
        fwrite(STDERR, "  $ver: " . implode(', ', array_unique($who)) . "\n");
    }
    exit(1);
}
$extVersion = array_key_first($versions);

// ── run ────────────────────────────────────────────────────────────────────
$self  = escapeshellarg(__FILE__);
$loads = [vp_load_snapshot('before')];
$ms    = [];   // name => row => list<float> indexed by round
$meta  = [];   // name => list of child metadata

fwrite(STDERR, "vendoring-probe: {$rounds} rounds, n={$n}, arms: " . implode(', ', $names)
    . " (reference={$reference}), floor={$floor}%\n");

for ($r = 0; $r < $rounds; $r++) {
    // ABBA: reverse the arm order on odd rounds so no arm permanently occupies
    // the early or late slot of a round.
    $order = $r % 2 ? array_reverse($names) : $names;
    foreach ($order as $name) {
        $list = $builds[$name];
        $b    = $list[$r % count($list)];
        $cmd  = vp_php($b['ini']) . $self . ' --child'
            . ' --n ' . $n
            . ' --iterations ' . $iters
            . ' --groups ' . $groups
            . ' --families ' . escapeshellarg($fams);
        $cerr = "$vp_tmp/child.err";
        $out  = shell_exec($cmd . ' 2> ' . escapeshellarg($cerr));
        $cstderr = trim((string) @file_get_contents($cerr));
        $j    = json_decode(trim((string) $out), true);
        if (!is_array($j) || empty($j['ok'])) {
            fwrite(STDERR, "round $r arm $name: child failed.\n  stdout: " . var_export($out, true)
                . "\n  stderr: " . ($cstderr === '' ? '(empty)' : $cstderr) . "\n");
            exit(1);
        }
        // A child that ran but complained is not silently accepted: a warning
        // here means the measured process was not the one that was intended.
        if ($cstderr !== '') {
            fwrite(STDERR, "\nround $r arm $name: child wrote to stderr — discarding the run:\n$cstderr\n");
            exit(1);
        }
        // Assert per child, not once at registration: a child that silently
        // picked up a different judy would otherwise be invisible.
        if ($j['mapped'] !== [] && !in_array($b['so'], $j['mapped'], true)) {
            fwrite(STDERR, "round $r arm $name: child mapped " . implode(', ', $j['mapped'])
                . " but was given {$b['so']} — discarding the run\n");
            exit(1);
        }
        if ($foreign = vp_foreign_objects($j['mapped'], $b['so'])) {
            fwrite(STDERR, "round $r arm $name: child mapped unexpected judy objects "
                . implode(', ', $foreign) . " — discarding the run\n");
            exit(1);
        }
        if (($j['ext'] ?? null) !== $extVersion) {
            fwrite(STDERR, "round $r arm $name: child reports ext {$j['ext']}, expected $extVersion\n");
            exit(1);
        }
        foreach ($j['rows'] as $id => $val) {
            $ms[$name][$id][$r] = (float) $val;
        }
        $meta[$name][] = ['round' => $r, 'so' => $b['so'], 'peak_rss' => $j['peak_rss'], 'mapped' => $j['mapped']];
    }
    $loads[] = vp_load_snapshot("after-round-$r");
    fwrite(STDERR, '.');
}
fwrite(STDERR, "\n");

$hygiene_failed = false;
foreach ($loads as $l) {
    if ($l['over'] || $l['foreign_busy']) {
        $hygiene_failed = true;
    }
}

// ── The canary: does an arm's ABSOLUTE series hold still? ───────────────────
//
// Paired ratios divide out drift that hits both arms, and the PHP-array
// control catches runner movement — but a PHP array is neither pointer-chasing
// nor DRAM-bound, so it is STRUCTURALLY BLIND to a co-resident memory-bound
// tenant stealing last-level cache and bandwidth. That is not a hypothetical:
// on this project's own host two campaigns each passed a load < N/2 check and
// corrupted each other anyway, moving an untouched baseline arm by 2.2x while
// a PHP-array control read +0.36%.
//
// The detector that does work is watching an arm's own absolute numbers across
// rounds. They are measuring identical work every round, so they must hold
// still; a baseline that moves invalidates the comparison however clean the
// deltas look. `spread_pct` is (max-min)/min across rounds, matching the
// tolerance php-judy's tools/bench-stability.py gates on, and `drift_pct`
// compares the second half of the run to the first, which is the signature of
// a tenant arriving partway through.
$stability = [];
foreach ($ms as $name => $rows) {
    $spreads = [];
    $drifts  = [];
    foreach ($rows as $id => $series) {
        if (str_starts_with($id, 'ctl.')) {
            continue;   // the blind control is not the canary
        }
        ksort($series);
        $vals = array_values($series);
        if (count($vals) < 4 || min($vals) <= 0.0) {
            continue;
        }
        $spreads[$id] = (max($vals) - min($vals)) / min($vals) * 100.0;
        $half = intdiv(count($vals), 2);
        $a = vp_median(array_slice($vals, 0, $half));
        $b = vp_median(array_slice($vals, $half));
        $drifts[$id] = $a > 0.0 ? ($b / $a - 1.0) * 100.0 : 0.0;
    }
    arsort($spreads);
    $stability[$name] = [
        'median_spread_pct' => round(vp_median(array_values($spreads)), 2),
        'max_spread_pct'    => $spreads ? round(max($spreads), 2) : 0.0,
        'worst_row'         => $spreads ? array_key_first($spreads) : null,
        'median_drift_pct'  => round(vp_median(array_map('abs', array_values($drifts))), 2),
        'max_drift_pct'     => $drifts ? round(max(array_map('abs', $drifts)), 2) : 0.0,
        'rows'              => count($spreads),
    ];
}
// 15% is bench-stability.py's default tolerance, adopted rather than invented.
const VP_STABILITY_TOL_PCT = 15.0;
$baseline_unstable = false;
foreach ($stability as $st) {
    if ($st['median_spread_pct'] > VP_STABILITY_TOL_PCT) {
        $baseline_unstable = true;
    }
}

// ── analysis ───────────────────────────────────────────────────────────────
/** Paired per-round ratios of reference over comparison. */
$pairs = static function (array $num, array $den): array {
    $out = [];
    foreach ($num as $r => $x) {
        if (isset($den[$r]) && $den[$r] > 0.0) {
            $out[$r] = $x / $den[$r];
        }
    }
    ksort($out);
    return array_values($out);
};

$report = [];
foreach ($names as $name) {
    if ($name === $reference) {
        continue;
    }

    // The control's median is this run's own measurement of runner movement.
    // Only genuine PHP-array rows qualify: any row that touches Judy would
    // carry the very effect we are trying to divide out.
    $ctlDeltas = [];
    $ctlRows   = [];
    foreach ($ms[$reference] as $id => $series) {
        if (!str_starts_with($id, 'ctl.')) {
            continue;
        }
        $p = $pairs($series, $ms[$name][$id] ?? []);
        if (count($p) < 3) {
            continue;
        }
        $ctlRows[$id] = vp_verdict($p, $floor);
        $ctlDeltas[]  = vp_median($p);
    }
    // No control rows means no measurement of this run's own noise, and
    // silently substituting 1.0 would let a drifting runner pass as a library
    // effect. The run is marked instead of quietly degraded.
    $haveControl = $ctlDeltas !== [];
    $ctlMedian = $haveControl ? vp_median($ctlDeltas) : 1.0;
    $ctlSpread = $haveControl
        ? max(abs(max($ctlDeltas) - 1.0), abs(min($ctlDeltas) - 1.0)) * 100.0
        : 0.0;
    if (!$haveControl) {
        fwrite(STDERR, "WARNING: no `ctl` rows in this run — nothing re-centres the "
            . "ratios and the noise floor is assumed rather than measured. Add `ctl` "
            . "to --families.\n");
    }

    // If the control itself moves further than the claim floor, the floor is
    // too optimistic FOR THIS RUN and nothing may be claimed.
    $contaminated = $hygiene_failed || $baseline_unstable
        || abs($ctlMedian - 1.0) * 100.0 > $floor;

    $rows = [];
    foreach ($ms[$reference] as $id => $series) {
        if (str_starts_with($id, 'ctl.')) {
            continue;
        }
        $p = $pairs($series, $ms[$name][$id] ?? []);
        if (count($p) < 3) {
            continue;
        }
        $adj = array_map(static fn($x) => $x / $ctlMedian, $p);
        $v   = vp_verdict($adj, $floor);

        if ($contaminated && $v['status'] !== 'null') {
            $v['status'] = 'null';
            $v['reason'] = 'suppressed: run flagged contaminated';
        }
        $pb = vp_per_build($adj, count($builds[$reference]), count($builds[$name]));
        if ($v['status'] !== 'null' && $pb['builds'] > 1 && $pb['build_spread_pct'] > abs($v['delta_pct'])) {
            $v['status'] = 'null';
            $v['reason'] = sprintf(
                'per-build spread %.2f%% exceeds the %.2f%% delta — layout, not libJudy',
                $pb['build_spread_pct'],
                abs($v['delta_pct'])
            );
        }
        // The per-round ABSOLUTES are kept, not just their medians and the
        // ratios derived from them: a reader re-checking this run for a moving
        // baseline needs the raw series, and a ratio series cannot show one.
        $refAbs = $series; ksort($refAbs);
        $cmpAbs = $ms[$name][$id]; ksort($cmpAbs);
        $rows[$id] = $v + $pb + [
            'reference_ms'  => round(vp_median($series), 4),
            'comparison_ms' => round(vp_median($ms[$name][$id]), 4),
            'reference_ms_by_round'  => array_map(static fn($x) => round($x, 4), array_values($refAbs)),
            'comparison_ms_by_round' => array_map(static fn($x) => round($x, 4), array_values($cmpAbs)),
            'paired_ratios' => array_map(static fn($x) => round($x, 5), $adj),
        ];
    }

    $report[$name] = [
        'reference'          => $reference,
        'control_measured'   => $haveControl,
        'control_median'     => round($ctlMedian, 5),
        'control_spread_pct' => round($ctlSpread, 2),
        'control_rows'       => $ctlRows,
        'contaminated'       => $contaminated,
        'rows'               => $rows,
    ];
}

// ── Peak RSS per arm ───────────────────────────────────────────────────────
//
// libJudy's patches changed inner-loop code, not the data structures, so the
// footprint should be identical across arms. Reporting it makes that a checked
// prediction rather than an assumption: an arm that differs in memory is not
// the controlled comparison this run claims to be.
$rss = [];
foreach ($meta as $name => $list) {
    $vals = array_column($list, 'peak_rss');
    $rss[$name] = [
        'median_mb' => round(vp_median($vals) / 1048576, 1),
        'min_mb'    => round(min($vals) / 1048576, 1),
        'max_mb'    => round(max($vals) / 1048576, 1),
    ];
}

// ── Amdahl attribution ─────────────────────────────────────────────────────
//
// judy_share is measured, not assumed: the mirror family issues exactly the
// Judy calls its PSR-16 counterpart issues, so the ratio of their medians is
// the fraction of the operation spent inside libJudy (plus the extension's own
// zend_call overhead, which is charged to Judy — so this is an UPPER bound on
// the share, and therefore an upper bound on the predicted end-to-end gain).
$attrib = [];
foreach (VP_FAMILIES as $fam => $info) {
    $mirror = $info['mirror'];
    if ($mirror === null) {
        continue;
    }
    foreach (array_keys(VP_OPS) as $op) {
        $host = "$fam.$op";
        $mrow = "$mirror.$op";
        if (!isset($ms[$reference][$host], $ms[$reference][$mrow])) {
            continue;
        }
        $hostMs   = vp_median($ms[$reference][$host]);
        $mirrorMs = vp_median($ms[$reference][$mrow]);
        if ($hostMs <= 0.0) {
            continue;
        }
        $share = min(1.0, $mirrorMs / $hostMs);
        foreach ($report as $name => $rep) {
            $mDelta = $rep['rows'][$mrow]['delta_pct'] ?? null;
            $hDelta = $rep['rows'][$host]['delta_pct'] ?? null;
            if ($mDelta === null || $hDelta === null) {
                continue;
            }
            $attrib[$name][$host] = [
                'mirror'              => $mrow,
                'judy_share'          => round($share, 4),
                'mirror_delta_pct'    => $mDelta,
                'predicted_delta_pct' => round($share * $mDelta, 2),
                'measured_delta_pct'  => $hDelta,
                'measured_status'     => $rep['rows'][$host]['status'],
                'floor_pct'           => $floor,
                // Can a gain of this size even be claimed at this floor?
                'detectable'          => abs($share * $mDelta) >= $floor,
            ];
        }
    }
}

// ── output ─────────────────────────────────────────────────────────────────
$doc = [
    'schema'      => 'judy-cache-vendoring-probe/1',
    'label'       => $label,
    'at'          => date('Y-m-d\TH:i:sP'),
    'host'        => php_uname('a'),
    'php'         => PHP_VERSION,
    'ext_version' => $extVersion,
    'params'      => [
        'rounds' => $rounds, 'n' => $n, 'iterations' => $iters, 'groups' => $groups,
        'families' => $fams, 'floor_pct' => $floor, 'dram_n' => $dram_n,
        'residency' => $n >= $dram_n ? 'out-of-cache' : 'cache-resident',
    ],
    'arms'        => $verify,
    'provenance'  => $provenance,
    'reference'   => $reference,
    'hygiene'     => ['failed' => $hygiene_failed, 'snapshots' => $loads],
    'stability'   => ['unstable' => $baseline_unstable, 'tol_pct' => VP_STABILITY_TOL_PCT, 'arms' => $stability],
    'peak_rss'    => $rss,
    'comparisons' => $report,
    'attribution' => $attrib,
    'children'    => $meta,
];

if (isset($opts['out'])) {
    file_put_contents((string) $opts['out'], json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

// Optional CSV in the contract php-judy's tools/bench-stability.py gates on:
//   arm,seed,corpus,n,trial,kernel,ns_per_op,hits
// That tool treats the arm literally named `pre` as the untouched canary and
// fails a cell whose per-trial spread exceeds its tolerance. The REFERENCE arm
// takes that name here: it is the arm whose absolute numbers must not move,
// and gating on it externally is a stronger check than this script grading its
// own homework.
if (isset($opts['csv'])) {
    $lines = ["# label=$label", "# n=$n", "# rounds=$rounds"];
    foreach ($loads as $l) {
        $lines[] = "# loadavg_{$l['phase']}=" . (string) $l['load1'];
    }
    $lines[] = 'arm,seed,corpus,n,trial,kernel,ns_per_op,hits';
    foreach ($ms as $name => $rowsByArm) {
        $csvArm = $name === $reference ? 'pre' : $name;
        foreach ($rowsByArm as $id => $series) {
            ksort($series);
            foreach ($series as $r => $val) {
                $lines[] = sprintf('%s,0,%s,%d,%d,serial,%.3f,%d',
                    $csvArm, $id, $n, $r, $val * 1e6 / max(1, $n), $n);
            }
        }
    }
    file_put_contents((string) $opts['csv'], implode("\n", $lines) . "\n");
}

printf("judy-cache vendoring probe — %s\n", $label);
printf("PHP %s, ext-judy %s (identical in every arm), n=%s, %d rounds x %d iterations, %s\n",
    PHP_VERSION, $extVersion, number_format($n), $rounds, $iters, $doc['params']['residency']);
foreach ($verify as $name => $list) {
    printf("  arm %-10s %d build(s)%s\n", $name, count($list),
        isset($provenance[$name]) ? ' — ' . $provenance[$name] : '');
    foreach ($list as $v) {
        printf("      %s  sha256=%s\n", $v['so'], substr($v['sha256'], 0, 16));
        foreach ($v['mapped'] as $p) {
            if (!str_contains(basename($p), 'judy.so')) {
                printf("      links %s\n", $p);
            }
        }
    }
}
printf("peak RSS per arm (should be identical — the patches changed code, not structures):\n");
foreach ($rss as $name => $m) {
    printf("  %-10s %7.1f MB [%.1f..%.1f]\n", $name, $m['median_mb'], $m['min_mb'], $m['max_mb']);
}
printf("baseline stability (an arm measuring identical work every round must hold still;\n");
printf("  a PHP-array control cannot see LLC/bandwidth contention, an absolute series can):\n");
foreach ($stability as $name => $st) {
    printf("  %-10s median spread %6.2f%%  max %6.2f%% (%s)  median |drift| %5.2f%%  %s\n",
        $name, $st['median_spread_pct'], $st['max_spread_pct'], (string) $st['worst_row'],
        $st['median_drift_pct'],
        $st['median_spread_pct'] > VP_STABILITY_TOL_PCT ? '** UNSTABLE **' : 'ok');
}
printf("hygiene: %s", $hygiene_failed ? "FAILED\n" : "ok\n");
foreach ($loads as $l) {
    if ($l['over'] || $l['foreign_busy']) {
        printf("  %s load1=%s threshold=%s foreign_cpu=%s%%\n",
            $l['phase'], (string) $l['load1'], (string) $l['threshold'], (string) $l['foreign_cpu_pct']);
    }
}

foreach ($report as $name => $rep) {
    printf("\n### %s vs %s — negative delta = %s is faster\n", $reference, $name, $reference);
    printf("control (PHP array, no libJudy): median %+0.2f%%, spread %.2f%%%s\n",
        ($rep['control_median'] - 1.0) * 100.0, $rep['control_spread_pct'],
        $rep['contaminated'] ? '  ** CONTAMINATED: every verdict suppressed **' : '');
    printf("\n| %-14s | %10s | %10s | %8s | %-18s | %-8s | %6s |\n",
        'row', "$reference ms", 'other ms', 'delta %', '95% CI', 'verdict', 'bspread');
    echo "|----------------|------------|------------|----------|--------------------|----------|--------|\n";
    foreach ($rep['rows'] as $id => $v) {
        printf("| %-14s | %10.3f | %10.3f | %+8.2f | [%+6.2f, %+6.2f]   | %-8s | %6.2f |\n",
            $id, $v['reference_ms'], $v['comparison_ms'], $v['delta_pct'],
            $v['ci_delta_pct'][0], $v['ci_delta_pct'][1], $v['status'], $v['build_spread_pct']);
    }

    if (!empty($attrib[$name])) {
        printf("\nAmdahl attribution (%s vs %s): how much of each operation is libJudy,\n", $reference, $name);
        printf("and what end-to-end movement that share can carry.\n\n");
        printf("| %-14s | %-12s | %9s | %11s | %11s | %11s | %s\n",
            'row', 'mirror', 'judy share', 'mirror d%', 'predicted d%', 'measured d%', 'above floor?');
        echo "|----------------|--------------|-----------|-------------|-------------|-------------|-------------|\n";
        foreach ($attrib[$name] as $id => $a) {
            printf("| %-14s | %-12s | %8.1f%% | %+11.2f | %+11.2f | %+11.2f | %s\n",
                $id, $a['mirror'], $a['judy_share'] * 100.0, $a['mirror_delta_pct'],
                $a['predicted_delta_pct'], $a['measured_delta_pct'],
                $a['detectable'] ? 'yes' : 'no — under the ' . $a['floor_pct'] . '% floor');
        }
    }
}

echo "\nA row is a claim only when its verdict is FASTER or SLOWER: that requires the\n";
echo "whole CI to clear the claim floor AND the delta to exceed the per-build spread.\n";
echo "Everything else is null — inside demonstrated noise, not a small win.\n";
