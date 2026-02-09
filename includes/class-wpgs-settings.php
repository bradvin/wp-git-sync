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
			'pat_token' => '',
			'github_owner' => '',
			'github_repo' => '',
			'branch' => 'main',
			'included_post_types' => [ 'post', 'page' ],
			'excluded_post_meta' => '',
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

		$out['github_owner'] = isset( $raw['github_owner'] ) ? sanitize_text_field( (string) $raw['github_owner'] ) : (string) ( $prev['github_owner'] ?? '' );
		$out['github_repo']  = isset( $raw['github_repo'] ) ? sanitize_text_field( (string) $raw['github_repo'] ) : (string) ( $prev['github_repo'] ?? '' );
		$out['branch']       = isset( $raw['branch'] ) ? sanitize_text_field( (string) $raw['branch'] ) : (string) ( $prev['branch'] ?? $out['branch'] );
		$available_post_types = self::available_post_type_options();
		$allowed_post_types = array_fill_keys( array_keys( $available_post_types ), true );
		$included_post_types = isset( $raw['included_post_types'] ) && is_array( $raw['included_post_types'] )
			? $raw['included_post_types']
			: [];
		$included = [];
		foreach ( $included_post_types as $post_type ) {
			$slug = sanitize_key( (string) $post_type );
			if ( '' !== $slug && isset( $allowed_post_types[ $slug ] ) ) {
				$included[] = $slug;
			}
		}
		$out['included_post_types'] = array_values( array_unique( $included ) );

		if ( isset( $raw['excluded_post_meta'] ) ) {
			$out['excluded_post_meta'] = self::sanitize_excluded_post_meta_input( (string) $raw['excluded_post_meta'] );
		} else {
			$out['excluded_post_meta'] = isset( $prev['excluded_post_meta'] )
				? self::sanitize_excluded_post_meta_input( (string) $prev['excluded_post_meta'] )
				: '';
		}

		if ( isset( $raw['github_repo_full'] ) ) {
			$repo_full = trim( (string) $raw['github_repo_full'] );
			if ( '' !== $repo_full && false !== strpos( $repo_full, '/' ) ) {
				[ $owner, $repo ] = explode( '/', $repo_full, 2 );
				$out['github_owner'] = sanitize_text_field( $owner );
				$out['github_repo']  = sanitize_text_field( $repo );
			}
		}

		// PAT token only updates if user re-enters it.
		if ( isset( $raw['pat_token'] ) && '' !== trim( (string) $raw['pat_token'] ) ) {
			$out['pat_token'] = sanitize_text_field( (string) $raw['pat_token'] );
		} else {
			$out['pat_token'] = isset( $prev['pat_token'] ) ? sanitize_text_field( (string) $prev['pat_token'] ) : '';
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
	 * Parse saved excluded-post-meta setting into a normalized list.
	 *
	 * @param array<string,mixed>|null $settings Optional settings payload.
	 * @return string[]
	 */
	public static function excluded_post_meta_keys( ?array $settings = null ): array {
		$settings = is_array( $settings ) ? array_merge( self::defaults(), $settings ) : self::get();
		$raw = isset( $settings['excluded_post_meta'] ) ? (string) $settings['excluded_post_meta'] : '';

		$keys = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			$key = sanitize_text_field( trim( (string) $line ) );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Return all selectable post types for settings UI.
	 *
	 * @return array<string,string> Map of post type slug => label.
	 */
	public static function available_post_type_options(): array {
		$post_types = get_post_types( [ 'show_ui' => true ], 'objects' );
		if ( ! is_array( $post_types ) ) {
			return [];
		}

		$options = [];
		foreach ( $post_types as $slug => $post_type ) {
			$key = sanitize_key( (string) $slug );
			if ( '' === $key ) {
				continue;
			}

			$label = $key;
			if ( is_object( $post_type ) && isset( $post_type->labels->name ) && '' !== trim( (string) $post_type->labels->name ) ) {
				$label = (string) $post_type->labels->name;
			}
			$options[ $key ] = $label;
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
		return $options;
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

	/**
	 * Sanitize textarea input for excluded post-meta keys.
	 *
	 * Stores one key per line for predictable editing and diffs.
	 *
	 * @param string $raw Raw textarea input.
	 * @return string
	 */
	private static function sanitize_excluded_post_meta_input( string $raw ): string {
		$keys = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
			$key = sanitize_text_field( trim( (string) $line ) );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return implode( "\n", array_values( array_unique( $keys ) ) );
	}
}
