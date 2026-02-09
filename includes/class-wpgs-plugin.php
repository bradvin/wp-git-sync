<?php
/**
 * Main plugin bootstrap.
 *
 * Loads required class files and registers the plugin's WordPress hooks.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wpgs-settings.php';
require_once __DIR__ . '/class-wpgs-auth.php';
require_once __DIR__ . '/class-wpgs-paths.php';
require_once __DIR__ . '/class-wpgs-sync-meta.php';
require_once __DIR__ . '/class-wpgs-diff.php';
require_once __DIR__ . '/class-wpgs-github-client.php';
require_once __DIR__ . '/class-wpgs-github-provider.php';
require_once __DIR__ . '/class-wpgs-exporter.php';
require_once WPGS_PLUGIN_DIR . '/admin/class-wpgs-admin-page-main.php';
require_once WPGS_PLUGIN_DIR . '/admin/class-wpgs-admin-page-diff.php';
require_once WPGS_PLUGIN_DIR . '/admin/class-wpgs-admin-page-settings.php';
require_once WPGS_PLUGIN_DIR . '/admin/class-wpgs-admin-metabox.php';
require_once __DIR__ . '/class-wpgs-admin.php';

/**
 * Plugin singleton.
 *
 * Side effects:
 * - Registers admin settings.
 * - Registers admin pages and post metabox.
 */
final class WPGS_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Private because this is a singleton.
	 *
	 * Side effects:
	 * - Hooks settings + admin UI into WordPress.
	 */
	private function __construct() {
		WPGS_Settings::register();
		WPGS_Admin::register();
	}
}
