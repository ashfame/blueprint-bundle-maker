<?php
/**
 * Bundle generation coordinator.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bundle_Generator {
	/**
	 * Store.
	 *
	 * @var Job_Store
	 */
	private $store;

	/**
	 * File scanner.
	 *
	 * @var File_Scanner
	 */
	private $scanner;

	/**
	 * Blueprint writer.
	 *
	 * @var Blueprint_Writer
	 */
	private $blueprint_writer;

	/**
	 * Constructor.
	 *
	 * @param Job_Store        $store Store.
	 * @param File_Scanner     $scanner Scanner.
	 * @param Blueprint_Writer $blueprint_writer Blueprint writer.
	 */
	public function __construct( Job_Store $store, File_Scanner $scanner, Blueprint_Writer $blueprint_writer ) {
		$this->store            = $store;
		$this->scanner          = $scanner;
		$this->blueprint_writer = $blueprint_writer;
	}

	/**
	 * Create a new generation job.
	 *
	 * @return array
	 */
	public function create_job() {
		return $this->store->create();
	}

	/**
	 * Cancel a job.
	 *
	 * @param string $id Job ID.
	 * @return array
	 * @throws \RuntimeException When the job is missing.
	 */
	public function cancel_job( $id ) {
		$job = $this->get_job_or_fail( $id );

		if ( 'completed' !== $job['status'] ) {
			$job['status']  = 'canceled';
			$job['message'] = __( 'Canceled.', 'blueprint-bundle-maker' );
			$this->store->save( $job );
		}

		return $job;
	}

	/**
	 * Run one chunk of a job.
	 *
	 * @param string $id Job ID.
	 * @param float  $time_budget Time budget in seconds.
	 * @return array
	 * @throws \RuntimeException When the job is missing.
	 */
	public function run_step( $id, $time_budget = 4.0 ) {
		$job = $this->get_job_or_fail( $id );

		if ( in_array( $job['status'], array( 'completed', 'failed', 'canceled' ), true ) ) {
			return $job;
		}

		$job['status'] = 'running';

		try {
			$this->assert_requirements();

			switch ( $job['stage'] ) {
				case 'wxr':
					$this->export_wxr( $job );
					$job['stage']   = 'scan';
					$job['message'] = __( 'WXR export complete. Scanning wp-content.', 'blueprint-bundle-maker' );
					break;

				case 'scan':
					if ( $this->scanner->scan( $job, $time_budget ) ) {
						$job['stage']   = 'zip';
						$job['message'] = __( 'Creating WordPress files ZIP.', 'blueprint-bundle-maker' );
					}
					break;

				case 'zip':
					if ( $this->zip_wordpress_files( $job, $time_budget ) ) {
						$job['stage']   = 'bundle';
						$job['message'] = __( 'Assembling Blueprint bundle.', 'blueprint-bundle-maker' );
					}
					break;

				case 'bundle':
					$this->write_bundle( $job );
					$job['stage']   = 'complete';
					$job['status']  = 'completed';
					$job['message'] = __( 'Bundle ready.', 'blueprint-bundle-maker' );
					break;

				default:
					throw new \RuntimeException( esc_html__( 'Unknown bundle generation stage.', 'blueprint-bundle-maker' ) );
			}
		} catch ( \Throwable $throwable ) {
			$job['status']   = 'failed';
			$job['message']  = $throwable->getMessage();
			$job['errors'][] = $throwable->getMessage();
		}

		$this->store->save( $job );

		return $job;
	}

	/**
	 * Get a job or fail.
	 *
	 * @param string $id Job ID.
	 * @return array
	 * @throws \RuntimeException When the job is missing.
	 */
	private function get_job_or_fail( $id ) {
		$job = $this->store->get( $id );

		if ( ! $job ) {
			throw new \RuntimeException( esc_html__( 'Bundle job not found.', 'blueprint-bundle-maker' ) );
		}

		return $job;
	}

	/**
	 * Check runtime requirements.
	 *
	 * @throws \RuntimeException When a requirement is missing.
	 */
	private function assert_requirements() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( esc_html__( 'The PHP ZipArchive extension is required.', 'blueprint-bundle-maker' ) );
		}
	}

	/**
	 * Generate WXR using WordPress core's exporter.
	 *
	 * @param array $job Job state.
	 * @throws \RuntimeException When export fails.
	 */
	private function export_wxr( array &$job ) {
		$wxr_path = $this->store->get_job_path( $job, $job['paths']['wxr'] );

		if ( ! function_exists( 'export_wp' ) ) {
			require_once ABSPATH . 'wp-admin/includes/export.php';
		}

		$args = (array) apply_filters(
			'blueprint_bundle_maker_wxr_args',
			array(
				'content' => 'all',
			),
			$job
		);

		ob_start();
		export_wp( $args );
		$wxr = ob_get_clean();

		$this->clear_export_headers();

		if ( '' === trim( $wxr ) ) {
			throw new \RuntimeException( esc_html__( 'The WXR export was empty.', 'blueprint-bundle-maker' ) );
		}

		if ( false === file_put_contents( $wxr_path, $wxr ) ) {
			throw new \RuntimeException( esc_html__( 'Could not write the WXR export.', 'blueprint-bundle-maker' ) );
		}
	}

	/**
	 * Clear headers set by export_wp() so AJAX can still return JSON.
	 */
	private function clear_export_headers() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		foreach ( array( 'Content-Description', 'Content-Disposition', 'Content-Type', 'Pragma', 'Expires', 'Cache-Control' ) as $header ) {
			header_remove( $header );
		}
	}

	/**
	 * Add a chunk of files to files/wordpress-files.zip.
	 *
	 * @param array $job Job state.
	 * @param float $time_budget Time budget in seconds.
	 * @return bool True when ZIP is complete.
	 * @throws \RuntimeException When ZIP writing fails.
	 */
	private function zip_wordpress_files( array &$job, $time_budget ) {
		$list_path = $this->store->get_job_path( $job, $job['paths']['file_list'] );
		$zip_path  = $this->store->get_job_path( $job, $job['paths']['wordpress_files_zip'] );

		if ( ! file_exists( $list_path ) ) {
			throw new \RuntimeException( esc_html__( 'The file manifest is missing.', 'blueprint-bundle-maker' ) );
		}

		$zip = new \ZipArchive();
		$flags = file_exists( $zip_path ) ? \ZipArchive::CREATE : \ZipArchive::CREATE | \ZipArchive::OVERWRITE;

		if ( true !== $zip->open( $zip_path, $flags ) ) {
			throw new \RuntimeException( esc_html__( 'Could not open the WordPress files ZIP.', 'blueprint-bundle-maker' ) );
		}

		$list = fopen( $list_path, 'rb' );
		if ( false === $list ) {
			$zip->close();
			throw new \RuntimeException( esc_html__( 'Could not read the file manifest.', 'blueprint-bundle-maker' ) );
		}

		fseek( $list, (int) $job['zip']['offset'] );

		$deadline    = microtime( true ) + max( 1, (float) $time_budget );
		$chunk_limit = (int) apply_filters( 'blueprint_bundle_maker_zip_chunk_file_limit', 150, $job );
		$processed   = 0;

		while ( ! feof( $list ) && microtime( true ) < $deadline && $processed < $chunk_limit ) {
			$line = fgets( $list );
			if ( false === $line ) {
				break;
			}

			$relative_path = json_decode( trim( $line ), true );
			if ( ! is_string( $relative_path ) || '' === $relative_path ) {
				continue;
			}

			$absolute_path = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . $relative_path;
			$local_name    = 'wp-content/' . $relative_path;

			if ( ! is_readable( $absolute_path ) || ! is_file( $absolute_path ) ) {
				++$job['zip']['skipped'];
				continue;
			}

			$size = filesize( $absolute_path );
			if ( false === $size ) {
				$size = 0;
			}

			if ( $zip->addFile( $absolute_path, $local_name ) ) {
				++$job['zip']['files'];
				$job['zip']['bytes'] += (int) $size;
			} else {
				++$job['zip']['skipped'];
			}

			++$processed;
		}

		$job['zip']['offset'] = ftell( $list );
		$complete             = feof( $list );

		fclose( $list );

		if ( ! $zip->close() ) {
			throw new \RuntimeException( esc_html__( 'Could not finalize the WordPress files ZIP.', 'blueprint-bundle-maker' ) );
		}

		$job['message'] = $complete
			? __( 'WordPress files ZIP complete.', 'blueprint-bundle-maker' )
			: sprintf(
				/* translators: 1: zipped count, 2: total count. */
				__( 'Zipping files: %1$s of %2$s.', 'blueprint-bundle-maker' ),
				number_format_i18n( (int) $job['zip']['files'] ),
				number_format_i18n( (int) $job['scan']['files'] )
			);

		return $complete;
	}

	/**
	 * Write the final Blueprint bundle ZIP.
	 *
	 * @param array $job Job state.
	 * @throws \RuntimeException When writing fails.
	 */
	private function write_bundle( array &$job ) {
		$blueprint_path = $this->store->get_job_path( $job, $job['paths']['blueprint'] );
		$manifest_path  = $this->store->get_job_path( $job, $job['paths']['manifest'] );
		$wxr_path       = $this->store->get_job_path( $job, $job['paths']['wxr'] );
		$wp_files_path  = $this->store->get_job_path( $job, $job['paths']['wordpress_files_zip'] );

		$blueprint = $this->blueprint_writer->build( $job );
		$manifest  = $this->build_manifest( $job );

		if ( false === file_put_contents( $blueprint_path, wp_json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			throw new \RuntimeException( esc_html__( 'Could not write blueprint.json.', 'blueprint-bundle-maker' ) );
		}

		if ( false === file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			throw new \RuntimeException( esc_html__( 'Could not write the bundle manifest.', 'blueprint-bundle-maker' ) );
		}

		foreach ( array( $blueprint_path, $manifest_path, $wxr_path, $wp_files_path ) as $required_file ) {
			if ( ! is_readable( $required_file ) ) {
				throw new \RuntimeException( esc_html__( 'A required bundle file is missing.', 'blueprint-bundle-maker' ) );
			}
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? sanitize_title( $host ) : 'site';

		$job['paths']['bundle'] = sprintf(
			'blueprint-bundle-%1$s-%2$s.zip',
			$host,
			gmdate( 'Ymd-His' )
		);

		$bundle_path = $this->store->get_bundle_path( $job );
		$zip         = new \ZipArchive();

		if ( true !== $zip->open( $bundle_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the final bundle ZIP.', 'blueprint-bundle-maker' ) );
		}

		$zip->addFile( $blueprint_path, 'blueprint.json' );
		$zip->addFile( $wxr_path, 'content/site.wxr' );
		$zip->addFile( $wp_files_path, 'files/wordpress-files.zip' );
		$zip->addFile( $manifest_path, 'metadata/manifest.json' );

		if ( ! $zip->close() ) {
			throw new \RuntimeException( esc_html__( 'Could not finalize the bundle ZIP.', 'blueprint-bundle-maker' ) );
		}
	}

	/**
	 * Build bundle metadata.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function build_manifest( array $job ) {
		$wxr_path      = $this->store->get_job_path( $job, $job['paths']['wxr'] );
		$wp_files_path = $this->store->get_job_path( $job, $job['paths']['wordpress_files_zip'] );

		return array(
			'generator'  => array(
				'name'    => 'blueprint-bundle-maker',
				'version' => BLUEPRINT_BUNDLE_MAKER_VERSION,
			),
			'source'     => array(
				'site_url'   => home_url(),
				'wp_version' => get_bloginfo( 'version' ),
				'locale'     => get_locale(),
				'multisite'  => is_multisite(),
			),
			'created_at' => gmdate( 'c' ),
			'wxr'        => array(
				'path'  => 'content/site.wxr',
				'bytes' => file_exists( $wxr_path ) ? filesize( $wxr_path ) : 0,
			),
			'files'      => array(
				'path'           => 'files/wordpress-files.zip',
				'bytes'          => file_exists( $wp_files_path ) ? filesize( $wp_files_path ) : 0,
				'scanned_files'  => (int) $job['scan']['files'],
				'scanned_bytes'  => (int) $job['scan']['bytes'],
				'zipped_files'   => (int) $job['zip']['files'],
				'zipped_bytes'   => (int) $job['zip']['bytes'],
				'skipped_files'  => (int) $job['zip']['skipped'],
				'excluded_paths' => (int) $job['scan']['excluded'],
			),
			'warnings'   => $job['warnings'],
		);
	}
}
