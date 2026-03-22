<?php
declare(strict_types=1);

namespace EdgeCache\Tests\Unit;

use EdgeCache\Metrics;
use EdgeCache\NullAdapter;
use EdgeCache\SWRCache;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for SWRCache — no WordPress bootstrap needed.
 */
final class SWRCacheTest extends TestCase
{
    private InMemoryAdapter $adapter;
    private SWRCache $cache;
    private int $refreshCount;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryAdapter();
        $this->refreshCount = 0;

        $this->cache = new SWRCache(
            adapter: $this->adapter,
            beta: 0.0, // Disable XFetch randomness for deterministic tests.
            staleWindow: 60,
            refreshTrigger: function () { ++$this->refreshCount; },
            metrics: new Metrics(new NullAdapter()),
        );
    }

    public function test_cold_miss_computes_and_caches(): void
    {
        $calls = 0;
        $result = $this->cache->get('key1', function () use (&$calls) {
            ++$calls;
            return 'computed';
        }, 300);

        $this->assertSame('computed', $result);
        $this->assertSame(1, $calls);

        // Second call should hit cache.
        $result2 = $this->cache->get('key1', function () use (&$calls) {
            ++$calls;
            return 'recomputed';
        }, 300);

        $this->assertSame('computed', $result2);
        $this->assertSame(1, $calls); // No recomputation.
    }

    public function test_stale_entry_serves_old_data_and_triggers_refresh(): void
    {
        // Manually insert an expired envelope.
        $this->adapter->set('swr:stale_key', [
            'data'       => 'old_value',
            'created_at' => time() - 400, // 400s ago, past 300s TTL.
            'ttl'        => 300,
            'delta'      => 1.0,
        ], 9999);

        $result = $this->cache->get('stale_key', fn() => 'fresh_value', 300);

        // Should serve stale and trigger background refresh.
        $this->assertSame('old_value', $result);
        $this->assertSame(1, $this->refreshCount);
    }

    public function test_expired_beyond_stale_window_recomputes(): void
    {
        $this->adapter->set('swr:dead_key', [
            'data'       => 'dead_value',
            'created_at' => time() - 500, // 500s ago, past 300s TTL + 60s stale window.
            'ttl'        => 300,
            'delta'      => 1.0,
        ], 9999);

        $result = $this->cache->get('dead_key', fn() => 'recomputed', 300);

        $this->assertSame('recomputed', $result);
    }

    public function test_delete_removes_entry(): void
    {
        $this->cache->get('del_key', fn() => 'value', 300);
        $this->cache->delete('del_key');

        $calls = 0;
        $this->cache->get('del_key', function () use (&$calls) {
            ++$calls;
            return 'new_value';
        }, 300);

        $this->assertSame(1, $calls); // Had to recompute.
    }

    public function test_lock_prevents_concurrent_recomputation(): void
    {
        $this->assertTrue($this->cache->acquireLock('lock_test'));
        $this->assertFalse($this->cache->acquireLock('lock_test'));

        $this->cache->releaseLock('lock_test');
        $this->assertTrue($this->cache->acquireLock('lock_test'));
    }
}
