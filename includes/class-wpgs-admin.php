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
				<input type="hidden" name="action" value="wpgs_export_post" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post->ID ) ); ?>" />
				<?php submit_button( 'Sync this post now', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
