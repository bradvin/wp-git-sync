<?php
/**
 * Path helpers for mapping WordPress content to a deterministic repo layout.
 *
 * This is intentionally repo-only logic (no rewrite rules / permalinks), used to
 * decide where content and meta files should be written within the synced git
 * working tree.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utilities for generating relative file paths.
 */
final class WPGS_Paths {
	/**
	 * Convert an arbitrary slug into a safe, non-empty filename slug.
	 *
	 * @param string $slug Raw slug.
	 * @return string Sanitized slug, or "no-slug" when empty after sanitization.
	 */
	public static function safe_slug( string $slug ): string {
		$slug = sanitize_title( $slug );
		return $slug ? $slug : 'no-slug';
	}

	/**
	 * Get the relative path to the mapping file (within the repo).
	 *
	 * @return string Relative path.
	 */
	public static function mapping_relpath(): string {
		return 'wp-git-sync/mapping.json';
	}

	/**
	 * Get the base content directory for a given post type.
	 *
	 * Repo structure rule: the folder name must always be exactly the post type
	 * key (e.g. "post", "page", "event").
	 *
	 * @param string $post_type Post type key.
	 * @return string Relative directory name.
	 */
	private static function content_dir_for_post_type( string $post_type ): string {
		// Content files are grouped under posts/<post_type>/...
		$post_type = sanitize_key( $post_type );
		return 'posts/' . ( $post_type ? $post_type : 'unknown' );
	}

	/**
	 * Get the base meta directory for a given post type.
	 *
	 * Meta files are grouped under meta/<post_type>/...
	 *
	 * @param string $post_type Post type key.
	 * @return string Relative directory path.
	 */
	private static function meta_dir_for_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		return 'meta/' . ( $post_type ? $post_type : 'unknown' );
	}

	/**
	 * Get the relative path to the markdown content file for a post.
	 *
	 * Always places the post in a subfolder: <post_type>/<id>-<slug>.md
	 *
	 * @param string $post_type Post type key.
	 * @param int    $post_id   WordPress post ID.
	 * @param string $slug      Post slug.
	 * @return string Relative content path.
	 */
	public static function post_relpath( string $post_type, int $post_id, string $slug ): string {
		$slug = self::safe_slug( $slug );
		return sprintf( '%s/%d-%s.md', self::content_dir_for_post_type( $post_type ), $post_id, $slug );
	}

	/**
	 * Get the relative path to the JSON meta file for a post.
	 *
	 * Written to: meta/<post_type>/<id>-<slug>.json
	 *
	 * @param string $post_type Post type key.
	 * @param int    $post_id   WordPress post ID.
	 * @param string $slug      Post slug.
	 * @return string Relative meta path.
	 */
	public static function meta_relpath( string $post_type, int $post_id, string $slug ): string {
		$slug = self::safe_slug( $slug );
		return sprintf( '%s/%d-%s.json', self::meta_dir_for_post_type( $post_type ), $post_id, $slug );
	}
}
