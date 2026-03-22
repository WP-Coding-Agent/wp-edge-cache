<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Adapter that delegates to WordPress's wp_cache_* functions.
 *
 * This bridges the pure SWRCache to whatever object cache drop-in
 * the site is running (Redis, Memcached, APCu, etc).
 */
final class WPObjectCacheAdapter implements CacheAdapterInterface
{
    public function __construct(
        private readonly string $group = 'edge_cache',
    ) {}

    public function get(string $key): mixed
    {
        $found = false;
        $value = wp_cache_get($key, $this->group, false, $found);
        return $found ? $value : false;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return wp_cache_set($key, $value, $this->group, $ttl);
    }

    public function delete(string $key): bool
    {
        return wp_cache_delete($key, $this->group);
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return wp_cache_add($key, $value, $this->group, $ttl);
    }
}
