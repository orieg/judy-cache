<?php
/**
 * Smoke test for the Symfony Cache adapter (requires composer install with
 * dev dependencies; exits 0 with a notice when symfony/cache is absent).
 */

if (!\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "symfony-adapter: skipped (no composer autoload)\n";
    exit(0);
}
require __DIR__ . '/../vendor/autoload.php';

if (!\class_exists(\Symfony\Component\Cache\Adapter\Psr16Adapter::class)) {
    echo "symfony-adapter: skipped (symfony/cache not installed)\n";
    exit(0);
}

use Orieg\JudyCache\JudyAdapter;
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

$judy = new JudySimpleCache();
$pool = new JudyAdapter(cache: $judy);

// PSR-6 basics through the pool
$item = $pool->getItem('answer');
check('miss', false, $item->isHit());
$item->set(42);
check('save', true, $pool->save($item));
check('hit', true, $pool->getItem('answer')->isHit());
check('value', 42, $pool->getItem('answer')->get());

// Symfony's get() contract-style callback
$computed = $pool->get('report.7', fn () => 'computed-7');
check('callback compute', 'computed-7', $computed);
check('callback cached', 'computed-7', $pool->get('report.7', fn () => 'recomputed'));

// deleteItem + clear
check('deleteItem', true, $pool->deleteItem('answer'));
check('after delete', false, $pool->getItem('answer')->isHit());

// Prefix invalidation via the underlying JudySimpleCache
$pool->get('report.8', fn () => 'r8');
$n = $judy->deletePrefix('report.');
check('prefix invalidation reaches pool storage', true, $n >= 1);
check('pool recomputes after prefix delete', 'fresh', $pool->get('report.7', fn () => 'fresh'));

if ($failures === 0) {
    echo "symfony-adapter: all checks passed\n";
    exit(0);
}
echo "symfony-adapter: $failures failure(s)\n";
exit(1);
