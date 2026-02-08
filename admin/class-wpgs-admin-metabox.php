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

		$status = $synced ? 'Synced' : 'Not synced yet';
		?>
		<p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $synced ) : ?>
			<p><strong>Repo:</strong><br /><code><?php echo esc_html( (string) ( $state['repo'] ?? '' ) ); ?></code></p>
			<p><strong>Branch:</strong><br /><code><?php echo esc_html( (string) ( $state['branch'] ?? '' ) ); ?></code></p>
			<p><strong>Content path:</strong><br /><code><?php echo esc_html( (string) ( $state['content_path'] ?? '' ) ); ?></code></p>
			<p><strong>Post path:</strong><br /><code><?php echo esc_html( (string) ( $state['post_path'] ?? '' ) ); ?></code></p>
			<?php if ( '' !== $file_url ) : ?>
				<p><strong>Repo file:</strong><br /><a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer">Open on GitHub</a></p>
			<?php endif; ?>
			<p><strong>Last commit:</strong><br /><code><?php echo esc_html( (string) ( $state['last_commit'] ?? '' ) ); ?></code></p>
			<p><strong>Last synced:</strong><br /><?php echo esc_html( (string) ( $state['last_synced_at'] ?? '' ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<p><strong>Last error:</strong><br /><code style="white-space:pre-wrap;display:block;"><?php echo esc_html( (string) $state['last_error'] ); ?></code></p>
		<?php endif; ?>

		<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
			<p class="description">Only administrators can export/sync content.</p>
		<?php else : ?>
			<p><a class="button button-secondary" href="<?php echo esc_url( $diff_url ); ?>">Check for changes</a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;">
				<input type="hidden" name="action" value="wpgs_export_post" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post->ID ) ); ?>" />
				<?php submit_button( 'Sync this post now', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
