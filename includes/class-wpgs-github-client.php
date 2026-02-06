<?php
/**
 * GitHub HTTP client wrapper.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low-level GitHub API client.
 *
 * Side effects:
 * - Performs outbound HTTP requests using wp_remote_request().
 *
 * Security notes:
 * - The token is sent as an Authorization Bearer token to api.github.com.
 */
final class WPGS_GitHub_Client {
	/**
	 * OAuth/PAT token.
	 */
	private string $token;

	/**
	 * @param string $token OAuth/PAT token.
	 * @throws InvalidArgumentException If token is empty.
	 */
	public function __construct( string $token ) {
		$this->token = trim( $token );
		if ( '' === $this->token ) {
			throw new InvalidArgumentException( 'GitHub token is missing.' );
		}
	}

	/**
	 * Perform a GitHub API request.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $url Full URL.
	 * @param array<string,mixed>|null $body JSON body.
	 * @return array<string,mixed> Decoded JSON response.
	 * @throws RuntimeException On network errors or non-2xx responses.
	 */
	public function request( string $method, string $url, ?array $body = null ): array {
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => [
				'Accept'              => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'          => 'WP-Git-Sync/' . ( defined( 'WPGS_VERSION' ) ? WPGS_VERSION : 'dev' ),
				'Authorization'       => 'Bearer ' . $this->token,
			],
		];

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                   = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );

		$data = [];
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : 'GitHub API error.';
			throw new RuntimeException( sprintf( 'GitHub API request failed (%d): %s', $code, $message ) );
		}

		return $data;
	}
}
