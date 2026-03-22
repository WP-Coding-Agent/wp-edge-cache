# WP Edge Cache

Stale-while-revalidate object cache layer for WordPress with XFetch stampede protection and tag-based invalidation.

## The Problem

WordPress object caching is all-or-nothing: either you have a cached value or you block while recomputing it. On high-traffic sites this causes:

- **Cache stampedes** — when a popular cache entry expires, hundreds of concurrent requests all try to regenerate it simultaneously
- **Cold-start latency spikes** — users waiting on expensive queries while the cache rebuilds
- **Blunt invalidation** — `wp_cache_flush()` nukes everything; there's no way to invalidate "all caches related to post 42"

## How It Works

### Stale-While-Revalidate
When a cache entry expires, the stale value is served instantly while a non-blocking loopback request triggers background recomputation. Users never wait for cache rebuilds.

### XFetch Stampede Protection
Before an entry even expires, the [XFetch algorithm](https://cseweb.ucsd.edu/~avattani/papers/cache_stampede.pdf) probabilistically triggers early recomputation. The formula `delta * beta * ln(random())` ensures that exactly one request out of many will start rebuilding the cache slightly before expiry — preventing the thundering herd.

### Tag-Based Invalidation
Cache entries can be associated with tags (e.g., `post:42`, `term:5`). Invalidating a tag uses a generation counter approach — no need to enumerate and delete individual keys. When you invalidate `post:42`, all entries tagged with it become effectively stale on next read.

## Installation

```bash
composer require wp-coding-agent/wp-edge-cache
```

Or copy the plugin directory to `wp-content/plugins/` and run `composer install` within it.

Optionally define a secret for the refresh endpoint in `wp-config.php`:
```php
define('EDGE_CACHE_SECRET', 'your-random-secret-here');
```

## Usage

```php
use EdgeCache\SWRCache;
use EdgeCache\TagStore;
use EdgeCache\WPObjectCacheAdapter;
use EdgeCache\BackgroundRefresh;

$adapter = new WPObjectCacheAdapter();
$tags    = new TagStore($adapter);

$cache = new SWRCache(
    adapter:        $adapter,
    beta:           1.0,          // XFetch aggressiveness (1.0 = standard).
    staleWindow:    300,          // Serve stale for up to 5 minutes.
    refreshTrigger: [BackgroundRefresh::class, 'trigger'],
);

// Fetch with SWR — the callback only runs on a true miss.
$posts = $cache->get(
    key:     'recent_posts',
    compute: fn() => get_posts(['numberposts' => 10]),
    ttl:     600,    // Fresh for 10 minutes.
    delta:   0.5,    // Estimated compute time in seconds.
);

// Tag-based invalidation — when post 42 is updated:
$tags->invalidate(['post:42', 'query:recent']);
```

## WP-CLI Commands

```bash
wp edge-cache stats                  # Hit/miss/stale rates (last 24h)
wp edge-cache stats --hours=1        # Last hour only
wp edge-cache flush-tag post:42      # Invalidate by tag
wp edge-cache warm manifest.json     # Pre-warm from manifest file
wp edge-cache health                 # Backend connectivity + rate check
```

## REST API

```
GET /wp-json/edge-cache/v1/metrics?hours=24
```
Requires `manage_options` capability. Returns hit/miss/stale/stampede counts and hit rate.

## Architecture

| File | Purpose |
|------|---------|
| `src/SWRCache.php` | Core SWR + XFetch logic (pure PHP, no WP deps) |
| `src/TagStore.php` | Generation-based tag invalidation (pure PHP) |
| `src/Metrics.php` | Hourly-bucketed atomic counters |
| `src/BackgroundRefresh.php` | Non-blocking loopback refresh trigger |
| `src/WPObjectCacheAdapter.php` | Bridges `wp_cache_*` to the adapter interface |
| `src/CacheAdapterInterface.php` | Backend abstraction |

## License

GPL-2.0-or-later
