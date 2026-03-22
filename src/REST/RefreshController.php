<?php
declare(strict_types=1);

namespace EdgeCache\REST;

use EdgeCache\BackgroundRefresh;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Internal endpoint hit by the non-blocking loopback request to
 * trigger background cache recomputation.
 */
final class RefreshController extends WP_REST_Controller
{
    protected $namespace = 'edge-cache/v1';
    protected $rest_base = 'refresh';

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_refresh'],
                'permission_callback' => '__return_true', // Auth via HMAC signature.
            ]
        );
    }

    public function handle_refresh(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $key = $request->get_param('key');
        $signature = $request->get_header('X-Edge-Cache-Sig');

        if (empty($key) || empty($signature)) {
            return new WP_Error('missing_params', 'Missing key or signature.', ['status' => 400]);
        }

        if (!BackgroundRefresh::verifySignature($key, $signature)) {
            return new WP_Error('invalid_signature', 'Request signature is invalid.', ['status' => 403]);
        }

        /**
         * Fires when a cache key needs background recomputation.
         *
         * Consumers should listen for this action and call SWRCache::set()
         * with the freshly computed value.
         *
         * @param string $key The cache key to refresh.
         */
        do_action('edge_cache_refresh', $key);

        return new WP_REST_Response(['refreshed' => $key], 200);
    }
}
