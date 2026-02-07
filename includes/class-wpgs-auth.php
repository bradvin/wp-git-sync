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
		if ( defined( 'WPGS_GITHUB_PAT' ) && is_string( WPGS_GITHUB_PAT ) && '' !== trim( WPGS_GITHUB_PAT ) ) {
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
