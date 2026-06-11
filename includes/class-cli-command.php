<?php
/**
 * WP-CLI command.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLI_Command {
	/**
	 * Generator.
	 *
	 * @var Bundle_Generator
	 */
	private $generator;

	/**
	 * Store.
	 *
	 * @var Job_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Bundle_Generator $generator Generator.
	 * @param Job_Store        $store Store.
	 */
	public function __construct( Bundle_Generator $generator, Job_Store $store ) {
		$this->generator = $generator;
		$this->store     = $store;
	}

	/**
	 * Generate a Blueprint bundle ZIP.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<path>]
	 * : Destination path for the final bundle ZIP.
	 *
	 * [--force]
	 * : Overwrite the output path when it already exists.
	 *
	 * [--publish]
	 * : Publish the generated bundle to the public bundle directory and print Playground URLs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp blueprint-bundle make --output=/tmp/site-bundle.zip
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function make( $args, $assoc_args ) {
		$output  = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '';
		$force   = isset( $assoc_args['force'] );
		$publish = isset( $assoc_args['publish'] );

		if ( '' !== $output && file_exists( $output ) && ! $force ) {
			\WP_CLI::error( 'Output file exists. Use --force to overwrite it.' );
		}

		$job = $this->generator->create_job();
		\WP_CLI::log( 'Created bundle job ' . $job['id'] . '.' );

		$last_message = '';

		while ( ! in_array( $job['status'], array( 'completed', 'failed', 'canceled' ), true ) ) {
			$job = $this->generator->run_step( $job['id'], 10.0 );

			$message = sprintf( '[%3d%%] %s', (int) $job['percent'], $job['message'] );
			if ( $message !== $last_message ) {
				\WP_CLI::log( $message );
				$last_message = $message;
			}
		}

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( $job['message'] );
		}

		$bundle_path = $this->store->get_bundle_path( $job );

		if ( '' !== $output ) {
			$target_dir = dirname( $output );
			if ( ! is_dir( $target_dir ) ) {
				\WP_CLI::error( 'Output directory does not exist: ' . $target_dir );
			}

			if ( file_exists( $output ) && $force && ! unlink( $output ) ) {
				\WP_CLI::error( 'Could not remove existing output file.' );
			}

			if ( ! copy( $bundle_path, $output ) ) {
				\WP_CLI::error( 'Could not copy bundle to output path.' );
			}

			$bundle_path = $output;
		}

		\WP_CLI::success( 'Bundle ready: ' . $bundle_path );

		if ( $publish ) {
			$bundle = $this->store->get_generated_bundle_by_path( $this->store->get_bundle_path( $job ) );
			if ( ! $bundle ) {
				\WP_CLI::error( 'Could not locate the generated bundle for publishing.' );
			}

			$bundle = $this->generator->publish_bundle( $bundle['id'] );
			\WP_CLI::log( 'Public URL: ' . $bundle['public_url'] );
			\WP_CLI::log( 'Playground URL: ' . $bundle['playground_url'] );
		}
	}
}
