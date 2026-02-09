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
			<h1>WP Git Sync</h1>
			<?php WPGS_Admin::render_primary_tabs( 'overview' ); ?>
			<?php if ( 'repo_setup' === $state ) : ?>
				<div class="notice notice-success inline"><p>Repository branch was prepared successfully.</p></div>
			<?php elseif ( 'exported' === $state ) : ?>
				<div class="notice notice-success inline"><p>Export completed successfully.</p></div>
			<?php elseif ( 'batch_started' === $state ) : ?>
				<div class="notice notice-info inline"><p>Export batch started. Progress appears below.</p></div>
			<?php endif; ?>

			<?php if ( ! $repo_ready ) : ?>
				<div class="notice notice-warning inline">
					<p>GitHub repo is not configured yet. <a href="<?php echo esc_url( $settings_url ); ?>">Setup a repo in Settings</a>.</p>
				</div>
			<?php else : ?>
				<div class="wpgs-overview-card">
					<h2>Repository</h2>
					<p class="wpgs-repo-status">
						<span id="wpgs-repo-status-dot" class="wpgs-status-dot <?php echo esc_attr( $repo_dot_class ); ?>" aria-hidden="true"></span>
						<strong><?php echo esc_html( $repo_full ); ?></strong>
						<span>on</span>
						<code><?php echo esc_html( $branch ); ?></code>
					</p>
					<p id="wpgs-rate-limit-summary" class="description wpgs-rate-limit-summary"><?php echo esc_html( $rate_limit_summary ); ?></p>

					<div class="wpgs-action-row">
						<p><a class="button" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer">Open Repo</a></p>
						<p>
							<button type="button" class="button button-secondary" id="wpgs-setup-repo-toggle-btn" aria-expanded="false" aria-controls="wpgs-setup-repo-confirm">Setup Repo</button>
						</p>
					</div>

					<div id="wpgs-setup-repo-confirm" class="wpgs-setup-repo-confirm" hidden>
						<p class="wpgs-danger-note"><strong>Warning:</strong> Setup Repo is destructive. It creates the configured branch if needed, then resets that branch to an empty tree.</p>
						<div class="wpgs-setup-repo-actions">
							<form method="post" action="<?php echo esc_url( $action_url ); ?>">
								<input type="hidden" name="action" value="wpgs_setup_repo" />
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_setup_repo' ) ); ?>" />
								<?php submit_button( 'Confirm Setup Repo', 'wpgs-button-danger', 'submit', false ); ?>
							</form>
							<button type="button" class="button button-secondary" id="wpgs-setup-repo-cancel-btn">Cancel</button>
						</div>
					</div>
				</div>

				<div class="wpgs-overview-card wpgs-export-card">
					<h2>Export Posts</h2>
					<?php if ( empty( $included_post_types ) ) : ?>
						<p class="description">No Included Post Types are selected. <a href="<?php echo esc_url( $settings_url ); ?>">Choose at least one in Settings</a>.</p>
					<?php else : ?>
						<p class="description">Start, resume, or stop export batches for included post types.</p>
					<?php endif; ?>
					<p class="wpgs-action-row">
						<button type="button" class="button button-primary" id="wpgs-export-all-btn">Export All Posts</button>
						<span id="wpgs-export-controls" class="wpgs-export-controls" hidden>
							<button type="button" class="button button-secondary" id="wpgs-export-resume-btn" hidden>Resume Export</button>
							<button type="button" class="button button-secondary" id="wpgs-export-stop-btn" hidden>Stop Export</button>
						</span>
					</p>
					<div id="wpgs-export-progress" class="wpgs-export-progress" hidden>
						<div class="wpgs-export-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<span id="wpgs-export-progress-fill"></span>
						</div>
						<p id="wpgs-export-progress-text" class="description">Preparing export batch...</p>
					</div>
				</div>

				<?php if ( ! empty( $post_type_tabs ) ) : ?>
					<div class="wpgs-overview-card wpgs-post-types-card">
						<h2>Post Types</h2>
						<div class="wpgs-type-tabs-wrap">
							<nav class="nav-tab-wrapper wpgs-type-tabs-nav" id="wpgs-type-tabs-nav" role="tablist" aria-label="Post Types">
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
										<strong>Post count:</strong>
										<span class="wpgs-type-post-count"><?php echo (int) $tab['count']; ?></span>
										<span class="wpgs-type-error-summary"<?php echo (int) ( $tab['error_count'] ?? 0 ) > 0 ? '' : ' hidden'; ?>>
											&nbsp;|&nbsp;<strong>Error count:</strong>
											<span class="wpgs-type-error-count"><?php echo (int) ( $tab['error_count'] ?? 0 ); ?></span>
										</span>
									</p>
									<p class="wpgs-type-action-row">
										<button type="button" class="button button-secondary wpgs-export-type-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>">
											Export all <?php echo esc_html( (string) $tab['label'] ); ?>
										</button>
										<?php if ( (int) ( $tab['error_count'] ?? 0 ) > 0 ) : ?>
											<button type="button" class="button button-secondary wpgs-retry-errors-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>" data-post-label="<?php echo esc_attr( (string) $tab['label'] ); ?>">
												Retry Errors
											</button>
											<button type="button" class="button wpgs-only-errors-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>" aria-pressed="false">
												Only Show Errors
											</button>
										<?php endif; ?>
									</p>

									<h3>Synced Posts</h3>
									<?php if ( empty( $tab['rows'] ) ) : ?>
										<p class="description">No synced/error posts found for this post type.</p>
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
													<th>Post</th>
													<th>Sync state</th>
													<th>Last synced</th>
													<th>Last error</th>
													<th>Actions</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $tab['rows'] as $row ) : ?>
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
																<span class="wpgs-pill wpgs-row-state-pill is-error">Error</span>
															<?php else : ?>
																<span class="wpgs-pill wpgs-row-state-pill is-synced">Synced</span>
															<?php endif; ?>
														</td>
														<td class="wpgs-row-last-synced"><?php echo '' !== (string) $row['last_synced_at'] ? esc_html( (string) $row['last_synced_at'] ) : '—'; ?></td>
														<td class="wpgs-row-last-error"><?php echo '' !== (string) $row['last_error'] ? esc_html( (string) $row['last_error'] ) : '—'; ?></td>
														<td class="wpgs-row-actions">
															<button type="button" class="button button-small wpgs-sync-post-btn" data-post-id="<?php echo (int) $row['id']; ?>">Export</button>
															<a class="button button-small" href="<?php echo esc_url( WPGS_Admin::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $row['id'] ] ) ); ?>">Diff</a>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
										<p class="description wpgs-only-errors-empty" hidden>No posts with sync errors for this post type.</p>
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
		$resource = trim( (string) ( $rate_limit['resource'] ?? '' ) );

		if ( $limit < 1 ) {
			return 'GitHub rate limit: no data yet. Start an export to fetch current usage.';
		}

		$parts = [
			sprintf( 'GitHub rate limit: used %d / %d, remaining %d', $used, $limit, $remaining ),
		];
		if ( $reset > 0 ) {
			$parts[] = 'resets at ' . gmdate( 'Y-m-d H:i:s', $reset ) . ' UTC';
		}
		if ( '' !== $resource ) {
			$parts[] = 'resource: ' . $resource;
		}
		return implode( ' | ', $parts );
	}
}
