<?php
/**
 * GitHub API exporter.
 *
 * Writes deterministic content/meta files + mapping.json + repo-root README.md
 * to a GitHub branch using Git Data API (single commit for many files).
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports WordPress content/meta to GitHub.
 *
 * Side effects:
 * - Performs outbound HTTPS requests to api.github.com.
 * - Writes per-post sync state in postmeta.
 *
 * Security notes:
 * - Must be invoked from nonce-protected admin actions.
 * - Uses OAuth/PAT tokens; do not log tokens.
 */
final class WPGS_Exporter {
	/**
	 * Plugin settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Export all posts for the given post types.
	 *
	 * Implementation notes:
	 * - Loads current mapping.json from the target branch.
	 * - Exports content/meta for requested post types.
	 * - Regenerates mapping.json and repo-root README.md deterministically.
	 * - Creates one commit via Git Data API writing all changed files.
	 *
	 * @param string[] $post_types List of post type slugs.
	 * @return void
	 * @throws RuntimeException On auth/API errors.
	 */
	public function export_all( array $post_types = [ 'post', 'page' ] ): void {
		[ $owner, $repo, $branch, $token ] = $this->resolve_target();
		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );

		$mapping = $this->load_remote_mapping( $provider, $branch );

		$files_to_write   = [];
		$paths_to_delete  = [];
		$post_states      = [];

		foreach ( $post_types as $post_type ) {
			$this->export_post_type_into_changeset( (string) $post_type, $mapping, $files_to_write, $paths_to_delete, $post_states );
		}

		$mapping['generated_at'] = gmdate( 'c' );
		$mapping['version']      = defined( 'WPGS_VERSION' ) ? (string) WPGS_VERSION : 'dev';
		$mapping['github_owner'] = $owner;
		$mapping['github_repo']  = $repo;
		$mapping['branch']       = $branch;

		$files_to_write[ WPGS_Paths::mapping_relpath() ] = $this->stable_json( $mapping ) . "\n";
		$files_to_write['README.md'] = $this->generate_repo_index_readme( $mapping );

		// Apply the changes in a single commit.
		$commit_sha = $this->commit_changeset( $provider, $branch, 'Export all posts/pages via WP Git Sync', $files_to_write, $paths_to_delete );

