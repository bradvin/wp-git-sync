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
			// Empty repos have no refs yet; initial commit path will create one.
			if ( $this->is_empty_repo_error( $e ) ) {
				return;
			}
			// Branch missing; create it below.
		}

		$repo_info    = $this->client->request( 'GET', $this->api( '' ) );
		$default      = isset( $repo_info['default_branch'] ) ? (string) $repo_info['default_branch'] : 'main';
		try {
			$default_head = $this->get_ref_sha( $default );
		} catch ( Throwable $e ) {
			// Empty repos have no default branch head yet.
			if ( $this->is_empty_repo_error( $e ) ) {
				return;
			}
			throw $e;
		}

		$this->create_ref( $branch, $default_head );
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
	 * @param string|null $base_tree_sha Base tree SHA.
	 * @param array<int,array{path:string,mode:string,type:string,sha:string|null}> $tree Tree items.
	 * @return string Tree SHA.
	 */
	public function create_tree( ?string $base_tree_sha, array $tree ): string {
		// GitHub accepts sha=null for deletions.
		$payload = [ 'tree' => $tree ];
		if ( is_string( $base_tree_sha ) && '' !== trim( $base_tree_sha ) ) {
			$payload['base_tree'] = $base_tree_sha;
		}

		$data = $this->client->request( 'POST', $this->api( '/git/trees' ), $payload );
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
	 * @param string|null $parent_commit_sha Parent commit SHA (or null for initial commit).
	 * @return string Commit SHA.
	 */
	public function create_commit( string $message, string $tree_sha, ?string $parent_commit_sha = null ): string {
		$payload = [
			'message' => $message,
			'tree'    => $tree_sha,
		];
		if ( is_string( $parent_commit_sha ) && '' !== trim( $parent_commit_sha ) ) {
			$payload['parents'] = [ $parent_commit_sha ];
		}

		$data = $this->client->request( 'POST', $this->api( '/git/commits' ), $payload );
		$sha = isset( $data['sha'] ) ? (string) $data['sha'] : '';
		if ( '' === $sha ) {
			throw new RuntimeException( 'Unable to create commit.' );
		}
		return $sha;
	}

	/**
	 * Create a branch ref.
	 *
	 * @param string $branch Branch name.
	 * @param string $commit_sha Commit SHA.
	 * @return void
	 */
	public function create_ref( string $branch, string $commit_sha ): void {
		$this->client->request( 'POST', $this->api( '/git/refs' ), [
			'ref' => 'refs/heads/' . $branch,
			'sha' => $commit_sha,
		] );
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
	 * Ensure a branch exists and reset it to an empty tree.
	 *
	 * @param string $branch Branch name.
	 * @param string $message Commit message.
	 * @return void
	 */
	public function reset_branch_to_empty( string $branch, string $message ): void {
		$branch = trim( $branch );
		if ( '' === $branch ) {
			throw new InvalidArgumentException( 'Branch is required.' );
		}

		$this->ensure_branch( $branch );

		try {
			$parent_commit = $this->get_ref_sha( $branch );
		} catch ( Throwable $e ) {
			if ( $this->is_empty_repo_error( $e ) ) {
				$empty_tree = $this->create_tree( null, [] );
				$new_commit = $this->create_commit( $message, $empty_tree, null );
				try {
					$this->create_ref( $branch, $new_commit );
				} catch ( Throwable $ref_error ) {
					$this->update_ref( $branch, $new_commit );
				}
				return;
			}
			throw $e;
		}

		$empty_tree = $this->create_tree( null, [] );
		$new_commit = $this->create_commit( $message, $empty_tree, $parent_commit );
		$this->update_ref( $branch, $new_commit );
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
		try {
			$this->ensure_branch( $branch );

			$parent_commit = null;
			$base_tree     = null;
			$is_initial_commit = false;
			try {
				$parent_commit = $this->get_ref_sha( $branch );
				$base_tree     = $this->get_commit_tree_sha( $parent_commit );
			} catch ( Throwable $e ) {
				if ( $this->is_empty_repo_error( $e ) ) {
					$is_initial_commit = true;
				} else {
					throw $e;
				}
			}

			$tree_items = $this->build_tree_items_for_files( $files );

			if ( ! $is_initial_commit ) {
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
			}

			$new_tree   = $this->create_tree( $base_tree, $tree_items );
			$new_commit = $this->create_commit( $message, $new_tree, $parent_commit );
			if ( $is_initial_commit ) {
				// First commit in an empty repo: branch ref does not exist yet.
				try {
					$this->create_ref( $branch, $new_commit );
				} catch ( Throwable $e ) {
					// In case another writer created the ref concurrently, just update.
					$this->update_ref( $branch, $new_commit );
				}
			} else {
				$this->update_ref( $branch, $new_commit );
			}

			return [ 'commit_sha' => $new_commit ];
		} catch ( Throwable $e ) {
			if ( ! $this->is_empty_repo_error( $e ) ) {
				throw $e;
			}

			// Last-resort bootstrap for empty repos if any earlier step still returned 409.
			return $this->bootstrap_empty_repo( $branch, $message, $files );
		}
	}

	/**
	 * Bootstrap first commit for empty repos, then continue normal sync.
	 *
	 * This avoids relying on refs/heads existing in a brand-new repository.
	 *
	 * @param string               $branch Branch name.
	 * @param string               $message Commit message.
	 * @param array<string,string> $files Files to write.
	 * @return array{commit_sha:string}
	 */
	private function bootstrap_empty_repo( string $branch, string $message, array $files ): array {
		if ( empty( $files ) ) {
			throw new RuntimeException( 'Cannot initialize empty repository: no files to commit.' );
		}

		$first_path = (string) array_key_first( $files );
		$first_content = (string) $files[ $first_path ];
		unset( $files[ $first_path ] );

		$init_commit = $this->create_initial_file_via_contents_api( $branch, $first_path, $first_content, 'Initialize repository for WP Git Sync' );
		if ( empty( $files ) ) {
			return [ 'commit_sha' => $init_commit ];
		}

		// Repo is initialized now. Apply remaining files in a normal commit.
		return $this->commit_files_with_deletes( $branch, $message, $files, [] );
	}

	/**
	 * Create the very first file via the Contents API.
	 *
	 * @param string $branch Branch name.
	 * @param string $path Repo-relative file path.
	 * @param string $content File content.
	 * @param string $message Commit message.
	 * @return string Commit SHA.
	 */
	private function create_initial_file_via_contents_api( string $branch, string $path, string $content, string $message ): string {
		$path = ltrim( $path, '/' );
		$url  = $this->api( '/contents/' . str_replace( '%2F', '/', rawurlencode( $path ) ) );
		$payload = [
			'message' => $message,
			'content' => base64_encode( $content ),
			'branch'  => $branch,
		];

		try {
			$data = $this->client->request( 'PUT', $url, $payload );
		} catch ( Throwable $e ) {
			// Some empty repos only accept the initial write on default branch.
			$data = $this->client->request(
				'PUT',
				$url,
				[
					'message' => $message,
					'content' => base64_encode( $content ),
				]
			);
		}

		$commit_sha = isset( $data['commit']['sha'] ) ? (string) $data['commit']['sha'] : '';
		if ( '' === $commit_sha ) {
			throw new RuntimeException( 'Unable to create initial commit in empty repository.' );
		}

		// Ensure selected branch exists and points to the initial commit.
		try {
			$this->create_ref( $branch, $commit_sha );
		} catch ( Throwable $e ) {
			try {
				$this->update_ref( $branch, $commit_sha );
			} catch ( Throwable $ignored ) {
				// If branch already points correctly, we can continue.
			}
		}

		return $commit_sha;
	}

	/**
	 * Build git tree entries for files to write.
	 *
	 * @param array<string,string> $files Files.
	 * @return array<int,array{path:string,mode:string,type:string,sha:string|null}>
	 */
	private function build_tree_items_for_files( array $files ): array {
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
		return $tree_items;
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

	/**
	 * Detect GitHub's empty-repository response.
	 *
	 * @param Throwable $e Exception.
	 * @return bool
	 */
	private function is_empty_repo_error( Throwable $e ): bool {
		$message = strtolower( $e->getMessage() );
		return false !== strpos( $message, 'repository is empty' ) || false !== strpos( $message, 'git repository is empty' );
	}
}
