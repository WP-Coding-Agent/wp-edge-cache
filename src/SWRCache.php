<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Stale-While-Revalidate cache with XFetch probabilistic early expiration.
 *
 * This is a pure PHP class with zero WordPress dependencies so it can be
 * unit-tested without a WP bootstrap.
 *
 * How it works:
 *  - Every cached value is wrapped in an envelope: {data, created_at, ttl, delta}
 *  - On read, if the entry is within TTL, XFetch decides probabilistically
 *    whether to trigger early recomputation (preventing stampedes at expiry)
 *  - If the entry is past TTL but within the stale window, the stale value is
 *    returned immediately and a background refresh is triggered via a callback
 */
final class SWRCache
{
    /** @var callable(string, callable): void  Triggers async refresh */
    private $refreshTrigger;

    private Metrics $metrics;

    public function __construct(
        private readonly CacheAdapterInterface $adapter,
        private readonly float $beta = 1.0,
        private readonly int $staleWindow = 300,
        ?callable $refreshTrigger = null,
        ?Metrics $metrics = null,
    ) {
        $this->refreshTrigger = $refreshTrigger ?? static fn() => null;
        $this->metrics = $metrics ?? new Metrics(new NullAdapter());
    }

    /**
     * Fetch with SWR semantics.
     *
     * @param string   $key      Cache key.
     * @param callable $compute  fn(): mixed — recompute callback.
     * @param int      $ttl      Fresh TTL in seconds.
     * @param float    $delta    Estimated recomputation time in seconds.
     * @return mixed
     */
    public function get(string $key, callable $compute, int $ttl = 300, float $delta = 1.0): mixed
    {
        $envelope = $this->adapter->get($this->envelopeKey($key));

        if ($envelope === false || !is_array($envelope) || !isset($envelope['data'])) {
            // Cold miss — compute synchronously.
            $this->metrics->increment('miss');
            return $this->recompute($key, $compute, $ttl, $delta);
        }

        $age = time() - $envelope['created_at'];
        $entryTtl = $envelope['ttl'];
        $entryDelta = $envelope['delta'];

        if ($age < $entryTtl) {
            // Within TTL — check XFetch for probabilistic early recomputation.
            if ($this->shouldRecompute($age, $entryTtl, $entryDelta)) {
                $this->metrics->increment('stampede_prevented');
                $this->triggerRefresh($key);
            }
            $this->metrics->increment('hit');
            return $envelope['data'];
        }

        if ($age < $entryTtl + $this->staleWindow) {
            // Stale but within window — serve stale, refresh in background.
            $this->metrics->increment('stale_serve');
            $this->triggerRefresh($key);
            return $envelope['data'];
        }

        // Beyond stale window — treat as miss.
        $this->metrics->increment('miss');
        return $this->recompute($key, $compute, $ttl, $delta);
    }

    /**
     * Store a value with SWR envelope.
     */
    public function set(string $key, mixed $value, int $ttl = 300, float $delta = 1.0): void
    {
        $envelope = [
            'data'       => $value,
            'created_at' => time(),
            'ttl'        => $ttl,
            'delta'      => $delta,
        ];

        // Store for TTL + stale window so stale reads are possible.
        $this->adapter->set($this->envelopeKey($key), $envelope, $ttl + $this->staleWindow + 60);
    }

    /**
     * Invalidate a single key.
     */
    public function delete(string $key): void
    {
        $this->adapter->delete($this->envelopeKey($key));
    }

    /**
     * Acquire a recomputation lock to prevent stampedes.
     * Returns true if this caller won the lock.
     */
    public function acquireLock(string $key, int $ttl = 30): bool
    {
        return $this->adapter->add($this->lockKey($key), time(), $ttl);
    }

    public function releaseLock(string $key): void
    {
        $this->adapter->delete($this->lockKey($key));
    }

    /**
     * XFetch algorithm: probabilistic early recomputation.
     *
     * Returns true when the item should be recomputed early to avoid
     * a thundering-herd at exact expiry time.
     *
     * Formula: now - (delta * beta * ln(random())) >= expiry
     */
    private function shouldRecompute(int $age, int $ttl, float $delta): bool
    {
        $remaining = $ttl - $age;
        $xfetch = $delta * $this->beta * (-1 * log(random_int(1, PHP_INT_MAX) / PHP_INT_MAX));

        return $xfetch >= $remaining;
    }

    private function recompute(string $key, callable $compute, int $ttl, float $delta): mixed
    {
        if (!$this->acquireLock($key)) {
            // Another process is recomputing; return stale if available.
            $envelope = $this->adapter->get($this->envelopeKey($key));
            if ($envelope !== false && is_array($envelope) && isset($envelope['data'])) {
                return $envelope['data'];
            }
        }

        try {
            $start = hrtime(true);
            $value = $compute();
            $measuredDelta = (hrtime(true) - $start) / 1e9;

            $this->set($key, $value, $ttl, max($delta, $measuredDelta));
            return $value;
        } finally {
            $this->releaseLock($key);
        }
    }

    private function triggerRefresh(string $key): void
    {
        ($this->refreshTrigger)($key);
    }

    private function envelopeKey(string $key): string
    {
        return "swr:{$key}";
    }

    private function lockKey(string $key): string
    {
        return "swr_lock:{$key}";
    }
}
