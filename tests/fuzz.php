<?php
/**
 * Model-based fuzz test: random operation sequences against JudySimpleCache
 * and a trivially-correct reference implementation (plain array + expiry
 * map), asserting identical observable behavior at every step.
 *
 * Deterministic: fixed seed set, no wall-clock dependence (injected clock).
 *
 * Run: php tests/fuzz.php [ops-per-seed] [seed seed ...]
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

/** Trivially-correct PSR-16 model: array of values + array of expiries. */
class ReferenceCache
{
    private array $v = [];
    private array $e = [];

    public function __construct(private \Closure $clock)
    {
    }

    private function live(string $k): bool
    {
        if (!\array_key_exists($k, $this->v)) {
            return false;
        }
        if (isset($this->e[$k]) && $this->e[$k] <= ($this->clock)()) {
            unset($this->v[$k], $this->e[$k]);
            return false;
        }
        return true;
    }

    public function get(string $k, mixed $d = null): mixed
    {
        return $this->live($k) ? \unserialize($this->v[$k]) : $d;
    }

    public function set(string $k, mixed $val, ?int $ttl): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            unset($this->v[$k], $this->e[$k]);
            return true;
        }
        $this->v[$k] = \serialize($val);
        if ($ttl === null) {
            unset($this->e[$k]);
        } else {
            $this->e[$k] = ($this->clock)() + $ttl;
        }
        return true;
    }

    public function delete(string $k): bool
    {
        unset($this->v[$k], $this->e[$k]);
        return true;
    }

    public function has(string $k): bool
    {
        return $this->live($k);
    }

    public function clear(): void
    {
        $this->v = $this->e = [];
    }

    public function deletePrefix(string $p): int
    {
        $n = 0;
        foreach (\array_keys($this->v) as $k) {
            if ($p === '' || \str_starts_with((string) $k, $p)) {
                unset($this->v[$k], $this->e[$k]);
                $n++;
            }
        }
        return $n;
    }

    public function keysLive(): array
    {
        $keys = [];
        foreach (\array_keys($this->v) as $k) {
            if ($this->live((string) $k)) {
                $keys[] = (string) $k;
            }
        }
        \sort($keys, SORT_STRING);
        return $keys;
    }
}

$opsPerSeed = (int) ($argv[1] ?? 5000);
$seeds = \array_slice($argv, 2) ?: [1, 7, 42, 1337, 99991];
$backends = [
    'trie' => \Judy::STRING_TO_MIXED,
    'hash' => \Judy::STRING_TO_MIXED_HASH,
    'adaptive' => \Judy::STRING_TO_MIXED_ADAPTIVE,
];

$keyPool = [];
foreach (['user', 'report', 'cfg', 'x'] as $ns) {
    for ($i = 0; $i < 40; $i++) {
        $keyPool[] = "$ns.$i";
        $keyPool[] = "$ns.$i.detail";
    }
}
// Bare numeric keys are legal PSR-16 keys and are the ones PHP array-key
// coercion turns into ints on the way out of toArray()/foreach.
for ($i = 0; $i < 20; $i++) {
    $keyPool[] = (string) $i;
    $keyPool[] = '-' . $i;
    $keyPool[] = '0' . $i;   // non-canonical: stays a string key
}
$valuePool = [null, true, false, 0, -1, 42, 3.14, '', 'str', [1, 2, 3], ['a' => ['b' => 'c']]];
$prefixPool = ['user.', 'user.1', 'report.', 'cfg.3', 'nope.', ''];

$configs = [
    'default' => [],
    'compressed' => ['compressionThreshold' => 20, 'compressionCodec' => 'gzip'],
    'interned' => ['enableInterning' => true, 'internThreshold' => 20],
    'combo' => ['compressionThreshold' => 20, 'enableInterning' => true, 'internThreshold' => 20],
];

$total = 0;
foreach ($configs as $cname => $copt) {
    foreach ($backends as $bname => $btype) {
        foreach ($seeds as $seed) {
            $now = 1_000_000;
            $clock = function () use (&$now) { return $now; };
            $judy = new JudySimpleCache(
                clock: $clock,
                backend: $btype,
                compressionThreshold: $copt['compressionThreshold'] ?? null,
                compressionCodec: $copt['compressionCodec'] ?? 'gzip',
                enableInterning: $copt['enableInterning'] ?? false,
                internThreshold: $copt['internThreshold'] ?? 256,
            );
            $ref  = new ReferenceCache(\Closure::fromCallable($clock));


        \mt_srand((int) $seed);
        for ($op = 0; $op < $opsPerSeed; $op++) {
            $total++;
            $k = $keyPool[\mt_rand(0, \count($keyPool) - 1)];
            switch (\mt_rand(0, 9)) {
                case 0:
                case 1:
                case 2: // set
                    $v = $valuePool[\mt_rand(0, \count($valuePool) - 1)];
                    $ttl = [null, null, 5, 30, 0, -3][\mt_rand(0, 5)];
                    $judy->set($k, $v, $ttl);
                    $ref->set($k, $v, $ttl);
                    break;
                case 3:
                case 4:
                case 5: // get
                    $a = $judy->get($k, 'DFLT');
                    $b = $ref->get($k, 'DFLT');
                    if ($a !== $b) {
                        fwrite(STDERR, "FUZZ DIVERGE [$bname seed=$seed op=$op] get($k): judy=" . \json_encode($a) . " ref=" . \json_encode($b) . "\n");
                        exit(1);
                    }
                    break;
                case 6: // has
                    if ($judy->has($k) !== $ref->has($k)) {
                        fwrite(STDERR, "FUZZ DIVERGE [$bname seed=$seed op=$op] has($k)\n");
                        exit(1);
                    }
                    break;
                case 7: // delete
                    $judy->delete($k);
                    $ref->delete($k);
                    break;
                case 8: // advance clock
                    $now += \mt_rand(0, 8);
                    break;
                case 9: // prefix delete (both must agree on the count)
                    $p = $prefixPool[\mt_rand(0, \count($prefixPool) - 1)];
                    // deletePrefix() counts expired-but-unevicted entries,
                    // so eagerly evict on both sides before comparing counts.
                    $judy->prune();
                    $ref->keysLive(); // forces lazy eviction in the model
                    $a = $judy->deletePrefix($p);
                    $b = $ref->deletePrefix($p);
                    if ($a !== $b) {
                        fwrite(STDERR, "FUZZ DIVERGE [$bname seed=$seed op=$op] deletePrefix($p): judy=$a ref=$b\n");
                        exit(1);
                    }
                    break;
            }
        }

        // Final state must match exactly.
        $judy->prune();
        $judyKeys = $judy->keysByPrefix('');
        \sort($judyKeys, SORT_STRING);
        if ($judyKeys !== $ref->keysLive()) {
            fwrite(STDERR, "FUZZ DIVERGE [$bname seed=$seed] final keys\n  judy: " . \implode(',', $judyKeys) . "\n  ref:  " . \implode(',', $ref->keysLive()) . "\n");
            exit(1);
        }
        }
    }
}

echo "fuzz: $total ops across " . \count($seeds) . " seeds x " . \count($backends) . " backends x " . \count($configs) . " configs, no divergence (backend: ", \judy_version(), ")\n";
