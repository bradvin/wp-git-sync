<?php
/**
 * Settings admin page renderer.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Admin_Page_Settings {
	/**
	 * Render the settings tab.
	 *
	 * @param array<string,mixed> $view View data.
	 * @return void
	 */
	public static function render( array $view ): void {
		$settings            = isset( $view['settings'] ) && is_array( $view['settings'] ) ? $view['settings'] : [];
		$token_source        = (string) ( $view['token_source'] ?? 'none' );
		$token_available     = ! empty( $view['token_available'] );
		$repo_options        = isset( $view['repo_options'] ) && is_array( $view['repo_options'] ) ? $view['repo_options'] : [];
		$repo_fetch_error    = (string) ( $view['repo_fetch_error'] ?? '' );
		$refresh_repos_url   = (string) ( $view['refresh_repos_url'] ?? '' );
		$post_type_options   = isset( $view['post_type_options'] ) && is_array( $view['post_type_options'] ) ? $view['post_type_options'] : [];
		$included_post_types = isset( $view['included_post_types'] ) && is_array( $view['included_post_types'] ) ? $view['included_post_types'] : [];
		$selected_repo_full  = (string) ( $view['selected_repo_full'] ?? '' );
		$token_source_label  = __( 'Not configured', 'wp-git-sync' );
		$token_source_class  = 'is-none';
		if ( 'wp_config' === $token_source ) {
			$token_source_label = __( 'Using token from wp-config.php', 'wp-git-sync' );
			$token_source_class = 'is-config';
		} elseif ( 'options' === $token_source ) {
			$token_source_label = __( 'Using token from plugin options', 'wp-git-sync' );
			$token_source_class = 'is-options';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Git Sync', 'wp-git-sync' ); ?></h1>
			<?php WPGS_Admin::render_primary_tabs( 'settings' ); ?>
			<h2><?php esc_html_e( 'Settings', 'wp-git-sync' ); ?></h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpgs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpgs_pat_token"><?php esc_html_e( 'GitHub PAT token', 'wp-git-sync' ); ?></label></th>
						<td>
							<input class="regular-text" type="password" autocomplete="new-password" id="wpgs_pat_token" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[pat_token]" value="" />
							<p class="wpgs-token-indicator <?php echo esc_attr( $token_source_class ); ?>">
								<span class="wpgs-status-dot" aria-hidden="true"></span>
								<span><?php echo esc_html( $token_source_label ); ?></span>
							</p>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing token. You can also define WPGS_GITHUB_PAT in wp-config.php (preferred).', 'wp-git-sync' ); ?></p>
						</td>
					</tr>

					<?php if ( ! $token_available ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next step', 'wp-git-sync' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'Save a PAT token first. Repo and branch settings will appear after PAT is configured.', 'wp-git-sync' ); ?></p></td>
					</tr>
					<?php else : ?>
					<tr>
						<th scope="row"><label for="wpgs_github_repo_full"><?php esc_html_e( 'GitHub repo', 'wp-git-sync' ); ?></label></th>
						<td>
							<div class="wpgs-repo-select-row">
								<select id="wpgs_github_repo_full" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[github_repo_full]" class="regular-text">
									<option value=""><?php esc_html_e( 'Select a repository', 'wp-git-sync' ); ?></option>
									<?php foreach ( $repo_options as $repo_full ) : ?>
										<option value="<?php echo esc_attr( (string) $repo_full ); ?>" <?php selected( (string) $repo_full, $selected_repo_full ); ?>><?php echo esc_html( (string) $repo_full ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( '' !== $refresh_repos_url ) : ?>
									<a class="button" href="<?php echo esc_url( $refresh_repos_url ); ?>"><?php esc_html_e( 'Refresh', 'wp-git-sync' ); ?></a>
								<?php endif; ?>
							</div>
								<?php if ( '' !== $repo_fetch_error ) : ?>
									<?php /* translators: %s: Error message returned while loading GitHub repositories. */ ?>
									<p class="description"><?php echo esc_html( sprintf( __( 'Could not load repos from GitHub: %s', 'wp-git-sync' ), $repo_fetch_error ) ); ?></p>
								<?php else : ?>
								<p class="description"><?php esc_html_e( 'Only repositories you can push to are listed.', 'wp-git-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_branch"><?php esc_html_e( 'Branch', 'wp-git-sync' ); ?></label></th>
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( (string) ( $settings['branch'] ?? '' ) ); ?>" /></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Included Post Types', 'wp-git-sync' ); ?></th>
						<td>
							<?php if ( empty( $post_type_options ) ) : ?>
								<p class="description"><?php esc_html_e( 'No post types are currently available.', 'wp-git-sync' ); ?></p>
							<?php else : ?>
								<fieldset>
									<?php foreach ( $post_type_options as $post_type_slug => $post_type_label ) : ?>
										<label style="display:block;margin-bottom:4px;">
												<input
													type="checkbox"
												name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[included_post_types][]"
												value="<?php echo esc_attr( (string) $post_type_slug ); ?>"
												<?php checked( in_array( (string) $post_type_slug, $included_post_types, true ) ); ?>
											/>
												<?php /* translators: 1: Post type label, 2: Post type slug. */ ?>
												<?php echo esc_html( sprintf( __( '%1$s (%2$s)', 'wp-git-sync' ), (string) $post_type_label, (string) $post_type_slug ) ); ?>
											</label>
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Only selected post types appear on Overview and are exported by Export All Posts.', 'wp-git-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpgs_excluded_post_meta"><?php esc_html_e( 'Excluded Post Meta Keys', 'wp-git-sync' ); ?></label></th>
						<td>
							<textarea
								class="large-text code"
								rows="8"
								id="wpgs_excluded_post_meta"
								name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[excluded_post_meta]"
								placeholder="_edit_lock&#10;_edit_last"
							><?php echo esc_textarea( (string) ( $settings['excluded_post_meta'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Enter one post meta key or wildcard pattern per line. Use * as a wildcard (for example: _elementor*). Matching keys are excluded from meta export and diff checks.', 'wp-git-sync' ); ?></p>
							</td>
						</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'wp-git-sync' ) ); ?>
			</form>
		</div>
		<?php
	}
}
