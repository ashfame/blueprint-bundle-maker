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

		$steps[] = array(
			'step' => 'importWxr',
			'file' => $this->get_bundled_file_reference( '/content/site.wxr' ),
		);

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
		return array( '8.4', '8.3', '8.2', '8.1', '8.0', '7.4', '7.3', '7.2' );
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
			. '} flush_rewrite_rules();';

		return array(
			'step' => 'runPHP',
			'code' => $code,
		);
	}
}
