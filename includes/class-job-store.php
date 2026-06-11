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
	const PUBLIC_DIR_NAME = 'blueprint-bundle-maker-public';

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
				'public_bundle'       => '',
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

		return $this->save( $job );
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
	 * @return array Saved job state.
	 */
	public function save( array $job ) {
		$job['updated_at'] = time();
		$job['percent']    = $this->calculate_percent( $job );

		update_option( self::OPTION_PREFIX . $job['id'], $job, false );

		return $job;
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
	 * Publish a generated bundle to a public, unguessable URL.
	 *
	 * @param array  $job Job state.
	 * @param string $bundle_path Private bundle path.
	 * @return array|null Public export record.
	 * @throws \RuntimeException When publishing fails.
	 */
	public function publish_bundle( array &$job, $bundle_path ) {
		if ( ! is_readable( $bundle_path ) ) {
			throw new \RuntimeException( esc_html__( 'The generated bundle cannot be read for publishing.', 'blueprint-bundle-maker' ) );
		}

		$this->ensure_public_root();

		if ( ! empty( $job['paths']['public_bundle'] ) ) {
			$existing_export = $this->get_public_export( $job['paths']['public_bundle'] );
			if ( $existing_export ) {
				return $existing_export;
			}
		}

		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$host   = $host ? sanitize_title( $host ) : 'site';
		$host   = '' !== $host ? $host : 'site';
		$random = strtolower( wp_generate_password( 16, false, false ) );

		$filename = sprintf(
			'blueprint-bundle-%1$s-%2$s-%3$s.zip',
			$host,
			gmdate( 'Ymd-His' ),
			$random
		);

		$public_path = trailingslashit( $this->get_public_dir() ) . $filename;

		if ( ! copy( $bundle_path, $public_path ) ) {
			throw new \RuntimeException( esc_html__( 'Could not publish the bundle ZIP.', 'blueprint-bundle-maker' ) );
		}

		$job['paths']['public_bundle'] = $filename;

		return $this->get_public_export( $filename );
	}

	/**
	 * Get completed bundle generation jobs.
	 *
	 * @return array
	 */
	public function list_completed_jobs() {
		global $wpdb;

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$jobs = array();
		foreach ( $option_names as $option_name ) {
			$job = get_option( $option_name );
			if ( ! is_array( $job ) || 'completed' !== ( $job['status'] ?? '' ) ) {
				continue;
			}

			$bundle_path = $this->get_bundle_path( $job );
			if ( '' === $bundle_path || ! is_readable( $bundle_path ) ) {
				continue;
			}

			$jobs[] = $job;
		}

		usort(
			$jobs,
			function ( $a, $b ) {
				return (int) filemtime( $this->get_bundle_path( $b ) ) <=> (int) filemtime( $this->get_bundle_path( $a ) );
			}
		);

		return $jobs;
	}

	/**
	 * Get public bundle exports.
	 *
	 * @return array
	 */
	public function list_public_exports() {
		$this->ensure_public_root();

		$files = glob( trailingslashit( $this->get_public_dir() ) . '*.zip' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return (int) filemtime( $b ) <=> (int) filemtime( $a );
			}
		);

		$exports = array();
		foreach ( $files as $file ) {
			$export = $this->get_public_export( basename( $file ) );
			if ( $export ) {
				$exports[] = $export;
			}
		}

		return $exports;
	}

	/**
	 * Get one public export record.
	 *
	 * @param string $filename Export filename.
	 * @return array|null
	 */
	public function get_public_export( $filename ) {
		$filename = $this->sanitize_public_filename( $filename );
		if ( '' === $filename ) {
			return null;
		}

		$path = trailingslashit( $this->get_public_dir() ) . $filename;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$url = trailingslashit( $this->get_public_url_base() ) . rawurlencode( $filename );

		return array(
			'filename'       => $filename,
			'path'           => $path,
			'url'            => $url,
			'playground_url' => $this->get_playground_url( $url ),
			'modified'       => (int) filemtime( $path ),
			'size'           => (int) filesize( $path ),
		);
	}

	/**
	 * Delete a public bundle export.
	 *
	 * @param string $filename Export filename.
	 * @return bool
	 */
	public function delete_public_export( $filename ) {
		$export = $this->get_public_export( $filename );
		if ( ! $export ) {
			return false;
		}

		return unlink( $export['path'] );
	}

	/**
	 * Delete a generated bundle job and its public copy, when present.
	 *
	 * @param string $id Job ID.
	 * @return bool
	 */
	public function delete_job( $id ) {
		$job = $this->get( $id );
		if ( ! $job ) {
			return false;
		}

		if ( ! empty( $job['paths']['public_bundle'] ) ) {
			$this->delete_public_export( $job['paths']['public_bundle'] );
		}

		$this->remove_directory( $this->get_job_dir( $job['id'] ) );
		delete_option( self::OPTION_PREFIX . $job['id'] );

		return true;
	}

	/**
	 * Get the public export directory.
	 *
	 * @return string
	 * @throws \RuntimeException When uploads are unavailable.
	 */
	public function get_public_dir() {
		$upload_dir = wp_upload_dir( null, false );

		if ( ! empty( $upload_dir['error'] ) ) {
			throw new \RuntimeException( esc_html( $upload_dir['error'] ) );
		}

		return trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . self::PUBLIC_DIR_NAME;
	}

	/**
	 * Get the public export base URL.
	 *
	 * @return string
	 * @throws \RuntimeException When uploads are unavailable.
	 */
	public function get_public_url_base() {
		$upload_dir = wp_upload_dir( null, false );

		if ( ! empty( $upload_dir['error'] ) ) {
			throw new \RuntimeException( esc_html( $upload_dir['error'] ) );
		}

		return trailingslashit( $upload_dir['baseurl'] ) . self::PUBLIC_DIR_NAME;
	}

	/**
	 * Build a Playground URL for a public bundle URL.
	 *
	 * @param string $bundle_url Public bundle URL.
	 * @return string
	 */
	public function get_playground_url( $bundle_url ) {
		return add_query_arg( 'blueprint-url', $bundle_url, 'https://playground.wordpress.net/' );
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
	 * Ensure the public export root exists.
	 *
	 * @throws \RuntimeException When the public root cannot be prepared.
	 */
	private function ensure_public_root() {
		$root = $this->get_public_dir();

		if ( ! wp_mkdir_p( $root ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the public bundle directory.', 'blueprint-bundle-maker' ) );
		}

		$index = trailingslashit( $root ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $root ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_headers.c>\n<FilesMatch \"\\.zip$\">\nHeader set Access-Control-Allow-Origin \"*\"\n</FilesMatch>\n</IfModule>\n"
			);
		}
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

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$known_completed_jobs = array();

		foreach ( $option_names as $option_name ) {
			$job = get_option( $option_name );
			if ( ! is_array( $job ) || empty( $job['id'] ) ) {
				continue;
			}

			if ( 'completed' === ( $job['status'] ?? '' ) ) {
				$known_completed_jobs[] = $this->sanitize_job_id( $job['id'] );
				continue;
			}

			if ( ! empty( $job['updated_at'] ) && (int) $job['updated_at'] < $cutoff ) {
				$this->remove_directory( $this->get_job_dir( $job['id'] ) );
				delete_option( $option_name );
			}
		}

		if ( is_dir( $root . '/jobs' ) ) {
			$dirs = glob( $root . '/jobs/*', GLOB_ONLYDIR );
			if ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					$job_id = basename( $dir );
					if ( in_array( $job_id, $known_completed_jobs, true ) ) {
						continue;
					}

					if ( filemtime( $dir ) && filemtime( $dir ) < $cutoff ) {
						$this->remove_directory( $dir );
					}
				}
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

	/**
	 * Sanitize a public bundle filename.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	private function sanitize_public_filename( $filename ) {
		$filename = sanitize_file_name( wp_basename( (string) $filename ) );

		if ( ! preg_match( '/^blueprint-bundle-[a-z0-9-]+-\d{8}-\d{6}-[a-z0-9]+\.zip$/', $filename ) ) {
			return '';
		}

		return $filename;
	}
}
