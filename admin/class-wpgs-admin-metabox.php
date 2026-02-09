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
		$file_url = (string) ( $view['file_url'] ?? '' );
		$diff_url = (string) ( $view['diff_url'] ?? '' );

		$status = $synced ? __( 'Synced', 'wp-git-sync' ) : __( 'Not synced yet', 'wp-git-sync' );
		?>
		<p><strong><?php esc_html_e( 'Status:', 'wp-git-sync' ); ?></strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $synced ) : ?>
			<p><strong><?php esc_html_e( 'Repo:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['repo'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Branch:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['branch'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Content path:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['content_path'] ?? '' ) ); ?></code></p>
			<p><strong><?php esc_html_e( 'Post path:', 'wp-git-sync' ); ?></strong><br /><code><?php echo esc_html( (string) ( $state['post_path'] ?? '' ) ); ?></code></p>
			<?php if ( '' !== $file_url ) : ?>
				<p><strong><?php esc_html_e( 'Repo file:', 'wp-git-sync' ); ?></strong><br /><a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open on GitHub', 'wp-git-sync' ); ?></a></p>
			<?php endif; ?>
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;">
				<input type="hidden" name="action" value="wpgs_export_post" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post->ID ) ); ?>" />
				<?php submit_button( __( 'Sync this post now', 'wp-git-sync' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
