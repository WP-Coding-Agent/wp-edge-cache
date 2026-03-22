<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * No-op cache adapter for metrics storage when no backend is configured.
 */
final class NullAdapter implements CacheAdapterInterface
{
    public function get(string $key): mixed { return false; }
    public function set(string $key, mixed $value, int $ttl = 0): bool { return true; }
    public function delete(string $key): bool { return true; }
    public function add(string $key, mixed $value, int $ttl = 0): bool { return true; }
}
