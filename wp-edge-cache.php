<?php
declare(strict_types=1);
/**
 * Plugin Name:  WP Edge Cache
 * Description:  Stale-while-revalidate object cache layer with XFetch stampede protection and tag-based invalidation.
 * Version:      1.0.0
 * Requires PHP: 8.0
 * License:      GPL-2.0-or-later
 *
 * @package EdgeCache
 */

defined( 'ABSPATH' ) || exit;

define( 'EDGE_CACHE_VERSION', '1.0.0' );
define( 'EDGE_CACHE_DIR', plugin_dir_path( __FILE__ ) );

require_once EDGE_CACHE_DIR . 'vendor/autoload.php';

add_action( 'rest_api_init', static function (): void {
	( new EdgeCache\REST\MetricsController() )->register_routes();
	( new EdgeCache\REST\RefreshController() )->register_routes();
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'edge-cache', EdgeCache\CLI\CacheCommand::class );
}
