<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Paths {
	public static function safe_slug( string $slug ): string {
		$slug = sanitize_title( $slug );
		return $slug ? $slug : 'no-slug';
	}

	public static function mapping_relpath(): string {
		return 'wp-git-sync/mapping.json';
	}

	private static function content_dir_for_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		if ( 'post' === $post_type ) {
			return 'posts';
		}
		if ( 'page' === $post_type ) {
			return 'pages';
		}
		return 'cpt/' . $post_type;
	}

	private static function meta_dir_for_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		if ( 'post' === $post_type ) {
			return 'meta/posts';
		}
		if ( 'page' === $post_type ) {
			return 'meta/pages';
		}
		return 'meta/cpt/' . $post_type;
	}

	public static function post_relpath( string $post_type, int $post_id, string $slug ): string {
		$slug = self::safe_slug( $slug );
		return sprintf( '%s/%d-%s.md', self::content_dir_for_post_type( $post_type ), $post_id, $slug );
	}

	public static function meta_relpath( string $post_type, int $post_id, string $slug ): string {
		$slug = self::safe_slug( $slug );
		return sprintf( '%s/%d-%s.json', self::meta_dir_for_post_type( $post_type ), $post_id, $slug );
	}
}
