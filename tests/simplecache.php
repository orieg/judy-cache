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
    require __DIR__ . '/../src/Storage/SlabArena.php';
    require __DIR__ . '/../src/Storage/SharedMemoryPool.php';
    require __DIR__ . '/../src/JudySimpleCache.php';
}

use Orieg\JudyCache\JudySimpleCache;
use Orieg\JudyCache\Storage\SlabArena;
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

// prune() with numeric-string keys across all backends
foreach ([\Judy::STRING_TO_ENTRY, \Judy::STRING_TO_MIXED, \Judy::STRING_TO_MIXED_HASH, \Judy::STRING_TO_MIXED_ADAPTIVE] as $b) {
    $num = new JudySimpleCache(clock: function () use (&$now) { return $now; }, backend: $b);
    $num->set('42', 'dies', 10);
    $num->set('-7', 'dies', 10);
    $num->set('007', 'dies', 10);   // not canonical: stays a string key
    $num->set('42.keep', 'lives');
    $now += 11;
    check("numeric-key prune evicts (backend $b)", 3, $num->prune());
    check("numeric-key prune leaves the rest (backend $b)", ['42.keep'], $num->keysByPrefix(''));
    check("numeric-key prune is a miss (backend $b)", 'MISS', $num->get('42', 'MISS'));
}

// Cursor prune on larger population with mixed expiries
$sweepCache = new JudySimpleCache(clock: function () use (&$now) { return $now; });
for ($i = 0; $i < 2000; $i++) {
    $sweepCache->set("bulk.$i", $i, ($i % 2 === 0) ? 10 : 1000);
}
$now += 15;
check('bulk count before prune', 2000, $sweepCache->count());
check('bulk prune evicts half', 1000, $sweepCache->prune());
check('bulk count after prune', 1000, $sweepCache->count());

// The same coercion hazard on the prefix ops, which read keys back from Judy.
$num = new JudySimpleCache(clock: function () use (&$now) { return $now; });
$num->setMultiple(['1' => 'a', '2' => 'b', '10' => 'c', '1.x' => 'd']);
check('numeric keysByPrefix', ['1', '1.x', '10'], $num->keysByPrefix('1'));
check('numeric keysByPrefix dotted', ['1.x'], $num->keysByPrefix('1.'));
check('numeric deletePrefix', 3, $num->deletePrefix('1'));
check('numeric deletePrefix leaves siblings', ['2'], $num->keysByPrefix(''));

// storeSerialized=false stores by reference
$raw = new JudySimpleCache(storeSerialized: false, clock: function () use (&$now) { return $now; });
$o = new \stdClass();
$o->v = 1;
$raw->set('o', $o);
$o->v = 2;
check('by-reference storage sees mutation', 2, $raw->get('o')->v);

// Single-Trie Metadata Packing Verification
$stCache = new JudySimpleCache(clock: function () use (&$now) { return $now; });
$stCache->set('st.infinite', 'inf_value');
$stCache->set('st.expiring', 'exp_value', 100);
check('single-trie: get infinite', 'inf_value', $stCache->get('st.infinite'));
check('single-trie: get expiring', 'exp_value', $stCache->get('st.expiring'));
$r = new \ReflectionClass($stCache);
check('single-trie: expiries property completely eliminated', false, $r->hasProperty('expiries'));

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

/* ── Transparent Adaptive Compression Tests ───────────────────── */
throws('compression: negative threshold throws', \Psr\SimpleCache\InvalidArgumentException::class,
    fn() => new JudySimpleCache(compressionThreshold: -1));
throws('compression: invalid codec throws', \Psr\SimpleCache\InvalidArgumentException::class,
    fn() => new JudySimpleCache(compressionThreshold: 100, compressionCodec: 'nonexistent_algo'));

foreach (['gzip', 'deflate'] as $codec) {
    $compCache = new JudySimpleCache(
        clock: function () use (&$now) { return $now; },
        compressionThreshold: 100,
        compressionCodec: $codec,
    );

    // Short string below threshold (uncompressed)
    $short = 'short-value-under-100-bytes';
    $compCache->set('c.short', $short);
    check("compression ($codec): short string roundtrip", $short, $compCache->get('c.short'));

    // Repetitive large string (highly compressible)
    $large = str_repeat('The quick brown fox jumps over the lazy dog. ', 100); // ~4.6 KB
    $compCache->set('c.large', $large);
    check("compression ($codec): large string roundtrip", $large, $compCache->get('c.large'));

    // Large structured object / array
    $complex = [
        'users' => array_map(fn($i) => ['id' => $i, 'name' => "User $i", 'roles' => ['admin', 'member']], range(1, 50)),
        'metadata' => ['total' => 50, 'page' => 1],
    ];
    $compCache->set('c.complex', $complex);
    check("compression ($codec): complex array roundtrip", $complex, $compCache->get('c.complex'));

    // High entropy binary string (adaptive fallback: compression overhead would increase size)
    $random = random_bytes(500);
    $compCache->set('c.rand', $random);
    check("compression ($codec): high entropy binary roundtrip", $random, $compCache->get('c.rand'));

    // Prefix operations work transparently with compressed payloads
    check("compression ($codec): keysByPrefix", ['c.complex', 'c.large', 'c.rand', 'c.short'], $compCache->keysByPrefix('c.'));
    check("compression ($codec): deletePrefix", 4, $compCache->deletePrefix('c.'));
    check("compression ($codec): after deletePrefix", 0, $compCache->count());
}

