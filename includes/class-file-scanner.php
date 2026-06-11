<?php
/**
 * Chunked wp-content scanner.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class File_Scanner {
	/**
	 * Job store.
	 *
	 * @var Job_Store
	 */
	private $store;

	/**
	 * Storage directory relative to wp-content.
	 *
	 * @var string|null
	 */
	private $storage_relative_path = null;

	/**
	 * Constructor.
	 *
	 * @param Job_Store $store Job store.
	 */
	public function __construct( Job_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Scan a chunk of wp-content.
	 *
	 * @param array $job Job state.
	 * @param float $time_budget Time budget in seconds.
	 * @return bool True when scanning is complete.
	 * @throws \RuntimeException When wp-content is unavailable.
	 */
	public function scan( array &$job, $time_budget ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! is_dir( WP_CONTENT_DIR ) ) {
			throw new \RuntimeException( esc_html__( 'WP_CONTENT_DIR is not available.', 'blueprint-bundle-maker' ) );
		}

		$base     = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$deadline = microtime( true ) + max( 1, (float) $time_budget );
		$list     = $this->store->get_job_path( $job, $job['paths']['file_list'] );

		if ( ! file_exists( $list ) && 0 === (int) $job['scan']['files'] ) {
			file_put_contents( $list, '' );
		}

		$handle = fopen( $list, 'ab' );
		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'Could not open the file manifest for writing.', 'blueprint-bundle-maker' ) );
		}

		$processed_dirs = 0;

		while ( ! empty( $job['scan']['queue'] ) && microtime( true ) < $deadline && $processed_dirs < 250 ) {
			$relative_dir = array_shift( $job['scan']['queue'] );
			$dir          = '' === $relative_dir ? $base : $base . '/' . $relative_dir;

			$entries = @scandir( $dir );
			if ( ! is_array( $entries ) ) {
				$this->store->add_warning(
					$job,
					sprintf(
						/* translators: %s: directory path. */
						__( 'Skipped unreadable directory: %s', 'blueprint-bundle-maker' ),
						$relative_dir
					)
				);
				continue;
			}

			++$processed_dirs;
			++$job['scan']['dirs'];

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$relative_path = '' === $relative_dir ? $entry : $relative_dir . '/' . $entry;
				$absolute_path = $base . '/' . $relative_path;

				if ( is_link( $absolute_path ) ) {
					++$job['scan']['excluded'];
					continue;
				}

				if ( is_dir( $absolute_path ) ) {
					if ( $this->is_excluded( $relative_path, true, $job ) ) {
						++$job['scan']['excluded'];
						continue;
					}

					$job['scan']['queue'][] = $relative_path;
					continue;
				}

				if ( ! is_file( $absolute_path ) ) {
					++$job['scan']['excluded'];
					continue;
				}

				if ( $this->is_excluded( $relative_path, false, $job ) ) {
					++$job['scan']['excluded'];
					continue;
				}

				$size = filesize( $absolute_path );
				if ( false === $size ) {
					$size = 0;
				}

				fwrite( $handle, wp_json_encode( $relative_path ) . "\n" );
				++$job['scan']['files'];
				$job['scan']['bytes'] += (int) $size;
			}
		}

		fclose( $handle );

		$complete = empty( $job['scan']['queue'] );
		$job['message'] = $complete
			? __( 'File scan complete.', 'blueprint-bundle-maker' )
			: sprintf(
				/* translators: 1: file count, 2: directory count. */
				__( 'Scanning files: %1$s files across %2$s directories.', 'blueprint-bundle-maker' ),
				number_format_i18n( (int) $job['scan']['files'] ),
				number_format_i18n( (int) $job['scan']['dirs'] )
			);

		return $complete;
	}

	/**
	 * Decide whether a wp-content path should be excluded.
	 *
	 * @param string $relative_path Path relative to wp-content.
	 * @param bool   $is_dir Whether the path is a directory.
	 * @param array  $job Job state.
	 * @return bool
	 */
	private function is_excluded( $relative_path, $is_dir, array $job ) {
		$relative_path = ltrim( wp_normalize_path( $relative_path ), '/' );
		$context       = array(
			'is_dir' => (bool) $is_dir,
			'job'    => $job,
		);

		$includes = (array) apply_filters( 'blueprint_bundle_maker_included_paths', array(), $context );
		if ( $this->matches_rules( $relative_path, $includes, $context ) ) {
			return false;
		}

		$excludes = array(
			'uploads/blueprint-bundle-maker',
			'cache',
			'caches',
			'upgrade',
			'backup',
			'backups',
			'ai1wm-backups',
			'updraft',
			'wpvividbackups',
			'wp-staging',
			'wflogs',
			'regex:(^|/)(cache|caches|tmp|temp|logs?|backup|backups)(/|$)',
			'regex:(^|/)debug\.log$',
			'regex:(^|/)error_log$',
			'regex:(^|/)\.env(\..*)?$',
			'regex:(^|/).*\.(key|pem)$',
			'regex:(^|/).*\.sql(\.gz)?$',
			'regex:(^|/).*\.(sqlite|sqlite3)$',
			'regex:(^|/).*(backup|dump|export).*\.(zip|tar|tgz|gz|bz2|7z|rar)$',
		);

		$storage_relative_path = $this->get_storage_relative_path();
		if ( '' !== $storage_relative_path ) {
			$excludes[] = $storage_relative_path;
		}

		$public_relative_path = $this->get_public_relative_path();
		if ( '' !== $public_relative_path ) {
			$excludes[] = $public_relative_path;
		}

		$excludes = (array) apply_filters( 'blueprint_bundle_maker_excluded_paths', $excludes, $context );

		return $this->matches_rules( $relative_path, $excludes, $context );
	}

	/**
	 * Get the export storage path relative to wp-content when applicable.
	 *
	 * @return string
	 */
	private function get_storage_relative_path() {
		if ( null !== $this->storage_relative_path ) {
			return $this->storage_relative_path;
		}

		$wp_content_dir = trailingslashit( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) );

		try {
			$storage_dir = trailingslashit( untrailingslashit( wp_normalize_path( $this->store->get_root_dir() ) ) );
		} catch ( \Throwable $throwable ) {
			$this->storage_relative_path = '';
			return '';
		}

		if ( 0 !== strpos( $storage_dir, $wp_content_dir ) ) {
			$this->storage_relative_path = '';
			return '';
		}

		$this->storage_relative_path = trim( substr( $storage_dir, strlen( $wp_content_dir ) ), '/' );

		return $this->storage_relative_path;
	}

	/**
	 * Get the public export path relative to wp-content when applicable.
	 *
	 * @return string
	 */
	private function get_public_relative_path() {
		$wp_content_dir = trailingslashit( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) );

		try {
			$public_dir = trailingslashit( untrailingslashit( wp_normalize_path( $this->store->get_public_dir() ) ) );
		} catch ( \Throwable $throwable ) {
			return '';
		}

		if ( 0 !== strpos( $public_dir, $wp_content_dir ) ) {
			return '';
		}

		return trim( substr( $public_dir, strlen( $wp_content_dir ) ), '/' );
	}

	/**
	 * Match a path against prefix, regex, or callable rules.
	 *
	 * @param string $relative_path Path relative to wp-content.
	 * @param array  $rules Rules.
	 * @param array  $context Context.
	 * @return bool
	 */
	private function matches_rules( $relative_path, array $rules, array $context ) {
		foreach ( $rules as $rule ) {
			if ( is_callable( $rule ) && call_user_func( $rule, $relative_path, $context ) ) {
				return true;
			}

			if ( ! is_string( $rule ) || '' === $rule ) {
				continue;
			}

			if ( 0 === strpos( $rule, 'regex:' ) ) {
				$pattern = substr( $rule, 6 );
				if ( @preg_match( '#' . $pattern . '#i', $relative_path ) ) {
					return true;
				}
				continue;
			}

			$rule = trim( wp_normalize_path( $rule ), '/' );
			if ( $relative_path === $rule || 0 === strpos( $relative_path, $rule . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
