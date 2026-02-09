<?php
/**
 * Auth/token resolution.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the GitHub PAT based on settings.
 */
final class WPGS_Auth {
	/**
	 * Whether a non-empty PAT is defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function has_wp_config_token(): bool {
		return defined( 'WPGS_GITHUB_PAT' ) && is_string( WPGS_GITHUB_PAT ) && '' !== trim( WPGS_GITHUB_PAT );
	}

	/**
	 * Whether a non-empty PAT exists in plugin options.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return bool
	 */
	public static function has_option_token( array $settings ): bool {
		$token = isset( $settings['pat_token'] ) ? trim( (string) $settings['pat_token'] ) : '';
		return '' !== $token;
	}

	/**
	 * Resolve where the active PAT is sourced from.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string One of: wp_config, options, none.
	 */
	public static function token_source( array $settings ): string {
		if ( self::has_wp_config_token() ) {
			return 'wp_config';
		}
		if ( self::has_option_token( $settings ) ) {
			return 'options';
		}
		return 'none';
	}

	/**
	 * Resolve a GitHub PAT token.
	 *
	 * Security notes:
	 * - If defined in wp-config.php, token is read from the constant and never
	 *   stored/echoed.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string Access token.
	 * @throws RuntimeException When no usable token is configured.
	 */
	public static function get_token( array $settings ): string {
		if ( self::has_wp_config_token() ) {
			return trim( (string) WPGS_GITHUB_PAT );
		}

		$token = isset( $settings['pat_token'] ) ? (string) $settings['pat_token'] : '';
		$token = trim( $token );
		if ( '' === $token ) {
			throw new RuntimeException( 'PAT token is not configured. Set WPGS_GITHUB_PAT in wp-config.php or save a PAT token in WP Git Sync settings.' );
		}
		return $token;
	}
}
