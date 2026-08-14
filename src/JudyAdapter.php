<?php

namespace Orieg\JudyCache;

use Symfony\Component\Cache\Adapter\Psr16Adapter;

/**
 * Symfony Cache adapter (PSR-6 pool) over JudySimpleCache.
 *
 * Requires symfony/cache to be installed. Usable anywhere Symfony expects a
 * cache pool:
 *
 *   $pool = new JudyAdapter();
 *   $value = $pool->get('report.42', fn () => computeReport(42));
 *
 * For prefix invalidation, keep a reference to the underlying cache:
 *
 *   $judy = new JudySimpleCache();
 *   $pool = new JudyAdapter(cache: $judy);
 *   $judy->deletePrefix('report.');
 */
class JudyAdapter extends Psr16Adapter
{
    public function __construct(
        string $namespace = '',
        int $defaultLifetime = 0,
        ?JudySimpleCache $cache = null,
    ) {
        parent::__construct($cache ?? new JudySimpleCache(), $namespace, $defaultLifetime);
    }
}
