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
				'step'              => 'importWordPressFiles',
				'wordPressFilesZip' => array(
					'resource' => 'bundled',
					'path'     => '/files/wordpress-files.zip',
				),
			),
			array(
				'step'     => 'login',
				'username' => 'admin',
			),
		);

		$locale = get_locale();
		if ( $locale ) {
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
			'file' => array(
				'resource' => 'bundled',
				'path'     => '/content/site.wxr',
			),
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
			'$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
			'landingPage' => '/wp-admin/',
			'steps'       => $steps,
		);

		return (array) apply_filters( 'blueprint_bundle_maker_blueprint', $blueprint, $job );
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
