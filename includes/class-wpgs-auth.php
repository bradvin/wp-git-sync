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
 * Resolves the GitHub access token based on settings.
 */
final class WPGS_Auth {
	/**
	 * Resolve a GitHub access token.
	 *
	 * Security notes:
	 * - If PAT storage is wp-config, token is read from the constant and never
	 *   stored/echoed.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string Access token.
	 * @throws RuntimeException When no usable token is configured.
	 */
	public static function get_token( array $settings ): string {
		$mode = isset( $settings['auth_mode'] ) ? (string) $settings['auth_mode'] : 'pat';

		if ( 'device_oauth' === $mode ) {
			$token = isset( $settings['device_token'] ) ? (string) $settings['device_token'] : '';
			$token = trim( $token );
			if ( '' === $token ) {
				throw new RuntimeException( 'Not connected to GitHub. Please connect via Device Flow OAuth.' );
			}
			return $token;
		}

		// PAT mode.
		$storage = isset( $settings['pat_storage'] ) ? (string) $settings['pat_storage'] : 'wp_config';
		if ( 'wp_config' === $storage ) {
			if ( defined( 'WPGS_GITHUB_PAT' ) && is_string( WPGS_GITHUB_PAT ) && '' !== trim( WPGS_GITHUB_PAT ) ) {
				return trim( (string) WPGS_GITHUB_PAT );
			}
			throw new RuntimeException( 'WPGS_GITHUB_PAT is not defined in wp-config.php.' );
		}

		$token = isset( $settings['pat_token'] ) ? (string) $settings['pat_token'] : '';
		$token = trim( $token );
		if ( '' === $token ) {
			throw new RuntimeException( 'PAT token is not configured.' );
		}
		return $token;
	}
}
