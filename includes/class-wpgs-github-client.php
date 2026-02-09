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
	 * Most recently observed GitHub primary rate-limit state.
	 *
	 * @var array{limit:int,remaining:int,used:int,reset:int,resource:string,collected_at:int}|array{}
	 */
	private static array $last_rate_limit = [];

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
		$method = strtoupper( $method );
		$endpoint = $this->endpoint_label( $url );
		$args = [
			'method'  => $method,
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
			throw new RuntimeException(
				sprintf(
					'GitHub API transport failed during %s %s: %s',
					esc_html( $method ),
					esc_html( $endpoint ),
					esc_html( $res->get_error_message() )
				)
			);
		}
		self::capture_rate_limit_headers( $res );

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
			$detail = '';
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$error_parts = [];
				foreach ( $data['errors'] as $error_item ) {
					if ( ! is_array( $error_item ) ) {
						continue;
					}
					$error_message = isset( $error_item['message'] ) ? trim( (string) $error_item['message'] ) : '';
					$error_code = isset( $error_item['code'] ) ? trim( (string) $error_item['code'] ) : '';
					$error_resource = isset( $error_item['resource'] ) ? trim( (string) $error_item['resource'] ) : '';
					$error_field = isset( $error_item['field'] ) ? trim( (string) $error_item['field'] ) : '';
					$bits = array_filter(
						[
							'' !== $error_resource ? "resource={$error_resource}" : '',
							'' !== $error_field ? "field={$error_field}" : '',
							'' !== $error_code ? "code={$error_code}" : '',
							'' !== $error_message ? "message={$error_message}" : '',
						]
					);
					if ( ! empty( $bits ) ) {
						$error_parts[] = implode( ', ', $bits );
					}
				}
				if ( ! empty( $error_parts ) ) {
					$detail = ' | details: ' . implode( ' ; ', $error_parts );
				}
			}

			throw new RuntimeException(
				sprintf(
					'GitHub API request failed (%s) during %s %s: %s%s',
					esc_html( (string) $code ),
					esc_html( $method ),
					esc_html( $endpoint ),
					esc_html( $message ),
					esc_html( $detail )
				)
			);
		}

		return $data;
	}

	/**
	 * Get the last captured GitHub primary rate-limit values.
	 *
	 * @return array{limit:int,remaining:int,used:int,reset:int,resource:string,collected_at:int}|array{}
	 */
	public static function get_last_rate_limit(): array {
		return self::$last_rate_limit;
	}

	/**
	 * Extract and store rate-limit headers from a GitHub API response.
	 *
	 * @param array<string,mixed> $response WP HTTP response array.
	 * @return void
	 */
	private static function capture_rate_limit_headers( array $response ): void {
		$limit_raw     = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
		$remaining_raw = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$used_raw      = wp_remote_retrieve_header( $response, 'x-ratelimit-used' );
		$reset_raw     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
		$resource_raw  = wp_remote_retrieve_header( $response, 'x-ratelimit-resource' );

		$has_any_header =
			( is_string( $limit_raw ) && '' !== trim( $limit_raw ) ) ||
			( is_string( $remaining_raw ) && '' !== trim( $remaining_raw ) ) ||
			( is_string( $used_raw ) && '' !== trim( $used_raw ) ) ||
			( is_string( $reset_raw ) && '' !== trim( $reset_raw ) ) ||
			( is_string( $resource_raw ) && '' !== trim( $resource_raw ) );
		if ( ! $has_any_header ) {
			return;
		}

		self::$last_rate_limit = [
			'limit'        => max( 0, (int) $limit_raw ),
			'remaining'    => max( 0, (int) $remaining_raw ),
			'used'         => max( 0, (int) $used_raw ),
			'reset'        => max( 0, (int) $reset_raw ),
			'resource'     => is_string( $resource_raw ) ? trim( $resource_raw ) : '',
			'collected_at' => time(),
		];
	}

	/**
	 * Build a short endpoint label for error output.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	private function endpoint_label( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		if ( '' === $path && '' === $query ) {
			return $url;
		}
		return '' !== $query ? ( $path . '?' . $query ) : $path;
	}
}
