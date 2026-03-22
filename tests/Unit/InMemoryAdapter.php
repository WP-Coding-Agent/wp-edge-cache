<?php
declare(strict_types=1);

namespace EdgeCache\Tests\Unit;

use EdgeCache\CacheAdapterInterface;

/**
 * Simple in-memory cache adapter for testing.
 */
final class InMemoryAdapter implements CacheAdapterInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if (isset($this->store[$key])) {
            return false;
        }
        $this->store[$key] = $value;
        return true;
    }
}
