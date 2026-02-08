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
		$token_available     = ! empty( $view['token_available'] );
		$repo_options        = isset( $view['repo_options'] ) && is_array( $view['repo_options'] ) ? $view['repo_options'] : [];
		$repo_fetch_error    = (string) ( $view['repo_fetch_error'] ?? '' );
		$post_type_options   = isset( $view['post_type_options'] ) && is_array( $view['post_type_options'] ) ? $view['post_type_options'] : [];
		$included_post_types = isset( $view['included_post_types'] ) && is_array( $view['included_post_types'] ) ? $view['included_post_types'] : [];
		$selected_repo_full  = (string) ( $view['selected_repo_full'] ?? '' );
		?>
		<div class="wrap">
			<h1>WP Git Sync</h1>
			<?php WPGS_Admin::render_primary_tabs( 'settings' ); ?>
			<h2>Settings</h2>

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
									<option value="<?php echo esc_attr( (string) $repo_full ); ?>" <?php selected( (string) $repo_full, $selected_repo_full ); ?>><?php echo esc_html( (string) $repo_full ); ?></option>
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
						<td><input class="regular-text" id="wpgs_branch" name="<?php echo esc_attr( WPGS_Settings::OPTION_KEY ); ?>[branch]" value="<?php echo esc_attr( (string) ( $settings['branch'] ?? '' ) ); ?>" /></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">Included Post Types</th>
						<td>
							<?php if ( empty( $post_type_options ) ) : ?>
								<p class="description">No post types are currently available.</p>
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
											<?php echo esc_html( sprintf( '%s (%s)', (string) $post_type_label, (string) $post_type_slug ) ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
							<p class="description">Only selected post types appear on Overview and are exported by Export All Posts.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}
}
