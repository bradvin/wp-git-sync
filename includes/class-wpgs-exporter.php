<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Exporter {
	private array $settings;
	private WPGS_Git $git;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->git      = new WPGS_Git( $settings );
	}

	public function export_all( array $post_types = [ 'post', 'page' ] ): void {
		$this->git->lock();
		try {
			$this->git->ensure_clone();
			$this->git->checkout_branch();
			$this->git->pull();

			$mapping = $this->load_mapping();
			foreach ( $post_types as $post_type ) {
				$this->export_post_type( $post_type, $mapping );
			}
			$this->save_mapping( $mapping );

			$this->git->commit_and_push( 'Export all posts/pages via WP Git Sync' );

			$head = $this->git->head_commit();
			$this->stamp_mapping_commit( $mapping, $head );
			$this->save_mapping( $mapping );

		} finally {
			$this->git->unlock();
		}
	}

	public function export_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new InvalidArgumentException( 'Invalid post.' );
		}

		$this->git->lock();
		try {
			$this->git->ensure_clone();
			$this->git->checkout_branch();
			$this->git->pull();

			$mapping = $this->load_mapping();
			$this->export_one( $post, $mapping );
			$this->save_mapping( $mapping );

			$this->git->commit_and_push( sprintf( 'Export post %d (%s) via WP Git Sync', $post_id, $post->post_type ) );

			$head = $this->git->head_commit();
			$this->stamp_mapping_commit( $mapping, $head, $post_id );
			$this->save_mapping( $mapping );
		} finally {
			$this->git->unlock();
		}
	}

	private function export_post_type( string $post_type, array &$mapping ): void {
		$q = new WP_Query([
			'post_type'              => $post_type,
			'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page'         => 200,
			'paged'                  => 1,
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		]);

		while ( $q->have_posts() ) {
			$q->the_post();
			$post = get_post();
			if ( $post ) {
				$this->export_one( $post, $mapping );
			}

			if ( $q->max_num_pages > $q->get( 'paged' ) ) {
				$q->set( 'paged', $q->get( 'paged' ) + 1 );
				$q->get_posts();
			} else {
				break;
			}
		}
		wp_reset_postdata();
	}

	private function export_one( WP_Post $post, array &$mapping ): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$slug = $post->post_name ? $post->post_name : (string) $post->ID;

		$content_rel = WPGS_Paths::post_relpath( $post->post_type, (int) $post->ID, $slug );
		$meta_rel    = WPGS_Paths::meta_relpath( $post->post_type, (int) $post->ID, $slug );

		$content_abs = $dir . '/' . $content_rel;
		$meta_abs    = $dir . '/' . $meta_rel;
		$mapping_abs = $dir . '/' . WPGS_Paths::mapping_relpath();

		wp_mkdir_p( dirname( $content_abs ) );
		wp_mkdir_p( dirname( $meta_abs ) );
		wp_mkdir_p( dirname( $mapping_abs ) );

		// Content file: raw post_content (no transforms yet).
		file_put_contents( $content_abs, $post->post_content );

		$meta = [
			'post' => [
				'ID'           => (int) $post->ID,
				'post_type'    => (string) $post->post_type,
				'post_status'  => (string) $post->post_status,
				'post_title'   => (string) $post->post_title,
				'post_name'    => (string) $post->post_name,
				'post_date_gmt'=> (string) $post->post_date_gmt,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			],
			'meta' => get_post_meta( $post->ID ),
		];

		file_put_contents( $meta_abs, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		$mapping['items'][ (string) $post->ID ] = array_merge(
			$mapping['items'][ (string) $post->ID ] ?? [],
			[
				'post_id'      => (int) $post->ID,
				'post_type'    => (string) $post->post_type,
				'slug'         => (string) WPGS_Paths::safe_slug( $slug ),
				'content_path' => $content_rel,
				'meta_path'    => $meta_rel,
				'last_synced_at' => gmdate( 'c' ),
			]
		);
	}

	private function load_mapping(): array {
		$dir  = (string) ( $this->settings['local_clone_path'] ?? '' );
		$file = $dir . '/' . WPGS_Paths::mapping_relpath();

		if ( ! file_exists( $file ) ) {
			return [
				'version'      => WPGS_VERSION,
				'repo_url'     => (string) ( $this->settings['repo_url'] ?? '' ),
				'branch'       => (string) ( $this->settings['branch'] ?? 'wp-content-sync' ),
				'generated_at' => gmdate( 'c' ),
				'items'        => [],
			];
		}

		$json = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $json ) ? $json : [ 'items' => [] ];
	}

	private function save_mapping( array $mapping ): void {
		$dir  = (string) ( $this->settings['local_clone_path'] ?? '' );
		$file = $dir . '/' . WPGS_Paths::mapping_relpath();
		wp_mkdir_p( dirname( $file ) );

		$mapping['version']      = WPGS_VERSION;
		$mapping['repo_url']     = (string) ( $this->settings['repo_url'] ?? '' );
		$mapping['branch']       = (string) ( $this->settings['branch'] ?? 'wp-content-sync' );
		$mapping['generated_at'] = gmdate( 'c' );

		file_put_contents( $file, wp_json_encode( $mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	private function stamp_mapping_commit( array &$mapping, string $commit, int $only_post_id = 0 ): void {
		foreach ( $mapping['items'] as $id => &$item ) {
			if ( $only_post_id && (int) $id !== $only_post_id ) {
				continue;
			}
			$item['last_synced_commit'] = $commit;
			$item['last_synced_at']     = gmdate( 'c' );
		}
	}
}
