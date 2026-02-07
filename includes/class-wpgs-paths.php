<?php
/**
 * Path helpers for mapping WordPress content to a deterministic repo layout.
 *
 * This is intentionally repo-only logic (no rewrite rules / permalinks), used to
 * decide where content and meta files should be written within the synced git
 * working tree.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utilities for generating relative file paths.
 */
final class WPGS_Paths {
	/**
	 * Get the relative path to the mapping file (within the repo).
	 *
	 * @return string Relative path.
	 */
	public static function mapping_relpath(): string {
		return 'wp-git-sync/mapping.json';
	}

	/**
	 * Get the base directory for a given post type.
	 *
	 * Repo structure rule: the folder name must always be exactly the post type
	 * key (e.g. "post", "page", "event").
	 *
	 * @param string $post_type Post type key.
	 * @return string Relative directory name.
	 */
	private static function dir_for_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		return $post_type ? $post_type : 'unknown';
	}

	/**
	 * Get the relative path to the markdown content file for a post.
	 *
	 * Written to: <post_type>/<id>.md
	 *
	 * @param string $post_type Post type key.
	 * @param int    $post_id   WordPress post ID.
	 * @return string Relative content path.
	 */
	public static function content_relpath( string $post_type, int $post_id ): string {
		return sprintf( '%s/%d.md', self::dir_for_post_type( $post_type ), $post_id );
	}

	/**
	 * Get the relative path to the post-data JSON file for a post.
	 *
	 * Written to: <post_type>/<id>.json
	 *
	 * @param string $post_type Post type key.
	 * @param int    $post_id   WordPress post ID.
	 * @return string Relative post-data path.
	 */
	public static function post_data_relpath( string $post_type, int $post_id ): string {
		return sprintf( '%s/%d.json', self::dir_for_post_type( $post_type ), $post_id );
	}

	/**
	 * Get the relative path to the JSON meta file for a post.
	 *
	 * Written to: <post_type>/<id>.meta.json
	 *
	 * @param string $post_type Post type key.
	 * @param int    $post_id   WordPress post ID.
	 * @return string Relative meta path.
	 */
	public static function meta_relpath( string $post_type, int $post_id ): string {
		return sprintf( '%s/%d.meta.json', self::dir_for_post_type( $post_type ), $post_id );
	}
}
