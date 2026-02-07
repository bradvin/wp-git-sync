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
		add_action( 'admin_post_wpgs_setup_repo', [ __CLASS__, 'handle_setup_repo' ] );
		add_action( 'admin_post_wpgs_export_post', [ __CLASS__, 'handle_export_post' ] );
		add_action( 'admin_post_wpgs_check_post', [ __CLASS__, 'handle_check_post' ] );
		add_action( 'admin_post_wpgs_pull_post', [ __CLASS__, 'handle_pull_post' ] );
		add_action( 'wp_ajax_wpgs_export_batch_start', [ __CLASS__, 'ajax_export_batch_start' ] );
		add_action( 'wp_ajax_wpgs_export_batch_status', [ __CLASS__, 'ajax_export_batch_status' ] );
		add_action( 'wp_ajax_wpgs_export_batch_step', [ __CLASS__, 'ajax_export_batch_step' ] );
		add_action( 'wp_ajax_wpgs_export_batch_stop', [ __CLASS__, 'ajax_export_batch_stop' ] );
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
	 * Read the active top-level tab.
	 *
	 * @return string One of: overview, diff, settings.
	 */
	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';
		if ( ! in_array( $tab, [ 'overview', 'diff', 'settings' ], true ) ) {
			return 'overview';
		}
		return $tab;
	}

	/**
	 * Build the unified tools page URL.
	 *
	 * @param array<string,mixed> $args Additional query args.
	 * @return string
	 */
	private static function tools_page_url( array $args = [] ): string {
		$base_args = [ 'page' => 'wpgs' ];
		return add_query_arg( array_merge( $base_args, $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Render unified tabs for the single tools page.
	 *
	 * @param string $active_tab Active tab.
	 * @param int    $post_id Optional post ID for diff deep-linking.
	 * @return void
	 */
	private static function render_primary_tabs( string $active_tab, int $post_id = 0 ): void {
		$overview_url = self::tools_page_url();
		$diff_args = [ 'tab' => 'diff' ];
		if ( $post_id > 0 ) {
			$diff_args['post_id'] = $post_id;
		}
		$diff_url = self::tools_page_url( $diff_args );
		$settings_url = self::tools_page_url( [ 'tab' => 'settings' ] );
		?>
		<nav class="nav-tab-wrapper" aria-label="WP Git Sync">
			<a href="<?php echo esc_url( $overview_url ); ?>" class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">Overview</a>
			<a href="<?php echo esc_url( $diff_url ); ?>" class="nav-tab <?php echo 'diff' === $active_tab ? 'nav-tab-active' : ''; ?>">Diff</a>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
		</nav>
		<?php
	}

	/**
	 * Build user-scoped transient key for export-all batch state.
	 *
	 * @return string
	 */
	private static function export_batch_transient_key(): string {
		return 'wpgs_export_batch_' . (int) get_current_user_id();
	}

	/**
	 * Collect post IDs to export in deterministic order.
	 *
	 * @param string[] $post_types Post types.
	 * @return int[]
	 */
	private static function collect_export_post_ids( array $post_types = [ 'post', 'page' ] ): array {
		$statuses = self::exportable_post_statuses();
		$post_ids = [];
		foreach ( $post_types as $post_type ) {
			$q = new WP_Query(
				[
					'post_type'              => (string) $post_type,
					'post_status'            => $statuses,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);

			if ( ! empty( $q->posts ) && is_array( $q->posts ) ) {
				foreach ( $q->posts as $id ) {
					$post_ids[] = (int) $id;
				}
			}
		}

		return array_values( array_unique( $post_ids ) );
	}

	/**
	 * Post statuses included in export operations.
	 *
	 * @return string[]
	 */
	private static function exportable_post_statuses(): array {
		return [ 'publish', 'draft', 'pending', 'private' ];
	}

	/**
	 * Read selected post types from settings (validated against registered types).
	 *
	 * @return string[]
	 */
	private static function included_post_types(): array {
		$settings = WPGS_Settings::get();
		$selected = isset( $settings['included_post_types'] ) && is_array( $settings['included_post_types'] )
			? $settings['included_post_types']
			: [];
		$available = WPGS_Settings::available_post_type_options();
		$allowed = array_fill_keys( array_keys( $available ), true );

		$out = [];
		foreach ( $selected as $post_type ) {
			$slug = sanitize_key( (string) $post_type );
			if ( '' !== $slug && isset( $allowed[ $slug ] ) ) {
				$out[] = $slug;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Human-readable batch scope label for progress output.
	 *
	 * @param string[] $post_types Post types included in batch.
	 * @return string
	 */
	private static function batch_scope_label( array $post_types ): string {
		$post_types = array_values( array_unique( array_map( 'strval', $post_types ) ) );
		if ( 1 === count( $post_types ) ) {
			$obj = get_post_type_object( $post_types[0] );
			if ( $obj && isset( $obj->labels->name ) && '' !== (string) $obj->labels->name ) {
				return (string) $obj->labels->name;
			}
			return (string) $post_types[0];
		}
		return 'All post types';
	}

	/**
	 * Build post-type tab data for the overview repository card.
	 *
	 * @param string[] $post_types Post types to include.
	 * @return array<int,array{slug:string,label:string,count:int,rows:array<int,array{id:int,title:string,edit_link:string,synced:bool,last_synced_at:string,last_error:string}>}>
	 */
	private static function post_type_tab_data( array $post_types ): array {
		$statuses = self::exportable_post_statuses();
		$data = [];
		foreach ( $post_types as $post_type ) {
			$slug = sanitize_key( (string) $post_type );
			if ( '' === $slug ) {
				continue;
			}

			$obj = get_post_type_object( $slug );
			$label = ( $obj && isset( $obj->labels->name ) && '' !== (string) $obj->labels->name )
				? (string) $obj->labels->name
				: $slug;

			$q = new WP_Query(
				[
					'post_type'              => $slug,
					'post_status'            => $statuses,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				]
			);

			$ids = is_array( $q->posts ) ? array_map( 'intval', $q->posts ) : [];
			$rows = [];
			foreach ( $ids as $id ) {
				$state = WPGS_Sync_Meta::get( $id );
				$synced = WPGS_Sync_Meta::is_synced( $id );
				$last_error = trim( (string) ( $state['last_error'] ?? '' ) );
				if ( ! $synced && '' === $last_error ) {
					continue;
				}

				$title = get_the_title( $id );
				$title = '' !== trim( (string) $title ) ? (string) $title : '(no title)';
				$rows[] = [
					'id'             => $id,
					'title'          => $title,
					'edit_link'      => (string) get_edit_post_link( $id, 'raw' ),
					'synced'         => $synced,
					'last_synced_at' => (string) ( $state['last_synced_at'] ?? '' ),
					'last_error'     => $last_error,
				];
			}

			$data[] = [
				'slug'  => $slug,
				'label' => $label,
				'count' => count( $ids ),
				'rows'  => $rows,
			];
		}

		return $data;
	}

	/**
	 * Start (or restart) an export-all batch for the current user.
	 *
	 * @return array<string,mixed>
	 */
	private static function start_export_batch( array $post_types = [] ): array {
		$settings = WPGS_Settings::get();
		$owner = trim( (string) ( $settings['github_owner'] ?? '' ) );
		$repo  = trim( (string) ( $settings['github_repo'] ?? '' ) );
		if ( '' === $owner || '' === $repo ) {
			throw new RuntimeException( 'GitHub owner/repo not configured.' );
		}
		// Validate token is available before queueing work.
		WPGS_Auth::get_token( $settings );

		$included_post_types = self::included_post_types();
		if ( empty( $included_post_types ) ) {
			throw new RuntimeException( 'No included post types configured. Update settings first.' );
		}

		$post_types = empty( $post_types ) ? $included_post_types : array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', $post_types ) ) ) );
		$allowed_post_types = array_fill_keys( $included_post_types, true );
		$post_types = array_values(
			array_filter(
				$post_types,
				static fn( string $slug ): bool => '' !== $slug && isset( $allowed_post_types[ $slug ] )
			)
		);
		if ( empty( $post_types ) ) {
			throw new RuntimeException( 'No included post types configured. Update settings first.' );
		}
		$post_ids = self::collect_export_post_ids( $post_types );
		$total_steps = count( $post_ids ) + 1; // +1 finalization step.

		$batch = [
			'post_types'      => $post_types,
			'scope_label'     => self::batch_scope_label( $post_types ),
			'queue'           => $post_ids,
			'processed_steps' => 0,
			'total_steps'     => max( 1, $total_steps ),
			'succeeded'       => 0,
			'failed'          => [],
			'finalized'       => false,
			'last_step'       => [
				'type'    => 'start',
				'ok'      => true,
				'message' => 'Batch queued.',
			],
			'started_at'      => gmdate( 'c' ),
			'updated_at'      => gmdate( 'c' ),
		];

		set_transient( self::export_batch_transient_key(), $batch, 2 * HOUR_IN_SECONDS );
		return $batch;
	}

	/**
	 * Build a standardized payload for export-batch progress responses.
	 *
	 * @param array<string,mixed> $batch Batch state.
	 * @param array<string,mixed> $last_step Last step result.
	 * @param bool                $done Whether batch is complete.
	 * @return array<string,mixed>
	 */
	private static function export_batch_progress_payload( array $batch, array $last_step, bool $done ): array {
		$total = max( 1, (int) ( $batch['total_steps'] ?? 1 ) );
		$processed = min( $total, (int) ( $batch['processed_steps'] ?? 0 ) );
		$failed = isset( $batch['failed'] ) && is_array( $batch['failed'] ) ? $batch['failed'] : [];

		return [
			'scope_label' => (string) ( $batch['scope_label'] ?? 'All post types' ),
			'done'      => $done,
			'processed' => $processed,
			'total'     => $total,
			'remaining' => isset( $batch['queue'] ) && is_array( $batch['queue'] ) ? count( $batch['queue'] ) : 0,
			'succeeded' => (int) ( $batch['succeeded'] ?? 0 ),
			'failed'    => count( $failed ),
			'percent'   => (int) floor( ( $processed / $total ) * 100 ),
			'last_step' => $last_step,
			'failures'  => $done ? array_values( $failed ) : [],
		];
	}

	/**
	 * Run one export-all batch step and return response payload.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException On missing batch state.
	 */
	private static function run_export_batch_step(): array {
		$key = self::export_batch_transient_key();
		$batch = get_transient( $key );
		if ( ! is_array( $batch ) ) {
			throw new RuntimeException( 'No active export batch. Start a new export first.' );
		}

		$exporter = new WPGS_Exporter( WPGS_Settings::get() );
		$last_step = [
			'type'    => '',
			'ok'      => true,
			'message' => '',
		];

		$queue = isset( $batch['queue'] ) && is_array( $batch['queue'] ) ? $batch['queue'] : [];
		if ( ! empty( $queue ) ) {
			$post_id = (int) array_shift( $queue );
			$batch['queue'] = $queue;
			try {
				$exporter->export_post( $post_id );
				$batch['succeeded'] = (int) ( $batch['succeeded'] ?? 0 ) + 1;
				$last_step = [
					'type'    => 'post',
					'ok'      => true,
					'message' => sprintf( 'Exported post #%d', $post_id ),
					'post_id' => $post_id,
				];
			} catch ( Throwable $e ) {
				WPGS_Sync_Meta::set_error( $post_id, (string) $e->getMessage() );
				if ( ! isset( $batch['failed'] ) || ! is_array( $batch['failed'] ) ) {
					$batch['failed'] = [];
				}
				$batch['failed'][] = [
					'post_id' => $post_id,
					'error'   => (string) $e->getMessage(),
				];
				$last_step = [
					'type'    => 'post',
					'ok'      => false,
					'message' => sprintf( 'Failed exporting post #%d: %s', $post_id, (string) $e->getMessage() ),
					'post_id' => $post_id,
				];
			}
		} elseif ( empty( $batch['finalized'] ) ) {
			try {
					$post_types = isset( $batch['post_types'] ) && is_array( $batch['post_types'] )
						? array_values( array_unique( array_map( 'strval', $batch['post_types'] ) ) )
						: self::included_post_types();
					$exporter->finalize_export_batch( $post_types );
				$last_step = [
					'type'    => 'finalize',
					'ok'      => true,
					'message' => 'Finalized export batch cleanup.',
				];
			} catch ( Throwable $e ) {
				if ( ! isset( $batch['failed'] ) || ! is_array( $batch['failed'] ) ) {
					$batch['failed'] = [];
				}
				$batch['failed'][] = [
					'post_id' => 0,
					'error'   => (string) $e->getMessage(),
				];
				$last_step = [
					'type'    => 'finalize',
					'ok'      => false,
					'message' => 'Finalize step failed: ' . (string) $e->getMessage(),
				];
			}

			$batch['finalized'] = true;
		}

		$batch['processed_steps'] = (int) ( $batch['processed_steps'] ?? 0 ) + 1;
		$batch['updated_at']      = gmdate( 'c' );

		$done = ( empty( $batch['queue'] ) && ! empty( $batch['finalized'] ) );
		$batch['last_step'] = $last_step;
		if ( $done ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $batch, 2 * HOUR_IN_SECONDS );
		}

		$payload = self::export_batch_progress_payload( $batch, $last_step, $done );
		$payload['active'] = ! $done;
		return $payload;
	}

	/**
	 * Render the Tools → WP Git Sync page (unified tabs view).
	 *
	 * @return void
	 */
	public static function render_tools_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$tab = self::current_tab();
		if ( 'diff' === $tab ) {
			self::render_diff_page();
			return;
		}
		if ( 'settings' === $tab ) {
			self::render_settings_page();
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$batch_nonce = wp_create_nonce( 'wpgs_export_batch' );
		$settings   = WPGS_Settings::get();
		$owner      = trim( (string) ( $settings['github_owner'] ?? '' ) );
		$repo       = trim( (string) ( $settings['github_repo'] ?? '' ) );
		$branch     = trim( (string) ( $settings['branch'] ?? 'main' ) );
		$branch     = '' !== $branch ? $branch : 'main';
		$repo_ready = '' !== $owner && '' !== $repo;
		$repo_full  = $repo_ready ? ( $owner . '/' . $repo ) : '';
		$repo_url   = $repo_ready
			? sprintf( 'https://github.com/%s/%s', rawurlencode( $owner ), rawurlencode( $repo ) )
			: '';
		$state      = isset( $_GET['wpgs'] ) ? sanitize_key( (string) $_GET['wpgs'] ) : '';
		$settings_url = self::tools_page_url( [ 'tab' => 'settings' ] );
		$included_post_types = self::included_post_types();
		$post_type_tabs = $repo_ready ? self::post_type_tab_data( $included_post_types ) : [];
		?>
		<div class="wrap">
			<h1>WP Git Sync</h1>
			<?php self::render_primary_tabs( 'overview' ); ?>
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
						<span class="wpgs-status-dot is-green" aria-hidden="true"></span>
						<strong><?php echo esc_html( $repo_full ); ?></strong>
						<span>on</span>
						<code><?php echo esc_html( $branch ); ?></code>
					</p>

					<div class="wpgs-action-row">
						<p><a class="button" href="<?php echo esc_url( $repo_url ); ?>" target="_blank" rel="noopener noreferrer">Open Repo</a></p>

						<form method="post" action="<?php echo esc_url( $action_url ); ?>" onsubmit="return confirm('Setup Repo will wipe all files on this branch. Continue?');">
							<input type="hidden" name="action" value="wpgs_setup_repo" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpgs_setup_repo' ) ); ?>" />
							<?php submit_button( 'Setup Repo', 'delete', 'submit', false ); ?>
						</form>

						<p>
							<button type="button" class="button button-primary" id="wpgs-export-all-btn">Export All Posts</button>
						</p>
					</div>

					<?php if ( empty( $included_post_types ) ) : ?>
						<p class="description">No Included Post Types are selected. <a href="<?php echo esc_url( $settings_url ); ?>">Choose at least one in Settings</a>.</p>
					<?php endif; ?>

					<div id="wpgs-export-progress" class="wpgs-export-progress" hidden>
						<div class="wpgs-export-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<span id="wpgs-export-progress-fill"></span>
						</div>
						<p id="wpgs-export-progress-text" class="description">Preparing export batch...</p>
						<p id="wpgs-export-controls" class="wpgs-export-controls" hidden>
							<button type="button" class="button button-secondary" id="wpgs-export-resume-btn" hidden>Resume Export</button>
							<button type="button" class="button button-secondary" id="wpgs-export-pause-btn" hidden>Pause Export</button>
							<button type="button" class="button button-link-delete" id="wpgs-export-stop-btn" hidden>Stop Export</button>
						</p>
					</div>

					<p class="description"><strong>Warning:</strong> Setup Repo is destructive. It creates the configured branch if needed, then resets that branch to an empty tree.</p>
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
									<?php echo 0 === $i ? '' : 'hidden'; ?>
								>
									<p><strong>Post count:</strong> <?php echo (int) $tab['count']; ?></p>
									<p>
										<button type="button" class="button button-secondary wpgs-export-type-btn" data-post-type="<?php echo esc_attr( (string) $tab['slug'] ); ?>">
											Export all <?php echo esc_html( (string) $tab['label'] ); ?>
										</button>
									</p>

									<h3>Synced Or Error Posts</h3>
									<?php if ( empty( $tab['rows'] ) ) : ?>
										<p class="description">No synced/error posts found for this post type.</p>
									<?php else : ?>
										<table class="widefat striped wpgs-sync-table">
											<thead>
												<tr>
													<th>Post</th>
													<th>Sync state</th>
													<th>Last synced</th>
													<th>Last error</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $tab['rows'] as $row ) : ?>
													<tr>
														<td>
															<?php if ( ! empty( $row['edit_link'] ) ) : ?>
																<a href="<?php echo esc_url( (string) $row['edit_link'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a>
															<?php else : ?>
																<?php echo esc_html( (string) $row['title'] ); ?>
															<?php endif; ?>
															<code>#<?php echo (int) $row['id']; ?></code>
														</td>
														<td>
															<?php if ( ! empty( $row['last_error'] ) ) : ?>
																<span class="wpgs-pill is-error">Error</span>
															<?php else : ?>
																<span class="wpgs-pill is-synced">Synced</span>
															<?php endif; ?>
														</td>
														<td><?php echo '' !== (string) $row['last_synced_at'] ? esc_html( (string) $row['last_synced_at'] ) : '—'; ?></td>
														<td><?php echo '' !== (string) $row['last_error'] ? esc_html( (string) $row['last_error'] ) : '—'; ?></td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									<?php endif; ?>
								</section>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<style>
				.wpgs-overview-card {
					margin-top: 16px;
					max-width: 860px;
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					padding: 14px 16px;
					box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
				}
				.wpgs-overview-card h2 {
					margin-top: 0;
				}
				.wpgs-repo-status {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 0 0 10px;
				}
				.wpgs-status-dot {
					display: inline-block;
					width: 10px;
					height: 10px;
					border-radius: 50%;
					border: 1px solid rgba(0, 0, 0, 0.18);
				}
				.wpgs-status-dot.is-green {
					background: #46b450;
				}
				.wpgs-action-row {
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					align-items: center;
				}
				.wpgs-action-row p {
					margin: 0;
				}
				.wpgs-type-tabs-wrap {
					margin-top: 16px;
				}
				.wpgs-type-tabs-nav {
					margin-bottom: 12px;
				}
				.wpgs-type-panel[hidden] {
					display: none !important;
				}
				.wpgs-sync-table {
					margin-top: 8px;
				}
				.wpgs-pill {
					display: inline-block;
					padding: 2px 8px;
					border-radius: 999px;
					font-size: 11px;
					font-weight: 600;
				}
				.wpgs-pill.is-synced {
					background: #edfaef;
					color: #1f7a2f;
				}
				.wpgs-pill.is-error {
					background: #fdecec;
					color: #9a1f1f;
				}
				.wpgs-export-progress {
					margin-top: 12px;
				}
				.wpgs-export-controls {
					display: flex;
					gap: 8px;
					align-items: center;
					margin: 8px 0 0;
				}
				.wpgs-export-progress-bar {
					width: 100%;
					max-width: 540px;
					height: 10px;
					background: #f0f0f1;
					border: 1px solid #dcdcde;
					border-radius: 999px;
					overflow: hidden;
				}
				.wpgs-export-progress-bar span {
					display: block;
					height: 100%;
					width: 0;
					background: #2271b1;
					transition: width 160ms linear;
				}
			</style>
			<?php if ( $repo_ready ) : ?>
				<script>
					(function () {
						var btn = document.getElementById('wpgs-export-all-btn');
						if (!btn) {
							return;
						}

						var progressWrap = document.getElementById('wpgs-export-progress');
						var progressFill = document.getElementById('wpgs-export-progress-fill');
						var progressText = document.getElementById('wpgs-export-progress-text');
						var controlsWrap = document.getElementById('wpgs-export-controls');
						var resumeBtn = document.getElementById('wpgs-export-resume-btn');
						var pauseBtn = document.getElementById('wpgs-export-pause-btn');
						var stopBtn = document.getElementById('wpgs-export-stop-btn');
						var typeTabsNav = document.getElementById('wpgs-type-tabs-nav');
						var typeTabLinks = typeTabsNav ? typeTabsNav.querySelectorAll('[data-type-tab]') : [];
						var typePanels = document.querySelectorAll('.wpgs-type-panel');
						var typeButtons = document.querySelectorAll('.wpgs-export-type-btn');
						var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
						var nonce = <?php echo wp_json_encode( $batch_nonce ); ?>;
						var isRunning = false;
						var isPaused = false;
						var isStopping = false;
						var stopRequested = false;
						var currentScopeLabel = 'All post types';
						var pendingResumeData = null;
						var latestProgressData = null;

						function toBody(action, postType) {
							var body = new URLSearchParams();
							body.append('action', action);
							body.append('nonce', nonce);
							if (postType) {
								body.append('post_type', postType);
							}
							return body.toString();
						}

						function request(action, postType) {
							return fetch(ajaxUrl, {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
								},
								body: toBody(action, postType)
							}).then(function (res) {
								return res.json();
							});
						}

						function setExportButtonsEnabled(enabled) {
							btn.disabled = !enabled;
							for (var i = 0; i < typeButtons.length; i++) {
								typeButtons[i].disabled = !enabled;
							}
						}

						function setControlVisible(el, visible) {
							if (!el) {
								return;
							}
							el.hidden = !visible;
							el.style.display = visible ? '' : 'none';
						}

						function refreshControlButtons() {
							if (!controlsWrap) {
								return;
							}
							var hasPendingResume = !isRunning && !!pendingResumeData;
							var showControls = isRunning || isPaused || hasPendingResume;
							controlsWrap.hidden = !showControls;
							controlsWrap.style.display = showControls ? 'flex' : 'none';
							setControlVisible(resumeBtn, isPaused || hasPendingResume);
							setControlVisible(pauseBtn, isRunning && !isPaused && !isStopping);
							setControlVisible(stopBtn, isRunning || isPaused || hasPendingResume);
						}

						function renderProgress(data) {
							if (data.scope_label) {
								currentScopeLabel = data.scope_label;
							}
							latestProgressData = data;
							var pct = typeof data.percent === 'number' ? data.percent : 0;
							pct = Math.max(0, Math.min(100, pct));
							progressFill.style.width = pct + '%';
							var bar = progressWrap.querySelector('.wpgs-export-progress-bar');
							if (bar) {
								bar.setAttribute('aria-valuenow', String(pct));
							}

							var base = currentScopeLabel + ' export: ' + data.processed + '/' + data.total + ' steps (' + pct + '%). ';
							if (data.last_step && data.last_step.message) {
								base += data.last_step.message + ' ';
							}
							base += 'Succeeded: ' + data.succeeded + '. Failed: ' + data.failed + '.';
							progressText.textContent = base;
						}

						function finishRun(data) {
							isRunning = false;
							isPaused = false;
							pendingResumeData = null;
							latestProgressData = data || latestProgressData;
							setExportButtonsEnabled(true);
							btn.textContent = 'Export All Posts';
							refreshControlButtons();
							renderProgress(data);
						}

						function beginPollingWithCurrentState(data) {
							stopRequested = false;
							isRunning = true;
							isPaused = false;
							pendingResumeData = null;
							setExportButtonsEnabled(false);
							btn.textContent = 'Exporting...';
							progressWrap.hidden = false;
							refreshControlButtons();
							renderProgress(data);
							window.setTimeout(pollStep, 200);
						}

						function pollStep() {
							if (!isRunning || isPaused) {
								return;
							}
							request('wpgs_export_batch_step')
								.then(function (res) {
									if (!res.success) {
										throw new Error((res.data && res.data.message) ? res.data.message : 'Batch step failed.');
									}
									if (stopRequested) {
										return;
									}
									var data = res.data || {};
									renderProgress(data);
									if (data.done) {
										finishRun(data);
										return;
									}
									if (isPaused || !isRunning) {
										pendingResumeData = data;
										refreshControlButtons();
										return;
									}
										window.setTimeout(pollStep, 350);
								})
									.catch(function (err) {
										if (stopRequested) {
											return;
										}
										isRunning = false;
										isPaused = false;
										pendingResumeData = null;
										setExportButtonsEnabled(true);
										btn.textContent = 'Export All Posts';
										refreshControlButtons();
										progressText.textContent = 'Batch failed: ' + (err && err.message ? err.message : 'Unknown error');
									});
						}

						function startBatch(postType, scopeLabel) {
							if (isRunning || isPaused || isStopping) {
								return;
							}
							progressWrap.hidden = false;
							progressFill.style.width = '0%';
							progressText.textContent = 'Starting export batch...';
							currentScopeLabel = scopeLabel || 'All post types';
							pendingResumeData = null;
							latestProgressData = null;
							stopRequested = false;
							isRunning = true;
							isPaused = false;
							setExportButtonsEnabled(false);
							btn.textContent = 'Exporting...';
							refreshControlButtons();

							request('wpgs_export_batch_start', postType)
								.then(function (res) {
									if (!res.success) {
										throw new Error((res.data && res.data.message) ? res.data.message : 'Unable to start batch.');
									}
									var data = res.data || {};
									beginPollingWithCurrentState(data);
								})
								.catch(function (err) {
									isRunning = false;
									isPaused = false;
									setExportButtonsEnabled(true);
									btn.textContent = 'Export All Posts';
									refreshControlButtons();
									progressText.textContent = 'Unable to start batch: ' + (err && err.message ? err.message : 'Unknown error');
								});
						}

						function pauseBatch() {
							if (!isRunning) {
								return;
							}
							isPaused = true;
							isRunning = false;
							pendingResumeData = latestProgressData;
							btn.textContent = 'Export Paused';
							refreshControlButtons();
							progressText.textContent = progressText.textContent.replace(/\s*Export paused\.$/, '') + ' Export paused.';
						}

						function stopBatch() {
							if (isStopping) {
								return;
							}
							isStopping = true;
							stopRequested = true;
							isRunning = false;
							isPaused = false;
							refreshControlButtons();
							request('wpgs_export_batch_stop')
								.then(function (res) {
									if (!res.success) {
										throw new Error((res.data && res.data.message) ? res.data.message : 'Unable to stop export.');
									}
									var data = res.data || {};
									pendingResumeData = null;
									latestProgressData = null;
									btn.textContent = 'Export All Posts';
									setExportButtonsEnabled(true);
									refreshControlButtons();
									progressWrap.hidden = false;
									progressFill.style.width = '0%';
									progressText.textContent = (data.last_step && data.last_step.message)
										? data.last_step.message
										: 'Export stopped.';
								})
								.catch(function (err) {
									setExportButtonsEnabled(false);
									progressText.textContent = 'Unable to stop export: ' + (err && err.message ? err.message : 'Unknown error');
								})
								.finally(function () {
									isStopping = false;
								});
						}

						btn.addEventListener('click', function () {
							startBatch('', 'All post types');
						});

						for (var t = 0; t < typeButtons.length; t++) {
							typeButtons[t].addEventListener('click', function () {
								var postType = this.getAttribute('data-post-type') || '';
								var label = this.textContent ? this.textContent.replace(/^Export all\s+/i, '') : postType;
								startBatch(postType, label);
							});
						}

						function activateTypeTab(slug) {
							if (!typeTabLinks.length) {
								return;
							}
							for (var i = 0; i < typeTabLinks.length; i++) {
								var active = typeTabLinks[i].getAttribute('data-type-tab') === slug;
								typeTabLinks[i].classList.toggle('nav-tab-active', active);
								typeTabLinks[i].setAttribute('aria-selected', active ? 'true' : 'false');
							}
							for (var j = 0; j < typePanels.length; j++) {
								var panelActive = typePanels[j].id === ('wpgs-type-tab-' + slug);
								if (panelActive) {
									typePanels[j].removeAttribute('hidden');
								} else {
									typePanels[j].setAttribute('hidden', 'hidden');
								}
							}
						}

						for (var n = 0; n < typeTabLinks.length; n++) {
							typeTabLinks[n].addEventListener('click', function (event) {
								event.preventDefault();
								activateTypeTab(this.getAttribute('data-type-tab'));
							});
						}

						if (resumeBtn) {
							resumeBtn.addEventListener('click', function () {
								if (!pendingResumeData || isRunning) {
									return;
								}
								beginPollingWithCurrentState(pendingResumeData);
							});
						}
						if (pauseBtn) {
							pauseBtn.addEventListener('click', pauseBatch);
						}
						if (stopBtn) {
							stopBtn.addEventListener('click', stopBatch);
						}

						// Detect an active batch after refresh/navigation and offer manual resume.
						request('wpgs_export_batch_status')
							.then(function (res) {
								if (!res.success) {
									return;
								}
								var data = res.data || {};
								if (!data.active) {
									return;
								}
								pendingResumeData = data;
								progressWrap.hidden = false;
								renderProgress(data);
								progressText.textContent += ' Click Resume Export to continue.';
								refreshControlButtons();
								setExportButtonsEnabled(false);
							})
							.catch(function () {
								// Silent failure: manual start button remains available.
							});
					})();
				</script>
			<?php endif; ?>
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
		$post_type_options = WPGS_Settings::available_post_type_options();
		$included_post_types = isset( $settings['included_post_types'] ) && is_array( $settings['included_post_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $settings['included_post_types'] ) ) )
			: [];

		?>
		<div class="wrap">
			<h1>WP Git Sync</h1>
			<?php self::render_primary_tabs( 'settings' ); ?>
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

	/**
	 * Start export batch via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_export_batch_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		try {
			$post_type = isset( $_POST['post_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['post_type'] ) ) : '';
			$post_types = [];
			if ( '' !== $post_type ) {
				$allowed = self::included_post_types();
				if ( ! in_array( $post_type, $allowed, true ) ) {
					wp_send_json_error( [ 'message' => 'Invalid post type selected for export.' ], 400 );
				}
				$post_types = [ $post_type ];
			}

			$batch = self::start_export_batch( $post_types );
			$payload = self::export_batch_progress_payload(
				$batch,
				[
					'type'    => 'start',
					'ok'      => true,
					'message' => 'Batch queued.',
				],
				false
			);
			$payload['active'] = true;
			wp_send_json_success( $payload );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => (string) $e->getMessage() ], 500 );
		}
	}

	/**
	 * Get current export batch status via AJAX (for resume-on-refresh).
	 *
	 * @return void
	 */
	public static function ajax_export_batch_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		$batch = get_transient( self::export_batch_transient_key() );
		if ( ! is_array( $batch ) ) {
			wp_send_json_success(
				[
					'active' => false,
					'done'   => false,
				]
			);
		}

		$last_step = isset( $batch['last_step'] ) && is_array( $batch['last_step'] )
			? $batch['last_step']
			: [
				'type'    => '',
				'ok'      => true,
				'message' => '',
			];

		$payload = self::export_batch_progress_payload( $batch, $last_step, false );
		$payload['active'] = true;
		wp_send_json_success( $payload );
	}

	/**
	 * Process one export batch step via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_export_batch_step(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		try {
			wp_send_json_success( self::run_export_batch_step() );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => (string) $e->getMessage() ], 500 );
		}
	}

	/**
	 * Stop and clear any active export batch for the current user.
	 *
	 * @return void
	 */
	public static function ajax_export_batch_stop(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		$key = self::export_batch_transient_key();
		$had_batch = is_array( get_transient( $key ) );
		delete_transient( $key );

		wp_send_json_success(
			[
				'active'    => false,
				'done'      => false,
				'last_step' => [
					'type'    => 'stop',
					'ok'      => true,
					'message' => $had_batch ? 'Export batch stopped.' : 'No active export batch to stop.',
				],
			]
		);
	}

	/**
	 * Handle export-all admin action (non-AJAX fallback).
	 *
	 * @return void
	 */
	public static function handle_export_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_export_all' );

		try {
			self::start_export_batch();
			wp_safe_redirect( self::tools_page_url( [ 'wpgs' => 'batch_started' ] ) );
			exit;
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Handle repository setup action (create branch if needed, then wipe branch tree).
	 *
	 * @return void
	 */
	public static function handle_setup_repo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'wpgs_setup_repo' );

		$settings = WPGS_Settings::get();
		$owner  = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo   = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$branch = '' !== $branch ? $branch : 'main';
		$token  = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			wp_die( 'GitHub owner/repo not configured.' );
		}

		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );
		try {
			$provider->reset_branch_to_empty( $branch, 'Setup repository for WP Git Sync' );
			wp_safe_redirect( self::tools_page_url( [ 'wpgs' => 'repo_setup' ] ) );
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
			WPGS_Sync_Meta::set_error( $post_id, (string) $e->getMessage() );
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
			$remote_post    = $provider->get_file_contents( $branch, $paths['post_path'] );
			$remote_meta    = $provider->get_file_contents( $branch, $paths['meta_path'] );
		} catch ( Throwable $e ) {
			wp_die( esc_html( 'Unable to fetch remote files for diff: ' . $e->getMessage() ) );
		}

		$remote_content_n = WPGS_Diff::normalize_newlines( (string) $remote_content );
		$local_content_n  = WPGS_Diff::normalize_newlines( (string) $local['content'] );
		$remote_post_n    = WPGS_Diff::normalize_newlines( (string) $remote_post );
		$local_post_n     = WPGS_Diff::normalize_newlines( (string) $local['post_json'] );
		$remote_meta_n    = WPGS_Diff::normalize_newlines( (string) $remote_meta );
		$local_meta_n     = WPGS_Diff::normalize_newlines( (string) $local['meta_json'] );

		$content_changed = hash( 'sha256', $remote_content_n ) !== hash( 'sha256', $local_content_n );
		$post_changed    = hash( 'sha256', $remote_post_n ) !== hash( 'sha256', $local_post_n );
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
		$post_diff    = $post_changed ? wp_text_diff(
			$remote_post_n,
			$local_post_n,
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
			'post_path'       => (string) $paths['post_path'],
			'meta_path'       => (string) $paths['meta_path'],
			'content_changed' => (bool) $content_changed,
			'post_changed'    => (bool) $post_changed,
			'meta_changed'    => (bool) $meta_changed,
			'content_diff'    => (string) $content_diff,
			'post_diff'       => (string) $post_diff,
			'meta_diff'       => (string) $meta_diff,
		], 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id ] ) );
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
		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id, 'wpgs' => 'pulled' ] ) );
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
			echo '<div class="wrap"><h1>WP Git Sync</h1>';
			self::render_primary_tabs( 'diff' );
			echo '<h2>Diff</h2><p>' . esc_html__( 'Missing or invalid post_id.', 'wpgs' ) . '</p></div>';
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
			<h1>WP Git Sync</h1>
			<?php self::render_primary_tabs( 'diff', (int) $post_id ); ?>
			<h2>Diff</h2>
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
									<div class="wpgs-detail-row">
										<dt>Post path</dt>
										<dd><code><?php echo esc_html( (string) ( $diff['post_path'] ?? '' ) ); ?></code></dd>
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
										<dt>Post changed</dt>
										<dd><?php echo esc_html( ! empty( $diff['post_changed'] ) ? 'Yes' : 'No' ); ?></dd>
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
		$diff_url = self::tools_page_url(
			[
				'tab'     => 'diff',
				'post_id' => (int) $post->ID,
			]
		);

		$status = $synced ? 'Synced' : 'Not synced yet';
		?>
		<p><strong>Status:</strong> <?php echo esc_html( $status ); ?></p>
		<?php if ( $synced ) : ?>
			<p><strong>Repo:</strong><br /><code><?php echo esc_html( $state['repo'] ); ?></code></p>
			<p><strong>Branch:</strong><br /><code><?php echo esc_html( $state['branch'] ); ?></code></p>
			<p><strong>Content path:</strong><br /><code><?php echo esc_html( $state['content_path'] ); ?></code></p>
			<p><strong>Post path:</strong><br /><code><?php echo esc_html( $state['post_path'] ); ?></code></p>
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
