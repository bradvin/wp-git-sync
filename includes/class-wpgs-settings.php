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
			'repo_url'         => '',
			'branch'           => 'wp-content-sync',
			'auth_method'      => 'ssh', // ssh|https
			'ssh_key_path'     => '',
			'https_token'      => '',
			'local_clone_path' => WP_CONTENT_DIR . '/wpgs-repo',
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

		$out['repo_url']         = isset( $raw['repo_url'] ) ? esc_url_raw( $raw['repo_url'] ) : '';
		$out['branch']           = isset( $raw['branch'] ) ? sanitize_text_field( $raw['branch'] ) : $out['branch'];
		$out['auth_method']      = isset( $raw['auth_method'] ) ? sanitize_key( $raw['auth_method'] ) : $out['auth_method'];
		$out['ssh_key_path']     = isset( $raw['ssh_key_path'] ) ? sanitize_text_field( $raw['ssh_key_path'] ) : '';
		// Token: only update if user explicitly re-enters a non-empty value.
		if ( isset( $raw['https_token'] ) && '' !== trim( (string) $raw['https_token'] ) ) {
			$out['https_token'] = sanitize_text_field( $raw['https_token'] );
		} else {
			$prev              = get_option( self::OPTION_KEY, [] );
			$out['https_token'] = is_array( $prev ) && isset( $prev['https_token'] ) ? sanitize_text_field( (string) $prev['https_token'] ) : '';
		}
		$out['local_clone_path'] = isset( $raw['local_clone_path'] ) ? untrailingslashit( sanitize_text_field( $raw['local_clone_path'] ) ) : $out['local_clone_path'];

		if ( ! in_array( $out['auth_method'], [ 'ssh', 'https' ], true ) ) {
			$out['auth_method'] = 'ssh';
		}

		return $out;
	}
}
