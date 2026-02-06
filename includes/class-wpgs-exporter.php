<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports WordPress posts/pages to GitHub (in-memory, no filesystem, no git CLI).
 */
final class WPGS_Exporter {
	private array $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function export_all( array $post_types = [ 'post', 'page' ] ): void {
		foreach ( $post_types as $post_type ) {
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
					$this->export_post( (int) $post->ID );
				}
			}
			wp_reset_postdata();
		}
	}

	/**
	 * Sync a single post to GitHub.
	 *
	 * If nothing changed (based on content/meta hashes), this is a no-op.
	 */
	public function export_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new InvalidArgumentException( 'Invalid post.' );
		}

		[ $repo, $branch, $token ] = $this->resolve_target();

		$slug        = $post->post_name ? (string) $post->post_name : (string) $post->ID;
		$content_rel = WPGS_Paths::post_relpath( (string) $post->post_type, (int) $post->ID, $slug );
		$meta_rel    = WPGS_Paths::meta_relpath( (string) $post->post_type, (int) $post->ID, $slug );

		$content = (string) $post->post_content;
		$meta    = $this->build_meta_payload( $post );
		$meta_js = $this->stable_json( $meta ) . "\n";

		$content_hash = hash( 'sha256', $content );
		$meta_hash    = hash( 'sha256', $meta_js );

		$prev = WPGS_Sync_Meta::get( $post_id );
		if ( $prev['content_hash'] === $content_hash
			&& $prev['meta_hash'] === $meta_hash
			&& $prev['content_path'] === $content_rel
			&& $prev['meta_path'] === $meta_rel
			&& $prev['repo'] === $repo
			&& $prev['branch'] === $branch
		) {
			return;
		}

		$client   = new WPGS_GitHub_Client( $token );
		$provider = new WPGS_GitHub_Provider( $client, $repo );

		try {
			$result = $provider->commit_files(
				$branch,
				sprintf( 'Sync post %d (%s) via WP Git Sync', (int) $post_id, (string) $post->post_type ),
				[
					$content_rel => $content,
					$meta_rel    => $meta_js,
				]
			);

			WPGS_Sync_Meta::set_success( $post_id, [
				'repo'           => $repo,
				'branch'         => $branch,
				'content_path'   => $content_rel,
				'meta_path'      => $meta_rel,
				'last_commit'    => (string) $result['commit_sha'],
				'last_synced_at' => gmdate( 'c' ),
				'content_hash'   => $content_hash,
				'meta_hash'      => $meta_hash,
			] );
		} catch ( Throwable $e ) {
			WPGS_Sync_Meta::set_error( $post_id, $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * @return array{0:string,1:string,2:string} repo, branch, token
	 */
	private function resolve_target(): array {
		$repo_raw = isset( $this->settings['repo'] ) ? (string) $this->settings['repo'] : '';
		$branch   = isset( $this->settings['branch'] ) ? (string) $this->settings['branch'] : 'wp-content-sync';

		$repo = $this->normalize_repo( $repo_raw );
		if ( '' === $repo ) {
			throw new InvalidArgumentException( 'Repo is not configured. Expected owner/repo or a GitHub URL.' );
		}

		$token       = '';
		$auth_method = isset( $this->settings['auth_method'] ) ? (string) $this->settings['auth_method'] : 'pat';
		if ( 'oauth' === $auth_method ) {
			$token = isset( $this->settings['oauth_access_token'] ) ? (string) $this->settings['oauth_access_token'] : '';
		} else {
			// Prefer wp-config constant if defined.
			if ( defined( 'WPGS_GITHUB_TOKEN' ) && is_string( WPGS_GITHUB_TOKEN ) && '' !== trim( WPGS_GITHUB_TOKEN ) ) {
				$token = (string) WPGS_GITHUB_TOKEN;
			} else {
				$token = isset( $this->settings['pat_token'] ) ? (string) $this->settings['pat_token'] : '';
			}
		}

		$token = trim( $token );
		if ( '' === $token ) {
			throw new InvalidArgumentException( 'GitHub token is not configured.' );
		}

		return [ $repo, trim( $branch ), $token ];
	}

	private function normalize_repo( string $repo ): string {
		$repo = trim( $repo );
		if ( '' === $repo ) {
			return '';
		}

		if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			return $repo;
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repo, $m ) ) {
			return $m[1] . '/' . $m[2];
		}

		if ( preg_match( '#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $repo, $m ) ) {
			return $m[1] . '/' . $m[2];
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_meta_payload( WP_Post $post ): array {
		$all_meta = get_post_meta( $post->ID );
		if ( ! is_array( $all_meta ) ) {
			$all_meta = [];
		}

		foreach ( WPGS_Sync_Meta::internal_keys() as $k ) {
			unset( $all_meta[ $k ] );
		}

		/**
		 * Filter exported post meta.
		 *
		 * @param array<string,mixed> $all_meta
		 * @param int                $post_id
		 */
		$all_meta = apply_filters( 'wpgs_export_postmeta', $all_meta, (int) $post->ID );

		return [
			'post' => [
				'ID'                => (int) $post->ID,
				'post_type'         => (string) $post->post_type,
				'post_status'       => (string) $post->post_status,
				'post_title'        => (string) $post->post_title,
				'post_name'         => (string) $post->post_name,
				'post_date_gmt'     => (string) $post->post_date_gmt,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			],
			'meta' => $all_meta,
		];
	}

	private function stable_json( $data ): string {
		$data = $this->ksort_recursive( $data );
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private function ksort_recursive( $value ) {
		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			if ( $is_assoc ) {
				ksort( $value );
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->ksort_recursive( $v );
			}
		}
		return $value;
	}
}
