<?php
/**
 * Plugin Name: WP Git Sync
 * Description: Sync WordPress post content + meta to a Git branch.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Foo Bender
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPGS_VERSION', '0.1.0' );
define( 'WPGS_PLUGIN_FILE', __FILE__ );
define( 'WPGS_PLUGIN_DIR', __DIR__ );

require_once WPGS_PLUGIN_DIR . '/includes/class-wpgs-plugin.php';

add_action( 'plugins_loaded', static function () {
	WPGS_Plugin::instance();
} );
