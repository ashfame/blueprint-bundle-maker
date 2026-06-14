<?php
/**
 * Blueprint JSON builder.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blueprint_Writer {
	/**
	 * Build a Playground Blueprint.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	public function build( array $job ) {
		$steps = array(
			array(
				'step'          => 'unzip',
				'zipFile'       => $this->get_bundled_file_reference( '/files/wordpress-files.zip' ),
				'extractToPath' => '/wordpress',
			),
			array(
				'step'     => 'login',
				'username' => 'admin',
			),
		);

		$locale = get_locale();
		if ( $locale && 'en_US' !== $locale ) {
			$steps[] = array(
				'step'     => 'setSiteLanguage',
				'language' => $locale,
			);
		}

		$theme = get_stylesheet();
		if ( $theme ) {
			$steps[] = array(
				'step'            => 'activateTheme',
				'themeFolderName' => $theme,
			);
		}

		foreach ( $this->get_active_plugins() as $plugin_file ) {
			$steps[] = array(
				'step'       => 'activatePlugin',
				'pluginPath' => $plugin_file,
			);
		}

		$enable_local_media_import = (bool) apply_filters(
			'blueprint_bundle_maker_enable_local_media_import_interceptor',
			true,
			$job
		);

		if ( $enable_local_media_import ) {
			$steps[] = $this->get_install_local_media_import_interceptor_step( $job );
		}

		$steps[] = array(
			'step' => 'importWxr',
			'file' => $this->get_bundled_file_reference( '/content/site.wxr' ),
		);

		if ( $enable_local_media_import ) {
			$steps[] = $this->get_cleanup_local_media_import_interceptor_step( $job );
		}

		$safe_options = $this->get_safe_options( $job );
		if ( ! empty( $safe_options ) ) {
			$steps[] = array(
				'step'    => 'setSiteOptions',
				'options' => $safe_options,
			);
		}

		$front_page_step = $this->get_front_page_step();
		if ( ! empty( $front_page_step ) ) {
			$steps[] = $front_page_step;
		}

		$steps[] = $this->get_flush_rewrite_rules_step();

		$blueprint = array(
			'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
			'preferredVersions' => $this->get_preferred_versions( $job ),
			'landingPage'       => '/wp-admin/',
			'steps'             => $steps,
		);

		return (array) apply_filters( 'blueprint_bundle_maker_blueprint', $blueprint, $job );
	}

	/**
	 * Build a bundled file reference for a file inside the Blueprint bundle ZIP.
	 *
	 * @param string $path Bundle-root-relative path.
	 * @return array
	 */
	private function get_bundled_file_reference( $path ) {
		return array(
			'resource' => 'bundled',
			'path'     => '/' . ltrim( wp_normalize_path( (string) $path ), '/' ),
		);
	}

	/**
	 * Build the runPHP step that installs the temporary WXR media interceptor.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function get_install_local_media_import_interceptor_step( array $job ) {
		$plugin_filename = $this->get_local_media_import_interceptor_filename( $job );
		$plugin_code     = $this->get_local_media_import_interceptor_plugin_code();
		$code            = "<?php "
			. '$bbm_dir = "/wordpress/wp-content/mu-plugins"; '
			. 'if (!is_dir($bbm_dir)) { mkdir($bbm_dir, 0777, true); } '
			. 'file_put_contents($bbm_dir . "/" . ' . var_export( $plugin_filename, true ) . ', ' . var_export( $plugin_code, true ) . ');';

		return array(
			'step' => 'runPHP',
			'code' => $code,
		);
	}

	/**
	 * Build the runPHP step that removes the temporary WXR media interceptor.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function get_cleanup_local_media_import_interceptor_step( array $job ) {
		$plugin_file = '/wordpress/wp-content/mu-plugins/' . $this->get_local_media_import_interceptor_filename( $job );
		$code        = "<?php\n" . '$bbm_file = ' . var_export( $plugin_file, true ) . ";\n" . <<<'PHP'
require_once '/wordpress/wp-load.php';

$bbm_misses     = get_option( 'blueprint_bundle_maker_media_import_misses', array() );
$bbm_miss_count = (int) get_option( 'blueprint_bundle_maker_media_import_miss_count', 0 );
$bbm_urls       = array();

if ( is_array( $bbm_misses ) ) {
	foreach ( $bbm_misses as $bbm_miss ) {
		if ( is_array( $bbm_miss ) && ! empty( $bbm_miss['url'] ) ) {
			$bbm_urls[] = (string) $bbm_miss['url'];
		}
	}
}

if ( $bbm_miss_count > 0 ) {
	$bbm_message = sprintf(
		'Blueprint Bundle Maker: %d media import request(s) were not found in the bundled uploads and fell back to the origin URL.',
		$bbm_miss_count
	);
	error_log( $bbm_message );

	foreach ( $bbm_urls as $bbm_url ) {
		error_log( 'Blueprint Bundle Maker media import fallback: ' . $bbm_url );
	}

	echo '<script>console.warn(' . wp_json_encode( $bbm_message ) . ', ' . wp_json_encode( $bbm_urls ) . ');</script>';
}

delete_option( 'blueprint_bundle_maker_media_import_misses' );
delete_option( 'blueprint_bundle_maker_media_import_miss_count' );

if ( is_file( $bbm_file ) ) {
	unlink( $bbm_file );
}
PHP;

		return array(
			'step' => 'runPHP',
			'code' => $code,
		);
	}

	/**
	 * Get a job-specific filename for the temporary WXR media interceptor.
	 *
	 * @param array $job Job state.
	 * @return string
	 */
	private function get_local_media_import_interceptor_filename( array $job ) {
		$id = isset( $job['id'] ) ? (string) $job['id'] : '';
		$id = preg_replace( '/[^a-zA-Z0-9-]/', '', $id );

		if ( '' === $id ) {
			$id = 'bundle';
		}

		return 'blueprint-bundle-maker-media-import-' . $id . '.php';
	}

	/**
	 * Get the temporary MU plugin that serves importer media requests from uploads.
	 *
	 * @return string
	 */
	private function get_local_media_import_interceptor_plugin_code() {
		return <<<'PHP'
<?php
/**
 * Temporary media import interceptor generated by Blueprint Bundle Maker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract a path relative to wp-content/uploads from a requested URL.
 *
 * @param string $url Requested URL.
 * @return string
 */
function bbm_media_import_uploads_relative_path( $url ) {
	$path = wp_parse_url( (string) $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path   = wp_normalize_path( rawurldecode( $path ) );
	$marker = '/wp-content/uploads/';
	$offset = strpos( $path, $marker );

	if ( false === $offset ) {
		return '';
	}

	$relative_path = substr( $path, $offset + strlen( $marker ) );
	$relative_path = ltrim( wp_normalize_path( $relative_path ), '/' );

	if ( '' === $relative_path ) {
		return '';
	}

	$parts = explode( '/', $relative_path );
	foreach ( $parts as $part ) {
		if ( '' === $part || '.' === $part || '..' === $part ) {
			return '';
		}
	}

	return implode( '/', $parts );
}

/**
 * Record a local media miss while keeping the option bounded.
 *
 * @param string $url Requested URL.
 * @param string $relative_path Uploads-relative path.
 */
function bbm_media_import_record_miss( $url, $relative_path ) {
	$misses = get_option( 'blueprint_bundle_maker_media_import_misses', array() );
	if ( ! is_array( $misses ) ) {
		$misses = array();
	}

	$key            = md5( (string) $url . '|' . (string) $relative_path );
	$misses[ $key ] = array(
		'url'  => (string) $url,
		'path' => (string) $relative_path,
	);

	if ( count( $misses ) > 100 ) {
		$misses = array_slice( $misses, -100, null, true );
	}

	update_option( 'blueprint_bundle_maker_media_import_misses', $misses, false );
	update_option( 'blueprint_bundle_maker_media_import_miss_count', (int) get_option( 'blueprint_bundle_maker_media_import_miss_count', 0 ) + 1, false );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $parsed_args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( empty( $parsed_args['stream'] ) || empty( $parsed_args['filename'] ) || ! is_string( $parsed_args['filename'] ) ) {
			return $preempt;
		}

		$relative_path = bbm_media_import_uploads_relative_path( $url );
		if ( '' === $relative_path ) {
			return $preempt;
		}

		$uploads_dir = '/wordpress/wp-content/uploads/';
		$local_file  = wp_normalize_path( $uploads_dir . $relative_path );

		if ( 0 !== strpos( $local_file, $uploads_dir ) || ! is_file( $local_file ) || ! is_readable( $local_file ) ) {
			bbm_media_import_record_miss( $url, $relative_path );
			return $preempt;
		}

		$filesize = filesize( $local_file );
		if ( false === $filesize || $filesize <= 0 ) {
			bbm_media_import_record_miss( $url, $relative_path );
			return $preempt;
		}

		if ( ! copy( $local_file, $parsed_args['filename'] ) ) {
			bbm_media_import_record_miss( $url, $relative_path );
			return $preempt;
		}

		$headers = array(
			'content-length' => (string) $filesize,
		);
		$filetype = wp_check_filetype( $local_file );
		if ( ! empty( $filetype['type'] ) ) {
			$headers['content-type'] = $filetype['type'];
		}

		return array(
			'headers'  => $headers,
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $parsed_args['filename'],
		);
	},
	10,
	3
);
PHP;
	}

	/**
	 * Get preferred Playground runtime versions.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function get_preferred_versions( array $job ) {
		$preferred_versions = array();
		$wp_version         = get_bloginfo( 'version' );

		if ( is_string( $wp_version ) && preg_match( '/^\d+\.\d+/', $wp_version, $matches ) ) {
			$preferred_versions['wp'] = $matches[0];
		} else {
			$preferred_versions['wp'] = 'latest';
		}

		if ( defined( 'PHP_MAJOR_VERSION' ) && defined( 'PHP_MINOR_VERSION' ) ) {
			$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
			if ( in_array( $php_version, $this->get_supported_php_versions(), true ) ) {
				$preferred_versions['php'] = $php_version;
			}
		}

		if ( empty( $preferred_versions['php'] ) ) {
			$preferred_versions['php'] = 'latest';
		}

		return (array) apply_filters( 'blueprint_bundle_maker_preferred_versions', $preferred_versions, $job );
	}

	/**
	 * Get PHP versions supported by Blueprint v1.
	 *
	 * @return array
	 */
	private function get_supported_php_versions() {
		return array( '8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4', '7.3', '7.2' );
	}

	/**
	 * Get active plugins, excluding this exporter plugin.
	 *
	 * @return array
	 */
	private function get_active_plugins() {
		$plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$sitewide = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sitewide ) ) {
				$plugins = array_merge( $plugins, array_keys( $sitewide ) );
			}
		}

		$self    = plugin_basename( BLUEPRINT_BUNDLE_MAKER_FILE );
		$plugins = array_values(
			array_filter(
				array_unique( $plugins ),
				static function ( $plugin_file ) use ( $self ) {
					return is_string( $plugin_file ) && '' !== $plugin_file && $plugin_file !== $self;
				}
			)
		);

		return (array) apply_filters( 'blueprint_bundle_maker_active_plugins', $plugins );
	}

	/**
	 * Get safe scalar site options.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function get_safe_options( array $job ) {
		$option_names = array(
			'blogname',
			'blogdescription',
			'permalink_structure',
			'category_base',
			'tag_base',
			'show_on_front',
			'posts_per_page',
			'default_comment_status',
			'default_ping_status',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
		);

		$option_names = (array) apply_filters( 'blueprint_bundle_maker_safe_options', $option_names, $job );
		$options      = array();

		foreach ( $option_names as $option_name ) {
			if ( ! is_string( $option_name ) || '' === $option_name ) {
				continue;
			}

			$value = get_option( $option_name, null );
			if ( null === $value || is_object( $value ) || is_resource( $value ) ) {
				continue;
			}

			$options[ $option_name ] = $value;
		}

		return $options;
	}

	/**
	 * Build a small runPHP step to map front/posts page IDs after WXR import.
	 *
	 * @return array|null
	 */
	private function get_front_page_step() {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return null;
		}

		$targets = array();

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $option_name ) {
			$post_id = (int) get_option( $option_name );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$targets[ $option_name ] = array(
				'path'      => get_page_uri( $post ),
				'post_type' => $post->post_type,
			);
		}

		if ( empty( $targets ) ) {
			return null;
		}

		$json = wp_json_encode( $targets );
		$code = "<?php require_once '/wordpress/wp-load.php'; "
			. '$bbm_pages = json_decode(' . var_export( $json, true ) . ', true); '
			. 'foreach ($bbm_pages as $bbm_option => $bbm_page) { '
			. '$bbm_post = get_page_by_path($bbm_page["path"], OBJECT, $bbm_page["post_type"]); '
			. 'if ($bbm_post) { update_option($bbm_option, (int) $bbm_post->ID); } '
			. '}';

		return array(
			'step' => 'runPHP',
			'code' => $code,
		);
	}

	/**
	 * Build the final runPHP step that refreshes permalink rewrite rules.
	 *
	 * @return array
	 */
	private function get_flush_rewrite_rules_step() {
		$code = "<?php require_once '/wordpress/wp-load.php'; flush_rewrite_rules();";

		return array(
			'step' => 'runPHP',
			'code' => $code,
		);
	}
}
