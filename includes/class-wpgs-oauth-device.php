<?php
/**
 * GitHub OAuth Device Flow helper.
 *
 * Implements the device authorization flow:
 * - POST https://github.com/login/device/code
 * - Poll POST https://github.com/login/oauth/access_token
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub OAuth Device Flow.
 *
 * Side effects:
 * - Performs outbound HTTPS requests to github.com.
 *
 * Security notes:
 * - Requires a GitHub OAuth App client ID.
 * - Stores device_code and access tokens in wp_options via WPGS_Settings.
 */
final class WPGS_OAuth_Device {
	/**
	 * Start the device flow.
	 *
	 * @param string $client_id OAuth app client id.
	 * @param string $scope OAuth scope string.
	 * @return array{device_code:string,user_code:string,verification_uri:string,verification_uri_complete:string,expires_in:int,interval:int}
	 * @throws RuntimeException On network/API errors.
	 */
	public static function start( string $client_id, string $scope ): array {
		$client_id = trim( $client_id );
		if ( '' === $client_id ) {
			throw new RuntimeException( 'Missing GitHub OAuth client_id.' );
		}

		$res = wp_remote_post( 'https://github.com/login/device/code', [
			'timeout' => 20,
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body'    => [
				'client_id' => $client_id,
				'scope'     => $scope,
			],
		] );

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description'] : 'Device flow start failed.';
			throw new RuntimeException( sprintf( 'GitHub device flow start failed (%d): %s', $code, $msg ) );
		}

		return [
			'device_code'               => (string) ( $data['device_code'] ?? '' ),
			'user_code'                 => (string) ( $data['user_code'] ?? '' ),
			'verification_uri'          => (string) ( $data['verification_uri'] ?? '' ),
			'verification_uri_complete' => (string) ( $data['verification_uri_complete'] ?? '' ),
			'expires_in'                => (int) ( $data['expires_in'] ?? 0 ),
			'interval'                  => (int) ( $data['interval'] ?? 5 ),
		];
	}

	/**
	 * Poll GitHub to exchange a device_code for an access token.
	 *
	 * @param string $client_id OAuth app client id.
	 * @param string $device_code Device code.
	 * @return array{access_token:string,token_type:string,scope:string,refresh_token?:string,expires_in?:int,refresh_token_expires_in?:int}
	 * @throws RuntimeException When authorization is denied or on API errors.
	 */
	public static function poll( string $client_id, string $device_code ): array {
		$client_id   = trim( $client_id );
		$device_code = trim( $device_code );
		if ( '' === $client_id || '' === $device_code ) {
			throw new RuntimeException( 'Missing client_id or device_code.' );
		}

		$res = wp_remote_post( 'https://github.com/login/oauth/access_token', [
			'timeout' => 20,
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body'    => [
				'client_id'  => $client_id,
				'device_code'=> $device_code,
				'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
			],
		] );

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description'] : 'Token poll failed.';
			throw new RuntimeException( sprintf( 'GitHub device flow poll failed (%d): %s', $code, $msg ) );
		}

		if ( isset( $data['error'] ) && $data['error'] ) {
			// Common: authorization_pending, slow_down, expired_token, access_denied.
			throw new RuntimeException( (string) $data['error'] );
		}

		return [
			'access_token'             => (string) ( $data['access_token'] ?? '' ),
			'token_type'               => (string) ( $data['token_type'] ?? '' ),
			'scope'                    => (string) ( $data['scope'] ?? '' ),
			'refresh_token'            => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : null,
			'expires_in'               => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : null,
			'refresh_token_expires_in' => isset( $data['refresh_token_expires_in'] ) ? (int) $data['refresh_token_expires_in'] : null,
		];
	}
}
