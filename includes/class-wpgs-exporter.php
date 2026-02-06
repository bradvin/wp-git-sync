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
			$this->write_repo_index_readme( $mapping );

			$this->git->add_all();
			$did_commit = $this->git->commit( 'Export all posts/pages via WP Git Sync' );
			if ( $did_commit ) {
				$head = $this->git->head_commit();
				$this->stamp_mapping_commit( $mapping, $head );
				$this->save_mapping( $mapping );

				$this->git->add_path( WPGS_Paths::mapping_relpath() );
				$this->git->amend_no_edit();
				$this->git->push();
			}
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
			$this->write_repo_index_readme( $mapping );

			$this->git->add_all();
			$did_commit = $this->git->commit( sprintf( 'Export post %d (%s) via WP Git Sync', $post_id, $post->post_type ) );
			if ( $did_commit ) {
				$head = $this->git->head_commit();
				$this->stamp_mapping_commit( $mapping, $head, $post_id );
				$this->save_mapping( $mapping );

				$this->git->add_path( WPGS_Paths::mapping_relpath() );
				$this->git->amend_no_edit();
				$this->git->push();
			}
		} finally {
			$this->git->unlock();
		}
	}

	private function export_post_type( string $post_type, array &$mapping ): void {
		// v0: simplest approach (OK for small sites). If/when needed, switch to a paged loop.
		$q = new WP_Query([
			'post_type'              => $post_type,
			'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		]);

		while ( $q->have_posts() ) {
			$q->the_post();
			$post = get_post();
			if ( $post ) {
				$this->export_one( $post, $mapping );
			}
		}
		wp_reset_postdata();
	}

	private function export_one( WP_Post $post, array &$mapping ): void {
		$dir  = (string) ( $this->settings['local_clone_path'] ?? '' );
		$slug = $post->post_name ? $post->post_name : (string) $post->ID;

		$content_rel = WPGS_Paths::post_relpath( $post->post_type, (int) $post->ID, $slug );
		$meta_rel    = WPGS_Paths::meta_relpath( $post->post_type, (int) $post->ID, $slug );

		// If this post was previously exported to different paths, remove stale files.
		$prev = $mapping['items'][ (string) $post->ID ] ?? null;
		if ( is_array( $prev ) ) {
			$prev_content = isset( $prev['content_path'] ) ? (string) $prev['content_path'] : '';
			$prev_meta    = isset( $prev['meta_path'] ) ? (string) $prev['meta_path'] : '';
			if ( $prev_content && $prev_content !== $content_rel ) {
				$abs = $dir . '/' . ltrim( $prev_content, '/' );
				if ( file_exists( $abs ) ) {
					@unlink( $abs );
				}
			}
			if ( $prev_meta && $prev_meta !== $meta_rel ) {
				$abs = $dir . '/' . ltrim( $prev_meta, '/' );
				if ( file_exists( $abs ) ) {
					@unlink( $abs );
				}
			}
		}

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
				'ID'                => (int) $post->ID,
				'post_type'         => (string) $post->post_type,
				'post_status'       => (string) $post->post_status,
				'post_title'        => (string) $post->post_title,
				'post_name'         => (string) $post->post_name,
				'post_date_gmt'     => (string) $post->post_date_gmt,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			],
			'meta' => get_post_meta( $post->ID ),
		];

		file_put_contents( $meta_abs, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		$mapping['items'][ (string) $post->ID ] = array_merge(
			$mapping['items'][ (string) $post->ID ] ?? [],
			[
				'post_id'        => (int) $post->ID,
				'post_type'      => (string) $post->post_type,
				'slug'           => (string) WPGS_Paths::safe_slug( $slug ),
				'content_path'   => $content_rel,
				'meta_path'      => $meta_rel,
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

	private function write_repo_index_readme( array $mapping ): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		if ( '' === $dir ) {
			return;
		}

		$pages = [];
		$posts = [];
		$other = [];

		$items = isset( $mapping['items'] ) && is_array( $mapping['items'] ) ? $mapping['items'] : [];
		foreach ( $items as $id => $item ) {
			$post_id = (int) $id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$title     = $post->post_title ? (string) $post->post_title : sprintf( 'Post %d', $post_id );
			$permalink = get_permalink( $post_id );
			$path      = isset( $item['content_path'] ) ? (string) $item['content_path'] : '';
			if ( ! $permalink || ! $path ) {
				continue;
			}

			$line = sprintf( '- [%s](%s) — [file](%s)', $this->md_escape( $title ), $permalink, $path );
			if ( 'page' === $post->post_type ) {
				$pages[] = $line;
			} elseif ( 'post' === $post->post_type ) {
				$posts[] = $line;
			} else {
				$other[ $post->post_type ][] = $line;
			}
		}

		sort( $pages );
		sort( $posts );
		ksort( $other );
		foreach ( $other as $pt => $lines ) {
			sort( $other[ $pt ] );
		}

		$out   = [];
		$out[] = '# WP Git Sync Index';
		$out[] = '';
		$out[] = 'This file is generated by the WP Git Sync plugin. Do not edit by hand.';
		$out[] = '';
		$out[] = sprintf( '- Generated at: %s', gmdate( 'c' ) );
		$out[] = sprintf( '- Branch: `%s`', (string) ( $mapping['branch'] ?? '' ) );
		$out[] = '';

		$out[] = '## Pages';
		$out[] = '';
		$out   = array_merge( $out, $pages ?: [ '- (none)' ] );
		$out[] = '';

		$out[] = '## Posts';
		$out[] = '';
		$out   = array_merge( $out, $posts ?: [ '- (none)' ] );
		$out[] = '';

		$out[] = '## Other';
		$out[] = '';
		if ( empty( $other ) ) {
			$out[] = '- (none)';
		} else {
			foreach ( $other as $pt => $lines ) {
				$out[] = '### ' . $pt;
				$out[] = '';
				$out   = array_merge( $out, $lines );
				$out[] = '';
			}
		}

		file_put_contents( $dir . '/README.md', implode( "\n", $out ) . "\n" );
	}

	private function md_escape( string $text ): string {
		// Minimal escaping for link text.
		return str_replace( [ '[', ']' ], [ '\\[', '\\]' ], $text );
	}
}
