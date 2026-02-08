<?php
/**
 * Diff admin page renderer.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Admin_Page_Diff {
	/**
	 * Render the diff tab.
	 *
	 * @param array<string,mixed> $view View data.
	 * @return void
	 */
	public static function render( array $view ): void {
		$post             = ( isset( $view['post'] ) && $view['post'] instanceof WP_Post ) ? $view['post'] : null;
		$post_id          = (int) ( $view['post_id'] ?? 0 );
		$overview_url     = (string) ( $view['overview_url'] ?? WPGS_Admin::tools_page_url() );
		$has_sync_state   = ! empty( $view['has_sync_state'] );
		$has_diff         = ! empty( $view['has_diff'] );
		$edit_link        = (string) ( $view['edit_link'] ?? '' );
		$action_url       = (string) ( $view['action_url'] ?? admin_url( 'admin-post.php' ) );
		$post_card_title  = (string) ( $view['post_card_title'] ?? '' );
		$content_file_url = (string) ( $view['content_file_url'] ?? '' );
		$post_file_url    = (string) ( $view['post_file_url'] ?? '' );
		$meta_file_url    = (string) ( $view['meta_file_url'] ?? '' );
		$sync_state       = isset( $view['sync_state'] ) && is_array( $view['sync_state'] ) ? $view['sync_state'] : [];
		$diff             = isset( $view['diff'] ) && is_array( $view['diff'] ) ? $view['diff'] : [];
		$content_changed  = ! empty( $view['content_changed'] );
		$post_changed     = ! empty( $view['post_changed'] );
		$meta_changed     = ! empty( $view['meta_changed'] );
		$content_status_class = (string) ( $view['content_status_class'] ?? 'is-neutral' );
		$post_status_class    = (string) ( $view['post_status_class'] ?? 'is-neutral' );
		$meta_status_class    = (string) ( $view['meta_status_class'] ?? 'is-neutral' );

		if ( ! $post ) {
			echo '<div class="wrap"><h1>WP Git Sync</h1>';
			WPGS_Admin::render_primary_tabs( 'diff' );
			echo '<p>No post was selected for diff. Open the Overview tab, then click <strong>Diff</strong> on a post row.</p>';
			echo '<p><a class="button" href="' . esc_url( $overview_url ) . '">Go to Overview</a></p></div>';
			return;
		}
		?>
		<div class="wrap wpgs-diff-wrap">
			<h1>WP Git Sync</h1>
			<?php WPGS_Admin::render_primary_tabs( 'diff', (int) $post_id ); ?>
			<div class="wpgs-overview-grid">
				<article class="wpgs-card wpgs-post-card">
					<h2 class="wpgs-card-title"><?php echo esc_html( $post_card_title ); ?></h2>
					<dl class="wpgs-detail-grid wpgs-post-sync-grid">
						<div class="wpgs-detail-row">
							<dt>Title</dt>
							<dd><?php echo esc_html( (string) $post->post_title ); ?></dd>
						</div>
						<div class="wpgs-detail-row">
							<dt>ID</dt>
							<dd><code><?php echo (int) $post_id; ?></code></dd>
						</div>
						<?php if ( $has_sync_state ) : ?>
							<div class="wpgs-detail-row">
								<dt>Repo</dt>
								<dd><code><?php echo esc_html( (string) ( $sync_state['repo'] ?? '' ) ); ?></code></dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Branch</dt>
								<dd><code><?php echo esc_html( (string) ( $sync_state['branch'] ?? '' ) ); ?></code></dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Content path</dt>
								<dd>
									<?php if ( '' !== $content_file_url ) : ?>
										<a href="<?php echo esc_url( $content_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $sync_state['content_path'] ?? '' ) ); ?></code></a>
									<?php else : ?>
										<code><?php echo esc_html( (string) ( $sync_state['content_path'] ?? '' ) ); ?></code>
									<?php endif; ?>
								</dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Post path</dt>
								<dd>
									<?php if ( '' !== $post_file_url ) : ?>
										<a href="<?php echo esc_url( $post_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $sync_state['post_path'] ?? '' ) ); ?></code></a>
									<?php else : ?>
										<code><?php echo esc_html( (string) ( $sync_state['post_path'] ?? '' ) ); ?></code>
									<?php endif; ?>
								</dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Meta path</dt>
								<dd>
									<?php if ( '' !== $meta_file_url ) : ?>
										<a href="<?php echo esc_url( $meta_file_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) ( $sync_state['meta_path'] ?? '' ) ); ?></code></a>
									<?php else : ?>
										<code><?php echo esc_html( (string) ( $sync_state['meta_path'] ?? '' ) ); ?></code>
									<?php endif; ?>
								</dd>
							</div>
						<?php endif; ?>
					</dl>
					<div class="wpgs-action-row wpgs-post-action-row">
						<?php if ( $edit_link ) : ?>
							<p><a class="button" href="<?php echo esc_url( $edit_link ); ?>">Edit post</a></p>
						<?php endif; ?>
						<?php if ( $has_sync_state ) : ?>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>">
								<input type="hidden" name="action" value="wpgs_check_post" />
								<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_check_post_' . (int) $post_id ) ); ?>" />
								<?php submit_button( 'Check For Changes', 'secondary', 'submit', false ); ?>
							</form>
						<?php endif; ?>
					</div>
				</article>
			</div>

			<?php if ( $has_diff ) : ?>
				<nav class="nav-tab-wrapper" id="wpgs-diff-tabs" role="tablist" aria-label="WP Git Sync Diff Tabs">
					<a id="wpgs-tab-check-link" href="#wpgs-tab-check" class="nav-tab nav-tab-active" role="tab" data-tab="wpgs-tab-check" aria-controls="wpgs-tab-check" aria-selected="true">Check</a>
					<a id="wpgs-tab-content-link" href="#wpgs-tab-content" class="nav-tab" role="tab" data-tab="wpgs-tab-content" aria-controls="wpgs-tab-content" aria-selected="false">Content <span class="wpgs-tab-light <?php echo esc_attr( $content_status_class ); ?>" aria-hidden="true"></span></a>
					<a id="wpgs-tab-post-link" href="#wpgs-tab-post" class="nav-tab" role="tab" data-tab="wpgs-tab-post" aria-controls="wpgs-tab-post" aria-selected="false">Post <span class="wpgs-tab-light <?php echo esc_attr( $post_status_class ); ?>" aria-hidden="true"></span></a>
					<a id="wpgs-tab-meta-link" href="#wpgs-tab-meta" class="nav-tab" role="tab" data-tab="wpgs-tab-meta" aria-controls="wpgs-tab-meta" aria-selected="false">Meta <span class="wpgs-tab-light <?php echo esc_attr( $meta_status_class ); ?>" aria-hidden="true"></span></a>
				</nav>

				<section id="wpgs-tab-check" class="wpgs-tab-panel is-active" role="tabpanel" aria-labelledby="wpgs-tab-check-link">
					<article class="wpgs-card">
						<h2 class="wpgs-card-title">Latest check</h2>
						<dl class="wpgs-detail-grid">
							<div class="wpgs-detail-row">
								<dt>Checked at</dt>
								<dd><?php echo esc_html( (string) ( $diff['checked_at'] ?? '' ) ); ?></dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Content changed</dt>
								<dd><?php echo esc_html( $content_changed ? 'Yes' : 'No' ); ?></dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Post changed</dt>
								<dd><?php echo esc_html( $post_changed ? 'Yes' : 'No' ); ?></dd>
							</div>
							<div class="wpgs-detail-row">
								<dt>Meta changed</dt>
								<dd><?php echo esc_html( $meta_changed ? 'Yes' : 'No' ); ?></dd>
							</div>
						</dl>
						<?php if ( $content_changed || $post_changed || $meta_changed ) : ?>
							<div class="wpgs-action-row wpgs-check-import-row">
								<?php if ( $content_changed ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('This will overwrite local post content using the remote content file. Continue?');">
										<input type="hidden" name="action" value="wpgs_pull_post" />
										<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_pull_post_' . (int) $post_id ) ); ?>" />
										<?php submit_button( 'Import Content', 'secondary', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<?php if ( $post_changed ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('This will overwrite local post table fields using the remote post JSON. Continue?');">
										<input type="hidden" name="action" value="wpgs_pull_post_data" />
										<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_pull_post_data_' . (int) $post_id ) ); ?>" />
										<?php submit_button( 'Import Post Data', 'secondary', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<?php if ( $meta_changed ) : ?>
									<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('This will replace local post meta using the remote meta JSON. Continue?');">
										<input type="hidden" name="action" value="wpgs_pull_post_meta" />
										<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_pull_post_meta_' . (int) $post_id ) ); ?>" />
										<?php submit_button( 'Import Meta Data', 'secondary', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</article>
				</section>

				<section id="wpgs-tab-content" class="wpgs-tab-panel" role="tabpanel" aria-labelledby="wpgs-tab-content-link" hidden>
					<?php if ( ! $content_changed ) : ?>
						<div class="wpgs-empty-panel">(no changes)</div>
					<?php else : ?>
						<div class="wpgs-diff-output wpgs-diff-surface">
							<?php echo (string) ( $diff['content_diff'] ?? '' ); ?>
						</div>
					<?php endif; ?>
				</section>

				<section id="wpgs-tab-post" class="wpgs-tab-panel" role="tabpanel" aria-labelledby="wpgs-tab-post-link" hidden>
					<?php if ( ! $post_changed ) : ?>
						<div class="wpgs-empty-panel">(no changes)</div>
					<?php else : ?>
						<div class="wpgs-diff-output wpgs-diff-surface">
							<?php echo (string) ( $diff['post_diff'] ?? '' ); ?>
						</div>
					<?php endif; ?>
				</section>

				<section id="wpgs-tab-meta" class="wpgs-tab-panel" role="tabpanel" aria-labelledby="wpgs-tab-meta-link" hidden>
					<?php if ( ! $meta_changed ) : ?>
						<div class="wpgs-empty-panel">(no changes)</div>
					<?php else : ?>
						<div class="wpgs-diff-output wpgs-diff-surface">
							<?php echo (string) ( $diff['meta_diff'] ?? '' ); ?>
						</div>
					<?php endif; ?>
				</section>
			<?php else : ?>
				<article class="wpgs-card wpgs-check-state-card">
					<h2 class="wpgs-card-title">Check</h2>
					<?php if ( $has_sync_state ) : ?>
						<p class="description">No check has been run yet. Click "Check For Changes" above.</p>
					<?php else : ?>
						<p class="description">No sync metadata found yet. Export this post first to enable checks.</p>
					<?php endif; ?>
				</article>
			<?php endif; ?>
		</div>
		<?php
	}
}
