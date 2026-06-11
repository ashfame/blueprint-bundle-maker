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
	 * @param string $bundle_path Private bundle path.
	 * @param string $existing_public_filename Existing public filename.
	 * @return array|null Public export record.
	 * @throws \RuntimeException When publishing fails.
	 */
	public function publish_bundle_file( $bundle_path, $existing_public_filename = '' ) {
		if ( ! is_readable( $bundle_path ) ) {
			throw new \RuntimeException( esc_html__( 'The generated bundle cannot be read for publishing.', 'blueprint-bundle-maker' ) );
		}

		$this->ensure_public_root();

		if ( '' !== $existing_public_filename ) {
			$existing_export = $this->get_public_export( $existing_public_filename );
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

		return $this->get_public_export( $filename );
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
		$public_export = $this->publish_bundle_file(
			$bundle_path,
			! empty( $job['paths']['public_bundle'] ) ? $job['paths']['public_bundle'] : ''
		);

		if ( $public_export ) {
			$job['paths']['public_bundle'] = $public_export['filename'];
		}

		return $public_export;
	}

	/**
	 * Get completed bundle generation jobs from the filesystem.
	 *
	 * @return array
	 */
	public function list_generated_bundles() {
		$jobs_root = trailingslashit( $this->get_root_dir() ) . 'jobs';
		if ( ! is_dir( $jobs_root ) ) {
			return array();
		}

		$files = glob( $jobs_root . '/*/blueprint-bundle-*.zip' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$bundles = array();
		foreach ( $files as $bundle_path ) {
			$job_id = basename( dirname( $bundle_path ) );
			if ( '' === $this->sanitize_job_id( $job_id ) || ! is_readable( $bundle_path ) ) {
				continue;
			}

			$bundle = $this->get_generated_bundle_by_path( $bundle_path );
			if ( ! $bundle ) {
				continue;
			}

			$bundles[] = $bundle;
		}

		usort(
			$bundles,
			static function ( $a, $b ) {
				return (int) $b['modified'] <=> (int) $a['modified'];
			}
		);

		return $bundles;
	}

	/**
	 * Get a generated bundle by its filesystem ID.
	 *
	 * @param string $bundle_id Bundle ID.
	 * @return array|null
	 */
	public function get_generated_bundle( $bundle_id ) {
		$parts = explode( ':', (string) $bundle_id, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$job_id   = $this->sanitize_job_id( rawurldecode( $parts[0] ) );
		$filename = sanitize_file_name( rawurldecode( $parts[1] ) );

		if ( '' === $job_id || '' === $filename || '.zip' !== substr( $filename, -4 ) ) {
			return null;
		}

		$path = trailingslashit( $this->get_root_dir() ) . 'jobs/' . $job_id . '/' . $filename;

		return $this->get_generated_bundle_by_path( $path );
	}

	/**
	 * Get a generated bundle by path.
	 *
	 * @param string $bundle_path Bundle path.
	 * @return array|null
	 */
	public function get_generated_bundle_by_path( $bundle_path ) {
		$bundle_path = wp_normalize_path( (string) $bundle_path );
		$jobs_root   = trailingslashit( wp_normalize_path( $this->get_root_dir() ) ) . 'jobs/';

		if ( 0 !== strpos( $bundle_path, $jobs_root ) || ! is_file( $bundle_path ) || ! is_readable( $bundle_path ) ) {
			return null;
		}

		$job_id = basename( dirname( $bundle_path ) );
		if ( '' === $this->sanitize_job_id( $job_id ) ) {
			return null;
		}

		$filename = basename( $bundle_path );
		if ( '.zip' !== substr( $filename, -4 ) ) {
			return null;
		}

		$job           = $this->get( $job_id );
		$public_export = null;

		if ( is_array( $job ) && ! empty( $job['paths']['public_bundle'] ) ) {
			$public_export = $this->get_public_export( $job['paths']['public_bundle'] );
		}

		if ( ! $public_export ) {
			$public_export = $this->find_public_export_for_bundle( $bundle_path );
		}

		return array(
			'id'                => rawurlencode( $job_id ) . ':' . rawurlencode( $filename ),
			'job_id'            => $job_id,
			'filename'          => $filename,
			'path'              => $bundle_path,
			'public_filename'   => $public_export ? $public_export['filename'] : '',
			'public_url'        => $public_export ? $public_export['url'] : '',
			'playground_url'    => $public_export ? $public_export['playground_url'] : '',
			'modified'          => (int) filemtime( $bundle_path ),
			'size'              => (int) filesize( $bundle_path ),
		);
	}

	/**
	 * Find a public export that appears to be a copy of a generated bundle.
	 *
	 * @param string $bundle_path Generated bundle path.
	 * @return array|null
	 */
	public function find_public_export_for_bundle( $bundle_path ) {
		$bundle_size = is_readable( $bundle_path ) ? (int) filesize( $bundle_path ) : -1;
		if ( $bundle_size < 0 ) {
			return null;
		}

		foreach ( $this->list_public_exports() as $export ) {
			if ( (int) $export['size'] === $bundle_size && basename( $bundle_path ) === $this->private_filename_from_public_filename( $export['filename'] ) ) {
				return $export;
			}
		}

		return null;
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

		$url = $this->get_public_bundle_url( $filename );

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
		$bundle = $this->get_generated_bundle( $id );
		if ( ! $bundle ) {
			return false;
		}

		$this->delete_public_exports_for_bundle( $bundle['path'] );

		$this->remove_directory( $this->get_job_dir( $bundle['job_id'] ) );
		delete_option( self::OPTION_PREFIX . $bundle['job_id'] );

		return true;
	}

	/**
	 * Delete public copies associated with a generated bundle.
	 *
	 * @param string $bundle_path Generated bundle path.
	 */
	private function delete_public_exports_for_bundle( $bundle_path ) {
		$private_filename = basename( (string) $bundle_path );
		if ( '' === $private_filename ) {
			return;
		}

		foreach ( $this->list_public_exports() as $export ) {
			if ( $private_filename === $this->private_filename_from_public_filename( $export['filename'] ) ) {
				@unlink( $export['path'] );
			}
		}
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
	 * Get the public route URL for an export filename.
	 *
	 * @param string $filename Export filename.
	 * @return string
	 */
	public function get_public_bundle_url( $filename ) {
		$path = '/blueprint-bundle-maker-public/' . rawurlencode( $this->sanitize_public_filename( $filename ) );
		if ( '' === get_option( 'permalink_structure' ) ) {
			$path = '/index.php' . $path;
		}

		return home_url( $path );
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
		$rules    = "Deny from all\n";
		$current  = is_readable( $htaccess ) ? (string) file_get_contents( $htaccess ) : '';

		if ( ! file_exists( $htaccess ) || false !== strpos( $current, 'Access-Control-Allow-Origin' ) ) {
			file_put_contents(
				$htaccess,
				$rules
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

	/**
	 * Infer the private bundle filename from a public bundle filename.
	 *
	 * @param string $public_filename Public filename.
	 * @return string
	 */
	private function private_filename_from_public_filename( $public_filename ) {
		$public_filename = $this->sanitize_public_filename( $public_filename );
		if ( '' === $public_filename ) {
			return '';
		}

		return preg_replace( '/-[a-z0-9]+\.zip$/', '.zip', $public_filename );
	}
}
