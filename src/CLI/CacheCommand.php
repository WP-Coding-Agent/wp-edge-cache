<?php
declare(strict_types=1);

namespace EdgeCache\CLI;

use EdgeCache\Metrics;
use EdgeCache\SWRCache;
use EdgeCache\TagStore;
use EdgeCache\WPObjectCacheAdapter;
use WP_CLI;

/**
 * Manage the Edge Cache layer.
 */
final class CacheCommand
{
    /**
     * Show cache hit/miss/stale statistics.
     *
     * ## OPTIONS
     *
     * [--hours=<hours>]
     * : Number of hours to aggregate. Default 24.
     *
     * ## EXAMPLES
     *
     *     wp edge-cache stats
     *     wp edge-cache stats --hours=1
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function stats(array $args, array $assoc_args): void
    {
        $hours = (int)($assoc_args['hours'] ?? 24);
        $adapter = new WPObjectCacheAdapter('edge_cache_metrics');
        $metrics = new Metrics($adapter);
        $data = $metrics->aggregate($hours);

        WP_CLI::log("Edge Cache stats (last {$hours}h):");
        WP_CLI::log("  Hits:               {$data['hit']}");
        WP_CLI::log("  Misses:             {$data['miss']}");
        WP_CLI::log("  Stale serves:       {$data['stale_serve']}");
        WP_CLI::log("  Stampedes avoided:  {$data['stampede_prevented']}");

        $rate = number_format($data['hit_rate'] * 100, 1);
        WP_CLI::success("Hit rate: {$rate}%");
    }

    /**
     * Invalidate all cache entries tagged with a given tag.
     *
     * ## OPTIONS
     *
     * <tag>...
     * : One or more tags to invalidate.
     *
     * ## EXAMPLES
     *
     *     wp edge-cache flush-tag post:42
     *     wp edge-cache flush-tag post:42 term:5
     *
     * @param array $args
     */
    public function flush_tag(array $args): void // phpcs:ignore -- WP_CLI naming.
    {
        $adapter = new WPObjectCacheAdapter();
        $tags = new TagStore($adapter);
        $tags->invalidate($args);

        WP_CLI::success('Invalidated tags: ' . implode(', ', $args));
    }

    /**
     * Pre-warm cache entries from a manifest file.
     *
     * The manifest is a JSON file where each entry has:
     *   { "key": "...", "url": "...", "ttl": 300 }
     *
     * The URL is fetched and the response body is cached.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the JSON manifest file.
     *
     * ## EXAMPLES
     *
     *     wp edge-cache warm /path/to/manifest.json
     *
     * @param array $args
     */
    public function warm(array $args): void
    {
        $file = $args[0];

        if (!file_exists($file)) {
            WP_CLI::error("Manifest file not found: {$file}");
        }

        $manifest = json_decode(file_get_contents($file), true);

        if (!is_array($manifest)) {
            WP_CLI::error('Invalid manifest format. Expected a JSON array.');
        }

        $adapter = new WPObjectCacheAdapter();
        $cache = new SWRCache($adapter);
        $warmed = 0;

        foreach ($manifest as $entry) {
            if (empty($entry['key']) || empty($entry['url'])) {
                WP_CLI::warning("Skipping invalid entry: " . wp_json_encode($entry));
                continue;
            }

            $response = wp_remote_get($entry['url'], ['timeout' => 10]);

            if (is_wp_error($response)) {
                WP_CLI::warning("Failed to fetch {$entry['url']}: {$response->get_error_message()}");
                continue;
            }

            $ttl = (int)($entry['ttl'] ?? 300);
            $cache->set($entry['key'], wp_remote_retrieve_body($response), $ttl);
            ++$warmed;
        }

        WP_CLI::success("Warmed {$warmed}/" . count($manifest) . " cache entries.");
    }

    /**
     * Show cache health summary.
     *
     * ## EXAMPLES
     *
     *     wp edge-cache health
     */
    public function health(): void
    {
        $adapter = new WPObjectCacheAdapter();

        // Test write/read cycle.
        $testKey = '__edge_cache_health_' . time();
        $adapter->set($testKey, 'ok', 10);
        $read = $adapter->get($testKey);
        $adapter->delete($testKey);

        if ($read !== 'ok') {
            WP_CLI::error('Cache backend read/write test FAILED.');
            return;
        }

        WP_CLI::log('Cache backend: OK (read/write verified)');

        $metrics = new Metrics(new WPObjectCacheAdapter('edge_cache_metrics'));
        $data = $metrics->aggregate(1);
        $total = $data['hit'] + $data['miss'] + $data['stale_serve'];

        if ($total === 0) {
            WP_CLI::log('No traffic in the last hour.');
        } else {
            $rate = number_format($data['hit_rate'] * 100, 1);
            WP_CLI::log("Last hour: {$total} requests, {$rate}% hit rate");
        }

        WP_CLI::success('Edge Cache is healthy.');
    }
}
