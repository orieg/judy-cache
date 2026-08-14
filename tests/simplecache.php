<?php
/**
 * Standalone PSR-16 behavior tests for JudySimpleCache.
 *
 * With composer:  php tests/simplecache.php   (after composer install)
 * Without:        uses the PSR shim + a judy-polyfill checkout next to this
 *                 repo (or set JUDY_POLYFILL_PATH).
 */

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/shims/psr-simple-cache.php';
    $polyfill = \getenv('JUDY_POLYFILL_PATH') ?: __DIR__ . '/../../judy-polyfill';
    require $polyfill . '/src/Judy.php';
    require $polyfill . '/src/bootstrap.php';
    require __DIR__ . '/../src/InvalidArgumentException.php';
    require __DIR__ . '/../src/JudySimpleCache.php';
}

use Orieg\JudyCache\JudySimpleCache;

$failures = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL $label\n  expected: ", json_encode($expected), "\n  actual:   ", json_encode($actual), "\n";
    }
}

// Controllable clock
$now = 1_000_000;
$cache = new JudySimpleCache(clock: function () use (&$now) { return $now; });

// Basic set/get/has/delete
check('miss returns default', 'dflt', $cache->get('a', 'dflt'));
check('set', true, $cache->set('a', 'value-a'));
check('get', 'value-a', $cache->get('a'));
check('has', true, $cache->has('a'));
check('delete', true, $cache->delete('a'));
check('deleted gone', false, $cache->has('a'));
check('delete missing ok', true, $cache->delete('nope'));

// Value fidelity (serialized snapshots)
$obj = new \stdClass();
$obj->x = [1, 2, 3];
$cache->set('obj', $obj);
$fetched = $cache->get('obj');
check('object roundtrip', $obj->x, $fetched->x);
$obj->x = 'mutated';
check('stored value is a snapshot', [1, 2, 3], $cache->get('obj')->x);
foreach ([null, false, 0, '', [], 3.14] as $i => $v) {
    $cache->set("scalar.$i", $v);
    check("scalar $i roundtrip", $v, $cache->get("scalar.$i", 'MISS'));
}

// TTL
$cache->set('ttl', 'x', 60);
check('within ttl', 'x', $cache->get('ttl'));
$now += 59;
check('still within ttl', 'x', $cache->get('ttl'));
$now += 2;
check('expired', null, $cache->get('ttl'));
check('expired has', false, $cache->has('ttl'));
$cache->set('ttl2', 'y', new DateInterval('PT30S'));
$now += 29;
check('interval ttl live', 'y', $cache->get('ttl2'));
$now += 2;
check('interval ttl expired', null, $cache->get('ttl2'));
check('zero ttl means gone', true, $cache->set('z', 'v', 0));
check('zero ttl not stored', false, $cache->has('z'));
$cache->set('neg', 'v', -5);
check('negative ttl not stored', false, $cache->has('neg'));

// Multiple ops
$cache->clear();
check('setMultiple', true, $cache->setMultiple(['m.1' => 1, 'm.2' => 2, 'm.3' => 3]));
check('getMultiple', ['m.1' => 1, 'm.9' => 'd', 'm.3' => 3],
    (array) $cache->getMultiple(['m.1', 'm.9', 'm.3'], 'd'));
check('deleteMultiple', true, $cache->deleteMultiple(['m.1', 'm.3']));
check('after deleteMultiple', [false, true], [$cache->has('m.1'), $cache->has('m.2')]);
check('clear', true, $cache->clear());
check('cleared', 0, $cache->count());

// Key validation (PSR-16 reserved characters)
foreach (['', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $bad) {
    try {
        $cache->get($bad);
        $failures++;
        echo "FAIL invalid key accepted: " . var_export($bad, true) . "\n";
    } catch (\Psr\SimpleCache\InvalidArgumentException) {
    }
}

// Prefix operations
$cache->clear();
$cache->setMultiple([
    'user.1.profile' => 'p1', 'user.1.settings' => 's1',
    'user.2.profile' => 'p2', 'report.7' => 'r7',
]);
check('keysByPrefix', ['user.1.profile', 'user.1.settings'], $cache->keysByPrefix('user.1.'));
check('keysByPrefix limit', ['user.1.profile'], $cache->keysByPrefix('user.', 1));
check('deletePrefix', 2, $cache->deletePrefix('user.1.'));
check('prefix deleted', [false, true], [$cache->has('user.1.profile'), $cache->has('user.2.profile')]);
check('deletePrefix no match', 0, $cache->deletePrefix('ghost.'));

// prune()
$cache->clear();
$cache->set('p.keep', 1);
$cache->set('p.dies', 2, 10);
$now += 11;
check('count before prune', 2, $cache->count());
check('prune evicts', 1, $cache->prune());
check('count after prune', 1, $cache->count());

// storeSerialized=false stores by reference
$raw = new JudySimpleCache(storeSerialized: false, clock: function () use (&$now) { return $now; });
$o = new \stdClass();
$o->v = 1;
$raw->set('o', $o);
$o->v = 2;
check('by-reference storage sees mutation', 2, $raw->get('o')->v);

if ($failures === 0) {
    echo "simplecache: all checks passed (backend: ", judy_version(), ")\n";
    exit(0);
}
echo "simplecache: $failures failure(s)\n";
exit(1);
