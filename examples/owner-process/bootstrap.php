<?php
/** Shared bootstrap: composer autoload, or the no-composer shim path. */

if (\file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
} else {
    require __DIR__ . '/../../tests/shims/psr-simple-cache.php';
    $polyfill = \getenv('JUDY_POLYFILL_PATH') ?: __DIR__ . '/../../../judy-polyfill';
    require $polyfill . '/src/Judy.php';
    require $polyfill . '/src/bootstrap.php';
    require __DIR__ . '/../../src/InvalidArgumentException.php';
    require __DIR__ . '/../../src/JudySimpleCache.php';
}
