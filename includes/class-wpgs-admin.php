<?php
/**
 * Admin UI and request handlers.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages + actions + metabox.
 *
 * Security notes:
 * - All state-changing actions are protected by capability checks and nonces.
 */
final class WPGS_Admin {
	/**
	 * Register admin hooks.
	 *
	 * Side effects:
	 * - Adds menu pages.
	 * - Registers admin-post handlers.
	 * - Registers post edit metabox.
	 * - Adds admin notices for migration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_wpgs_export_all', [ __CLASS__, 'handle_export_all' ] );
		add_action( 'admin_post_wpgs_export_post', [ __CLASS__, 'handle_export_post' ] );
		add_action( 'admin_post_wpgs_check_post', [ __CLASS__, 'handle_check_post' ] );
		add_action( 'admin_post_wpgs_pull_post', [ __CLASS__, 'handle_pull_post' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public static function admin_menu(): void {
		add_management_page(
			'WP Git Sync',
			'WP Git Sync',
			'manage_options',
			'wpgs',
			[ __CLASS__, 'render_tools_page' ]
		);

		// Diff/management page for a single post.
		add_management_page(
			'WP Git Sync — Diff',
			'WP Git Sync Diff',
			'manage_options',
			'wpgs-diff',
			[ __CLASS__, 'render_diff_page' ]
		);

		add_options_page(
			'WP Git Sync Settings',
			'WP Git Sync',
			'manage_options',
			'wpgs-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Show migration notices for users who still have legacy settings.
	 *
	 * @return void
	 */
	public static function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = WPGS_Settings::get();

		// Legacy: repo_url was used by git-shell adapter.
		$legacy = get_option( WPGS_Settings::OPTION_KEY, [] );
		$legacy = is_array( $legacy ) ? $legacy : [];
		$had_repo_url = isset( $legacy['repo_url'] ) && '' !== trim( (string) $legacy['repo_url'] );

