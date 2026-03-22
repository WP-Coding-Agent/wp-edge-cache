<?php
declare(strict_types=1);

namespace EdgeCache\REST;

use EdgeCache\Metrics;
use EdgeCache\WPObjectCacheAdapter;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class MetricsController extends WP_REST_Controller
{
    protected $namespace = 'edge-cache/v1';
    protected $rest_base = 'metrics';

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_metrics'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => [
                    'hours' => [
                        'type'              => 'integer',
                        'default'           => 24,
                        'minimum'           => 1,
                        'maximum'           => 168,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function get_metrics(WP_REST_Request $request): WP_REST_Response
    {
        $adapter = new WPObjectCacheAdapter('edge_cache_metrics');
        $metrics = new Metrics($adapter);

        return new WP_REST_Response($metrics->aggregate($request->get_param('hours')));
    }

    public function check_permissions(): bool
    {
        return current_user_can('manage_options');
    }
}
