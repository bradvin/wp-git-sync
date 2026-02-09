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
	 * @return array{content_path:string,post_path:string,meta_path:string}
	 */
	public static function paths_for_post( WP_Post $post ): array {
		return [
			'content_path' => WPGS_Paths::content_relpath( (string) $post->post_type, (int) $post->ID ),
			'post_path'    => WPGS_Paths::post_data_relpath( (string) $post->post_type, (int) $post->ID ),
			'meta_path'    => WPGS_Paths::meta_relpath( (string) $post->post_type, (int) $post->ID ),
		];
	}

	/**
	 * Build the local export content, post-data JSON, and meta JSON for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array{content:string,post_json:string,meta_json:string,post_data:array<string,mixed>,meta:array<string,mixed>}
	 */
	public static function build_local_payload( WP_Post $post ): array {
		$content = (string) $post->post_content;
		$post_data = get_post( $post->ID, ARRAY_A );
		if ( ! is_array( $post_data ) ) {
			$post_data = [];
		}
		unset( $post_data['post_content'] );

		$all_meta = get_post_meta( $post->ID );
		if ( ! is_array( $all_meta ) ) {
			$all_meta = [];
		}

		// Avoid exporting our own internal meta.
		foreach ( WPGS_Sync_Meta::internal_keys() as $k ) {
			unset( $all_meta[ $k ] );
		}

		foreach ( self::meta_blacklist() as $k ) {
			unset( $all_meta[ (string) $k ] );
		}

		/**
		 * Filter exported post meta.
		 *
		 * @param array<string,mixed> $all_meta All meta.
		 * @param int                $post_id  Post ID.
		 */
		$all_meta = apply_filters( 'wpgs_export_postmeta', $all_meta, (int) $post->ID );

		$post_json = self::stable_json( $post_data ) . "\n";
		$meta_json = self::stable_json( $all_meta ) . "\n";

		return [
			'content'   => $content,
			'post_json' => $post_json,
			'meta_json' => $meta_json,
			'post_data' => $post_data,
			'meta'      => $all_meta,
		];
	}

	/**
	 * Post-meta keys excluded from export by default.
	 *
	 * @return string[]
	 */
	public static function meta_blacklist(): array {
		$blacklist = array_merge(
			[ '_edit_lock' ],
			WPGS_Settings::excluded_post_meta_keys()
		);

		/**
		 * Filter post-meta keys excluded from export.
		 *
		 * @param string[] $blacklist Meta keys to exclude.
		 */
		$blacklist = apply_filters( 'wpgs_export_postmeta_blacklist', $blacklist );
		if ( ! is_array( $blacklist ) ) {
			return [ '_edit_lock' ];
		}

		return array_values( array_unique( array_map( 'strval', $blacklist ) ) );
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