		if ( $had_repo_url && ( '' === (string) $settings['github_owner'] || '' === (string) $settings['github_repo'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html( 'WP Git Sync: Settings were migrated from the legacy git-shell adapter. Please confirm GitHub owner/repo + auth settings.' );
			echo '</p></div>';
		}
	}

	/**
	 * Render the Tools → WP Git Sync page.
	 *
	 * @return void
	 */
	public static function render_tools_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$nonce      = wp_create_nonce( 'wpgs_export_all' );
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>WP Git Sync</h1>
			<p>Export WordPress content + meta into a GitHub repo/branch.</p>

			<h2>Export</h2>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="wpgs_export_all" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<?php submit_button( 'Export all posts/pages now', 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Settings → WP Git Sync page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$settings = WPGS_Settings::get();
		$selected_repo_full = '';
		if ( '' !== trim( (string) $settings['github_owner'] ) && '' !== trim( (string) $settings['github_repo'] ) ) {
			$selected_repo_full = trim( (string) $settings['github_owner'] ) . '/' . trim( (string) $settings['github_repo'] );
		}

		$token_available = false;
		$repo_options = [];
		$repo_fetch_error = '';
		try {
			$token = WPGS_Auth::get_token( $settings );
			$token_available = true;
			$repos = self::fetch_repo_options( $token );
			if ( is_wp_error( $repos ) ) {
				$repo_fetch_error = $repos->get_error_message();
			} else {
				$repo_options = $repos;
			}
		} catch ( Throwable $e ) {
			$token_available = false;
		}

		if ( '' !== $selected_repo_full && ! in_array( $selected_repo_full, $repo_options, true ) ) {
			$repo_options[] = $selected_repo_full;
		}
		sort( $repo_options, SORT_NATURAL | SORT_FLAG_CASE );

		?>
		<div class="wrap">
			<h1>WP Git Sync Settings</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpgs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpgs_pat_token">GitHub PAT token</label></th>
						<td>
							<input class="regular-text" type="password" autocomplete="new-password" id="wpgs_pat_token" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_token]" value="" />
							<p class="description">Leave blank to keep the existing token. You can also define <code>WPGS_GITHUB_PAT</code> in <code>wp-config.php</code> (preferred).</p>
						</td>
					</tr>

					<?php if ( ! $token_available ) : ?>
					<tr>
						<th scope="row">Next step</th>
						<td><p class="description">Save a PAT token first. Repo and branch settings will appear after PAT is configured.</p></td>
					</tr>
					<?php else : ?>
				<tr>
					<th scope="row"><label for="wpgs_github_repo_full">GitHub repo</label></th>
					<td>
						<select id="wpgs_github_repo_full" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[github_repo_full]" class="regular-text">
							<option value="">Select a repository</option>
								<?php foreach ( $repo_options as $repo_full ) : ?>
									<option value="<?php echo esc_attr( $repo_full ); ?>" <?php selected( $repo_full, $selected_repo_full ); ?>><?php echo esc_html( $repo_full ); ?></option>
								<?php endforeach; ?>
							</select>
								<?php if ( '' !== $repo_fetch_error ) : ?>
									<p class="description">Could not load repos from GitHub: <?php echo esc_html( $repo_fetch_error ); ?></p>
								<?php else : ?>
									<p class="description">Only repositories you can push to are listed.</p>
								<?php endif; ?>
							</td>
						</tr>
					<tr>
						<th scope="row"><label for="wpgs_branch">Branch</label></th>
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( (string) $settings['branch'] ); ?>" /></td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle export-all admin action.
	 *
	 * @return void
	 */
	public static function handle_export_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_export_all' );

		$exporter = new WPGS_Exporter( WPGS_Settings::get() );
		try {
			$exporter->export_all( [ 'post', 'page' ] );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs', 'wpgs' => 'exported' ], admin_url( 'tools.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Handle per-post export admin action.
	 *
	 * @return void
	 */
	public static function handle_export_post(): void {
		// Export is an admin-only operation.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_export_post_' . $post_id );

		$exporter = new WPGS_Exporter( WPGS_Settings::get() );
		try {
			$exporter->export_post( $post_id );
			wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
			exit;
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Handle "check for changes" for a single post.
	 *
	 * Computes diffs against remote GitHub content and stores the result in a
	 * user-scoped transient, then redirects to a management page.
	 *
	 * @return void
	 */
	public static function handle_check_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_check_post_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Invalid post.' );
		}

		$settings = WPGS_Settings::get();
		$owner  = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo   = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$token  = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			wp_die( 'GitHub owner/repo not configured.' );
		}

		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );

		$paths = WPGS_Diff::paths_for_post( $post );
		$local = WPGS_Diff::build_local_payload( $post );

		try {
			$remote_content = $provider->get_file_contents( $branch, $paths['content_path'] );
			$remote_meta    = $provider->get_file_contents( $branch, $paths['meta_path'] );
		} catch ( Throwable $e ) {
			wp_die( esc_html( 'Unable to fetch remote files for diff: ' . $e->getMessage() ) );
		}

		$remote_content_n = WPGS_Diff::normalize_newlines( (string) $remote_content );
		$local_content_n  = WPGS_Diff::normalize_newlines( (string) $local['content'] );
		$remote_meta_n    = WPGS_Diff::normalize_newlines( (string) $remote_meta );
		$local_meta_n     = WPGS_Diff::normalize_newlines( (string) $local['meta_json'] );

		$content_changed = hash( 'sha256', $remote_content_n ) !== hash( 'sha256', $local_content_n );
		$meta_changed    = hash( 'sha256', $remote_meta_n ) !== hash( 'sha256', $local_meta_n );

		$content_diff = $content_changed ? wp_text_diff(
			$remote_content_n,
			$local_content_n,
			[
				'show_split_view' => true,
				'title_left'      => 'Remote',
				'title_right'     => 'Local',
			]
		) : '';
		$meta_diff    = $meta_changed ? wp_text_diff(
			$remote_meta_n,
			$local_meta_n,
			[
				'show_split_view' => true,
				'title_left'      => 'Remote',
				'title_right'     => 'Local',
			]
		) : '';

		$transient_key = self::diff_transient_key( (int) $post_id, (int) get_current_user_id() );
		set_transient( $transient_key, [
			'checked_at'      => gmdate( 'c' ),
			'repo'            => $owner . '/' . $repo,
			'branch'          => $branch,
			'post_id'         => (int) $post_id,
			'post_type'       => (string) $post->post_type,
			'content_path'    => (string) $paths['content_path'],
			'meta_path'       => (string) $paths['meta_path'],
			'content_changed' => (bool) $content_changed,
			'meta_changed'    => (bool) $meta_changed,
			'content_diff'    => (string) $content_diff,
			'meta_diff'       => (string) $meta_diff,
		], 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs-diff', 'post_id' => (int) $post_id ], admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Pull remote content from GitHub and overwrite the local post content.
	 *
	 * @return void
	 */
	public static function handle_pull_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_pull_post_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Invalid post.' );
		}

		$settings = WPGS_Settings::get();
		$owner  = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo   = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$token  = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			wp_die( 'GitHub owner/repo not configured.' );
		}

		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );
		$paths = WPGS_Diff::paths_for_post( $post );

		try {
			$remote_content = $provider->get_file_contents( $branch, $paths['content_path'] );
		} catch ( Throwable $e ) {
			wp_die( esc_html( 'Unable to fetch remote content: ' . $e->getMessage() ) );
		}

		$res = wp_update_post( [
			'ID'           => (int) $post_id,
			'post_content' => (string) $remote_content,
		], true );

		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ) );
		}

		// Re-check after pulling so the diff page reflects current state.
		wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs-diff', 'post_id' => (int) $post_id, 'wpgs' => 'pulled' ], admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Render the diff/management page for a single post.
	 *
	 * @return void
	 */
	public static function render_diff_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			echo '<div class="wrap"><h1>WP Git Sync — Diff</h1><p>' . esc_html__( 'Missing or invalid post_id.', 'wpgs' ) . '</p></div>';
			return;
		}

		$transient_key = self::diff_transient_key( (int) $post_id, (int) get_current_user_id() );
		$diff = get_transient( $transient_key );
		$diff = is_array( $diff ) ? $diff : null;

		$edit_link = get_edit_post_link( (int) $post_id, 'raw' );
		$action_url = admin_url( 'admin-post.php' );

		$content_changed = (bool) ( $diff['content_changed'] ?? false );
		$meta_changed    = (bool) ( $diff['meta_changed'] ?? false );
		$has_diff        = (bool) $diff;
		$content_status_class = $has_diff ? ( $content_changed ? 'is-red' : 'is-green' ) : 'is-neutral';
		$meta_status_class    = $has_diff ? ( $meta_changed ? 'is-red' : 'is-green' ) : 'is-neutral';
		$post_type_obj = get_post_type_object( (string) $post->post_type );
		$post_card_title = ( $post_type_obj && isset( $post_type_obj->labels->singular_name ) && '' !== (string) $post_type_obj->labels->singular_name )
			? (string) $post_type_obj->labels->singular_name
			: ucfirst( (string) $post->post_type );
		$repo_file_url   = '';
		if ( $diff ) {
			$repo_file_url = self::github_file_url_from_state(
				[
					'repo'         => (string) ( $diff['repo'] ?? '' ),
					'branch'       => (string) ( $diff['branch'] ?? '' ),
					'content_path' => (string) ( $diff['content_path'] ?? '' ),
				]
			);
		}

		?>
		<div class="wrap wpgs-diff-wrap">
			<h1>WP Git Sync — Diff</h1>
			<nav class="nav-tab-wrapper" id="wpgs-diff-tabs" role="tablist" aria-label="WP Git Sync Diff Tabs">
				<a id="wpgs-tab-overview-link" href="#wpgs-tab-overview" class="nav-tab nav-tab-active" role="tab" data-tab="wpgs-tab-overview" aria-controls="wpgs-tab-overview" aria-selected="true">Overview</a>
				<a id="wpgs-tab-content-link" href="#wpgs-tab-content" class="nav-tab" role="tab" data-tab="wpgs-tab-content" aria-controls="wpgs-tab-content" aria-selected="false">Content <span class="wpgs-tab-light <?php echo esc_attr( $content_status_class ); ?>" aria-hidden="true"></span></a>
				<a id="wpgs-tab-meta-link" href="#wpgs-tab-meta" class="nav-tab" role="tab" data-tab="wpgs-tab-meta" aria-controls="wpgs-tab-meta" aria-selected="false">Meta <span class="wpgs-tab-light <?php echo esc_attr( $meta_status_class ); ?>" aria-hidden="true"></span></a>
			</nav>

				<section id="wpgs-tab-overview" class="wpgs-tab-panel is-active" role="tabpanel" aria-labelledby="wpgs-tab-overview-link">
					<div class="wpgs-overview-grid">
						<article class="wpgs-card">
							<h2 class="wpgs-card-title"><?php echo esc_html( $post_card_title ); ?></h2>
							<p class="wpgs-kv">
								<strong>Title:</strong> <?php echo esc_html( (string) $post->post_title ); ?><br />
								<strong>ID:</strong> <code><?php echo (int) $post_id; ?></code>
							</p>
							<?php if ( $edit_link ) : ?>
								<p><a class="button" href="<?php echo esc_url( $edit_link ); ?>">Edit post</a></p>
						<?php endif; ?>
					</article>

						<article class="wpgs-card">
							<h2 class="wpgs-card-title">Latest check</h2>
							<?php if ( ! $diff ) : ?>
								<p class="description">No diff data yet. Click "Check for changes".</p>
							<?php else : ?>
								<dl class="wpgs-detail-grid">
									<div class="wpgs-detail-row">
										<dt>Checked at</dt>
										<dd><?php echo esc_html( (string) ( $diff['checked_at'] ?? '' ) ); ?></dd>
									</div>
									<div class="wpgs-detail-row">
										<dt>Repo</dt>
										<dd><code><?php echo esc_html( (string) ( $diff['repo'] ?? '' ) ); ?></code></dd>
									</div>
									<div class="wpgs-detail-row">
										<dt>Branch</dt>
										<dd><code><?php echo esc_html( (string) ( $diff['branch'] ?? '' ) ); ?></code></dd>
									</div>
									<div class="wpgs-detail-row">
										<dt>Content path</dt>
										<dd><code><?php echo esc_html( (string) ( $diff['content_path'] ?? '' ) ); ?></code></dd>
									</div>
									<?php if ( '' !== $repo_file_url ) : ?>
										<div class="wpgs-detail-row">
											<dt>Repo file</dt>
											<dd><a href="<?php echo esc_url( $repo_file_url ); ?>" target="_blank" rel="noopener noreferrer">Open on GitHub</a></dd>
										</div>
									<?php endif; ?>
									<div class="wpgs-detail-row">
										<dt>Meta path</dt>
										<dd><code><?php echo esc_html( (string) ( $diff['meta_path'] ?? '' ) ); ?></code></dd>
									</div>
									<div class="wpgs-detail-row">
										<dt>Content changed</dt>
										<dd><?php echo esc_html( $content_changed ? 'Yes' : 'No' ); ?></dd>
									</div>
									<div class="wpgs-detail-row">
										<dt>Meta changed</dt>
										<dd><?php echo esc_html( $meta_changed ? 'Yes' : 'No' ); ?></dd>
									</div>
								</dl>
							<?php endif; ?>
						</article>
					</div>

				<article class="wpgs-card wpgs-actions-card">
					<h2 class="wpgs-card-title">Actions</h2>
					<div class="wpgs-action-row">
						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<input type="hidden" name="action" value="wpgs_check_post" />
							<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_check_post_' . (int) $post_id ) ); ?>" />
							<?php submit_button( 'Check for changes', 'secondary', 'submit', false ); ?>
						</form>

						<form method="post" action="<?php echo esc_url( $action_url ); ?>">
							<input type="hidden" name="action" value="wpgs_export_post" />
							<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post_id ) ); ?>" />
							<?php submit_button( 'Push local to GitHub (sync)', 'primary', 'submit', false ); ?>
						</form>

						<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('This will overwrite the current editor content with the version from GitHub. Continue?');">
							<input type="hidden" name="action" value="wpgs_pull_post" />
							<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_pull_post_' . (int) $post_id ) ); ?>" />
							<?php submit_button( 'Pull from GitHub (overwrite post)', 'delete', 'submit', false ); ?>
						</form>
					</div>
				</article>
			</section>

			<section id="wpgs-tab-content" class="wpgs-tab-panel" role="tabpanel" aria-labelledby="wpgs-tab-content-link" hidden>
				<?php if ( ! $diff ) : ?>
					<div class="wpgs-empty-panel">No diff data yet. Run "Check for changes" in the Overview tab.</div>
				<?php elseif ( ! $content_changed ) : ?>
					<div class="wpgs-empty-panel">(no changes)</div>
				<?php else : ?>
					<div class="wpgs-diff-output wpgs-diff-surface">
						<?php
							// wp_text_diff() returns HTML.
							echo (string) ( $diff['content_diff'] ?? '' );
						?>
					</div>
				<?php endif; ?>
			</section>

			<section id="wpgs-tab-meta" class="wpgs-tab-panel" role="tabpanel" aria-labelledby="wpgs-tab-meta-link" hidden>
				<?php if ( ! $diff ) : ?>
					<div class="wpgs-empty-panel">No diff data yet. Run "Check for changes" in the Overview tab.</div>
				<?php elseif ( ! $meta_changed ) : ?>
					<div class="wpgs-empty-panel">(no changes)</div>
				<?php else : ?>
					<div class="wpgs-diff-output wpgs-diff-surface">
						<?php echo (string) ( $diff['meta_diff'] ?? '' ); ?>
					</div>
				<?php endif; ?>
			</section>
			<style>
				.wpgs-overview-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
					gap: 14px;
				}
				.wpgs-card {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					padding: 14px 16px;
					box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
				}
				.wpgs-card-title {
					margin: 0 0 10px;
					font-size: 16px;
					line-height: 1.4;
				}
					.wpgs-kv {
						margin: 0;
						line-height: 1.7;
					}
					.wpgs-detail-grid {
						margin: 0;
						display: grid;
						gap: 8px;
					}
					.wpgs-detail-row {
						display: grid;
						grid-template-columns: 120px minmax(0, 1fr);
						align-items: start;
						column-gap: 10px;
					}
					.wpgs-detail-row dt {
						margin: 0;
						font-weight: 600;
						color: #50575e;
					}
					.wpgs-detail-row dd {
						margin: 0;
					}
					.wpgs-detail-row dd code {
						word-break: break-word;
						white-space: pre-wrap;
					}
					@media (max-width: 782px) {
						.wpgs-detail-row {
							grid-template-columns: 1fr;
							row-gap: 2px;
						}
					}
					.wpgs-actions-card {
						margin-top: 14px;
					}
				.wpgs-action-row {
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
				}
				.wpgs-tab-panel {
					margin-top: 16px;
				}
				.wpgs-tab-panel[hidden] {
					display: none !important;
				}
				.wpgs-diff-output {
					overflow-x: auto;
				}
				.wpgs-empty-panel {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					padding: 12px 14px;
				}
				.wpgs-diff-surface {
					background: #fff;
					border: 1px solid #ccd0d4;
					border-radius: 8px;
					padding: 10px;
					box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2);
				}
				.wpgs-diff-surface .diff {
					margin: 0;
					background: #fff;
					font-family: Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
				}
				.wpgs-diff-surface .diff td,
				.wpgs-diff-surface .diff th,
				.wpgs-diff-surface .diff pre {
					font-family: Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
					font-size: 12px;
					line-height: 1.5;
				}
				.wpgs-diff-surface .diff pre {
					margin: 0;
					white-space: pre-wrap;
					word-break: break-word;
				}
				.wpgs-tab-light {
					display: inline-block;
					width: 10px;
					height: 10px;
					margin-left: 6px;
					border-radius: 50%;
					vertical-align: middle;
					border: 1px solid rgba(0, 0, 0, 0.18);
				}
				.wpgs-tab-light.is-green {
					background: #46b450;
				}
				.wpgs-tab-light.is-red {
					background: #dc3232;
				}
				.wpgs-tab-light.is-neutral {
					background: #b5bcc2;
				}
			</style>
			<script>
				(function () {
					var tabWrapper = document.getElementById('wpgs-diff-tabs');
					if (!tabWrapper) {
						return;
					}
					var tabs = tabWrapper.querySelectorAll('[data-tab]');
					var panels = document.querySelectorAll('.wpgs-tab-panel');

					function activate(tabId, updateHash) {
						var hasMatch = false;
						for (var x = 0; x < tabs.length; x++) {
							if (tabs[x].getAttribute('data-tab') === tabId) {
								hasMatch = true;
								break;
							}
						}
						if (!hasMatch && tabs.length) {
							tabId = tabs[0].getAttribute('data-tab');
						}

						for (var i = 0; i < tabs.length; i++) {
							var isActive = tabs[i].getAttribute('data-tab') === tabId;
							tabs[i].classList.toggle('nav-tab-active', isActive);
							tabs[i].setAttribute('aria-selected', isActive ? 'true' : 'false');
						}

						for (var j = 0; j < panels.length; j++) {
							var panelIsActive = panels[j].id === tabId;
							panels[j].classList.toggle('is-active', panelIsActive);
							if (panelIsActive) {
								panels[j].removeAttribute('hidden');
							} else {
								panels[j].setAttribute('hidden', 'hidden');
							}
						}

						if (updateHash) {
							window.location.hash = tabId;
						}
					}

					for (var i = 0; i < tabs.length; i++) {
						tabs[i].addEventListener('click', function (event) {
							event.preventDefault();
							activate(this.getAttribute('data-tab'), true);
						});
					}

					var initialHash = window.location.hash ? window.location.hash.substring(1) : '';
					if (initialHash) {
						activate(initialHash, false);
					}
				})();
			</script>
		</div>
		<?php
	}

	/**
	 * Fetch repos visible to the current PAT and return full-name options.
	 *
	 * Uses a short transient cache keyed by token hash to avoid repeated API calls.
	 *
	 * @param string $token GitHub PAT.
	 * @return array<int,string>|WP_Error
	 */
	private static function fetch_repo_options( string $token ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return [];
		}

		$cache_key = 'wpgs_repo_opts_' . substr( sha1( $token ), 0, 16 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$repos = [];
		$page = 1;
		$per_page = 100;

		do {
			$url = add_query_arg(
				[
					'per_page'    => (string) $per_page,
					'page'        => (string) $page,
					'affiliation' => 'owner,collaborator,organization_member',
					'sort'        => 'full_name',
					'direction'   => 'asc',
				],
				'https://api.github.com/user/repos'
			);

			$res = wp_remote_get(
				$url,
				[
					'timeout' => 20,
					'headers' => [
						'Accept'               => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => '2022-11-28',
						'User-Agent'           => 'WP-Git-Sync/' . ( defined( 'WPGS_VERSION' ) ? WPGS_VERSION : 'dev' ),
						'Authorization'        => 'Bearer ' . $token,
					],
				]
			);

			if ( is_wp_error( $res ) ) {
				return $res;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				$data = [];
			}

			if ( $code < 200 || $code >= 300 ) {
				$message = isset( $data['message'] ) ? (string) $data['message'] : 'GitHub API error.';
				return new WP_Error( 'wpgs_repo_fetch_failed', sprintf( 'GitHub API request failed (%d): %s', $code, $message ) );
			}

			$count = count( $data );
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['full_name'] ) || ! is_string( $row['full_name'] ) ) {
					continue;
				}

				$can_push = false;
				if ( isset( $row['permissions'] ) && is_array( $row['permissions'] ) ) {
					$can_push = ! empty( $row['permissions']['push'] ) || ! empty( $row['permissions']['admin'] ) || ! empty( $row['permissions']['maintain'] );
				}

				if ( ! $can_push && isset( $row['role_name'] ) && is_string( $row['role_name'] ) ) {
					$can_push = in_array( strtolower( $row['role_name'] ), [ 'admin', 'maintain', 'write' ], true );
				}

				if ( ! $can_push ) {
					continue;
				}

				$full_name = trim( $row['full_name'] );
				if ( '' !== $full_name && false !== strpos( $full_name, '/' ) ) {
					$repos[] = $full_name;
				}
			}

			$page++;
		} while ( $count === $per_page && $page <= 10 );

		$repos = array_values( array_unique( $repos ) );
		sort( $repos, SORT_NATURAL | SORT_FLAG_CASE );
		set_transient( $cache_key, $repos, 5 * MINUTE_IN_SECONDS );
		return $repos;
	}

	/**
	 * Build a user-scoped transient key for a post diff.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function diff_transient_key( int $post_id, int $user_id ): string {
		return 'wpgs_diff_' . $post_id . '_' . $user_id;
	}

	/**
	 * Build a GitHub file URL from sync state.
	 *
	 * @param array<string,string> $state Sync state.
	 * @return string GitHub URL or empty string when not buildable.
	 */
	private static function github_file_url_from_state( array $state ): string {
		$repo    = trim( (string) ( $state['repo'] ?? '' ) );
		$branch  = trim( (string) ( $state['branch'] ?? '' ) );
		$path    = ltrim( (string) ( $state['content_path'] ?? '' ), '/' );

		if ( '' === $repo || '' === $branch || '' === $path || false === strpos( $repo, '/' ) ) {
			return '';
		}

		$parts = explode( '/', $repo, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return '';
		}

		$owner         = rawurlencode( $parts[0] );
		$repo_name     = rawurlencode( $parts[1] );
		$encoded_branch = rawurlencode( $branch );
		$encoded_path   = str_replace( '%2F', '/', rawurlencode( $path ) );

		return sprintf(
			'https://github.com/%s/%s/blob/%s/%s',
			$owner,
			$repo_name,
			$encoded_branch,
			$encoded_path
		);
	}

	/**
	 * Register per-post metabox on supported post types.
	 *
	 * @return void
	 */
	public static function register_metabox(): void {
		$post_types = [ 'post', 'page' ];
		foreach ( $post_types as $pt ) {
			add_meta_box( 'wpgs_metabox', 'WP Git Sync', [ __CLASS__, 'render_metabox' ], $pt, 'side' );
		}
	}

	/**
	 * Render the per-post metabox.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_metabox( WP_Post $post ): void {
		$state  = WPGS_Sync_Meta::get( (int) $post->ID );
		$synced = WPGS_Sync_Meta::is_synced( (int) $post->ID );
		$file_url = self::github_file_url_from_state( $state );
		$diff_url = add_query_arg(
			[
				'page'    => 'wpgs-diff',
				'post_id' => (int) $post->ID,
			],
			admin_url( 'tools.php' )
		);

		$status = $synced ? 'Synced' : 'Not synced yet';
		?>
		<p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $synced ) : ?>
			<p><strong>Repo:</strong><br /><code><?php echo esc_html( $state['repo'] ); ?></code></p>
			<p><strong>Branch:</strong><br /><code><?php echo esc_html( $state['branch'] ); ?></code></p>
			<p><strong>Content path:</strong><br /><code><?php echo esc_html( $state['content_path'] ); ?></code></p>
			<?php if ( '' !== $file_url ) : ?>
				<p><strong>Repo file:</strong><br /><a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer">Open on GitHub</a></p>
			<?php endif; ?>
			<p><strong>Last commit:</strong><br /><code><?php echo esc_html( $state['last_commit'] ); ?></code></p>
			<p><strong>Last synced:</strong><br /><?php echo esc_html( $state['last_synced_at'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<p><strong>Last error:</strong><br /><code style="white-space:pre-wrap;display:block;"><?php echo esc_html( $state['last_error'] ); ?></code></p>
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
