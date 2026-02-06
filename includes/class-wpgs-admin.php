<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Admin {
	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_wpgs_export_all', [ __CLASS__, 'handle_export_all' ] );
		add_action( 'admin_post_wpgs_export_post', [ __CLASS__, 'handle_export_post' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
	}

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

	public static function render_tools_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$nonce = wp_create_nonce( 'wpgs_export_all' );
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>WP Git Sync</h1>
			<p>Export WordPress content + meta into a Git repo/branch.</p>

			<h2>Export</h2>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="wpgs_export_all" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<?php submit_button( 'Export all posts/pages now', 'primary' ); ?>
			</form>
		</div>
		<?php
	}

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
						<th scope="row"><label for="wpgs_repo">Repo</label></th>
						<td>
							<input class="regular-text" id="wpgs_repo" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[repo]" value="<?php echo esc_attr( $settings['repo'] ); ?>" />
							<p class="description">Use <code>owner/repo</code> or a GitHub URL (e.g. <code>https://github.com/owner/repo</code>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_branch">Branch</label></th>
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( $settings['branch'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row">Auth</th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_method]" value="oauth" <?php checked( 'oauth', $settings['auth_method'] ); ?> /> Connect to GitHub (OAuth)</label><br />
								<label><input type="radio" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[auth_method]" value="pat" <?php checked( 'pat', $settings['auth_method'] ); ?> /> Personal Access Token (PAT)</label>
							</fieldset>
							<p class="description">OAuth redirect flow not wired up yet (next). For now, use PAT.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_pat_token">PAT token</label></th>
						<td>
							<input class="regular-text" type="password" autocomplete="new-password" id="wpgs_pat_token" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_token]" value="" />
							<p class="description">Leave blank to keep the existing token. Prefer defining <code>WPGS_GITHUB_TOKEN</code> in <code>wp-config.php</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">OAuth</th>
						<td>
							<p><button class="button" type="button" disabled>Connect to GitHub</button></p>
							<p class="description">OAuth UI scaffold only in v0.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

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

	public static function register_metabox(): void {
		$post_types = [ 'post', 'page' ];
		foreach ( $post_types as $pt ) {
			add_meta_box( 'wpgs_metabox', 'WP Git Sync', [ __CLASS__, 'render_metabox' ], $pt, 'side' );
		}
	}

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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpgs_export_post" />
			<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_export_post_' . (int) $post->ID ) ); ?>" />
			<?php submit_button( 'Sync this post now', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	// Mapping file removed in the in-memory GitHub implementation.
	// Per-post sync state lives in postmeta.

}
