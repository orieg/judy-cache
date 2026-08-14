<?php
/**
 * Minimal shim of psr/simple-cache 3.0 for running tests without composer.
 * CI always uses the real package via composer install.
 */

namespace Psr\SimpleCache;

// Guard on a parented interface: parentless ones are compile-time hoisted,
// so testing CacheInterface here would see this very file's declaration.
if (\interface_exists(InvalidArgumentException::class)) {
    return;
}

interface CacheException extends \Throwable
{
}

interface InvalidArgumentException extends CacheException
{
}

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;

    public function deleteMultiple(iterable $keys): bool;

    public function has(string $key): bool;
}