		// Update per-post sync state.
		foreach ( $post_states as $post_id => $state ) {
			WPGS_Sync_Meta::set_success( (int) $post_id, [
				'repo'          => $owner . '/' . $repo,
				'branch'        => $branch,
				'content_path'  => (string) $state['content_path'],
				'post_path'     => (string) $state['post_path'],
				'meta_path'     => (string) $state['meta_path'],
				'last_commit'   => $commit_sha,
				'last_synced_at'=> gmdate( 'c' ),
				'content_hash'  => (string) $state['content_hash'],
				'post_hash'     => (string) $state['post_hash'],
				'meta_hash'     => (string) $state['meta_hash'],
			] );
		}
	}

	/**
	 * Export a single post.
	 *
	 * Loads remote mapping.json, updates the single post, regenerates README, then
	 * commits just the touched files (plus mapping + README).
	 *
	 * @param int $post_id WordPress post ID.
	 * @return void
	 * @throws RuntimeException On auth/API errors.
	 */
	public function export_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( 'Invalid post.' );
		}

		[ $owner, $repo, $branch, $token ] = $this->resolve_target();
		$provider = new WPGS_GitHub_Provider( new WPGS_GitHub_Client( $token ), $owner . '/' . $repo );

		$mapping = $this->load_remote_mapping( $provider, $branch );

		$files_to_write  = [];
		$paths_to_delete = [];
		$post_states     = [];

		$this->export_one_into_changeset( $post, $mapping, $files_to_write, $paths_to_delete, $post_states );

		$mapping['generated_at'] = gmdate( 'c' );
		$mapping['version']      = defined( 'WPGS_VERSION' ) ? (string) WPGS_VERSION : 'dev';
		$mapping['github_owner'] = $owner;
		$mapping['github_repo']  = $repo;
		$mapping['branch']       = $branch;

		$files_to_write[ WPGS_Paths::mapping_relpath() ] = $this->stable_json( $mapping ) . "\n";
		$files_to_write['README.md'] = $this->generate_repo_index_readme( $mapping );

		$commit_sha = $this->commit_changeset( $provider, $branch, sprintf( 'Export post %d (%s) via WP Git Sync', (int) $post_id, (string) $post->post_type ), $files_to_write, $paths_to_delete );

		if ( isset( $post_states[ (int) $post_id ] ) && is_array( $post_states[ (int) $post_id ] ) ) {
			$state = $post_states[ (int) $post_id ];
			WPGS_Sync_Meta::set_success( (int) $post_id, [
				'repo'           => $owner . '/' . $repo,
				'branch'         => $branch,
				'content_path'   => (string) $state['content_path'],
				'post_path'      => (string) $state['post_path'],
				'meta_path'      => (string) $state['meta_path'],
				'last_commit'    => $commit_sha,
				'last_synced_at' => gmdate( 'c' ),
				'content_hash'   => (string) $state['content_hash'],
				'post_hash'      => (string) $state['post_hash'],
				'meta_hash'      => (string) $state['meta_hash'],
			] );
		}
	}

	/**
	 * Resolve GitHub target.
	 *
	 * @return array{0:string,1:string,2:string,3:string} owner, repo, branch, token
	 */
	private function resolve_target(): array {
		$owner  = isset( $this->settings['github_owner'] ) ? trim( (string) $this->settings['github_owner'] ) : '';
		$repo   = isset( $this->settings['github_repo'] ) ? trim( (string) $this->settings['github_repo'] ) : '';
		$branch = isset( $this->settings['branch'] ) ? trim( (string) $this->settings['branch'] ) : 'main';

		if ( '' === $owner || '' === $repo ) {
			throw new RuntimeException( 'GitHub owner/repo not configured.' );
		}

		$token = WPGS_Auth::get_token( $this->settings );
		return [ $owner, $repo, $branch, $token ];
	}

	/**
	 * Load mapping.json from GitHub (or return an empty mapping if missing).
	 *
	 * @param WPGS_GitHub_Provider $provider Provider.
	 * @param string              $branch Branch.
	 * @return array<string,mixed>
	 */
	private function load_remote_mapping( WPGS_GitHub_Provider $provider, string $branch ): array {
		try {
			$raw = $provider->get_file_contents( $branch, WPGS_Paths::mapping_relpath() );
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				if ( ! isset( $json['items'] ) || ! is_array( $json['items'] ) ) {
					$json['items'] = [];
				}
				return $json;
			}
		} catch ( Throwable $e ) {
			// Treat missing/invalid mapping as empty.
		}

		return [
			'version'      => defined( 'WPGS_VERSION' ) ? (string) WPGS_VERSION : 'dev',
			'generated_at' => gmdate( 'c' ),
			'items'        => [],
		];
	}

	/**
	 * Export a post type into a commit changeset.
	 *
	 * @param string               $post_type Post type.
	 * @param array<string,mixed> &$mapping Mapping (mutated).
	 * @param array<string,string> &$files_to_write Files to write (mutated).
	 * @param string[]             &$paths_to_delete Paths to delete (mutated).
	 * @param array<int,array<string,string>> &$post_states Per-post state for updating postmeta.
	 * @return void
	 */
	private function export_post_type_into_changeset( string $post_type, array &$mapping, array &$files_to_write, array &$paths_to_delete, array &$post_states ): void {
		$q = new WP_Query([
			'post_type'              => $post_type,
			'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		]);

		$seen_ids = [];
		while ( $q->have_posts() ) {
			$q->the_post();
			$post = get_post();
			if ( $post ) {
				$seen_ids[] = (int) $post->ID;
				$this->export_one_into_changeset( $post, $mapping, $files_to_write, $paths_to_delete, $post_states );
			}
		}
		wp_reset_postdata();

		// Remove mapping entries for posts of this type that no longer exist.
		if ( isset( $mapping['items'] ) && is_array( $mapping['items'] ) ) {
			foreach ( $mapping['items'] as $id => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( (string) ( $item['post_type'] ?? '' ) !== $post_type ) {
					continue;
				}
				if ( in_array( (int) $id, $seen_ids, true ) ) {
					continue;
				}
				// Post removed.
				foreach ( $this->known_paths_from_mapping_item( $item ) as $mapped_path ) {
					$paths_to_delete[] = $mapped_path;
				}
				unset( $mapping['items'][ (string) $id ] );
			}
		}
	}

	/**
	 * Export one post into a changeset.
	 *
	 * Computes deterministic paths and writes content/meta. Also detects stale
	 * files when the path changes and marks them for deletion.
	 *
	 * @param WP_Post              $post Post.
	 * @param array<string,mixed> &$mapping Mapping (mutated).
	 * @param array<string,string> &$files_to_write Files to write (mutated).
	 * @param string[]             &$paths_to_delete Paths to delete (mutated).
	 * @return void
	 */
	private function export_one_into_changeset( WP_Post $post, array &$mapping, array &$files_to_write, array &$paths_to_delete, array &$post_states ): void {
		$paths       = WPGS_Diff::paths_for_post( $post );
		$content_rel = (string) $paths['content_path'];
		$post_rel    = (string) $paths['post_path'];
		$meta_rel    = (string) $paths['meta_path'];
		$local       = WPGS_Diff::build_local_payload( $post );
		$content     = (string) $local['content'];
		$post_js     = (string) $local['post_json'];
		$meta_js     = (string) $local['meta_json'];
		$slug_raw    = $post->post_name ? (string) $post->post_name : (string) $post->ID;
		$slug_safe   = sanitize_title( $slug_raw );
		$slug_safe   = '' === $slug_safe ? 'no-slug' : $slug_safe;

		$prev = isset( $mapping['items'][ (string) $post->ID ] ) ? $mapping['items'][ (string) $post->ID ] : null;
		if ( is_array( $prev ) ) {
			$current_paths = [ $content_rel, $post_rel, $meta_rel ];
			foreach ( $this->known_paths_from_mapping_item( $prev ) as $mapped_path ) {
				if ( ! in_array( $mapped_path, $current_paths, true ) ) {
					$paths_to_delete[] = $mapped_path;
				}
			}
		}

		// Remove old layout files if they differ from the current deterministic paths.
		foreach ( $this->legacy_paths_for_post( $post ) as $legacy_path ) {
			if ( ! in_array( $legacy_path, [ $content_rel, $post_rel, $meta_rel ], true ) ) {
				$paths_to_delete[] = $legacy_path;
			}
		}

		$files_to_write[ $content_rel ] = $content;
		$files_to_write[ $post_rel ]    = $post_js;
		$files_to_write[ $meta_rel ]    = $meta_js;

		$post_states[ (int) $post->ID ] = [
			'content_path' => $content_rel,
			'post_path'    => $post_rel,
			'meta_path'    => $meta_rel,
			'content_hash' => hash( 'sha256', $content ),
			'post_hash'    => hash( 'sha256', $post_js ),
			'meta_hash'    => hash( 'sha256', $meta_js ),
		];

		$mapping['items'][ (string) $post->ID ] = array_merge(
			is_array( $prev ) ? $prev : [],
			[
				'post_id'      => (int) $post->ID,
				'post_type'    => (string) $post->post_type,
				'slug'         => $slug_safe,
				'content_path' => $content_rel,
				'post_path'    => $post_rel,
				'meta_path'    => $meta_rel,
				'permalink'     => (string) get_permalink( (int) $post->ID ),
				'post_title'    => (string) $post->post_title,
				'last_synced_at' => gmdate( 'c' ),
			]
		);
	}

	/**
	 * Collect all known per-post paths from a mapping item.
	 *
	 * @param array<string,mixed> $item Mapping item.
	 * @return string[]
	 */
	private function known_paths_from_mapping_item( array $item ): array {
		$out = [];
		foreach ( [ 'content_path', 'post_path', 'meta_path' ] as $key ) {
			if ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				$path = trim( (string) $item[ $key ] );
				if ( '' !== $path ) {
					$out[] = $path;
				}
			}
		}
		return $out;
	}

	/**
	 * Old-layout paths for this post, kept for migration cleanup.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function legacy_paths_for_post( WP_Post $post ): array {
		$post_type = sanitize_key( (string) $post->post_type );
		$post_type = '' !== $post_type ? $post_type : 'unknown';
		$slug_raw  = $post->post_name ? (string) $post->post_name : (string) $post->ID;
		$slug_safe = sanitize_title( $slug_raw );
		$slug_safe = '' === $slug_safe ? 'no-slug' : $slug_safe;

		return [
			sprintf( 'posts/%s/%d-%s.md', $post_type, (int) $post->ID, $slug_safe ),
			sprintf( 'meta/%s/%d-%s.json', $post_type, (int) $post->ID, $slug_safe ),
		];
	}

	/**
	 * Commit a changeset to GitHub.
	 *
	 * @param WPGS_GitHub_Provider $provider Provider.
	 * @param string              $branch Branch.
	 * @param string              $message Commit message.
	 * @param array<string,string> $files_to_write Files.
	 * @param string[]            $paths_to_delete Paths to delete.
	 * @return string Commit SHA.
	 */
	private function commit_changeset( WPGS_GitHub_Provider $provider, string $branch, string $message, array $files_to_write, array $paths_to_delete ): string {
		// Deduplicate delete paths and avoid deleting something we're writing.
		$paths_to_delete = array_values( array_unique( array_filter( array_map( 'strval', $paths_to_delete ) ) ) );
		foreach ( array_keys( $files_to_write ) as $p ) {
			$paths_to_delete = array_values( array_diff( $paths_to_delete, [ (string) $p ] ) );
		}

		$res = $provider->commit_files_with_deletes( $branch, $message, $files_to_write, $paths_to_delete );
		return (string) $res['commit_sha'];
	}

	/**
	 * Generate deterministic repo-root README.md content.
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 * @return string
	 */
	private function generate_repo_index_readme( array $mapping ): string {
		$pages = [];
		$posts = [];
		$other = [];

		$items = isset( $mapping['items'] ) && is_array( $mapping['items'] ) ? $mapping['items'] : [];
		foreach ( $items as $id => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$post_type = (string) ( $item['post_type'] ?? '' );
			$title     = (string) ( $item['post_title'] ?? ( 'Post ' . $id ) );
			$permalink = (string) ( $item['permalink'] ?? '' );
			$path      = (string) ( $item['content_path'] ?? '' );
			if ( '' === $permalink || '' === $path ) {
				continue;
			}
			$line = sprintf( '- [%s](%s) — [file](%s)', $this->md_escape( $title ), $permalink, $path );

			if ( 'page' === $post_type ) {
				$pages[] = $line;
			} elseif ( 'post' === $post_type ) {
				$posts[] = $line;
			} else {
				$other[ $post_type ][] = $line;
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

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * JSON encoding with stable ordering so diffs/hashes are reliable.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private function stable_json( $data ): string {
		$data = $this->ksort_recursive( $data );
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Recursively sort associative arrays by key.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
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

	/**
	 * Escape a string for markdown link text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function md_escape( string $text ): string {
		return str_replace( [ '[', ']' ], [ '\\[', '\\]' ], $text );
	}
}
