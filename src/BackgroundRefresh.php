<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Triggers non-blocking background cache refresh via loopback HTTP request.
 *
 * When SWRCache serves a stale entry, it calls this to schedule a background
 * recomputation without blocking the current request.
 */
final class BackgroundRefresh
{
    /**
     * Fire a non-blocking POST to the refresh endpoint.
     *
     * @param string $key     Cache key to refresh.
     * @param string $secret  Shared secret for request authentication.
     */
    public static function trigger(string $key, string $secret = ''): void
    {
        if (empty($secret)) {
            $secret = self::getSecret();
        }

        $url = rest_url('edge-cache/v1/refresh');

        $signature = hash_hmac('sha256', $key, $secret);

        wp_remote_post(
            $url,
            [
                'timeout'   => 0.01, // Non-blocking — fire and forget.
                'blocking'  => false,
                'sslverify' => apply_filters('edge_cache_ssl_verify', false),
                'headers'   => [
                    'Content-Type'         => 'application/json',
                    'X-Edge-Cache-Sig'     => $signature,
                ],
                'body'      => wp_json_encode(['key' => $key]),
            ]
        );
    }

    /**
     * Verify an incoming refresh request.
     */
    public static function verifySignature(string $key, string $signature): bool
    {
        $expected = hash_hmac('sha256', $key, self::getSecret());
        return hash_equals($expected, $signature);
    }

    private static function getSecret(): string
    {
        if (defined('EDGE_CACHE_SECRET')) {
            return EDGE_CACHE_SECRET;
        }

        // Fall back to a site-specific key derived from auth salts.
        return defined('AUTH_SALT') ? AUTH_SALT : 'edge-cache-default-secret';
    }
}
