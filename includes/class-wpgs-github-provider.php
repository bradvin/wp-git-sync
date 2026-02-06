<?php
/**
 * GitHub repo operations.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High-level GitHub operations (branch + commit creation).
 *
 * Side effects:
 * - Calls GitHub REST API.
 */
final class WPGS_GitHub_Provider {
	/**
	 * Fetch a file's raw contents from a branch using the Contents API.
	 *
	 * Side effects:
	 * - Calls GitHub REST API.
	 *
	 * @param string $branch Branch.
	 * @param string $path Repo-relative file path.
	 * @return string Raw file contents.
	 * @throws RuntimeException When the file cannot be fetched/decoded.
	 */
	public function get_file_contents( string $branch, string $path ): string {
		$path = ltrim( $path, '/' );
		$data = $this->client->request( 'GET', $this->api( '/contents/' . str_replace( '%2F', '/', rawurlencode( $path ) ) . '?ref=' . rawurlencode( $branch ) ) );
		$content = isset( $data['content'] ) ? (string) $data['content'] : '';
		$encoding = isset( $data['encoding'] ) ? (string) $data['encoding'] : '';
		if ( 'base64' !== $encoding || '' === $content ) {
			throw new RuntimeException( 'Unable to decode file from GitHub.' );
		}
		$decoded = base64_decode( str_replace( "\n", '', $content ), true );
		if ( false === $decoded ) {
			throw new RuntimeException( 'Base64 decode failed.' );
		}
		return (string) $decoded;
	}
	/**
	 * API client.
	 */
	private WPGS_GitHub_Client $client;

	/**
	 * Repo identifier in the form "owner/repo".
	 */
	private string $repo;

	/**
	 * @param WPGS_GitHub_Client $client HTTP client.
	 * @param string            $repo Repo in the form owner/repo.
	 * @throws InvalidArgumentException If repo is invalid.
	 */
	public function __construct( WPGS_GitHub_Client $client, string $repo ) {
		$this->client = $client;
		$this->repo   = trim( $repo );
		if ( '' === $this->repo || false === strpos( $this->repo, '/' ) ) {
			throw new InvalidArgumentException( 'Repo must be in the form owner/repo.' );
		}
	}

	/**
	 * Ensure a branch exists.
	 *
	 * Side effects:
	 * - May create the branch from the default branch.
	 *
	 * @param string $branch Branch name.
	 * @return void
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
			// Branch missing; create it below.
		}

		$repo_info    = $this->client->request( 'GET', $this->api( '' ) );
		$default      = isset( $repo_info['default_branch'] ) ? (string) $repo_info['default_branch'] : 'main';
		$default_head = $this->get_ref_sha( $default );

		$this->client->request( 'POST', $this->api( '/git/refs' ), [
			'ref' => 'refs/heads/' . $branch,
			'sha' => $default_head,
		] );
	}

	/**
	 * Get the current commit SHA for a branch.
	 *
	 * @param string $branch Branch name.
	 * @return string Commit SHA.
	 */
	public function get_ref_sha( string $branch ): string {
		$data = $this->client->request( 'GET', $this->api( '/git/refs/heads/' . rawurlencode( $branch ) ) );
		$sha  = isset( $data['object']['sha'] ) ? (string) $data['object']['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to read branch head SHA.' );
		}
		return $sha;
	}

	/**
	 * Get the tree SHA for a commit.
	 *
	 * @param string $commit_sha Commit SHA.
	 * @return string Tree SHA.
	 */
	public function get_commit_tree_sha( string $commit_sha ): string {
		$data = $this->client->request( 'GET', $this->api( '/git/commits/' . rawurlencode( $commit_sha ) ) );
		$sha  = isset( $data['tree']['sha'] ) ? (string) $data['tree']['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to read commit tree SHA.' );
		}
		return $sha;
	}

	/**
	 * Create a blob.
	 *
	 * @param string $content File content (utf-8).
	 * @return string Blob SHA.
	 */
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
	 * Create a tree.
	 *
	 * @param string $base_tree_sha Base tree SHA.
	 * @param array<int,array{path:string,mode:string,type:string,sha:string|null}> $tree Tree items.
	 * @return string Tree SHA.
	 */
	public function create_tree( string $base_tree_sha, array $tree ): string {
		// GitHub accepts sha=null for deletions.
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

	/**
	 * Create a commit.
	 *
	 * @param string $message Commit message.
	 * @param string $tree_sha Tree SHA.
	 * @param string $parent_commit_sha Parent commit SHA.
	 * @return string Commit SHA.
	 */
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

	/**
	 * Update a branch ref to point at a commit.
	 *
	 * @param string $branch Branch name.
	 * @param string $commit_sha Commit SHA.
	 * @return void
	 */
	public function update_ref( string $branch, string $commit_sha ): void {
		$this->client->request( 'PATCH', $this->api( '/git/refs/heads/' . rawurlencode( $branch ) ), [
			'sha'   => $commit_sha,
			'force' => false,
		] );
	}

	/**
	 * Commit/update multiple files (path => content) on a branch.
	 *
	 * Implementation uses Git Data API:
	 * - create blobs
	 * - create tree
	 * - create commit
	 * - update ref
	 *
	 * @param string               $branch Branch name.
	 * @param string               $message Commit message.
	 * @param array<string,string> $files Files to write.
	 * @return array{commit_sha:string}
	 */
	public function commit_files( string $branch, string $message, array $files ): array {
		return $this->commit_files_with_deletes( $branch, $message, $files, [] );
	}

	/**
	 * Commit/update files and delete stale paths in a single commit.
	 *
	 * Deletions are represented in the Git tree as entries with sha=null.
	 *
	 * @param string               $branch Branch name.
	 * @param string               $message Commit message.
	 * @param array<string,string> $files Files to write.
	 * @param string[]             $delete_paths Repo-relative paths to delete.
	 * @return array{commit_sha:string}
	 */
	public function commit_files_with_deletes( string $branch, string $message, array $files, array $delete_paths ): array {
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

		foreach ( $delete_paths as $path ) {
			$path = ltrim( (string) $path, '/' );
			if ( '' === $path ) {
				continue;
			}
			$tree_items[] = [
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => null,
			];
		}

		$new_tree   = $this->create_tree( $base_tree, $tree_items );
		$new_commit = $this->create_commit( $message, $new_tree, $parent_commit );
		$this->update_ref( $branch, $new_commit );

		return [ 'commit_sha' => $new_commit ];
	}

	/**
	 * Build an API URL for this repo.
	 *
	 * @param string $path Path under the repo API.
	 * @return string
	 */
	private function api( string $path ): string {
		$path = ltrim( $path, '/' );
		$base = 'https://api.github.com/repos/' . $this->repo;
		return '' === $path ? $base : ( $base . '/' . $path );
	}
}
