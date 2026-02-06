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
 * - OAuth token exchange happens server-side and tokens are stored in wp_options.
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
		add_action( 'admin_post_wpgs_oauth_start', [ __CLASS__, 'handle_oauth_start' ] );
		add_action( 'admin_post_wpgs_oauth_poll', [ __CLASS__, 'handle_oauth_poll' ] );
		add_action( 'admin_post_wpgs_oauth_disconnect', [ __CLASS__, 'handle_oauth_disconnect' ] );
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
		$oauth_connected = ( 'device_oauth' === (string) $settings['auth_mode'] ) && '' !== trim( (string) $settings['device_token'] );

		?>
		<div class="wrap">
			<h1>WP Git Sync Settings</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpgs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpgs_github_owner">GitHub owner</label></th>
						<td><input class="regular-text" id="wpgs_github_owner" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[github_owner]" value="<?php echo esc_attr( (string) $settings['github_owner'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_github_repo">GitHub repo</label></th>
						<td><input class="regular-text" id="wpgs_github_repo" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[github_repo]" value="<?php echo esc_attr( (string) $settings['github_repo'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_branch">Branch</label></th>
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( (string) $settings['branch'] ); ?>" /></td>
					</tr>

					<tr>
						<th scope="row">Auth mode</th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_mode]" value="device_oauth" <?php checked( 'device_oauth', (string) $settings['auth_mode'] ); ?> /> Device Flow OAuth</label><br />
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_mode]" value="pat" <?php checked( 'pat', (string) $settings['auth_mode'] ); ?> /> Fine-grained PAT</label>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">PAT storage</th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_storage]" value="wp_config" <?php checked( 'wp_config', (string) $settings['pat_storage'] ); ?> /> wp-config.php constant (preferred)</label><br />
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_storage]" value="options" <?php checked( 'options', (string) $settings['pat_storage'] ); ?> /> Store in wp_options</label>
							</fieldset>
							<p class="description">To use wp-config storage, define: <code>define('WPGS_GITHUB_PAT','...');</code></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wpgs_pat_token">PAT token (only if storing in wp_options)</label></th>
						<td>
							<input class="regular-text" type="password" autocomplete="new-password" id="wpgs_pat_token" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_token]" value="" />
							<p class="description">Leave blank to keep the existing token.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2>Device Flow OAuth</h2>
			<?php if ( ! defined( 'WPGS_GITHUB_CLIENT_ID' ) ) : ?>
				<p><strong>Missing configuration:</strong> define <code>WPGS_GITHUB_CLIENT_ID</code> in <code>wp-config.php</code>.</p>
			<?php else : ?>
				<p>
					<?php if ( $oauth_connected ) : ?>
						<strong>Status:</strong> Connected.
					<?php else : ?>
						<strong>Status:</strong> Not connected.
					<?php endif; ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="wpgs_oauth_start" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_oauth_start' ) ); ?>" />
					<?php submit_button( 'Connect GitHub', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="wpgs_oauth_poll" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_oauth_poll' ) ); ?>" />
					<?php submit_button( 'Complete connection (poll)', 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="wpgs_oauth_disconnect" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_oauth_disconnect' ) ); ?>" />
					<?php submit_button( 'Disconnect', 'delete', 'submit', false ); ?>
				</form>

				<?php if ( '' !== (string) $settings['user_code'] && '' !== (string) $settings['verification_uri'] ) : ?>
					<p>Enter code <code><?php echo esc_html( (string) $settings['user_code'] ); ?></code> at <a href="<?php echo esc_url( (string) $settings['verification_uri'] ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( (string) $settings['verification_uri'] ); ?></a>.</p>
					<?php if ( '' !== (string) $settings['verification_uri_complete'] ) : ?>
						<p>Direct link: <a href="<?php echo esc_url( (string) $settings['verification_uri_complete'] ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( (string) $settings['verification_uri_complete'] ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
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
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'wp-content-sync';
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

		$content_diff = $content_changed ? wp_text_diff( $remote_content_n, $local_content_n, [ 'show_split_view' => true ] ) : '';
		$meta_diff    = $meta_changed ? wp_text_diff( $remote_meta_n, $local_meta_n, [ 'show_split_view' => true ] ) : '';

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
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'wp-content-sync';
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

		?>
		<div class="wrap">
			<h1>WP Git Sync — Diff</h1>
			<p>
				<strong>Post:</strong> <?php echo esc_html( (string) $post->post_title ); ?>
				(<?php echo esc_html( (string) $post->post_type ); ?> #<?php echo (int) $post_id; ?>)
			</p>
			<p>
				<?php if ( $edit_link ) : ?>
					<a class="button" href="<?php echo esc_url( $edit_link ); ?>">Back to editor</a>
				<?php endif; ?>
			</p>

			<h2>Actions</h2>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
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

			<h2>Latest check</h2>
			<?php if ( ! $diff ) : ?>
				<p>No diff data yet. Click “Check for changes”.</p>
			<?php else : ?>
				<p>
					<strong>Checked at:</strong> <?php echo esc_html( (string) ( $diff['checked_at'] ?? '' ) ); ?><br />
					<strong>Repo:</strong> <code><?php echo esc_html( (string) ( $diff['repo'] ?? '' ) ); ?></code><br />
					<strong>Branch:</strong> <code><?php echo esc_html( (string) ( $diff['branch'] ?? '' ) ); ?></code><br />
					<strong>Content path:</strong> <code><?php echo esc_html( (string) ( $diff['content_path'] ?? '' ) ); ?></code><br />
					<strong>Meta path:</strong> <code><?php echo esc_html( (string) ( $diff['meta_path'] ?? '' ) ); ?></code>
				</p>
				<p>
					<strong>Content changed:</strong> <?php echo esc_html( $content_changed ? 'Yes' : 'No' ); ?><br />
					<strong>Meta changed:</strong> <?php echo esc_html( $meta_changed ? 'Yes' : 'No' ); ?>
				</p>

				<h2>Content diff</h2>
				<?php if ( ! $content_changed ) : ?>
					<p>(no changes)</p>
				<?php else : ?>
					<?php
						// wp_text_diff() returns HTML.
						echo (string) ( $diff['content_diff'] ?? '' );
					?>
				<?php endif; ?>

				<h2>Meta diff</h2>
				<?php if ( ! $meta_changed ) : ?>
					<p>(no changes)</p>
				<?php else : ?>
					<?php
						echo (string) ( $diff['meta_diff'] ?? '' );
					?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
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
	 * Start Device Flow OAuth.
	 *
	 * @return void
	 */
	public static function handle_oauth_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_oauth_start' );

		if ( ! defined( 'WPGS_GITHUB_CLIENT_ID' ) || ! is_string( WPGS_GITHUB_CLIENT_ID ) ) {
			wp_die( 'WPGS_GITHUB_CLIENT_ID is not defined.' );
		}

		$settings = WPGS_Settings::get();
		$scope = 'repo';
		try {
			$flow = WPGS_OAuth_Device::start( (string) WPGS_GITHUB_CLIENT_ID, $scope );
			$settings['auth_mode'] = 'device_oauth';
			$settings['device_code'] = (string) $flow['device_code'];
			$settings['user_code'] = (string) $flow['user_code'];
			$settings['verification_uri'] = (string) $flow['verification_uri'];
			$settings['verification_uri_complete'] = (string) $flow['verification_uri_complete'];
			$settings['device_code_expires_at'] = gmdate( 'c', time() + (int) $flow['expires_in'] );
			$settings['device_poll_interval'] = (int) $flow['interval'];
			update_option( WPGS_Settings::OPTION_KEY, $settings );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs-settings', 'wpgs' => 'oauth_started' ], admin_url( 'options-general.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Poll Device Flow OAuth to complete connection.
	 *
	 * @return void
	 */
	public static function handle_oauth_poll(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_oauth_poll' );

		if ( ! defined( 'WPGS_GITHUB_CLIENT_ID' ) || ! is_string( WPGS_GITHUB_CLIENT_ID ) ) {
			wp_die( 'WPGS_GITHUB_CLIENT_ID is not defined.' );
		}

		$settings = WPGS_Settings::get();
		$device_code = (string) $settings['device_code'];
		if ( '' === trim( $device_code ) ) {
			wp_die( 'No device_code found. Click Connect GitHub first.' );
		}

		try {
			$tok = WPGS_OAuth_Device::poll( (string) WPGS_GITHUB_CLIENT_ID, $device_code );
			$settings['auth_mode'] = 'device_oauth';
			$settings['device_token'] = (string) $tok['access_token'];
			if ( ! empty( $tok['refresh_token'] ) ) {
				$settings['device_refresh_token'] = (string) $tok['refresh_token'];
			}
			if ( ! empty( $tok['expires_in'] ) ) {
				$settings['token_expires_at'] = gmdate( 'c', time() + (int) $tok['expires_in'] );
			}
			if ( ! empty( $tok['refresh_token_expires_in'] ) ) {
				$settings['refresh_expires_at'] = gmdate( 'c', time() + (int) $tok['refresh_token_expires_in'] );
			}

			// Clear device prompt fields.
			$settings['device_code'] = '';
			$settings['user_code'] = '';
			$settings['verification_uri'] = '';
			$settings['verification_uri_complete'] = '';
			$settings['device_code_expires_at'] = '';

			update_option( WPGS_Settings::OPTION_KEY, $settings );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs-settings', 'wpgs' => 'oauth_connected' ], admin_url( 'options-general.php' ) ) );
			exit;
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Disconnect OAuth (clears stored tokens).
	 *
	 * @return void
	 */
	public static function handle_oauth_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_oauth_disconnect' );

		$settings = WPGS_Settings::get();
		foreach ( [
			'device_token',
			'device_refresh_token',
			'token_expires_at',
			'refresh_expires_at',
			'device_code',
			'user_code',
			'verification_uri',
			'verification_uri_complete',
			'device_code_expires_at',
		] as $k ) {
			$settings[ $k ] = '';
		}
		update_option( WPGS_Settings::OPTION_KEY, $settings );

		wp_safe_redirect( add_query_arg( [ 'page' => 'wpgs-settings', 'wpgs' => 'oauth_disconnected' ], admin_url( 'options-general.php' ) ) );
		exit;
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

		$status = $synced ? 'Synced' : 'Not synced yet';
		?>
		<p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $synced ) : ?>
			<p><strong>Repo:</strong><br /><code><?php echo esc_html( $state['repo'] ); ?></code></p>
			<p><strong>Branch:</strong><br /><code><?php echo esc_html( $state['branch'] ); ?></code></p>
			<p><strong>Content path:</strong><br /><code><?php echo esc_html( $state['content_path'] ); ?></code></p>
			<p><strong>Last commit:</strong><br /><code><?php echo esc_html( $state['last_commit'] ); ?></code></p>
			<p><strong>Last synced:</strong><br /><?php echo esc_html( $state['last_synced_at'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $state['last_error'] ) ) : ?>
			<p><strong>Last error:</strong><br /><code style="white-space:pre-wrap;display:block;"><?php echo esc_html( $state['last_error'] ); ?></code></p>
		<?php endif; ?>

		<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
			<p class="description">Only administrators can export/sync content.</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpgs_check_post" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_check_post_' . (int) $post->ID ) ); ?>" />
				<?php submit_button( 'Check for changes', 'secondary', 'submit', false ); ?>
			</form>

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
