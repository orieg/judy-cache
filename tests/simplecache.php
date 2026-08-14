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
            echo "FAIL $label: expected $class, got ", get_class($e), "\n";
        }
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

/* ── PSR-16 spec-clause compliance ─────────────────────────────
 * The official cache/integration-tests suite requires psr/cache ~1.0 and
 * predates the typed psr/simple-cache v3 interface, so it cannot run
 * against modern implementations. These checks map each testable MUST
 * clause of the PSR-16 spec (+ typed-interface reality) explicitly.
 */

$spec = new JudySimpleCache(clock: function () use (&$now) { return $now; });

// "Keys consisting of A-Z, a-z, 0-9, _, and . MUST be supported" — and a
// length of up to 64 characters MUST be supported.
$legal = 'AZaz09_.' . str_repeat('k', 56);
check('spec: 64-char legal key', true, strlen($legal) === 64 && $spec->set($legal, 'v') && $spec->get($legal) === 'v');
check('spec: legal charset key', true, $spec->set('AZaz09_.-', 'v2') && $spec->get('AZaz09_.-') === 'v2');

// Data MUST be returned exactly as stored, for all serializable types.
$exact = ['s' => 'str', 'i' => -42, 'f' => 1.5, 'b' => false, 'n' => null, 'a' => [['x']], 'o' => new stdClass()];
$spec->set('spec.types', $exact);
$back = $spec->get('spec.types');
check('spec: type fidelity', true,
    $back['s'] === 'str' && $back['i'] === -42 && $back['f'] === 1.5
    && $back['b'] === false && $back['n'] === null && $back['a'] === [['x']]
    && $back['o'] instanceof stdClass);

// A stored null MUST be distinguishable via has(), even though get()
// cannot distinguish it from a miss.
$spec->set('spec.null', null);
check('spec: null value has()', true, $spec->has('spec.null'));
check('spec: null value get with default', null, $spec->get('spec.null', 'DEFAULT') === null ? null : 'WRONG');

// getMultiple/setMultiple/deleteMultiple MUST accept any iterable, not
// just arrays.
$gen = (function () { yield 'g.1' => 'v1'; yield 'g.2' => 'v2'; })();
check('spec: setMultiple(Generator)', true, $spec->setMultiple($gen));
$keys = (function () { yield 'g.1'; yield 'g.2'; yield 'g.3'; })();
check('spec: getMultiple(Generator)', ['g.1' => 'v1', 'g.2' => 'v2', 'g.3' => 'D'],
    (array) $spec->getMultiple($keys, 'D'));
$dkeys = (function () { yield 'g.1'; })();
check('spec: deleteMultiple(Generator)', true, $spec->deleteMultiple($dkeys));
check('spec: after generator delete', false, $spec->has('g.1'));

// getMultiple MUST return defaults for missing keys, keyed by requested key.
check('spec: getMultiple defaults', ['none.1' => 0, 'none.2' => 0], (array) $spec->getMultiple(['none.1', 'none.2'], 0));

// Reserved characters {}()/\@: MUST throw InvalidArgumentException — on
// every key-taking method.
foreach (['set' => fn($k) => $spec->set($k, 1), 'get' => fn($k) => $spec->get($k),
          'has' => fn($k) => $spec->has($k), 'delete' => fn($k) => $spec->delete($k),
          'getMultiple' => fn($k) => $spec->getMultiple([$k]),
          'deleteMultiple' => fn($k) => $spec->deleteMultiple([$k])] as $m => $fn) {
    throws("spec: reserved char via $m", \Psr\SimpleCache\InvalidArgumentException::class, fn() => $fn('bad{key'));
}

// TTL: an expired item MUST be treated as a miss (get, has, getMultiple).
$spec->set('spec.exp', 'v', 10);
$now += 11;
check('spec: expired is miss on get', 'D', $spec->get('spec.exp', 'D'));
check('spec: expired is miss in getMultiple', ['spec.exp' => 'D'], (array) $spec->getMultiple(['spec.exp'], 'D'));

// clear() MUST empty the cache and return true.
$spec->set('spec.c', 1);
check('spec: clear returns true and empties', [true, false], [$spec->clear(), $spec->has('spec.c')]);

if ($failures === 0) {
    echo "simplecache: all checks passed (backend: ", judy_version(), ")\n";
    exit(0);
}
echo "simplecache: $failures failure(s)\n";
exit(1);
