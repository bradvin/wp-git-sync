<?php
/**
 * Settings storage and sanitization.
 *
 * NOTE: This file currently reflects the "git shell" scaffold.
 * Brad's direction is to pivot to GitHub API auth (Device Flow OAuth + PAT).
 * We keep README in sync with current behavior and document planned changes.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin settings in wp_options.
 *
 * Security notes:
 * - Tokens stored in wp_options can be read by admins with DB access.
 * - Prefer wp-config constants / environment variables for production.
 */
final class WPGS_Settings {
	/**
	 * Option key used in wp_options.
	 */
	public const OPTION_KEY = 'wpgs_settings';

	/**
	 * Register settings hooks.
	 *
	 * Side effects:
	 * - Adds admin_init hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	/**
	 * Register the settings group and sanitization callback.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'wpgs',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'repo_url'         => '',
			'branch'           => 'wp-content-sync',
			'auth_method'      => 'ssh', // ssh|https
			'ssh_key_path'     => '',
			'https_token'      => '',
			'local_clone_path' => WP_CONTENT_DIR . '/wpgs-repo',
		];
	}

	/**
	 * Get current settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$val = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $val ) ) {
			$val = [];
		}
		return array_merge( self::defaults(), $val );
	}

	/**
	 * Sanitize settings input.
	 *
	 * Security notes:
	 * - The HTTPS token is only updated when a non-empty value is provided.
	 * - This prevents accidentally wiping the token on every settings save.
	 *
	 * @param mixed $raw Raw option value from the settings form.
	 * @return array<string,mixed> Sanitized settings.
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];

		$out = self::defaults();

		$out['repo_url']     = isset( $raw['repo_url'] ) ? esc_url_raw( (string) $raw['repo_url'] ) : '';
		$out['branch']       = isset( $raw['branch'] ) ? sanitize_text_field( (string) $raw['branch'] ) : $out['branch'];
		$out['auth_method']  = isset( $raw['auth_method'] ) ? sanitize_key( (string) $raw['auth_method'] ) : $out['auth_method'];
		$out['ssh_key_path'] = isset( $raw['ssh_key_path'] ) ? sanitize_text_field( (string) $raw['ssh_key_path'] ) : '';

		// Token: only update if user explicitly re-enters a non-empty value.
		if ( isset( $raw['https_token'] ) && '' !== trim( (string) $raw['https_token'] ) ) {
			$out['https_token'] = sanitize_text_field( (string) $raw['https_token'] );
		} else {
			$prev               = get_option( self::OPTION_KEY, [] );
			$out['https_token'] = is_array( $prev ) && isset( $prev['https_token'] ) ? sanitize_text_field( (string) $prev['https_token'] ) : '';
		}

		$out['local_clone_path'] = isset( $raw['local_clone_path'] )
			? untrailingslashit( sanitize_text_field( (string) $raw['local_clone_path'] ) )
			: $out['local_clone_path'];

		if ( ! in_array( $out['auth_method'], [ 'ssh', 'https' ], true ) ) {
			$out['auth_method'] = 'ssh';
		}

		return $out;
	}
}
