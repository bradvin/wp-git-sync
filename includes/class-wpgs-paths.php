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

	public static function post_relpath( string $post_type, int $post_id, string $slug ): string {
		$post_type = sanitize_key( $post_type );
		$slug      = self::safe_slug( $slug );
		return sprintf( 'posts/%s/%d-%s.md', $post_type, $post_id, $slug );
	}

	public static function meta_relpath( string $post_type, int $post_id, string $slug ): string {
		$post_type = sanitize_key( $post_type );
		$slug      = self::safe_slug( $slug );
		return sprintf( 'meta/%s/%d-%s.json', $post_type, $post_id, $slug );
	}
}
