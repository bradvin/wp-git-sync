<?php
/**
 * Diff/check helpers.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for comparing a local post to its remote representation in GitHub.
 */
final class WPGS_Diff {
	/**
	 * Compute the deterministic repo-relative paths for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{content_path:string,meta_path:string}
	 */
	public static function paths_for_post( WP_Post $post ): array {
		$slug = $post->post_name ? (string) $post->post_name : (string) $post->ID;
		return [
			'content_path' => WPGS_Paths::post_relpath( (string) $post->post_type, (int) $post->ID, $slug ),
			'meta_path'    => WPGS_Paths::meta_relpath( (string) $post->post_type, (int) $post->ID, $slug ),
		];
	}

	/**
	 * Build the local export content and meta JSON for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{content:string,meta_json:string,meta:array<string,mixed>}
	 */
	public static function build_local_payload( WP_Post $post ): array {
		$content = (string) $post->post_content;

		$all_meta = get_post_meta( $post->ID );
		if ( ! is_array( $all_meta ) ) {
			$all_meta = [];
		}

		// Avoid exporting our own internal meta.
		foreach ( WPGS_Sync_Meta::internal_keys() as $k ) {
			unset( $all_meta[ $k ] );
		}

		/**
		 * Filter exported post meta.
		 *
		 * @param array<string,mixed> $all_meta All meta.
		 * @param int                $post_id  Post ID.
		 */
		$all_meta = apply_filters( 'wpgs_export_postmeta', $all_meta, (int) $post->ID );

		$meta = [
			'post' => [
				'ID'                => (int) $post->ID,
				'post_type'         => (string) $post->post_type,
				'post_status'       => (string) $post->post_status,
				'post_title'        => (string) $post->post_title,
				'post_name'         => (string) $post->post_name,
				'post_date_gmt'     => (string) $post->post_date_gmt,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			],
			'meta' => $all_meta,
		];

		$meta_json = self::stable_json( $meta ) . "\n";

		return [
			'content'  => $content,
			'meta_json'=> $meta_json,
			'meta'     => $meta,
		];
	}

	/**
	 * Normalize line endings to improve diff stability.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize_newlines( string $text ): string {
		return str_replace( "\r\n", "\n", $text );
	}

	/**
	 * Generate a stable JSON string with recursive key sorting.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function stable_json( $data ): string {
		$data = self::ksort_recursive( $data );
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Recursively sort associative arrays by key.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function ksort_recursive( $value ) {
		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			if ( $is_assoc ) {
				ksort( $value );
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::ksort_recursive( $v );
			}
		}
		return $value;
	}
}
