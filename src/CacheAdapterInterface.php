<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Abstraction over the actual cache backend (Redis, Memcached, APCu, etc).
 * Keeps SWRCache free of WordPress dependencies.
 */
interface CacheAdapterInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function delete(string $key): bool;
    public function add(string $key, mixed $value, int $ttl = 0): bool;
}
