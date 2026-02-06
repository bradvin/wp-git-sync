<?php
/**
 * Plugin Name: WP Git Sync
 * Description: Sync WordPress post content + meta to a Git branch.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Foo Bender
 * License: GPLv2 or later
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
define( 'WPGS_VERSION', '0.1.0' );

/**
 * Absolute path to main plugin file.
 */
define( 'WPGS_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to plugin directory.
 */
define( 'WPGS_PLUGIN_DIR', __DIR__ );

require_once WPGS_PLUGIN_DIR . '/includes/class-wpgs-plugin.php';

/**
 * Initialize the plugin.
 *
 * Side effects:
 * - Registers WordPress hooks (admin settings, pages, actions).
 *
 * @return void
 */
add_action( 'plugins_loaded', static function (): void {
	WPGS_Plugin::instance();
} );
