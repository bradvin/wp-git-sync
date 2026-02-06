<?php
/**
 * Settings storage and sanitization.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP Git Sync settings in wp_options.
 *
 * Security notes:
 * - Access tokens stored in wp_options can be read by anyone with DB access.
 * - Prefer wp-config constants for PAT where possible.
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
			'auth_mode' => 'pat', // device_oauth|pat
			'pat_storage' => 'wp_config', // options|wp_config
			'pat_token' => '', // only used when pat_storage=options

			// Device OAuth token storage.
			'device_token' => '',
			'device_refresh_token' => '',
			'token_expires_at' => '',
			'refresh_expires_at' => '',
			'device_code' => '',
			'user_code' => '',
			'verification_uri' => '',
			'verification_uri_complete' => '',
			'device_code_expires_at' => '',
			'device_poll_interval' => 5,

			'github_owner' => '',
			'github_repo' => '',
			'branch' => 'wp-content-sync',
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
	 * Migration notes:
	 * - If old keys `repo_url` contain a GitHub URL/SSH remote, we attempt to
	 *   parse and migrate into github_owner/github_repo.
	 * - Old keys related to local clones are dropped; the plugin is now API-only.
	 *
	 * Security notes:
	 * - Tokens are only updated when a non-empty value is submitted.
	 *
	 * @param mixed $raw Raw option value from the settings form.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$prev = get_option( self::OPTION_KEY, [] );
		$prev = is_array( $prev ) ? $prev : [];

		$out = self::defaults();

		$out['auth_mode']   = isset( $raw['auth_mode'] ) ? sanitize_key( (string) $raw['auth_mode'] ) : (string) ( $prev['auth_mode'] ?? $out['auth_mode'] );
		$out['pat_storage'] = isset( $raw['pat_storage'] ) ? sanitize_key( (string) $raw['pat_storage'] ) : (string) ( $prev['pat_storage'] ?? $out['pat_storage'] );

		$out['github_owner'] = isset( $raw['github_owner'] ) ? sanitize_text_field( (string) $raw['github_owner'] ) : (string) ( $prev['github_owner'] ?? '' );
		$out['github_repo']  = isset( $raw['github_repo'] ) ? sanitize_text_field( (string) $raw['github_repo'] ) : (string) ( $prev['github_repo'] ?? '' );
		$out['branch']       = isset( $raw['branch'] ) ? sanitize_text_field( (string) $raw['branch'] ) : (string) ( $prev['branch'] ?? $out['branch'] );

		// PAT token only updates if user re-enters it.
		if ( isset( $raw['pat_token'] ) && '' !== trim( (string) $raw['pat_token'] ) ) {
			$out['pat_token'] = sanitize_text_field( (string) $raw['pat_token'] );
		} else {
			$out['pat_token'] = isset( $prev['pat_token'] ) ? sanitize_text_field( (string) $prev['pat_token'] ) : '';
		}

		// Device OAuth tokens: only update if user re-enters (normally set via connect flow).
		foreach ( [
			'device_token',
			'device_refresh_token',
			'token_expires_at',
			'refresh_expires_at',
			'device_code',
			'user_code',
			'verification_uri',
			'verification_uri_complete',
			'device_code_expires_at',
		] as $k ) {
			if ( isset( $raw[ $k ] ) && '' !== trim( (string) $raw[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $raw[ $k ] );
			} else {
				$out[ $k ] = isset( $prev[ $k ] ) ? sanitize_text_field( (string) $prev[ $k ] ) : $out[ $k ];
			}
		}

		$out['device_poll_interval'] = isset( $raw['device_poll_interval'] ) ? (int) $raw['device_poll_interval'] : (int) ( $prev['device_poll_interval'] ?? $out['device_poll_interval'] );

		if ( ! in_array( $out['auth_mode'], [ 'device_oauth', 'pat' ], true ) ) {
			$out['auth_mode'] = 'pat';
		}
		if ( ! in_array( $out['pat_storage'], [ 'options', 'wp_config' ], true ) ) {
			$out['pat_storage'] = 'wp_config';
		}

		// One-time best-effort migration from old repo_url.
		if ( '' === $out['github_owner'] && '' === $out['github_repo'] && isset( $raw['repo_url'] ) ) {
			[ $owner, $repo ] = self::parse_github_owner_repo( (string) $raw['repo_url'] );
			if ( $owner && $repo ) {
				$out['github_owner'] = $owner;
				$out['github_repo']  = $repo;
			}
		}

		return $out;
	}

	/**
	 * Parse owner/repo from a GitHub URL or SSH remote.
	 *
	 * @param string $repo_url Repo URL.
	 * @return array{0:string,1:string} owner, repo
	 */
	public static function parse_github_owner_repo( string $repo_url ): array {
		$repo_url = trim( $repo_url );
		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repo_url, $m ) ) {
			return [ (string) $m[1], (string) $m[2] ];
		}
		if ( preg_match( '#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $repo_url, $m ) ) {
			return [ (string) $m[1], (string) $m[2] ];
		}
		return [ '', '' ];
	}
}
