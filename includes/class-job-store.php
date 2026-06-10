<?php
/**
 * Job state and filesystem storage.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Job_Store {
	const OPTION_PREFIX = 'blueprint_bundle_maker_job_';

	/**
	 * Create a new export job.
	 *
	 * @throws \RuntimeException When the upload directory cannot be prepared.
	 */
	public function create() {
		$this->ensure_root();
		$this->cleanup_stale_jobs();

		$id  = wp_generate_uuid4();
		$dir = $this->get_job_dir( $id );

		foreach ( array( $dir, $dir . '/content', $dir . '/files', $dir . '/metadata', $dir . '/tmp' ) as $path ) {
			if ( ! wp_mkdir_p( $path ) ) {
				throw new \RuntimeException( esc_html__( 'Could not create the bundle job directory.', 'blueprint-bundle-maker' ) );
			}
		}

		$this->protect_directory( $dir );

		$job = array(
			'id'         => $id,
			'status'     => 'queued',
			'stage'      => 'wxr',
			'message'    => __( 'Queued.', 'blueprint-bundle-maker' ),
			'percent'    => 0,
			'created_at' => time(),
			'updated_at' => time(),
			'paths'      => array(
				'bundle'              => '',
				'wxr'                 => 'content/site.wxr',
				'wordpress_files_zip' => 'files/wordpress-files.zip',
				'file_list'           => 'tmp/file-list.jsonl',
				'blueprint'           => 'blueprint.json',
				'manifest'            => 'metadata/manifest.json',
			),
			'scan'       => array(
				'queue'    => array( '' ),
				'files'    => 0,
				'bytes'    => 0,
				'dirs'     => 0,
				'excluded' => 0,
			),
			'zip'        => array(
				'offset'    => 0,
				'processed' => 0,
				'files'     => 0,
				'bytes'     => 0,
				'skipped'   => 0,
			),
			'warnings'   => array(),
			'errors'     => array(),
		);

		if ( is_multisite() ) {
			$this->add_warning(
				$job,
				__( 'Multisite detected. This bundle exports the current site content and wp-content files, but it does not recreate network database state.', 'blueprint-bundle-maker' )
			);
		}

		$this->save( $job );

		return $job;
	}

	/**
	 * Get a stored job.
	 *
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public function get( $id ) {
		$id = $this->sanitize_job_id( $id );

		if ( '' === $id ) {
			return null;
		}

		$job = get_option( self::OPTION_PREFIX . $id );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * Persist a job.
	 *
	 * @param array $job Job state.
	 */
	public function save( array $job ) {
		$job['updated_at'] = time();
		$job['percent']    = $this->calculate_percent( $job );

		update_option( self::OPTION_PREFIX . $job['id'], $job, false );
	}

	/**
	 * Add a bounded warning to the job.
	 *
	 * @param array  $job Job state.
	 * @param string $message Warning text.
	 */
	public function add_warning( array &$job, $message ) {
		$job['warnings'][] = $message;

		if ( count( $job['warnings'] ) > 50 ) {
			$job['warnings'] = array_slice( $job['warnings'], -50 );
		}
	}

	/**
	 * Get the plugin's upload root.
	 *
	 * @return string
	 * @throws \RuntimeException When uploads are unavailable.
	 */
	public function get_root_dir() {
		$upload_dir = wp_upload_dir( null, false );

		if ( ! empty( $upload_dir['error'] ) ) {
			throw new \RuntimeException( esc_html( $upload_dir['error'] ) );
		}

		return trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . 'blueprint-bundle-maker';
	}

	/**
	 * Get a job directory.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	public function get_job_dir( $id ) {
		return trailingslashit( $this->get_root_dir() ) . 'jobs/' . $this->sanitize_job_id( $id );
	}

	/**
	 * Get a path inside a job directory.
	 *
	 * @param array  $job Job state.
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	public function get_job_path( array $job, $relative_path ) {
		return trailingslashit( $this->get_job_dir( $job['id'] ) ) . ltrim( wp_normalize_path( $relative_path ), '/' );
	}

	/**
	 * Get the generated bundle path.
	 *
	 * @param array $job Job state.
	 * @return string
	 */
	public function get_bundle_path( array $job ) {
		if ( empty( $job['paths']['bundle'] ) ) {
			return '';
		}

		return $this->get_job_path( $job, $job['paths']['bundle'] );
	}

	/**
	 * Sanitize a job ID.
	 *
	 * @param string $id Raw job ID.
	 * @return string
	 */
	public function sanitize_job_id( $id ) {
		return preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $id );
	}

	/**
	 * Ensure the storage root exists.
	 *
	 * @throws \RuntimeException When the root cannot be prepared.
	 */
	private function ensure_root() {
		$root = $this->get_root_dir();

		if ( ! wp_mkdir_p( $root . '/jobs' ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the bundle storage directory.', 'blueprint-bundle-maker' ) );
		}

		$this->protect_directory( $root );
		$this->protect_directory( $root . '/jobs' );
	}

	/**
	 * Add basic web-server protection files.
	 *
	 * @param string $dir Directory path.
	 */
	private function protect_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Remove stale job directories and options.
	 */
	private function cleanup_stale_jobs() {
		global $wpdb;

		$max_age = (int) apply_filters( 'blueprint_bundle_maker_job_max_age', DAY_IN_SECONDS );
		$cutoff  = time() - max( HOUR_IN_SECONDS, $max_age );
		$root    = $this->get_root_dir();

		if ( is_dir( $root . '/jobs' ) ) {
			$dirs = glob( $root . '/jobs/*', GLOB_ONLYDIR );
			if ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					if ( filemtime( $dir ) && filemtime( $dir ) < $cutoff ) {
						$this->remove_directory( $dir );
					}
				}
			}
		}

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		foreach ( $option_names as $option_name ) {
			$job = get_option( $option_name );
			if ( is_array( $job ) && ! empty( $job['updated_at'] ) && (int) $job['updated_at'] < $cutoff ) {
				delete_option( $option_name );
			}
		}
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $dir );
	}

	/**
	 * Calculate coarse progress for the admin UI.
	 *
	 * @param array $job Job state.
	 * @return int
	 */
	private function calculate_percent( array $job ) {
		if ( 'completed' === $job['status'] ) {
			return 100;
		}

		if ( 'failed' === $job['status'] || 'canceled' === $job['status'] ) {
			return isset( $job['percent'] ) ? (int) $job['percent'] : 0;
		}

		switch ( $job['stage'] ) {
			case 'wxr':
				return 5;
			case 'scan':
				return 15;
				case 'zip':
					$total = max( 1, (int) $job['scan']['files'] );
					$done  = min( $total, (int) $job['zip']['processed'] );
					return 45 + (int) floor( ( $done / $total ) * 45 );
			case 'bundle':
				return 95;
			case 'complete':
				return 100;
			default:
				return isset( $job['percent'] ) ? (int) $job['percent'] : 0;
		}
	}
}
