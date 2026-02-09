<?php
/**
 * Overview admin page renderer.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Admin_Page_Main {
	/**
	 * Render the overview tab.
	 *
	 * @param array<string,mixed> $view View data.
	 * @return void
	 */
	public static function render( array $view ): void {
		$action_url           = (string) ( $view['action_url'] ?? '' );
		$state                = (string) ( $view['state'] ?? '' );
		$settings_url         = (string) ( $view['settings_url'] ?? '' );
		$repo_ready           = ! empty( $view['repo_ready'] );
		$repo_full            = (string) ( $view['repo_full'] ?? '' );
		$branch               = (string) ( $view['branch'] ?? 'main' );
		$repo_url             = (string) ( $view['repo_url'] ?? '' );
		$rate_limit           = isset( $view['rate_limit'] ) && is_array( $view['rate_limit'] ) ? $view['rate_limit'] : [];
		$rate_limit_summary   = self::rate_limit_summary( $rate_limit );
		$repo_dot_class       = self::repo_status_dot_class( $rate_limit );
		$included_post_types  = isset( $view['included_post_types'] ) && is_array( $view['included_post_types'] ) ? $view['included_post_types'] : [];
		$post_type_tabs       = isset( $view['post_type_tabs'] ) && is_array( $view['post_type_tabs'] ) ? $view['post_type_tabs'] : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Git Sync', 'wp-git-sync' ); ?></h1>
			<?php WPGS_Admin::render_primary_tabs( 'overview' ); ?>
			<?php if ( 'repo_setup' === $state ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Repository branch was prepared successfully.', 'wp-git-sync' ); ?></p></div>
			<?php elseif ( 'exported' === $state ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Export completed successfully.', 'wp-git-sync' ); ?></p></div>
			<?php elseif ( 'batch_started' === $state ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Export batch started. Progress appears below.', 'wp-git-sync' ); ?></p></div>
			<?php endif; ?>

				<?php if ( ! $repo_ready ) : ?>
					<div class="notice notice-warning inline">
						<?php /* translators: %s: URL to plugin settings tab. */ ?>
						<p><?php echo wp_kses_post( sprintf( __( 'GitHub repo is not configured yet. <a href="%s">Setup a repo in Settings</a>.', 'wp-git-sync' ), esc_url( $settings_url ) ) ); ?></p>
					</div>
			<?php else : ?>
				<div class="wpgs-overview-card">
					<h2><?php esc_html_e( 'Repository', 'wp-git-sync' ); ?></h2>
					<p class="wpgs-repo-status">
						<span id="wpgs-repo-status-dot" class="wpgs-status-dot <?php echo esc_attr( $repo_dot_class ); ?>" aria-hidden="true"></span>
						<strong><?php echo esc_html( $repo_full ); ?></strong>
						<span><?php esc_html_e( 'on', 'wp-git-sync' ); ?></span>
						<code><?php echo esc_html( $branch ); ?></code>
					</p>
					<p id="wpgs-rate-limit-summary" class="description wpgs-rate-limit-summary"><?php echo esc_html( $rate_limit_summary ); ?></p>

					<div class="wpgs-action-row">
						<p><a class="button" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Repo', 'wp-git-sync' ); ?></a></p>
						<p>
							<button type="button" class="button button-secondary" id="wpgs-setup-repo-toggle-btn" aria-expanded="false" aria-controls="wpgs-setup-repo-confirm"><?php esc_html_e( 'Setup Repo', 'wp-git-sync' ); ?></button>
						</p>
					</div>

					<div id="wpgs-setup-repo-confirm" class="wpgs-setup-repo-confirm" hidden>
						<p class="wpgs-danger-note"><strong><?php esc_html_e( 'Warning:', 'wp-git-sync' ); ?></strong> <?php esc_html_e( 'Setup Repo is destructive. It creates the configured branch if needed, then resets that branch to an empty tree.', 'wp-git-sync' ); ?></p>
						<div class="wpgs-setup-repo-actions">
							<form method="post" action="<?php echo esc_url( $action_url ); ?>">
								<input type="hidden" name="action" value="wpgs_setup_repo" />
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_setup_repo' ) ); ?>" />
								<?php submit_button( __( 'Confirm Setup Repo', 'wp-git-sync' ), 'wpgs-button-danger', 'submit', false ); ?>
							</form>
							<button type="button" class="button button-secondary" id="wpgs-setup-repo-cancel-btn"><?php esc_html_e( 'Cancel', 'wp-git-sync' ); ?></button>
						</div>
					</div>
				</div>

					<div class="wpgs-overview-card wpgs-export-card">
						<h2><?php esc_html_e( 'Export Posts', 'wp-git-sync' ); ?></h2>
						<?php if ( empty( $included_post_types ) ) : ?>
							<?php /* translators: %s: URL to plugin settings tab. */ ?>
							<p class="description"><?php echo wp_kses_post( sprintf( __( 'No Included Post Types are selected. <a href="%s">Choose at least one in Settings</a>.', 'wp-git-sync' ), esc_url( $settings_url ) ) ); ?></p>
						<?php else : ?>
						<p class="description"><?php esc_html_e( 'Start, resume, or stop export batches for included post types.', 'wp-git-sync' ); ?></p>
					<?php endif; ?>
					<p class="wpgs-action-row">
						<button type="button" class="button button-primary" id="wpgs-export-all-btn"><?php esc_html_e( 'Export All Posts', 'wp-git-sync' ); ?></button>
						<span id="wpgs-export-controls" class="wpgs-export-controls" hidden>
							<button type="button" class="button button-secondary" id="wpgs-export-pause-btn" hidden><?php esc_html_e( 'Pause Export', 'wp-git-sync' ); ?></button>
							<button type="button" class="button button-secondary" id="wpgs-export-resume-btn" hidden><?php esc_html_e( 'Resume Export', 'wp-git-sync' ); ?></button>
							<button type="button" class="button button-secondary" id="wpgs-export-stop-btn" hidden><?php esc_html_e( 'Stop Export', 'wp-git-sync' ); ?></button>
						</span>
					</p>
					<div id="wpgs-export-progress" class="wpgs-export-progress" hidden>
						<div class="wpgs-export-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<span id="wpgs-export-progress-fill"></span>
						</div>
						<p id="wpgs-export-progress-text" class="description"><?php esc_html_e( 'Preparing export batch...', 'wp-git-sync' ); ?></p>
					</div>
				</div>

				<?php if ( ! empty( $post_type_tabs ) ) : ?>
					<div class="wpgs-overview-card wpgs-post-types-card">
						<h2><?php esc_html_e( 'Post Types', 'wp-git-sync' ); ?></h2>
						<div class="wpgs-type-tabs-wrap">
							<nav class="nav-tab-wrapper wpgs-type-tabs-nav" id="wpgs-type-tabs-nav" role="tablist" aria-label="<?php echo esc_attr__( 'Post Types', 'wp-git-sync' ); ?>">
								<?php foreach ( $post_type_tabs as $i => $tab ) : ?>
									<a
										href="#wpgs-type-tab-<?php echo esc_attr( (string) $tab['slug'] ); ?>"
										class="nav-tab <?php echo 0 === $i ? 'nav-tab-active' : ''; ?>"
										role="tab"
										data-type-tab="<?php echo esc_attr( (string) $tab['slug'] ); ?>"
										aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
									><?php echo esc_html( (string) $tab['label'] ); ?></a>
								<?php endforeach; ?>
							</nav>

							<?php foreach ( $post_type_tabs as $i => $tab ) : ?>
								<section
									id="wpgs-type-tab-<?php echo esc_attr( (string) $tab['slug'] ); ?>"
									class="wpgs-type-panel"
									role="tabpanel"
									data-only-errors="0"
									<?php echo 0 === $i ? '' : 'hidden'; ?>
								>
									<p class="wpgs-type-counts">
										<strong><?php esc_html_e( 'Post count:', 'wp-git-sync' ); ?></strong>
										<span class="wpgs-type-post-count"><?php echo (int) $tab['count']; ?></span>
										<span class="wpgs-type-error-summary"<?php echo (int) ( $tab['error_count'] ?? 0 ) > 0 ? '' : ' hidden'; ?>>
											&nbsp;|&nbsp;<strong><?php esc_html_e( 'Error count:', 'wp-git-sync' ); ?></strong>
											<span class="wpgs-type-error-count"><?php echo (int) ( $tab['error_count'] ?? 0 ); ?></span>
										</span>
									</p>
									<p class="wpgs-type-action-row">
										<button type="button" class="button button-secondary wpgs-export-type-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>" data-post-label="<?php echo esc_attr( (string) $tab['label'] ); ?>">
											<?php /* translators: %s: Post type label. */ ?>
											<?php echo esc_html( sprintf( __( 'Export all %s', 'wp-git-sync' ), (string) $tab['label'] ) ); ?>
										</button>
										<?php if ( (int) ( $tab['error_count'] ?? 0 ) > 0 ) : ?>
											<button type="button" class="button button-secondary wpgs-retry-errors-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>" data-post-label="<?php echo esc_attr( (string) $tab['label'] ); ?>">
												<?php esc_html_e( 'Retry Errors', 'wp-git-sync' ); ?>
											</button>
											<button type="button" class="button wpgs-only-errors-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>" aria-pressed="false">
												<?php esc_html_e( 'Only Show Errors', 'wp-git-sync' ); ?>
											</button>
										<?php endif; ?>
									</p>

									<h3><?php esc_html_e( 'Synced Posts', 'wp-git-sync' ); ?></h3>
									<?php if ( empty( $tab['rows'] ) ) : ?>
										<p class="description"><?php esc_html_e( 'No synced/error posts found for this post type.', 'wp-git-sync' ); ?></p>
									<?php else : ?>
										<table class="widefat striped wpgs-sync-table">
											<colgroup>
												<col class="wpgs-col-post" />
												<col class="wpgs-col-state" />
												<col class="wpgs-col-synced" />
												<col class="wpgs-col-error" />
												<col class="wpgs-col-actions" />
											</colgroup>
											<thead>
												<tr>
													<th><?php esc_html_e( 'Post', 'wp-git-sync' ); ?></th>
													<th><?php esc_html_e( 'Sync state', 'wp-git-sync' ); ?></th>
													<th><?php esc_html_e( 'Last synced', 'wp-git-sync' ); ?></th>
													<th><?php esc_html_e( 'Last error', 'wp-git-sync' ); ?></th>
													<th><?php esc_html_e( 'Actions', 'wp-git-sync' ); ?></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $tab['rows'] as $row ) : ?>
													<?php $is_out_of_sync = WPGS_Sync_Meta::OVERRIDE_OUT_OF_SYNC === strtolower( trim( (string) ( $row['override_state'] ?? '' ) ) ); ?>
													<tr data-post-id="<?php echo (int) $row['id']; ?>" data-has-error="<?php echo ! empty( $row['last_error'] ) ? '1' : '0'; ?>">
														<td>
															<?php if ( ! empty( $row['edit_link'] ) ) : ?>
																<a href="<?php echo esc_url( (string) $row['edit_link'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a>
															<?php else : ?>
																<?php echo esc_html( (string) $row['title'] ); ?>
															<?php endif; ?>
															<code>#<?php echo (int) $row['id']; ?></code>
														</td>
														<td class="wpgs-row-state">
															<?php if ( ! empty( $row['last_error'] ) ) : ?>
																<span class="wpgs-pill wpgs-row-state-pill is-error"><?php esc_html_e( 'Error', 'wp-git-sync' ); ?></span>
															<?php elseif ( $is_out_of_sync ) : ?>
																<span class="wpgs-pill wpgs-row-state-pill is-out-of-sync"><?php esc_html_e( 'Out Of Sync', 'wp-git-sync' ); ?></span>
															<?php else : ?>
																<span class="wpgs-pill wpgs-row-state-pill is-synced"><?php esc_html_e( 'Synced', 'wp-git-sync' ); ?></span>
															<?php endif; ?>
														</td>
														<td class="wpgs-row-last-synced"><?php echo '' !== (string) $row['last_synced_at'] ? esc_html( (string) $row['last_synced_at'] ) : '—'; ?></td>
														<td class="wpgs-row-last-error"><?php echo '' !== (string) $row['last_error'] ? esc_html( (string) $row['last_error'] ) : '—'; ?></td>
														<td class="wpgs-row-actions">
															<button type="button" class="button button-small wpgs-sync-post-btn" data-post-id="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Export', 'wp-git-sync' ); ?></button>
															<a class="button button-small" href="<?php echo esc_url( WPGS_Admin::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $row['id'] ] ) ); ?>"><?php esc_html_e( 'Diff', 'wp-git-sync' ); ?></a>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
										<p class="description wpgs-only-errors-empty" hidden><?php esc_html_e( 'No posts with sync errors for this post type.', 'wp-git-sync' ); ?></p>
									<?php endif; ?>
								</section>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolve repository status dot class from rate-limit usage.
	 *
	 * @param array<string,mixed> $rate_limit Rate-limit payload.
	 * @return string
	 */
	private static function repo_status_dot_class( array $rate_limit ): string {
		$limit = max( 0, (int) ( $rate_limit['limit'] ?? 0 ) );
		$used = max( 0, (int) ( $rate_limit['used'] ?? 0 ) );
		$remaining = max( 0, (int) ( $rate_limit['remaining'] ?? 0 ) );
		if ( $limit > 0 && ( $remaining <= 0 || $used >= $limit ) ) {
			return 'is-red';
		}
		if ( $limit > 0 && $used > ( $limit / 2 ) ) {
			return 'is-orange';
		}
		return 'is-green';
	}

	/**
	 * Build a human-readable rate-limit summary line for the repository card.
	 *
	 * @param array<string,mixed> $rate_limit Rate-limit payload.
	 * @return string
	 */
	private static function rate_limit_summary( array $rate_limit ): string {
		$limit = max( 0, (int) ( $rate_limit['limit'] ?? 0 ) );
		$used = max( 0, (int) ( $rate_limit['used'] ?? 0 ) );
		$remaining = max( 0, (int) ( $rate_limit['remaining'] ?? 0 ) );
		$reset = max( 0, (int) ( $rate_limit['reset'] ?? 0 ) );

		if ( $limit < 1 ) {
			return __( 'GitHub rate limit: no data yet. Start an export to fetch current usage.', 'wp-git-sync' );
		}

		$parts = [
			/* translators: 1: Used requests, 2: Limit, 3: Remaining requests. */
			sprintf( __( 'GitHub rate limit: used %1$d / %2$d, remaining %3$d', 'wp-git-sync' ), $used, $limit, $remaining ),
		];
		if ( $reset > 0 ) {
			/* translators: %s: UTC datetime when rate limit resets. */
			$parts[] = sprintf( __( 'resets at %1$s UTC', 'wp-git-sync' ), gmdate( 'Y-m-d H:i:s', $reset ) );
		}
		return implode( ' | ', $parts );
	}
}
