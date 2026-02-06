<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Settings {
	public const OPTION_KEY = 'wpgs_settings';

	public static function register(): void {
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

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

	public static function defaults(): array {
		return [
			// Accepts either "owner/repo" or a GitHub URL/SSH remote; we normalize on use.
			'repo'       => '',
			'branch'     => 'wp-content-sync',
			'auth_method'=> 'pat', // pat|oauth
			'pat_token'  => '',
			// OAuth (v0 scaffold).
			'oauth_access_token' => '',
		];
	}

	public static function get(): array {
		$val = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $val ) ) {
			$val = [];
		}
		return array_merge( self::defaults(), $val );
	}

	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];

		$out = self::defaults();

		$out['repo']        = isset( $raw['repo'] ) ? sanitize_text_field( (string) $raw['repo'] ) : '';
		$out['branch']      = isset( $raw['branch'] ) ? sanitize_text_field( (string) $raw['branch'] ) : $out['branch'];
		$out['auth_method'] = isset( $raw['auth_method'] ) ? sanitize_key( (string) $raw['auth_method'] ) : $out['auth_method'];

		// PAT: only update if user explicitly re-enters a non-empty value.
		if ( isset( $raw['pat_token'] ) && '' !== trim( (string) $raw['pat_token'] ) ) {
			$out['pat_token'] = sanitize_text_field( (string) $raw['pat_token'] );
		} else {
			$prev             = get_option( self::OPTION_KEY, [] );
			$out['pat_token'] = is_array( $prev ) && isset( $prev['pat_token'] ) ? sanitize_text_field( (string) $prev['pat_token'] ) : '';
		}

		// OAuth access token (v0 scaffold): keep existing unless replaced.
		if ( isset( $raw['oauth_access_token'] ) && '' !== trim( (string) $raw['oauth_access_token'] ) ) {
			$out['oauth_access_token'] = sanitize_text_field( (string) $raw['oauth_access_token'] );
		} else {
			$prev                       = get_option( self::OPTION_KEY, [] );
			$out['oauth_access_token'] = is_array( $prev ) && isset( $prev['oauth_access_token'] ) ? sanitize_text_field( (string) $prev['oauth_access_token'] ) : '';
		}

		if ( ! in_array( $out['auth_method'], [ 'pat', 'oauth' ], true ) ) {
			$out['auth_method'] = 'pat';
		}

		return $out;
	}
}
