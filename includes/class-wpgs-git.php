<?php
/**
 * Shell git adapter.
 *
 * WARNING: Brad's current direction is to stop relying on proc_open/git CLI and
 * pivot to GitHub API (Device Flow OAuth + PAT). This class remains temporarily
 * for the existing scaffold and will be removed/replaced.
 *
 * @package WPGitSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal git wrapper.
 *
 * Side effects:
 * - Executes OS-level commands via proc_open().
 * - Creates a lockfile in wp-content to prevent concurrent operations.
 *
 * Security notes:
 * - Uses proc_open(): some hosts disable it.
 * - Only use with admin-only, nonce-protected actions.
 */
final class WPGS_Git {
	/**
	 * Settings array.
	 *
	 * @var array<string,mixed>
	 */
	private array $settings;

	/**
	 * Lockfile path.
	 */
	private string $lock_file;

	/**
	 * File handle for lock.
	 *
	 * @var resource|null
	 */
	private $lock_handle = null;

	/**
	 * @param array<string,mixed> $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings  = $settings;
		$this->lock_file = trailingslashit( WP_CONTENT_DIR ) . 'wpgs-git.lock';
	}

	/**
	 * Acquire an exclusive process lock to avoid concurrent git operations.
	 *
	 * Side effects:
	 * - Creates/opens the lock file.
	 *
	 * @return void
	 * @throws RuntimeException If lock file cannot be opened or locked.
	 */
	public function lock(): void {
		$h = fopen( $this->lock_file, 'c+' );
		if ( ! $h ) {
			throw new RuntimeException( 'Unable to open lock file.' );
		}

		if ( ! flock( $h, LOCK_EX ) ) {
			fclose( $h );
			throw new RuntimeException( 'Unable to acquire git lock.' );
		}

		$this->lock_handle = $h;
	}

	/**
	 * Release the git lock.
	 *
	 * @return void
	 */
	public function unlock(): void {
		if ( $this->lock_handle ) {
			flock( $this->lock_handle, LOCK_UN );
			fclose( $this->lock_handle );
			$this->lock_handle = null;
		}
	}

	/**
	 * Ensure a local clone exists.
	 *
	 * Side effects:
	 * - Creates local clone directory.
	 * - Runs `git clone`.
	 *
	 * @return void
	 * @throws InvalidArgumentException If repo URL or clone dir not configured.
	 * @throws RuntimeException On git failures.
	 */
	public function ensure_clone(): void {
		$repo = (string) ( $this->settings['repo_url'] ?? '' );
		$dir  = (string) ( $this->settings['local_clone_path'] ?? '' );

		if ( '' === $repo || '' === $dir ) {
			throw new InvalidArgumentException( 'Repo URL and local clone path must be configured.' );
		}

		if ( is_dir( $dir . '/.git' ) ) {
			return;
		}

		wp_mkdir_p( $dir );
		$this->run( [ 'git', 'clone', '--no-checkout', $repo, $dir ], ABSPATH );
	}

	/**
	 * Checkout the configured branch; create it if missing.
	 *
	 * Side effects:
	 * - Runs git fetch.
	 * - Runs git checkout.
	 *
	 * @return void
	 * @throws RuntimeException On git failures.
	 */
	public function checkout_branch(): void {
		$branch = (string) ( $this->settings['branch'] ?? 'wp-content-sync' );
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );

		$this->run( [ 'git', 'fetch', '--all', '--prune' ], $dir );

		// Create branch if missing; otherwise checkout.
		$result = $this->run( [ 'git', 'rev-parse', '--verify', $branch ], $dir, true, false );
		if ( 0 !== $result['code'] ) {
			$this->run( [ 'git', 'checkout', '-b', $branch ], $dir );
			return;
		}

		$this->run( [ 'git', 'checkout', $branch ], $dir );
	}

	/**
	 * Pull latest changes for the branch.
	 *
	 * @return void
	 * @throws RuntimeException On git failures.
	 */
	public function pull(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'pull', '--rebase' ], $dir );
	}

	/**
	 * Stage all changes.
	 *
	 * @return void
	 */
	public function add_all(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'add', '-A' ], $dir );
	}

	/**
	 * Stage a specific repo-relative path.
	 *
	 * @param string $relpath Repo-relative path.
	 * @return void
	 */
	public function add_path( string $relpath ): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'add', '--', $relpath ], $dir );
	}

	/**
	 * Check whether the working tree has staged or unstaged changes.
	 *
	 * @return bool
	 */
	public function has_changes(): bool {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$result = $this->run( [ 'git', 'status', '--porcelain' ], $dir, true );
		return '' !== trim( $result['stdout'] );
	}

	/**
	 * Commit changes if there are any.
	 *
	 * @param string $message Commit message.
	 * @return bool True if a commit was created.
	 */
	public function commit( string $message ): bool {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		if ( ! $this->has_changes() ) {
			return false;
		}
		$this->run( [ 'git', 'commit', '-m', $message ], $dir );
		return true;
	}

	/**
	 * Amend the last commit without changing its message.
	 *
	 * @return void
	 */
	public function amend_no_edit(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'commit', '--amend', '--no-edit' ], $dir );
	}

	/**
	 * Push branch to origin.
	 *
	 * @return void
	 */
	public function push(): void {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$branch = (string) ( $this->settings['branch'] ?? 'wp-content-sync' );
		$this->run( [ 'git', 'push', 'origin', $branch ], $dir );
	}

	/**
	 * Get HEAD commit SHA.
	 *
	 * @return string
	 */
	public function head_commit(): string {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$result = $this->run( [ 'git', 'rev-parse', 'HEAD' ], $dir, true );
		return trim( $result['stdout'] );
	}

	/**
	 * Run a git command.
	 *
	 * Side effects:
	 * - Executes OS commands via proc_open().
	 *
	 * @param string[] $cmd Command + args.
	 * @param string   $cwd Working directory.
	 * @param bool     $capture Whether stdout/stderr should be captured (always captured currently).
	 * @param bool     $throw Whether to throw on non-zero exit.
	 * @return array{code:int,stdout:string,stderr:string}
	 * @throws RuntimeException When the process cannot be started or when the command fails and $throw is true.
	 */
	private function run( array $cmd, string $cwd, bool $capture = false, bool $throw = true ): array {
		$descriptor_spec = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$env = null;
		if ( 'ssh' === (string) ( $this->settings['auth_method'] ?? 'ssh' ) ) {
			$key = (string) ( $this->settings['ssh_key_path'] ?? '' );
			if ( $key ) {
				$env = array_merge( $_ENV, [
					'GIT_SSH_COMMAND' => 'ssh -i ' . escapeshellarg( $key ) . ' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new',
				] );
			}
		}

		$process = proc_open( $cmd, $descriptor_spec, $pipes, $cwd, $env );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Failed to start git process.' );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$code = proc_close( $process );

		if ( 0 !== $code && $throw ) {
			throw new RuntimeException( sprintf( 'Git command failed (%d): %s', $code, $stderr ) );
		}

		return [
			'code'   => (int) $code,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		];
	}
}
