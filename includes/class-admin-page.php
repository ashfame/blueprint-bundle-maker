<?php
/**
 * Admin UI and AJAX endpoints.
 *
 * @package Blueprint_Bundle_Maker
 */

namespace Blueprint_Bundle_Maker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Page {
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
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_blueprint_bundle_maker_create_job', array( $this, 'ajax_create_job' ) );
		add_action( 'wp_ajax_blueprint_bundle_maker_run_step', array( $this, 'ajax_run_step' ) );
		add_action( 'wp_ajax_blueprint_bundle_maker_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'admin_post_blueprint_bundle_maker_download', array( $this, 'download' ) );
	}

	/**
	 * Add Tools page.
	 */
	public function admin_menu() {
		add_management_page(
			__( 'Blueprint Bundle Maker', 'blueprint-bundle-maker' ),
			__( 'Blueprint Bundle Maker', 'blueprint-bundle-maker' ),
			$this->capability(),
			'blueprint-bundle-maker',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'tools_page_blueprint-bundle-maker' !== $hook_suffix ) {
			return;
		}

		$js_path  = BLUEPRINT_BUNDLE_MAKER_DIR . 'assets/admin.js';
		$css_path = BLUEPRINT_BUNDLE_MAKER_DIR . 'assets/admin.css';

		wp_enqueue_style(
			'blueprint-bundle-maker-admin',
			BLUEPRINT_BUNDLE_MAKER_URL . 'assets/admin.css',
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : BLUEPRINT_BUNDLE_MAKER_VERSION
		);

		wp_enqueue_script(
			'blueprint-bundle-maker-admin',
			BLUEPRINT_BUNDLE_MAKER_URL . 'assets/admin.js',
			array( 'jquery' ),
			file_exists( $js_path ) ? filemtime( $js_path ) : BLUEPRINT_BUNDLE_MAKER_VERSION,
			true
		);

		wp_localize_script(
			'blueprint-bundle-maker-admin',
			'BlueprintBundleMaker',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'blueprint_bundle_maker_admin' ),
				'i18n'    => array(
					'working'   => __( 'Working...', 'blueprint-bundle-maker' ),
					'failed'    => __( 'Failed', 'blueprint-bundle-maker' ),
					'completed' => __( 'Bundle ready', 'blueprint-bundle-maker' ),
					'canceled'  => __( 'Canceled', 'blueprint-bundle-maker' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render() {
		?>
		<div class="wrap blueprint-bundle-maker">
			<h1><?php esc_html_e( 'Blueprint Bundle Maker', 'blueprint-bundle-maker' ); ?></h1>

			<div class="bbm-panel">
				<p>
					<?php esc_html_e( 'Generate a WordPress Playground Blueprint bundle ZIP for this site. The bundle includes a WXR export and wp-content files with cache, backup, log, temp, and generated export paths excluded.', 'blueprint-bundle-maker' ); ?>
				</p>

				<div class="bbm-actions">
					<button type="button" class="button button-primary" id="bbm-start">
						<?php esc_html_e( 'Generate Bundle', 'blueprint-bundle-maker' ); ?>
					</button>
					<button type="button" class="button" id="bbm-cancel" disabled>
						<?php esc_html_e( 'Cancel', 'blueprint-bundle-maker' ); ?>
					</button>
					<a class="button button-secondary bbm-download" id="bbm-download" href="#" hidden>
						<?php esc_html_e( 'Download Bundle', 'blueprint-bundle-maker' ); ?>
					</a>
				</div>

				<div class="bbm-progress" aria-live="polite">
					<div class="bbm-progress-bar" id="bbm-progress-bar" style="width: 0%"></div>
				</div>

				<p class="bbm-status" id="bbm-status">
					<?php esc_html_e( 'Ready.', 'blueprint-bundle-maker' ); ?>
				</p>

				<dl class="bbm-details">
					<div>
						<dt><?php esc_html_e( 'Stage', 'blueprint-bundle-maker' ); ?></dt>
						<dd id="bbm-stage">-</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Files scanned', 'blueprint-bundle-maker' ); ?></dt>
						<dd id="bbm-files">0</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Files zipped', 'blueprint-bundle-maker' ); ?></dt>
						<dd id="bbm-zipped">0</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Skipped', 'blueprint-bundle-maker' ); ?></dt>
						<dd id="bbm-skipped">0</dd>
					</div>
				</dl>

				<div class="bbm-warnings" id="bbm-warnings" hidden></div>
			</div>

			<p class="description">
				<?php esc_html_e( 'WP-CLI: wp blueprint-bundle make --output=/path/to/bundle.zip', 'blueprint-bundle-maker' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Create a job through AJAX.
	 */
	public function ajax_create_job() {
		$this->check_ajax_permissions();

		try {
			$job = $this->generator->create_job();
			wp_send_json_success( $this->format_job( $job ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 500 );
		}
	}

	/**
	 * Run one job step through AJAX.
	 */
	public function ajax_run_step() {
		$this->check_ajax_permissions();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		try {
			$job = $this->generator->run_step( $job_id, 4.0 );
			wp_send_json_success( $this->format_job( $job ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 500 );
		}
	}

	/**
	 * Cancel a job through AJAX.
	 */
	public function ajax_cancel_job() {
		$this->check_ajax_permissions();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		try {
			$job = $this->generator->cancel_job( $job_id );
			wp_send_json_success( $this->format_job( $job ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 500 );
		}
	}

	/**
	 * Secure bundle download endpoint.
	 */
	public function download() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to download bundles.', 'blueprint-bundle-maker' ), 403 );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( 'blueprint_bundle_maker_download_' . $job_id );

		$job = $this->store->get( $job_id );
		if ( ! $job || 'completed' !== $job['status'] ) {
			wp_die( esc_html__( 'Bundle job not found or not complete.', 'blueprint-bundle-maker' ), 404 );
		}

		$bundle_path = $this->store->get_bundle_path( $job );
		if ( ! $bundle_path || ! is_readable( $bundle_path ) ) {
			wp_die( esc_html__( 'Bundle file not found.', 'blueprint-bundle-maker' ), 404 );
		}

		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $bundle_path ) . '"' );
		header( 'Content-Length: ' . filesize( $bundle_path ) );
		readfile( $bundle_path );
		exit;
	}

	/**
	 * Check AJAX nonce and capability.
	 */
	private function check_ajax_permissions() {
		check_ajax_referer( 'blueprint_bundle_maker_admin', 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to generate bundles.', 'blueprint-bundle-maker' ) ), 403 );
		}
	}

	/**
	 * Capability required to run jobs.
	 *
	 * @return string
	 */
	private function capability() {
		return (string) apply_filters( 'blueprint_bundle_maker_job_capability', 'export' );
	}

	/**
	 * Format job data for the browser.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function format_job( array $job ) {
		$data = array(
			'id'       => $job['id'],
			'status'   => $job['status'],
			'stage'    => $job['stage'],
			'message'  => $job['message'],
			'percent'  => (int) $job['percent'],
			'warnings' => $job['warnings'],
			'counts'   => array(
				'scanned_files' => (int) $job['scan']['files'],
				'zipped_files'  => (int) $job['zip']['files'],
				'skipped_files' => (int) $job['zip']['skipped'],
				'excluded'      => (int) $job['scan']['excluded'],
			),
		);

		if ( 'completed' === $job['status'] ) {
			$data['download_url'] = wp_nonce_url(
				admin_url( 'admin-post.php?action=blueprint_bundle_maker_download&job_id=' . rawurlencode( $job['id'] ) ),
				'blueprint_bundle_maker_download_' . $job['id']
			);
		}

		return $data;
	}
}
