<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPGS_Git {
	private array $settings;
	private string $lock_file;
	private $lock_handle = null;

	public function __construct( array $settings ) {
		$this->settings  = $settings;
		$this->lock_file = trailingslashit( WP_CONTENT_DIR ) . 'wpgs-git.lock';
	}

	/**
	 * Acquire a process lock to avoid concurrent git operations.
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

	public function unlock(): void {
		if ( $this->lock_handle ) {
			flock( $this->lock_handle, LOCK_UN );
			fclose( $this->lock_handle );
			$this->lock_handle = null;
		}
	}

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

	public function pull(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'pull', '--rebase' ], $dir );
	}

	public function add_all(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'add', '-A' ], $dir );
	}

	public function add_path( string $relpath ): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'add', '--', $relpath ], $dir );
	}

	public function has_changes(): bool {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$result = $this->run( [ 'git', 'status', '--porcelain' ], $dir, true );
		return '' !== trim( $result['stdout'] );
	}

	public function commit( string $message ): bool {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		if ( ! $this->has_changes() ) {
			return false;
		}
		$this->run( [ 'git', 'commit', '-m', $message ], $dir );
		return true;
	}

	public function amend_no_edit(): void {
		$dir = (string) ( $this->settings['local_clone_path'] ?? '' );
		$this->run( [ 'git', 'commit', '--amend', '--no-edit' ], $dir );
	}

	public function push(): void {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$branch = (string) ( $this->settings['branch'] ?? 'wp-content-sync' );
		$this->run( [ 'git', 'push', 'origin', $branch ], $dir );
	}

	public function head_commit(): string {
		$dir    = (string) ( $this->settings['local_clone_path'] ?? '' );
		$result = $this->run( [ 'git', 'rev-parse', 'HEAD' ], $dir, true );
		return trim( $result['stdout'] );
	}

	/**
	 * @return array{code:int,stdout:string,stderr:string}
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
			throw new RuntimeException( sprintf( "Git command failed (%d): %s", $code, $stderr ) );
		}

		return [
			'code'   => (int) $code,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		];
	}
}
