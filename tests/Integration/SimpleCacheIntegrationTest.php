<?php

namespace Orieg\JudyCache\Tests\Integration;

use Cache\IntegrationTests\SimpleCacheTest;
use Orieg\JudyCache\JudySimpleCache;

/**
 * Runs the community-standard PSR-16 compliance suite
 * (cache/integration-tests) against JudySimpleCache.
 */
class SimpleCacheIntegrationTest extends SimpleCacheTest
{
    public function createSimpleCache(): JudySimpleCache
    {
        return new JudySimpleCache();
    }
}
