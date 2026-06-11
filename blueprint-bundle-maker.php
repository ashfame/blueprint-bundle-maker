<?php
/**
 * Plugin Name: Blueprint Bundle Maker
 * Description: Generates a WordPress Playground Blueprint bundle ZIP from the current installation.
 * Version: 0.2.3
 * Author: Blueprint Bundle Maker Contributors
 * Text Domain: blueprint-bundle-maker
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLUEPRINT_BUNDLE_MAKER_VERSION', '0.2.3' );
define( 'BLUEPRINT_BUNDLE_MAKER_FILE', __FILE__ );
define( 'BLUEPRINT_BUNDLE_MAKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUEPRINT_BUNDLE_MAKER_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Blueprint_Bundle_Maker\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = BLUEPRINT_BUNDLE_MAKER_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Blueprint_Bundle_Maker\Plugin::instance()->init();
	}
);
