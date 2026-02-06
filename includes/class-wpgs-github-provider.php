<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High-level GitHub repo operations.
 */
final class WPGS_GitHub_Provider {
	private WPGS_GitHub_Client $client;
	private string $repo; // owner/repo

	public function __construct( WPGS_GitHub_Client $client, string $repo ) {
		$this->client = $client;
		$this->repo   = trim( $repo );
		if ( '' === $this->repo || false === strpos( $this->repo, '/' ) ) {
			throw new InvalidArgumentException( 'Repo must be in the form owner/repo.' );
		}
	}

	/**
	 * Ensure branch exists. If missing, create it from repo default branch.
	 */
	public function ensure_branch( string $branch ): void {
		$branch = trim( $branch );
		if ( '' === $branch ) {
			throw new InvalidArgumentException( 'Branch is required.' );
		}

		try {
			$this->get_ref_sha( $branch );
			return;
		} catch ( Throwable $e ) {
			// Create it.
		}

		$repo_info     = $this->client->request( 'GET', $this->api( '' ) );
		$default       = isset( $repo_info['default_branch'] ) ? (string) $repo_info['default_branch'] : 'main';
		$default_head  = $this->get_ref_sha( $default );

		$this->client->request( 'POST', $this->api( '/git/refs' ), [
			'ref' => 'refs/heads/' . $branch,
			'sha' => $default_head,
		] );
	}

	public function get_ref_sha( string $branch ): string {
		$data = $this->client->request( 'GET', $this->api( '/git/refs/heads/' . rawurlencode( $branch ) ) );
		$sha  = isset( $data['object']['sha'] ) ? (string) $data['object']['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to read branch head SHA.' );
		}
		return $sha;
	}

	public function get_commit_tree_sha( string $commit_sha ): string {
		$data = $this->client->request( 'GET', $this->api( '/git/commits/' . rawurlencode( $commit_sha ) ) );
		$sha  = isset( $data['tree']['sha'] ) ? (string) $data['tree']['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to read commit tree SHA.' );
		}
		return $sha;
	}

	public function create_blob( string $content ): string {
		$data = $this->client->request( 'POST', $this->api( '/git/blobs' ), [
			'content'  => $content,
			'encoding' => 'utf-8',
		] );
		$sha = isset( $data['sha'] ) ? (string) $data['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to create blob.' );
		}
		return $sha;
	}

	/**
	 * @param array<int,array{path:string,mode:string,type:string,sha:string}> $tree
	 */
	public function create_tree( string $base_tree_sha, array $tree ): string {
		$data = $this->client->request( 'POST', $this->api( '/git/trees' ), [
			'base_tree' => $base_tree_sha,
			'tree'      => $tree,
		] );
		$sha = isset( $data['sha'] ) ? (string) $data['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to create tree.' );
		}
		return $sha;
	}

	public function create_commit( string $message, string $tree_sha, string $parent_commit_sha ): string {
		$data = $this->client->request( 'POST', $this->api( '/git/commits' ), [
			'message' => $message,
			'tree'    => $tree_sha,
			'parents' => [ $parent_commit_sha ],
		] );
		$sha = isset( $data['sha'] ) ? (string) $data['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to create commit.' );
		}
		return $sha;
	}

	public function update_ref( string $branch, string $commit_sha ): void {
		$this->client->request( 'PATCH', $this->api( '/git/refs/heads/' . rawurlencode( $branch ) ), [
			'sha'   => $commit_sha,
			'force' => false,
		] );
	}

	/**
	 * Commit/update files (path => content) on a branch.
	 *
	 * @param array<string,string> $files
	 * @return array{commit_sha:string}
	 */
	public function commit_files( string $branch, string $message, array $files ): array {
		$this->ensure_branch( $branch );

		$parent_commit = $this->get_ref_sha( $branch );
		$base_tree     = $this->get_commit_tree_sha( $parent_commit );

		$tree_items = [];
		foreach ( $files as $path => $content ) {
			$blob_sha     = $this->create_blob( $content );
			$tree_items[] = [
				'path' => ltrim( (string) $path, '/' ),
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $blob_sha,
			];
		}

		$new_tree   = $this->create_tree( $base_tree, $tree_items );
		$new_commit = $this->create_commit( $message, $new_tree, $parent_commit );
		$this->update_ref( $branch, $new_commit );

		return [ 'commit_sha' => $new_commit ];
	}

	private function api( string $path ): string {
		$path = ltrim( $path, '/' );
		$base = 'https://api.github.com/repos/' . $this->repo;
		return '' === $path ? $base : ( $base . '/' . $path );
	}
}
