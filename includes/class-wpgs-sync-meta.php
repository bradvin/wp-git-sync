<?php
/**
 * Per-post sync state storage.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores per-post sync state in postmeta.
 *
 * Side effects:
 * - Reads/writes postmeta.
 *
 * Security notes:
 * - Values are stored in the WordPress database.
 * - Do not store tokens here.
 */
final class WPGS_Sync_Meta {
	/**
	 * Synced repo in the form owner/repo.
	 */
	public const KEY_REPO           = '_wpgs_repo';
	public const KEY_BRANCH         = '_wpgs_branch';
	public const KEY_CONTENT_PATH   = '_wpgs_path_content';
	public const KEY_POST_PATH      = '_wpgs_path_post';
	public const KEY_META_PATH      = '_wpgs_path_meta';
	public const KEY_LAST_COMMIT    = '_wpgs_last_commit_sha';
	public const KEY_LAST_SYNCED_AT = '_wpgs_last_synced_at';
	public const KEY_CONTENT_HASH   = '_wpgs_content_hash';
	public const KEY_POST_HASH      = '_wpgs_post_hash';
	public const KEY_META_HASH      = '_wpgs_meta_hash';
	public const KEY_LAST_ERROR     = '_wpgs_last_error';
	public const KEY_OVERRIDE_STATE = '_wpgs_override_state';
	public const OVERRIDE_OUT_OF_SYNC = 'out of sync';

	/**
	 * Keys that should never be exported as part of post meta.
	 *
	 * @return string[]
	 */
	public static function internal_keys(): array {
		return [
			self::KEY_REPO,
			self::KEY_BRANCH,
			self::KEY_CONTENT_PATH,
			self::KEY_POST_PATH,
			self::KEY_META_PATH,
			self::KEY_LAST_COMMIT,
			self::KEY_LAST_SYNCED_AT,
			self::KEY_CONTENT_HASH,
			self::KEY_POST_HASH,
			self::KEY_META_HASH,
			self::KEY_LAST_ERROR,
			self::KEY_OVERRIDE_STATE,
		];
	}

	/**
	 * Determine whether a post is considered "synced".
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_synced( int $post_id ): bool {
		$repo   = (string) get_post_meta( $post_id, self::KEY_REPO, true );
		$branch = (string) get_post_meta( $post_id, self::KEY_BRANCH, true );
		return ( '' !== $repo && '' !== $branch );
	}

	/**
	 * Get sync state.
	 *
	 * @param int $post_id Post ID.
	 * @return array{repo:string,branch:string,content_path:string,post_path:string,meta_path:string,last_commit:string,last_synced_at:string,content_hash:string,post_hash:string,meta_hash:string,last_error:string,override_state:string}
	 */
	public static function get( int $post_id ): array {
		return [
			'repo'           => (string) get_post_meta( $post_id, self::KEY_REPO, true ),
			'branch'         => (string) get_post_meta( $post_id, self::KEY_BRANCH, true ),
			'content_path'   => (string) get_post_meta( $post_id, self::KEY_CONTENT_PATH, true ),
			'post_path'      => (string) get_post_meta( $post_id, self::KEY_POST_PATH, true ),
			'meta_path'      => (string) get_post_meta( $post_id, self::KEY_META_PATH, true ),
			'last_commit'    => (string) get_post_meta( $post_id, self::KEY_LAST_COMMIT, true ),
			'last_synced_at' => (string) get_post_meta( $post_id, self::KEY_LAST_SYNCED_AT, true ),
			'content_hash'   => (string) get_post_meta( $post_id, self::KEY_CONTENT_HASH, true ),
			'post_hash'      => (string) get_post_meta( $post_id, self::KEY_POST_HASH, true ),
			'meta_hash'      => (string) get_post_meta( $post_id, self::KEY_META_HASH, true ),
			'last_error'     => (string) get_post_meta( $post_id, self::KEY_LAST_ERROR, true ),
			'override_state' => (string) get_post_meta( $post_id, self::KEY_OVERRIDE_STATE, true ),
		];
	}

	/**
	 * Save a successful sync state.
	 *
	 * Side effects:
	 * - Updates postmeta.
	 *
	 * @param int $post_id Post ID.
	 * @param array{repo:string,branch:string,content_path:string,post_path:string,meta_path:string,last_commit:string,last_synced_at:string,content_hash:string,post_hash:string,meta_hash:string} $data Data.
	 * @return void
	 */
	public static function set_success( int $post_id, array $data ): void {
		update_post_meta( $post_id, self::KEY_REPO, $data['repo'] );
		update_post_meta( $post_id, self::KEY_BRANCH, $data['branch'] );
		update_post_meta( $post_id, self::KEY_CONTENT_PATH, $data['content_path'] );
		update_post_meta( $post_id, self::KEY_POST_PATH, $data['post_path'] );
		update_post_meta( $post_id, self::KEY_META_PATH, $data['meta_path'] );
		update_post_meta( $post_id, self::KEY_LAST_COMMIT, $data['last_commit'] );
		update_post_meta( $post_id, self::KEY_LAST_SYNCED_AT, $data['last_synced_at'] );
		update_post_meta( $post_id, self::KEY_CONTENT_HASH, $data['content_hash'] );
		update_post_meta( $post_id, self::KEY_POST_HASH, $data['post_hash'] );
		update_post_meta( $post_id, self::KEY_META_HASH, $data['meta_hash'] );
		delete_post_meta( $post_id, self::KEY_LAST_ERROR );
		delete_post_meta( $post_id, self::KEY_OVERRIDE_STATE );
	}

	/**
	 * Store last error message.
	 *
	 * Side effects:
	 * - Updates postmeta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 * @return void
	 */
	public static function set_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, self::KEY_LAST_ERROR, $message );
	}

	/**
	 * Mark a synced post as out of sync after local post updates.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post_after Updated post object.
	 * @param WP_Post $post_before Previous post object.
	 * @return void
	 */
	public static function mark_out_of_sync_on_post_update( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! self::is_synced( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::KEY_OVERRIDE_STATE, self::OVERRIDE_OUT_OF_SYNC );
	}
}
