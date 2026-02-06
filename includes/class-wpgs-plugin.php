<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wpgs-settings.php';
require_once __DIR__ . '/class-wpgs-paths.php';
require_once __DIR__ . '/class-wpgs-git.php';
require_once __DIR__ . '/class-wpgs-exporter.php';
require_once __DIR__ . '/class-wpgs-admin.php';

final class WPGS_Plugin {
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		WPGS_Settings::register();
		WPGS_Admin::register();
	}
}
