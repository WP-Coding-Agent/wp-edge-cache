<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Atomic hit/miss/stale counters stored in the cache backend.
 *
 * Counters are bucketed by hour for time-series analysis.
 * Each counter key is: metrics:{name}:{YmdH}
 */
final class Metrics
{
    private const COUNTER_TTL = 86400 * 7; // 7 days retention.

    public function __construct(
        private readonly CacheAdapterInterface $adapter,
    ) {}

    public function increment(string $name, int $amount = 1): void
    {
        $key = $this->counterKey($name);
        $current = $this->adapter->get($key);

        if ($current === false) {
            $this->adapter->set($key, $amount, self::COUNTER_TTL);
        } else {
            $this->adapter->set($key, (int)$current + $amount, self::COUNTER_TTL);
        }
    }

    /**
     * Get metrics for the current hour.
     *
     * @return array{hit: int, miss: int, stale_serve: int, stampede_prevented: int}
     */
    public function current(): array
    {
        return $this->forBucket(date('YmdH'));
    }

    /**
     * Get metrics across the last N hours.
     *
     * @param int $hours
     * @return array{hit: int, miss: int, stale_serve: int, stampede_prevented: int, hit_rate: float}
     */
    public function aggregate(int $hours = 24): array
    {
        $totals = ['hit' => 0, 'miss' => 0, 'stale_serve' => 0, 'stampede_prevented' => 0];

        for ($i = 0; $i < $hours; $i++) {
            $bucket = date('YmdH', strtotime("-{$i} hours"));
            $snapshot = $this->forBucket($bucket);
            foreach ($totals as $k => &$v) {
                $v += $snapshot[$k];
            }
        }

        $total = $totals['hit'] + $totals['miss'] + $totals['stale_serve'];
        $totals['hit_rate'] = $total > 0 ? round(($totals['hit'] + $totals['stale_serve']) / $total, 4) : 0.0;

        return $totals;
    }

    /**
     * @return array{hit: int, miss: int, stale_serve: int, stampede_prevented: int}
     */
    private function forBucket(string $bucket): array
    {
        $names = ['hit', 'miss', 'stale_serve', 'stampede_prevented'];
        $result = [];

        foreach ($names as $name) {
            $val = $this->adapter->get("metrics:{$name}:{$bucket}");
            $result[$name] = $val === false ? 0 : (int)$val;
        }

        return $result;
    }

    private function counterKey(string $name): string
    {
        return "metrics:{$name}:" . date('YmdH');
    }
}
