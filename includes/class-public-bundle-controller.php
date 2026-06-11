<?php
/**
 * Public bundle streaming endpoints.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Public_Bundle_Controller {
	/**
	 * Store.
	 *
	 * @var Job_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Job_Store $store Store.
	 */
	public function __construct( Job_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_post_blueprint_bundle_maker_public_bundle', array( $this, 'admin_post' ) );
		add_action( 'admin_post_nopriv_blueprint_bundle_maker_public_bundle', array( $this, 'admin_post' ) );
		add_action( 'parse_request', array( $this, 'maybe_stream_pretty_url' ), 0, 0 );
	}

	/**
	 * Back-compat admin-post endpoint for URLs copied before pretty public URLs existed.
	 */
	public function admin_post() {
		$filename = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$this->stream_public_bundle( $filename );
	}

	/**
	 * Stream pretty public URLs such as /blueprint-bundle-maker-public/file.zip.
	 */
	public function maybe_stream_pretty_url() {
		$path     = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$filename = $this->filename_from_public_path( $path );

		if ( '' === $filename ) {
			return;
		}

		$this->stream_public_bundle( $filename );
	}

	/**
	 * Stream a public bundle by filename.
	 *
	 * @param string $filename Public bundle filename.
	 */
	private function stream_public_bundle( $filename ) {
		$this->send_cors_headers();

		if ( 'OPTIONS' === $this->request_method() ) {
			status_header( 204 );
			header( 'Content-Length: 0' );
			exit;
		}

		try {
			$export = $this->store->get_public_export( $filename );
		} catch ( \Throwable $throwable ) {
			$this->send_error( 500, __( 'Could not load the public bundle.', 'blueprint-bundle-maker' ) );
		}

		if ( empty( $export['path'] ) || empty( $export['filename'] ) ) {
			$this->send_error( 404, __( 'Public bundle not found.', 'blueprint-bundle-maker' ) );
		}

		$this->stream_zip_file( $export['path'] );
	}

	/**
	 * Stream a ZIP file response.
	 *
	 * @param string $bundle_path File path.
	 */
	private function stream_zip_file( $bundle_path ) {
		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) {
				break;
			}
		}

		status_header( 200 );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Content-Type: application/zip' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . filesize( $bundle_path ) );

		if ( 'HEAD' === $this->request_method() ) {
			exit;
		}

		readfile( $bundle_path );
		exit;
	}

	/**
	 * Emit CORS headers for public bundle responses.
	 */
	private function send_cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Origin, Accept, Content-Type, Range' );
		header( 'Access-Control-Expose-Headers: Content-Length, Content-Type' );
		header( 'Cross-Origin-Resource-Policy: cross-origin' );
	}

	/**
	 * Send a public bundle error response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $message Error message.
	 */
	private function send_error( $status, $message ) {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}

	/**
	 * Extract the public bundle filename from a URL path.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function filename_from_public_path( $path ) {
		$path      = '/' . ltrim( rawurldecode( (string) $path ), '/' );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );

		if ( '/' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = trim( $path, '/' );
		if ( 0 === strpos( $path, 'index.php/' ) ) {
			$path = substr( $path, strlen( 'index.php/' ) );
		}

		if ( ! preg_match( '#^blueprint-bundle-maker-public/([^/]+\.zip)$#', $path, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * Get the current request method.
	 *
	 * @return string
	 */
	private function request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}
}
