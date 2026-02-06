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
 * - Actions are protected by capability checks and nonces.
 */
final class WPGS_Admin {
	/**
	 * Register admin hooks.
	 *
	 * Side effects:
	 * - Adds menu pages.
	 * - Registers admin-post handlers.
	 * - Registers post edit metabox.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_wpgs_export_all', [ __CLASS__, 'handle_export_all' ] );
		add_action( 'admin_post_wpgs_export_post', [ __CLASS__, 'handle_export_post' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
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
		?>
		<div class="wrap">
			<h1>WP Git Sync Settings</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpgs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpgs_repo_url">Repo URL</label></th>
						<td>
							<input class="regular-text" id="wpgs_repo_url" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[repo_url]" value="<?php echo esc_attr( $settings['repo_url'] ); ?>" />
							<p class="description">Currently uses git remote URL (shell adapter). Planned: GitHub owner/repo for API adapter.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_branch">Branch</label></th>
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( $settings['branch'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row">Auth method</th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_method]" value="ssh" <?php checked( 'ssh', $settings['auth_method'] ); ?> /> SSH</label><br />
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_method]" value="https" <?php checked( 'https', $settings['auth_method'] ); ?> /> HTTPS (token)</label>
							</fieldset>
							<p class="description">Planned: Device Flow OAuth + fine-grained PAT.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_ssh_key_path">SSH key path</label></th>
						<td><input class="regular-text" id="wpgs_ssh_key_path" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[ssh_key_path]" value="<?php echo esc_attr( $settings['ssh_key_path'] ); ?>" /> <p class="description">Optional. If blank, uses default SSH agent/keys on the host.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_https_token">HTTPS token</label></th>
						<td>
							<input class="regular-text" type="password" autocomplete="new-password" id="wpgs_https_token" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[https_token]" value="" />
							<p class="description">Leave blank to keep the existing token. Stored in wp_options (v0); prefer a constant/env var in production.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_local_clone_path">Local clone path</label></th>
						<td><input class="regular-text" id="wpgs_local_clone_path" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[local_clone_path]" value="<?php echo esc_attr( $settings['local_clone_path'] ); ?>" /> <p class="description">Directory on the WordPress server where the repo is cloned.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle export-all admin action.
	 *
	 * Security:
	 * - Requires manage_options.
	 * - Nonce-protected.
	 *
	 * Side effects:
	 * - Writes files into the configured repo clone.
	 * - Runs git commands (proc_open).
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
	 * Security:
	 * - Requires edit_posts.
	 * - Nonce-protected per post.
	 *
	 * Side effects:
	 * - Writes files into the configured repo clone.
	 * - Runs git commands (proc_open).
	 *
	 * @return void
	 */
	public static function handle_export_post(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
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
		$mapping = self::load_mapping_for_admin( WPGS_Settings::get() );
		$item    = $mapping['items'][ (string) $post->ID ] ?? null;

		$status = $item ? 'Synced' : 'Not synced yet';
		?>
		<p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $item ) : ?>
			<p><strong>Content path:</strong><br /><code><?php echo esc_html( $item['content_path'] ?? '' ); ?></code></p>
			<p><strong>Last commit:</strong><br /><code><?php echo esc_html( $item['last_synced_commit'] ?? '' ); ?></code></p>
			<p><strong>Last synced:</strong><br /><?php echo esc_html( $item['last_synced_at'] ?? '' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpgs_export_post" />
			<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post->ID ) ); ?>" />
			<?php submit_button( 'Sync this post now', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Load mapping.json for display purposes.
	 *
	 * Side effects:
	 * - Reads mapping file from local clone path.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private static function load_mapping_for_admin( array $settings ): array {
		$dir  = (string) ( $settings['local_clone_path'] ?? '' );
		$file = $dir ? ( $dir . '/' . WPGS_Paths::mapping_relpath() ) : '';
		if ( ! $file || ! file_exists( $file ) ) {
			return [ 'items' => [] ];
		}
		$json = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $json ) ? $json : [ 'items' => [] ];
	}
}
