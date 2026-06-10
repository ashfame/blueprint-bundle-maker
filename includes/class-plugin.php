<?php
/**
 * Plugin bootstrap.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the plugin instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register integrations.
	 */
	public function init() {
		$store     = new Job_Store();
		$scanner   = new File_Scanner( $store );
		$blueprint = new Blueprint_Writer();
		$generator = new Bundle_Generator( $store, $scanner, $blueprint );

		if ( is_admin() ) {
			$admin = new Admin_Page( $generator, $store );
			$admin->hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'blueprint-bundle', new CLI_Command( $generator, $store ) );
		}
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
