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
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'admin_post_wpgs_export_all', [ __CLASS__, 'handle_export_all' ] );
		add_action( 'admin_post_wpgs_setup_repo', [ __CLASS__, 'handle_setup_repo' ] );
		add_action( 'admin_post_wpgs_export_post', [ __CLASS__, 'handle_export_post' ] );
		add_action( 'admin_post_wpgs_check_post', [ __CLASS__, 'handle_check_post' ] );
		add_action( 'admin_post_wpgs_pull_post', [ __CLASS__, 'handle_pull_post' ] );
		add_action( 'admin_post_wpgs_pull_post_data', [ __CLASS__, 'handle_pull_post_data' ] );
		add_action( 'admin_post_wpgs_pull_post_meta', [ __CLASS__, 'handle_pull_post_meta' ] );
		add_action( 'wp_ajax_wpgs_export_batch_start', [ __CLASS__, 'ajax_export_batch_start' ] );
		add_action( 'wp_ajax_wpgs_export_batch_status', [ __CLASS__, 'ajax_export_batch_status' ] );
		add_action( 'wp_ajax_wpgs_export_batch_step', [ __CLASS__, 'ajax_export_batch_step' ] );
		add_action( 'wp_ajax_wpgs_export_batch_stop', [ __CLASS__, 'ajax_export_batch_stop' ] );
		add_action( 'wp_ajax_wpgs_export_post_ajax', [ __CLASS__, 'ajax_export_post' ] );
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
			__( 'WP Git Sync', 'wp-git-sync' ),
			__( 'WP Git Sync', 'wp-git-sync' ),
			'manage_options',
			'wpgs',
			[ __CLASS__, 'render_tools_page' ]
		);
	}

	/**
	 * Enqueue admin assets for the WP Git Sync tools page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'tools_page_wpgs' !== $hook_suffix ) {
			return;
		}

		$tab = self::current_tab();
		$base = plugin_dir_url( WPGS_PLUGIN_FILE );

		if ( 'overview' === $tab ) {
			wp_enqueue_style(
				'wpgs-admin-overview',
				$base . 'admin/css/wpgs-admin-overview.css',
				[],
				WPGS_VERSION
			);
			wp_enqueue_script(
				'wpgs-admin-overview',
				$base . 'admin/js/wpgs-admin-overview.js',
				[],
				WPGS_VERSION,
				true
			);
			wp_localize_script(
				'wpgs-admin-overview',
				'wpgsOverviewConfig',
				[
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'wpgs_export_batch' ),
					'initialRateLimit' => self::current_rate_limit_state(),
					'i18n'             => [
						'exportAllButton'              => __( 'Export All Posts', 'wp-git-sync' ),
						'exportingButton'              => __( 'Exporting...', 'wp-git-sync' ),
						'exportPausedButton'           => __( 'Export Paused', 'wp-git-sync' ),
						'startingBatch'                => __( 'Starting export batch...', 'wp-git-sync' ),
						'batchFailedPrefix'            => __( 'Batch failed: ', 'wp-git-sync' ),
						'unableToStartBatchPrefix'     => __( 'Unable to start batch: ', 'wp-git-sync' ),
						'unableToStopBatchPrefix'      => __( 'Unable to stop export: ', 'wp-git-sync' ),
						'exportStopped'                => __( 'Export stopped.', 'wp-git-sync' ),
						'onlyShowErrors'               => __( 'Only Show Errors', 'wp-git-sync' ),
						'showAll'                      => __( 'Show All', 'wp-git-sync' ),
						'noErrorsForType'              => __( 'No posts with sync errors for this post type.', 'wp-git-sync' ),
						'exportingPostButton'          => __( 'Exporting...', 'wp-git-sync' ),
						'failedSyncingPost'            => __( 'Failed syncing post.', 'wp-git-sync' ),
						'unknownError'                 => __( 'Unknown error', 'wp-git-sync' ),
						'rateLimitNoData'              => __( 'GitHub rate limit: no data yet. Start an export to fetch current usage.', 'wp-git-sync' ),
						'rateLimitPrefix'              => __( 'GitHub rate limit: used', 'wp-git-sync' ),
						'remainingLabel'               => __( 'remaining', 'wp-git-sync' ),
						'resetsAtLabel'                => __( 'resets at', 'wp-git-sync' ),
						'exportProgressPrefix'         => __( 'export:', 'wp-git-sync' ),
						'succeededLabel'               => __( 'Succeeded:', 'wp-git-sync' ),
						'failedLabel'                  => __( 'Failed:', 'wp-git-sync' ),
						'waitThenResumeHint'           => __( 'Wait a few minutes, then click Resume Export.', 'wp-git-sync' ),
						'clickResumeHint'              => __( 'Click Resume Export to continue.', 'wp-git-sync' ),
						'batchStepFailed'              => __( 'Batch step failed.', 'wp-git-sync' ),
						'unableStartBatch'             => __( 'Unable to start batch.', 'wp-git-sync' ),
						'unableStopExport'             => __( 'Unable to stop export.', 'wp-git-sync' ),
						'allPostTypes'                 => __( 'All post types', 'wp-git-sync' ),
						'errorsSuffix'                 => __( 'errors', 'wp-git-sync' ),
						'rowStateError'                => __( 'Error', 'wp-git-sync' ),
						'rowStateOutOfSync'            => __( 'Out Of Sync', 'wp-git-sync' ),
						'rowStateSynced'               => __( 'Synced', 'wp-git-sync' ),
					],
				]
			);
			return;
		}

		if ( 'diff' === $tab ) {
			wp_enqueue_style(
				'wpgs-admin-diff',
				$base . 'admin/css/wpgs-admin-diff.css',
				[],
				WPGS_VERSION
			);
			wp_enqueue_script(
				'wpgs-admin-diff',
				$base . 'admin/js/wpgs-admin-diff.js',
				[],
				WPGS_VERSION,
				true
			);
			return;
		}

		if ( 'settings' === $tab ) {
			wp_enqueue_style(
				'wpgs-admin-settings',
				$base . 'admin/css/wpgs-admin-settings.css',
				[],
				WPGS_VERSION
			);
		}
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
			echo esc_html( __( 'WP Git Sync: Settings were migrated from the legacy git-shell adapter. Please confirm GitHub owner/repo + auth settings.', 'wp-git-sync' ) );
			echo '</p></div>';
		}
	}

	/**
	 * Read a raw query-string value from INPUT_GET.
	 *
	 * @param string $key Query-string key.
	 * @param string $default Default value when key is not present.
	 * @return string
	 */
	private static function get_query_string( string $key, string $default = '' ): string {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		return is_string( $value ) ? $value : $default;
	}

	/**
	 * Read an integer query-string value from INPUT_GET.
	 *
	 * @param string $key Query-string key.
	 * @return int
	 */
	private static function get_query_int( string $key ): int {
		$value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_INT );
		return ( false === $value || null === $value ) ? 0 : (int) $value;
	}

	/**
	 * Read the active top-level tab.
	 *
	 * @return string One of: overview, diff, settings.
	 */
	private static function current_tab(): string {
		$tab = sanitize_key( self::get_query_string( 'tab', 'overview' ) );
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
	public static function tools_page_url( array $args = [] ): string {
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
	public static function render_primary_tabs( string $active_tab, int $post_id = 0 ): void {
		$overview_url = self::tools_page_url();
		$diff_args = [ 'tab' => 'diff' ];
		if ( $post_id > 0 ) {
			$diff_args['post_id'] = $post_id;
		}
		$diff_url = self::tools_page_url( $diff_args );
		$settings_url = self::tools_page_url( [ 'tab' => 'settings' ] );
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'WP Git Sync', 'wp-git-sync' ); ?>">
			<a href="<?php echo esc_url( $overview_url ); ?>" class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'wp-git-sync' ); ?></a>
			<a href="<?php echo esc_url( $diff_url ); ?>" class="nav-tab <?php echo 'diff' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Diff', 'wp-git-sync' ); ?></a>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'wp-git-sync' ); ?></a>
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
	 * Build a user-scoped transient key for GitHub rate-limit state.
	 *
	 * @return string
	 */
	private static function rate_limit_transient_key(): string {
		return 'wpgs_rate_limit_' . (int) get_current_user_id();
	}

	/**
	 * Normalize an internal rate-limit payload.
	 *
	 * @param array<string,mixed> $raw Raw payload.
	 * @return array{limit:int,remaining:int,used:int,reset:int,resource:string,collected_at:int}|array{}
	 */
	private static function normalize_rate_limit_state( array $raw ): array {
		$limit     = isset( $raw['limit'] ) ? max( 0, (int) $raw['limit'] ) : 0;
		$remaining = isset( $raw['remaining'] ) ? max( 0, (int) $raw['remaining'] ) : 0;
		$used      = isset( $raw['used'] ) ? max( 0, (int) $raw['used'] ) : 0;
		$reset     = isset( $raw['reset'] ) ? max( 0, (int) $raw['reset'] ) : 0;
		$resource  = isset( $raw['resource'] ) ? trim( (string) $raw['resource'] ) : '';
		$collected = isset( $raw['collected_at'] ) ? max( 0, (int) $raw['collected_at'] ) : time();

		if ( $limit <= 0 && $remaining <= 0 && $used <= 0 && $reset <= 0 && '' === $resource ) {
			return [];
		}

		return [
			'limit'        => $limit,
			'remaining'    => $remaining,
			'used'         => $used,
			'reset'        => $reset,
			'resource'     => $resource,
			'collected_at' => $collected,
		];
	}

	/**
	 * Persist user-scoped GitHub rate-limit state for UI display.
	 *
	 * @param array<string,mixed> $raw Raw rate-limit payload.
	 * @return void
	 */
	private static function store_rate_limit_state( array $raw ): void {
		$normalized = self::normalize_rate_limit_state( $raw );
		if ( empty( $normalized ) ) {
			return;
		}

		$ttl = 2 * HOUR_IN_SECONDS;
		if ( $normalized['reset'] > time() ) {
			$ttl = max( 60, (int) ( $normalized['reset'] - time() + ( 5 * MINUTE_IN_SECONDS ) ) );
		}

		set_transient( self::rate_limit_transient_key(), $normalized, $ttl );
	}

	/**
	 * Read current user-scoped GitHub rate-limit state.
	 *
	 * @return array{limit:int,remaining:int,used:int,reset:int,resource:string,collected_at:int}|array{}
	 */
	private static function current_rate_limit_state(): array {
		$stored = get_transient( self::rate_limit_transient_key() );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		return self::normalize_rate_limit_state( $stored );
	}

	/**
	 * Collect post IDs to export in deterministic order.
	 *
	 * @param string[] $post_types Post types.
	 * @param bool     $only_errors Whether to include only posts currently in error state.
	 * @return int[]
	 */
	private static function collect_export_post_ids( array $post_types = [ 'post', 'page' ], bool $only_errors = false ): array {
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
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				]
			);

			if ( ! empty( $q->posts ) && is_array( $q->posts ) ) {
				foreach ( $q->posts as $id ) {
					$post_id = (int) $id;
					if ( $only_errors ) {
						$last_error = trim( (string) get_post_meta( $post_id, WPGS_Sync_Meta::KEY_LAST_ERROR, true ) );
						if ( '' === $last_error ) {
							continue;
						}
					}
					$post_ids[] = $post_id;
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
		return __( 'All post types', 'wp-git-sync' );
	}

	/**
	 * Build post-type tab data for the overview repository card.
	 *
	 * @param string[] $post_types Post types to include.
	 * @return array<int,array{slug:string,label:string,count:int,error_count:int,rows:array<int,array{id:int,title:string,edit_link:string,synced:bool,last_synced_at:string,last_error:string,override_state:string}>}>
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
			$error_count = 0;
			foreach ( $ids as $id ) {
				$state = WPGS_Sync_Meta::get( $id );
				$synced = WPGS_Sync_Meta::is_synced( $id );
				$last_error = trim( (string) ( $state['last_error'] ?? '' ) );
				$override_state = trim( (string) ( $state['override_state'] ?? '' ) );
				$out_of_sync = WPGS_Sync_Meta::OVERRIDE_OUT_OF_SYNC === strtolower( $override_state );
				if ( ! $synced && '' === $last_error && ! $out_of_sync ) {
					continue;
				}
				if ( '' !== $last_error ) {
					++$error_count;
				}

				$title = get_the_title( $id );
				$title = '' !== trim( (string) $title ) ? (string) $title : __( '(no title)', 'wp-git-sync' );
				$rows[] = [
					'id'             => $id,
					'title'          => $title,
					'edit_link'      => (string) get_edit_post_link( $id, 'raw' ),
					'synced'         => $synced,
					'last_synced_at' => (string) ( $state['last_synced_at'] ?? '' ),
					'last_error'     => $last_error,
					'override_state' => $override_state,
				];
			}

			$data[] = [
				'slug'        => $slug,
				'label'       => $label,
				'count'       => count( $ids ),
				'error_count' => $error_count,
				'rows'        => $rows,
			];
		}

		return $data;
	}

	/**
	 * Start (or restart) an export-all batch for the current user.
	 *
	 * @param string[] $post_types Post types to include.
	 * @param bool     $only_errors Whether to queue only posts currently marked with sync errors.
	 * @return array<string,mixed>
	 */
	private static function start_export_batch( array $post_types = [], bool $only_errors = false ): array {
		$settings = WPGS_Settings::get();
		$owner = trim( (string) ( $settings['github_owner'] ?? '' ) );
		$repo  = trim( (string) ( $settings['github_repo'] ?? '' ) );
		if ( '' === $owner || '' === $repo ) {
			throw new RuntimeException( esc_html__( 'GitHub owner/repo not configured.', 'wp-git-sync' ) );
		}
		// Validate token is available before queueing work.
		WPGS_Auth::get_token( $settings );

		$included_post_types = self::included_post_types();
		if ( empty( $included_post_types ) ) {
			throw new RuntimeException( esc_html__( 'No included post types configured. Update settings first.', 'wp-git-sync' ) );
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
			throw new RuntimeException( esc_html__( 'No included post types configured. Update settings first.', 'wp-git-sync' ) );
		}
		$post_ids = self::collect_export_post_ids( $post_types, $only_errors );
		$total_posts = count( $post_ids );
		$total_steps = $total_posts + 1; // +1 finalization step.
		$scope_label = self::batch_scope_label( $post_types );
		if ( $only_errors ) {
			$scope_label .= ' (errors)';
		}

		$batch = [
			'post_types'      => $post_types,
			'scope_label'     => $scope_label,
			'only_errors'     => $only_errors,
			'queue'           => $post_ids,
			'rate_limit'      => self::current_rate_limit_state(),
			'paused'          => false,
			'total_posts'     => $total_posts,
			'processed_posts' => 0,
			'processed_steps' => 0,
			'total_steps'     => max( 1, $total_steps ),
			'succeeded'       => 0,
			'failed'          => [],
			'finalized'       => false,
			'last_step'       => [
				'type'    => 'start',
				'ok'      => true,
				'message' => $only_errors ? __( 'Error-only batch queued.', 'wp-git-sync' ) : __( 'Batch queued.', 'wp-git-sync' ),
			],
			'started_at'      => gmdate( 'c' ),
			'updated_at'      => gmdate( 'c' ),
		];

		set_transient( self::export_batch_transient_key(), $batch, 2 * HOUR_IN_SECONDS );
		return $batch;
	}

	/**
	 * Determine whether an exception message indicates a GitHub API rate-limit failure.
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	private static function is_github_rate_limit_error_message( string $message ): bool {
		$needle = strtolower( trim( $message ) );
		if ( '' === $needle ) {
			return false;
		}

		foreach ( [ 'api rate limit exceeded', 'rate limit exceeded', 'secondary rate limit', 'temporarily being throttled', 'being throttled', 'request failed (429)', 'too many requests' ] as $fragment ) {
			if ( false !== strpos( $needle, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Standard user-facing message for paused exports caused by API rate limits.
	 *
	 * @return string
	 */
	private static function github_rate_limit_pause_message(): string {
		return __( 'GitHub API rate limit reached. Export paused automatically. Please wait a few minutes, then click Resume Export.', 'wp-git-sync' );
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
		$total_posts = max( 0, (int) ( $batch['total_posts'] ?? 0 ) );
		$processed_posts = min( $total_posts, max( 0, (int) ( $batch['processed_posts'] ?? 0 ) ) );
		$failed = isset( $batch['failed'] ) && is_array( $batch['failed'] ) ? $batch['failed'] : [];
		$percent = 0;
		if ( $total_posts > 0 ) {
			$percent = (int) floor( ( $processed_posts / $total_posts ) * 100 );
		} elseif ( $done ) {
			$percent = 100;
		}

		$rate_limit = [];
		if ( isset( $batch['rate_limit'] ) && is_array( $batch['rate_limit'] ) ) {
			$rate_limit = self::normalize_rate_limit_state( $batch['rate_limit'] );
		}
		if ( empty( $rate_limit ) ) {
			$rate_limit = self::current_rate_limit_state();
		}

		return [
			'scope_label' => (string) ( $batch['scope_label'] ?? __( 'All post types', 'wp-git-sync' ) ),
			'done'      => $done,
			'paused'    => ! empty( $batch['paused'] ),
			'processed' => $processed_posts,
			'total'     => $total_posts,
			'remaining' => isset( $batch['queue'] ) && is_array( $batch['queue'] ) ? count( $batch['queue'] ) : 0,
			'succeeded' => (int) ( $batch['succeeded'] ?? 0 ),
			'failed'    => count( $failed ),
			'percent'   => $percent,
			'rate_limit'=> $rate_limit,
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
			throw new RuntimeException( esc_html__( 'No active export batch. Start a new export first.', 'wp-git-sync' ) );
		}

		$batch['paused'] = false;

		$exporter = new WPGS_Exporter( WPGS_Settings::get() );
		$last_step = [
			'type'    => '',
			'ok'      => true,
			'message' => '',
		];
		$count_step = false;
		$count_post = false;

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
						/* translators: %d: Post ID. */
						'message' => sprintf( __( 'Exported post #%d', 'wp-git-sync' ), $post_id ),
						'post_id' => $post_id,
					];
				$count_step = true;
				$count_post = true;
			} catch ( Throwable $e ) {
				$error_message = (string) $e->getMessage();
				if ( self::is_github_rate_limit_error_message( $error_message ) ) {
					array_unshift( $queue, $post_id );
					$batch['queue'] = $queue;
					$batch['paused'] = true;
					$last_step = [
						'type'         => 'pause',
						'ok'           => false,
						'rate_limited' => true,
						'message'      => self::github_rate_limit_pause_message(),
						'error'        => $error_message,
					];
				} else {
					WPGS_Sync_Meta::set_error( $post_id, $error_message );
					if ( ! isset( $batch['failed'] ) || ! is_array( $batch['failed'] ) ) {
						$batch['failed'] = [];
					}
					$batch['failed'][] = [
						'post_id' => $post_id,
						'error'   => $error_message,
					];
						$last_step = [
							'type'    => 'post',
							'ok'      => false,
							/* translators: 1: Post ID, 2: Error message. */
							'message' => sprintf( __( 'Failed exporting post #%1$d: %2$s', 'wp-git-sync' ), $post_id, $error_message ),
							'post_id' => $post_id,
						];
					$count_step = true;
					$count_post = true;
				}
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
					'message' => __( 'Finalized export batch cleanup.', 'wp-git-sync' ),
				];
				$batch['finalized'] = true;
				$count_step = true;
			} catch ( Throwable $e ) {
				$error_message = (string) $e->getMessage();
				if ( self::is_github_rate_limit_error_message( $error_message ) ) {
					$batch['paused'] = true;
					$last_step = [
						'type'         => 'pause',
						'ok'           => false,
						'rate_limited' => true,
						'message'      => self::github_rate_limit_pause_message(),
						'error'        => $error_message,
					];
				} else {
					if ( ! isset( $batch['failed'] ) || ! is_array( $batch['failed'] ) ) {
						$batch['failed'] = [];
					}
					$batch['failed'][] = [
						'post_id' => 0,
						'error'   => $error_message,
					];
						$last_step = [
							'type'    => 'finalize',
							'ok'      => false,
							/* translators: %s: Error message. */
							'message' => sprintf( __( 'Finalize step failed: %s', 'wp-git-sync' ), $error_message ),
						];
					$batch['finalized'] = true;
					$count_step = true;
				}
			}
		}

		if ( $count_step ) {
			$batch['processed_steps'] = (int) ( $batch['processed_steps'] ?? 0 ) + 1;
		}
		if ( $count_post ) {
			$batch['processed_posts'] = (int) ( $batch['processed_posts'] ?? 0 ) + 1;
		}
		$latest_rate_limit = WPGS_GitHub_Client::get_last_rate_limit();
		if ( ! empty( $latest_rate_limit ) ) {
			$normalized_rate_limit = self::normalize_rate_limit_state( $latest_rate_limit );
			if ( ! empty( $normalized_rate_limit ) ) {
				$batch['rate_limit'] = $normalized_rate_limit;
				self::store_rate_limit_state( $normalized_rate_limit );
			}
		}
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
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
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
		$state      = sanitize_key( self::get_query_string( 'wpgs', '' ) );
		$settings_url = self::tools_page_url( [ 'tab' => 'settings' ] );
		$included_post_types = self::included_post_types();
		$post_type_tabs = $repo_ready ? self::post_type_tab_data( $included_post_types ) : [];
		$rate_limit = self::current_rate_limit_state();

		WPGS_Admin_Page_Main::render(
			[
				'action_url'          => admin_url( 'admin-post.php' ),
				'state'               => $state,
				'settings_url'        => $settings_url,
				'repo_ready'          => $repo_ready,
				'repo_full'           => $repo_full,
				'branch'              => $branch,
				'repo_url'            => $repo_url,
				'rate_limit'          => $rate_limit,
				'included_post_types' => $included_post_types,
				'post_type_tabs'      => $post_type_tabs,
			]
		);
	}

	/**
	 * Render the Settings → WP Git Sync page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$settings = WPGS_Settings::get();
		$token_source = WPGS_Auth::token_source( $settings );
		$refresh_requested = '1' === self::get_query_string( 'wpgs_refresh_repos', '' );
		$refresh_nonce = self::get_query_string( '_wpgs_refresh_nonce', '' );
		$force_repo_refresh = $refresh_requested && wp_verify_nonce( $refresh_nonce, 'wpgs_refresh_repos' );
		$refresh_repos_url = wp_nonce_url(
			self::tools_page_url(
				[
					'tab'                => 'settings',
					'wpgs_refresh_repos' => '1',
				]
			),
			'wpgs_refresh_repos',
			'_wpgs_refresh_nonce'
		);
		$selected_repo_full = '';
		if ( '' !== trim( (string) $settings['github_owner'] ) && '' !== trim( (string) $settings['github_repo'] ) ) {
			$selected_repo_full = trim( (string) $settings['github_owner'] ) . '/' . trim( (string) $settings['github_repo'] );
		}

		$token_available = 'none' !== $token_source;
		$repo_options = [];
		$repo_fetch_error = '';
		if ( $token_available ) {
			try {
				$token = WPGS_Auth::get_token( $settings );
				$repos = self::fetch_repo_options( $token, $force_repo_refresh );
				if ( is_wp_error( $repos ) ) {
					$repo_fetch_error = $repos->get_error_message();
				} else {
					$repo_options = $repos;
				}
			} catch ( Throwable $e ) {
				$token_available = false;
				$token_source = 'none';
			}
		}

		if ( '' !== $selected_repo_full && ! in_array( $selected_repo_full, $repo_options, true ) ) {
			$repo_options[] = $selected_repo_full;
		}
		sort( $repo_options, SORT_NATURAL | SORT_FLAG_CASE );
		$post_type_options = WPGS_Settings::available_post_type_options();
		$included_post_types = isset( $settings['included_post_types'] ) && is_array( $settings['included_post_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $settings['included_post_types'] ) ) )
			: [];

		WPGS_Admin_Page_Settings::render(
			[
				'settings'            => $settings,
				'token_source'        => $token_source,
				'token_available'     => $token_available,
				'repo_options'        => $repo_options,
				'repo_fetch_error'    => $repo_fetch_error,
				'refresh_repos_url'   => $refresh_repos_url,
				'post_type_options'   => $post_type_options,
				'included_post_types' => $included_post_types,
				'selected_repo_full'  => $selected_repo_full,
			]
		);
	}

	/**
	 * Start export batch via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_export_batch_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-git-sync' ) ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		try {
			$post_type = isset( $_POST['post_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['post_type'] ) ) : '';
			$only_errors = isset( $_POST['only_errors'] ) && 1 === absint( wp_unslash( (string) $_POST['only_errors'] ) );
			$post_types = [];
			if ( '' !== $post_type ) {
				$allowed = self::included_post_types();
				if ( ! in_array( $post_type, $allowed, true ) ) {
					wp_send_json_error( [ 'message' => __( 'Invalid post type selected for export.', 'wp-git-sync' ) ], 400 );
				}
				$post_types = [ $post_type ];
			}

			$batch = self::start_export_batch( $post_types, $only_errors );
			$payload = self::export_batch_progress_payload(
				$batch,
				[
					'type'    => 'start',
					'ok'      => true,
					'message' => $only_errors ? __( 'Error-only batch queued.', 'wp-git-sync' ) : __( 'Batch queued.', 'wp-git-sync' ),
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
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-git-sync' ) ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		$batch = get_transient( self::export_batch_transient_key() );
		if ( ! is_array( $batch ) ) {
			wp_send_json_success(
				[
					'active'     => false,
					'done'       => false,
					'rate_limit' => self::current_rate_limit_state(),
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
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-git-sync' ) ], 403 );
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
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-git-sync' ) ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		$key = self::export_batch_transient_key();
		$had_batch = is_array( get_transient( $key ) );
		delete_transient( $key );

		wp_send_json_success(
			[
				'active'    => false,
				'done'      => false,
				'rate_limit'=> self::current_rate_limit_state(),
				'last_step' => [
					'type'    => 'stop',
					'ok'      => true,
					'message' => $had_batch ? __( 'Export batch stopped.', 'wp-git-sync' ) : __( 'No active export batch to stop.', 'wp-git-sync' ),
				],
			]
		);
	}

	/**
	 * Export a single post via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_export_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-git-sync' ) ], 403 );
		}
		check_ajax_referer( 'wpgs_export_batch', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post.', 'wp-git-sync' ) ], 400 );
		}

		$included = self::included_post_types();
		if ( ! in_array( (string) $post->post_type, $included, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Post type is not included in sync settings.', 'wp-git-sync' ) ], 400 );
		}

		$exporter = new WPGS_Exporter( WPGS_Settings::get() );
		try {
			$exporter->export_post( $post_id );
			$state = WPGS_Sync_Meta::get( $post_id );
			wp_send_json_success(
				[
					'post_id' => $post_id,
					/* translators: %d: Post ID. */
					'message' => sprintf( __( 'Exported post #%d', 'wp-git-sync' ), $post_id ),
					'state'   => [
						'synced'         => WPGS_Sync_Meta::is_synced( $post_id ),
						'last_synced_at' => (string) ( $state['last_synced_at'] ?? '' ),
						'last_error'     => (string) ( $state['last_error'] ?? '' ),
					],
				]
			);
		} catch ( Throwable $e ) {
			WPGS_Sync_Meta::set_error( $post_id, (string) $e->getMessage() );
			$state = WPGS_Sync_Meta::get( $post_id );
			wp_send_json_error(
				[
					'post_id' => $post_id,
					/* translators: 1: Post ID, 2: Error message. */
					'message' => sprintf( __( 'Failed syncing post #%1$d: %2$s', 'wp-git-sync' ), $post_id, (string) $e->getMessage() ),
					'state'   => [
						'synced'         => WPGS_Sync_Meta::is_synced( $post_id ),
						'last_synced_at' => (string) ( $state['last_synced_at'] ?? '' ),
						'last_error'     => (string) ( $state['last_error'] ?? '' ),
					],
				],
				500
			);
		}
	}

	/**
	 * Handle export-all admin action (non-AJAX fallback).
	 *
	 * @return void
	 */
	public static function handle_export_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
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
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}
		check_admin_referer( 'wpgs_setup_repo' );

		$settings = WPGS_Settings::get();
		$owner  = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo   = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$branch = '' !== $branch ? $branch : 'main';
		$token  = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			wp_die( esc_html__( 'GitHub owner/repo not configured.', 'wp-git-sync' ) );
		}

		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );
		try {
			$provider->reset_branch_to_empty( $branch, __( 'Setup repository for WP Git Sync', 'wp-git-sync' ) );
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
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
		$post_id = ( false === $post_id || null === $post_id ) ? 0 : (int) $post_id;
		if ( $post_id <= 0 ) {
			$post_id = self::get_query_int( 'post_id' );
		}

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			wp_die( esc_html__( 'Invalid post.', 'wp-git-sync' ) );
		}

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
	 * Build GitHub provider context from current settings.
	 *
	 * @return array{provider:WPGS_GitHub_Provider,branch:string,repo:string}
	 */
	private static function github_provider_context(): array {
		$settings = WPGS_Settings::get();
		$owner    = isset( $settings['github_owner'] ) ? trim( (string) $settings['github_owner'] ) : '';
		$repo     = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';
		$branch   = isset( $settings['branch'] ) ? trim( (string) $settings['branch'] ) : 'main';
		$branch   = '' !== $branch ? $branch : 'main';
		$token    = WPGS_Auth::get_token( $settings );

		if ( '' === $owner || '' === $repo ) {
			throw new RuntimeException( esc_html__( 'GitHub owner/repo not configured.', 'wp-git-sync' ) );
		}

		return [
			'provider' => new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo ),
			'branch'   => $branch,
			'repo'     => $owner . '/' . $repo,
		];
	}

	/**
	 * Compute and store diff result for a post in the user-scoped transient.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	private static function run_diff_check_for_post( WP_Post $post ): void {
		$context  = self::github_provider_context();
		$provider = $context['provider'];
		$branch   = (string) $context['branch'];
		$repo     = (string) $context['repo'];

		$paths = WPGS_Diff::paths_for_post( $post );
		$local = WPGS_Diff::build_local_payload( $post );

		try {
			$remote_content = $provider->get_file_contents( $branch, $paths['content_path'] );
			$remote_post    = $provider->get_file_contents( $branch, $paths['post_path'] );
			$remote_meta    = $provider->get_file_contents( $branch, $paths['meta_path'] );
		} catch ( Throwable $e ) {
			/* translators: %s: Error message from GitHub fetch. */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to fetch remote files for diff: %s', 'wp-git-sync' ), esc_html( $e->getMessage() ) ) );
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
				'title_left'      => __( 'Remote', 'wp-git-sync' ),
				'title_right'     => __( 'Local', 'wp-git-sync' ),
			]
		) : '';
		$post_diff    = $post_changed ? wp_text_diff(
			$remote_post_n,
			$local_post_n,
			[
				'show_split_view' => true,
				'title_left'      => __( 'Remote', 'wp-git-sync' ),
				'title_right'     => __( 'Local', 'wp-git-sync' ),
			]
		) : '';
		$meta_diff    = $meta_changed ? wp_text_diff(
			$remote_meta_n,
			$local_meta_n,
			[
				'show_split_view' => true,
				'title_left'      => __( 'Remote', 'wp-git-sync' ),
				'title_right'     => __( 'Local', 'wp-git-sync' ),
			]
		) : '';

		$transient_key = self::diff_transient_key( (int) $post->ID, (int) get_current_user_id() );
		set_transient(
			$transient_key,
			[
				'checked_at'      => gmdate( 'c' ),
				'repo'            => $repo,
				'branch'          => $branch,
				'post_id'         => (int) $post->ID,
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
			],
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Decode a JSON payload and ensure it is an array.
	 *
	 * @param string $json Raw JSON.
	 * @param string $label Payload label for errors.
	 * @return array<mixed>
	 */
	private static function decode_json_array_payload( string $json, string $label ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			/* translators: %s: Payload label (for example "post data" or "meta data"). */
			throw new RuntimeException( sprintf( esc_html__( 'Unable to decode remote %s JSON.', 'wp-git-sync' ), esc_html( $label ) ) );
		}
		return $decoded;
	}

	/**
	 * Apply imported post-table data to a local post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $post_data Remote post-data payload.
	 * @return void
	 */
	private static function apply_remote_post_data( int $post_id, array $post_data ): void {
		unset( $post_data['ID'], $post_data['post_content'], $post_data['filter'] );

		$allowed_keys = [
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_content_filtered',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
		];

		$update = [ 'ID' => $post_id ];
		foreach ( $allowed_keys as $key ) {
			if ( array_key_exists( $key, $post_data ) ) {
				$update[ $key ] = $post_data[ $key ];
			}
		}

		if ( 1 === count( $update ) ) {
			return;
		}

		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}
	}

	/**
	 * Apply imported post meta payload to a local post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $meta_payload Remote meta payload.
	 * @return void
	 */
	private static function apply_remote_post_meta( int $post_id, array $meta_payload ): void {
		$protected = array_fill_keys(
			array_merge( WPGS_Sync_Meta::internal_keys(), WPGS_Diff::meta_blacklist() ),
			true
		);

		$existing = get_post_meta( $post_id );
		if ( is_array( $existing ) ) {
			foreach ( array_keys( $existing ) as $meta_key ) {
				$meta_key = (string) $meta_key;
				if ( '' === $meta_key || isset( $protected[ $meta_key ] ) ) {
					continue;
				}
				delete_post_meta( $post_id, $meta_key );
			}
		}

		foreach ( $meta_payload as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			if ( '' === $meta_key || isset( $protected[ $meta_key ] ) ) {
				continue;
			}

			$list = self::normalize_meta_values_for_import( $values );
			foreach ( $list as $value ) {
				add_post_meta( $post_id, $meta_key, self::prepare_meta_value_for_storage( $value ), false );
			}
		}
	}

	/**
	 * Normalize imported meta values to a list of meta rows.
	 *
	 * Meta export payload is typically key => list-of-values. However, when a
	 * key stores a single value that is itself an associative array/object-like
	 * structure, treat it as one value rather than splitting by keys.
	 *
	 * @param mixed $values Raw payload value for one meta key.
	 * @return array<int,mixed>
	 */
	private static function normalize_meta_values_for_import( $values ): array {
		if ( ! is_array( $values ) ) {
			return [ $values ];
		}

		$keys = array_keys( $values );
		$is_list = ( $keys === range( 0, count( $values ) - 1 ) );
		if ( $is_list ) {
			return $values;
		}

		return [ $values ];
	}

	/**
	 * Prepare one meta value for storage without double-serializing payloads.
	 *
	 * WordPress metadata APIs will serialize arrays/objects and also re-serialize
	 * strings that already look serialized. To preserve existing serialized
	 * payloads from the repo, decode once before writing so core serializes once.
	 *
	 * @param mixed $value Meta value from repo payload.
	 * @return mixed
	 */
	private static function prepare_meta_value_for_storage( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return maybe_unserialize( $value );
		}

		return $value;
	}

	/**
	 * Handle "check for changes" for a single post.
	 *
	 * @return void
	 */
	public static function handle_check_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_check_post_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Invalid post.', 'wp-git-sync' ) );
		}

		try {
			self::run_diff_check_for_post( $post );
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id ] ) );
		exit;
	}

	/**
	 * Import remote content from GitHub and overwrite local post content.
	 *
	 * @return void
	 */
	public static function handle_pull_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_pull_post_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Invalid post.', 'wp-git-sync' ) );
		}

		try {
			$context  = self::github_provider_context();
			$provider = $context['provider'];
			$branch   = (string) $context['branch'];
			$paths    = WPGS_Diff::paths_for_post( $post );

			$remote_content = $provider->get_file_contents( $branch, $paths['content_path'] );
			$res = wp_update_post(
				[
					'ID'           => (int) $post_id,
					'post_content' => (string) $remote_content,
				],
				true
			);

			if ( is_wp_error( $res ) ) {
				throw new RuntimeException( $res->get_error_message() );
			}

			$updated_post = get_post( $post_id );
			if ( $updated_post ) {
				self::run_diff_check_for_post( $updated_post );
			}
		} catch ( Throwable $e ) {
			/* translators: %s: Error message while importing content. */
			wp_die( esc_html( sprintf( __( 'Unable to import remote content: %s', 'wp-git-sync' ), $e->getMessage() ) ) );
		}

		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id ] ) );
		exit;
	}

	/**
	 * Import remote post-table JSON and apply it to the local post.
	 *
	 * @return void
	 */
	public static function handle_pull_post_data(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_pull_post_data_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Invalid post.', 'wp-git-sync' ) );
		}

		try {
			$context  = self::github_provider_context();
			$provider = $context['provider'];
			$branch   = (string) $context['branch'];
			$paths    = WPGS_Diff::paths_for_post( $post );

			$remote_post_json = $provider->get_file_contents( $branch, $paths['post_path'] );
			$post_payload     = self::decode_json_array_payload( $remote_post_json, __( 'post data', 'wp-git-sync' ) );
			self::apply_remote_post_data( $post_id, $post_payload );

			$updated_post = get_post( $post_id );
			if ( $updated_post ) {
				self::run_diff_check_for_post( $updated_post );
			}
		} catch ( Throwable $e ) {
			/* translators: %s: Error message while importing post data. */
			wp_die( esc_html( sprintf( __( 'Unable to import remote post data: %s', 'wp-git-sync' ), $e->getMessage() ) ) );
		}

		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id ] ) );
		exit;
	}

	/**
	 * Import remote post-meta JSON and apply it to local post meta.
	 *
	 * @return void
	 */
	public static function handle_pull_post_meta(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'wpgs_pull_post_meta_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Invalid post.', 'wp-git-sync' ) );
		}

		try {
			$context  = self::github_provider_context();
			$provider = $context['provider'];
			$branch   = (string) $context['branch'];
			$paths    = WPGS_Diff::paths_for_post( $post );

			$remote_meta_json = $provider->get_file_contents( $branch, $paths['meta_path'] );
			$meta_payload     = self::decode_json_array_payload( $remote_meta_json, __( 'meta data', 'wp-git-sync' ) );
			self::apply_remote_post_meta( $post_id, $meta_payload );

			$updated_post = get_post( $post_id );
			if ( $updated_post ) {
				self::run_diff_check_for_post( $updated_post );
			}
		} catch ( Throwable $e ) {
			/* translators: %s: Error message while importing post meta data. */
			wp_die( esc_html( sprintf( __( 'Unable to import remote meta data: %s', 'wp-git-sync' ), $e->getMessage() ) ) );
		}

		wp_safe_redirect( self::tools_page_url( [ 'tab' => 'diff', 'post_id' => (int) $post_id ] ) );
		exit;
	}

	/**
	 * Render the diff/management page for a single post.
	 *
	 * @return void
	 */
	public static function render_diff_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-git-sync' ) );
		}

		$post_id = self::get_query_int( 'post_id' );
		$post    = $post_id ? get_post( $post_id ) : null;

		$transient_key = self::diff_transient_key( (int) $post_id, (int) get_current_user_id() );
		$diff          = get_transient( $transient_key );
		$diff          = is_array( $diff ) ? $diff : null;

		$sync_state     = $post_id > 0 ? WPGS_Sync_Meta::get( (int) $post_id ) : [];
		$has_sync_state = false;
		foreach ( $sync_state as $sync_value ) {
			if ( '' !== trim( (string) $sync_value ) ) {
				$has_sync_state = true;
				break;
			}
		}

		$content_changed = (bool) ( $diff['content_changed'] ?? false );
		$post_changed    = (bool) ( $diff['post_changed'] ?? false );
		$meta_changed    = (bool) ( $diff['meta_changed'] ?? false );
		$has_diff        = (bool) $diff;
		$content_status_class = $has_diff ? ( $content_changed ? 'is-red' : 'is-green' ) : 'is-neutral';
		$post_status_class    = $has_diff ? ( $post_changed ? 'is-red' : 'is-green' ) : 'is-neutral';
		$meta_status_class    = $has_diff ? ( $meta_changed ? 'is-red' : 'is-green' ) : 'is-neutral';

		$post_card_title = '';
		if ( $post instanceof WP_Post ) {
			$post_type_obj = get_post_type_object( (string) $post->post_type );
			$post_card_title = ( $post_type_obj && isset( $post_type_obj->labels->singular_name ) && '' !== (string) $post_type_obj->labels->singular_name )
				? (string) $post_type_obj->labels->singular_name
				: ucfirst( (string) $post->post_type );
		}

		$content_file_url = '';
		$post_file_url    = '';
		$meta_file_url    = '';
		if ( $has_sync_state ) {
			$link_state = [
				'repo'   => (string) ( $sync_state['repo'] ?? '' ),
				'branch' => (string) ( $sync_state['branch'] ?? '' ),
			];
			$content_file_url = self::github_file_url_from_state(
				[
					'repo'   => $link_state['repo'],
					'branch' => $link_state['branch'],
					'path'   => (string) ( $sync_state['content_path'] ?? '' ),
				]
			);
			$post_file_url = self::github_file_url_from_state(
				[
					'repo'   => $link_state['repo'],
					'branch' => $link_state['branch'],
					'path'   => (string) ( $sync_state['post_path'] ?? '' ),
				]
			);
			$meta_file_url = self::github_file_url_from_state(
				[
					'repo'   => $link_state['repo'],
					'branch' => $link_state['branch'],
					'path'   => (string) ( $sync_state['meta_path'] ?? '' ),
				]
			);
		}

		WPGS_Admin_Page_Diff::render(
			[
				'post'                 => $post,
				'post_id'              => (int) $post_id,
				'overview_url'         => self::tools_page_url(),
				'has_sync_state'       => $has_sync_state,
				'has_diff'             => $has_diff,
				'edit_link'            => get_edit_post_link( (int) $post_id, 'raw' ),
				'action_url'           => admin_url( 'admin-post.php' ),
				'post_card_title'      => $post_card_title,
				'content_file_url'     => $content_file_url,
				'post_file_url'        => $post_file_url,
				'meta_file_url'        => $meta_file_url,
				'sync_state'           => $sync_state,
				'diff'                 => $diff,
				'content_changed'      => $content_changed,
				'post_changed'         => $post_changed,
				'meta_changed'         => $meta_changed,
				'content_status_class' => $content_status_class,
				'post_status_class'    => $post_status_class,
				'meta_status_class'    => $meta_status_class,
			]
		);
	}

	/**
	 * Fetch repos visible to the current PAT and return full-name options.
	 *
	 * Uses a short transient cache keyed by token hash to avoid repeated API calls.
	 *
	 * @param string $token GitHub PAT.
	 * @return array<int,string>|WP_Error
	 */
	private static function fetch_repo_options( string $token, bool $force_refresh = false ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return [];
		}

		$cache_key = 'wpgs_repo_opts_' . substr( sha1( $token ), 0, 16 );
		if ( $force_refresh ) {
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
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
				$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'GitHub API error.', 'wp-git-sync' );
				/* translators: 1: HTTP status code, 2: Error message returned from GitHub API. */
				return new WP_Error( 'wpgs_repo_fetch_failed', sprintf( __( 'GitHub API request failed (%1$d): %2$s', 'wp-git-sync' ), $code, $message ) );
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
	 * @param array<string,mixed> $state Sync state.
	 * @return string GitHub URL or empty string when not buildable.
	 */
	private static function github_file_url_from_state( array $state ): string {
		$repo    = trim( (string) ( $state['repo'] ?? '' ) );
		$branch  = trim( (string) ( $state['branch'] ?? '' ) );
		$path    = '';
		foreach ( [ 'path', 'content_path', 'post_path', 'meta_path' ] as $key ) {
			$candidate = ltrim( (string) ( $state[ $key ] ?? '' ), '/' );
			if ( '' !== $candidate ) {
				$path = $candidate;
				break;
			}
		}

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
			add_meta_box( 'wpgs_metabox', __( 'WP Git Sync', 'wp-git-sync' ), [ __CLASS__, 'render_metabox' ], $pt, 'side' );
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
		$content_file_url = self::github_file_url_from_state(
			[
				'repo'   => (string) ( $state['repo'] ?? '' ),
				'branch' => (string) ( $state['branch'] ?? '' ),
				'path'   => (string) ( $state['content_path'] ?? '' ),
			]
		);
		$post_file_url = self::github_file_url_from_state(
			[
				'repo'   => (string) ( $state['repo'] ?? '' ),
				'branch' => (string) ( $state['branch'] ?? '' ),
				'path'   => (string) ( $state['post_path'] ?? '' ),
			]
		);
		$meta_file_url = self::github_file_url_from_state(
			[
				'repo'   => (string) ( $state['repo'] ?? '' ),
				'branch' => (string) ( $state['branch'] ?? '' ),
				'path'   => (string) ( $state['meta_path'] ?? '' ),
			]
		);
		$diff_url = self::tools_page_url(
			[
				'tab'     => 'diff',
				'post_id' => (int) $post->ID,
			]
		);
		$export_url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'wpgs_export_post',
					'post_id' => (int) $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'wpgs_export_post_' . (int) $post->ID
		);

		WPGS_Admin_Metabox::render(
			$post,
			[
				'state'            => $state,
				'synced'           => $synced,
				'content_file_url' => $content_file_url,
				'post_file_url'    => $post_file_url,
				'meta_file_url'    => $meta_file_url,
				'diff_url'         => $diff_url,
				'export_url'       => $export_url,
			]
		);
	}
}
