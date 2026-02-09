<?php
/**
 * Post metabox renderer.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Admin_Metabox {
	/**
	 * Render post metabox contents.
	 *
	 * @param WP_Post            $post Post object.
	 * @param array<string,mixed> $view View data.
	 * @return void
	 */
	public static function render( WP_Post $post, array $view ): void {
		$state    = isset( $view['state'] ) && is_array( $view['state'] ) ? $view['state'] : [];
		$synced   = ! empty( $view['synced'] );
		$content_file_url = (string) ( $view['content_file_url'] ?? '' );
		$post_file_url    = (string) ( $view['post_file_url'] ?? '' );
		$meta_file_url    = (string) ( $view['meta_file_url'] ?? '' );
		$diff_url = (string) ( $view['diff_url'] ?? '' );
		$export_url = (string) ( $view['export_url'] ?? '' );
		$is_out_of_sync = WPGS_Sync_Meta::OVERRIDE_OUT_OF_SYNC === strtolower( trim( (string) ( $state['override_state'] ?? '' ) ) );

		if ( $is_out_of_sync ) {
			$status = __( 'Out Of Sync', 'wp-git-sync' );
		} else {
			$status = $synced ? __( 'Synced', 'wp-git-sync' ) : __( 'Not synced yet', 'wp-git-sync' );
		}
		?>
		<p><strong><?php esc_html_e( 'Status:', 'wp-git-sync' ); ?></strong> <span<?php echo $is_out_of_sync ? ' style="color:#9a1f1f;font-weight:600;"' : ''; ?>><?php echo esc_html( $status ); ?></span></p>
		<?php if ( $synced ) : ?>
			<p><strong><?php esc_html_e( 'Repo:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['repo'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Branch:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['branch'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Content path:', 'wp-git-sync' ); ?></strong><br />
				<?php if ( '' !== $content_file_url ) : ?>
					<a href="<?php echo esc_url( $content_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $state['content_path'] ?? '' ) ); ?></code></a>
				<?php else : ?>
					<code><?php echo esc_html( (string) ( $state['content_path'] ?? '' ) ); ?></code>
				<?php endif; ?>
			</p>
			<p><strong><?php esc_html_e( 'Post path:', 'wp-git-sync' ); ?></strong><br />
				<?php if ( '' !== $post_file_url ) : ?>
					<a href="<?php echo esc_url( $post_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $state['post_path'] ?? '' ) ); ?></code></a>
				<?php else : ?>
					<code><?php echo esc_html( (string) ( $state['post_path'] ?? '' ) ); ?></code>
				<?php endif; ?>
			</p>
			<p><strong><?php esc_html_e( 'Meta path:', 'wp-git-sync' ); ?></strong><br />
				<?php if ( '' !== $meta_file_url ) : ?>
					<a href="<?php echo esc_url( $meta_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $state['meta_path'] ?? '' ) ); ?></code></a>
				<?php else : ?>
					<code><?php echo esc_html( (string) ( $state['meta_path'] ?? '' ) ); ?></code>
				<?php endif; ?>
			</p>
			<p><strong><?php esc_html_e( 'Last commit:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['last_commit'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Last synced:', 'wp-git-sync' ); ?></strong><br /><?php echo esc_html( (string) ( $state['last_synced_at'] ?? '' ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Last error:', 'wp-git-sync' ); ?></strong><br /><code style="white-space:pre-wrap;display:block;"><?php echo esc_html( (string) $state['last_error'] ); ?></code></p>
		<?php endif; ?>

		<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
			<p class="description"><?php esc_html_e( 'Only administrators can export/sync content.', 'wp-git-sync' ); ?></p>
		<?php else : ?>
			<p><a class="button button-secondary" href="<?php echo esc_url( $diff_url ); ?>"><?php esc_html_e( 'Check for changes', 'wp-git-sync' ); ?></a></p>

			<?php if ( '' !== $export_url ) : ?>
				<p><a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export to GitHub', 'wp-git-sync' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}
}