/* ── Content-Addressable Interning (Deduplication) Tests ──────── */
throws('interning: negative threshold throws', \Psr\SimpleCache\InvalidArgumentException::class,
    fn() => new JudySimpleCache(enableInterning: true, internThreshold: -1));

$internCache = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    enableInterning: true,
    internThreshold: 100,
);

$sharedPayload = str_repeat('A heavy shared API response template. ', 20); // ~760 bytes
$distinctPayload = str_repeat('A distinct API response template. ', 20);

// Set 10 different keys with the identical payload
for ($i = 0; $i < 10; $i++) {
    $internCache->set("shared.$i", $sharedPayload);
}
check('interning: 10 keys share 1 pool entry', 1, $internCache->internCount());
check('interning: cache count is 10', 10, $internCache->count());
for ($i = 0; $i < 10; $i++) {
    check("interning: get shared.$i", $sharedPayload, $internCache->get("shared.$i"));
}

// Add a distinct payload
$internCache->set('distinct.1', $distinctPayload);
check('interning: 2 distinct payload pool entries', 2, $internCache->internCount());

// Overwrite a key with a different value (ref count decrements)
$internCache->set('shared.0', $distinctPayload);
check('interning: pool count remains 2 after overwrite', 2, $internCache->internCount());
check('interning: shared.0 sees new payload', $distinctPayload, $internCache->get('shared.0'));

// Delete individual keys
$internCache->delete('distinct.1');
check('interning: pool count still 2 while ref exists', 2, $internCache->internCount());
$internCache->delete('shared.0');
check('interning: pool count drops to 1 after last ref deleted', 1, $internCache->internCount());

// Expiry and cursor prune cleans up interned payload
$internCache->set('expire.shared', $sharedPayload, 10);
check('interning: pool count is 1', 1, $internCache->internCount());
$internCache->deletePrefix('shared.'); // deletes shared.1..shared.9
check('interning: pool count is 1 with expire.shared holding ref', 1, $internCache->internCount());
$now += 15;
check('interning: prune evicts expired', 1, $internCache->prune());
check('interning: pool count is 0 after expired evicted', 0, $internCache->internCount());

// clear() frees intern pool completely
$internCache->set('temp.1', $sharedPayload);
$internCache->set('temp.2', $sharedPayload);
check('interning: count before clear', 2, $internCache->count());
check('interning: pool before clear', 1, $internCache->internCount());
$internCache->clear();
check('interning: count after clear', 0, $internCache->count());
check('interning: pool after clear', 0, $internCache->internCount());

// Combined: Compression + Interning
$comboCache = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    compressionThreshold: 100,
    enableInterning: true,
    internThreshold: 50,
);
for ($i = 0; $i < 5; $i++) {
    $comboCache->set("combo.$i", $sharedPayload);
}
check('combo: 5 keys share 1 compressed+interned entry', 1, $comboCache->internCount());
check('combo: retrieve key 0', $sharedPayload, $comboCache->get('combo.0'));
check('combo: retrieve key 4', $sharedPayload, $comboCache->get('combo.4'));
$comboCache->clear();
check('combo: cleared', 0, $comboCache->internCount());

/* ── SlabArena Integration Tests in simplecache ──────────────── */
$arena = new SlabArena(chunkSize: 64, initialChunks: 50, maxChunks: 200);
$slabCache = new JudySimpleCache(
    clock: function () use (&$now) { return $now; },
    slabArena: $arena,
    slabThreshold: 50,
);
$slabCache->set('s.1', str_repeat('Data chunk payload ', 10));
check('simplecache+slab: retrieve payload', str_repeat('Data chunk payload ', 10), $slabCache->get('s.1'));
check('simplecache+slab: chunk allocated', true, $arena->getAllocatedChunks() > 0);
$slabCache->clear();
check('simplecache+slab: chunks freed on clear', 0, $arena->getAllocatedChunks());

/* ── SharedMemoryPool Integration Tests in simplecache ───────── */
if (extension_loaded('shmop')) {
    $shm = new SharedMemoryPool(key: 0x53484DEE, size: 1024 * 1024, chunkSize: 64);
    $shmCache = new JudySimpleCache(
        clock: function () use (&$now) { return $now; },
        shmPool: $shm,
        shmThreshold: 50,
    );
    $shmCache->set('shm.1', str_repeat('SHM Chunk payload ', 10));
    check('simplecache+shm: retrieve payload', str_repeat('SHM Chunk payload ', 10), $shmCache->get('shm.1'));
    check('simplecache+shm: chunk allocated', true, $shm->getAllocatedChunks() > 0);
    $shmCache->clear();
    check('simplecache+shm: chunks freed on clear', 0, $shm->getAllocatedChunks());
    $shm->delete();
}

if ($failures === 0) {
    echo "simplecache: all checks passed (backend: ", judy_version(), ")\n";
    exit(0);
}
echo "simplecache: $failures failure(s)\n";
exit(1);
