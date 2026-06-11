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
		add_action( 'wp_ajax_blueprint_bundle_maker_publish_bundle', array( $this, 'ajax_publish_bundle' ) );
		add_action( 'admin_post_blueprint_bundle_maker_download', array( $this, 'download' ) );
		add_action( 'admin_post_blueprint_bundle_maker_delete_bundle', array( $this, 'delete_bundle' ) );
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
			$this->asset_version( $css_path )
		);

		wp_enqueue_script(
			'blueprint-bundle-maker-admin',
			BLUEPRINT_BUNDLE_MAKER_URL . 'assets/admin.js',
			array( 'jquery' ),
			$this->asset_version( $js_path ),
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
					'copyUrl'        => __( 'Copy URL', 'blueprint-bundle-maker' ),
					'copied'         => __( 'Copied', 'blueprint-bundle-maker' ),
					'download'       => __( 'Download', 'blueprint-bundle-maker' ),
					'getUrl'         => __( 'Get URL', 'blueprint-bundle-maker' ),
					'openPlayground' => __( 'Open in Playground', 'blueprint-bundle-maker' ),
					'notPublished'   => __( 'Not published', 'blueprint-bundle-maker' ),
					'delete'         => __( 'Delete', 'blueprint-bundle-maker' ),
					'confirmDelete'  => __( 'Delete this bundle?', 'blueprint-bundle-maker' ),
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

			<?php $this->render_generated_bundles_table(); ?>
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
	 * Publish a generated bundle through AJAX.
	 */
	public function ajax_publish_bundle() {
		$this->check_ajax_permissions();

		$bundle_id = isset( $_POST['bundle_id'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_id'] ) ) : '';

		try {
			$bundle = $this->generator->publish_bundle( $bundle_id );
			wp_send_json_success( array( 'bundle' => $this->format_bundle_row( $bundle ) ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 500 );
		}
	}

	/**
	 * Secure bundle download endpoint.
	 */
	public function download() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to download bundles.', 'blueprint-bundle-maker' ),
				'',
				array( 'response' => 403 )
			);
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( 'blueprint_bundle_maker_download_' . $job_id );

		$job = $this->store->get( $job_id );
		if ( $job ) {
			$bundle_path = $this->store->get_bundle_path( $job );
		} else {
			$bundle = $this->store->get_generated_bundle( $job_id );
			$bundle_path = $bundle ? $bundle['path'] : '';
		}

		if ( ! $bundle_path ) {
			wp_die(
				esc_html__( 'Bundle job not found or not complete.', 'blueprint-bundle-maker' ),
				'',
				array( 'response' => 404 )
			);
		}

		if ( ! $bundle_path || ! is_readable( $bundle_path ) ) {
			wp_die(
				esc_html__( 'Bundle file not found.', 'blueprint-bundle-maker' ),
				'',
				array( 'response' => 404 )
			);
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
	 * Delete a generated bundle job.
	 */
	public function delete_bundle() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to delete bundles.', 'blueprint-bundle-maker' ),
				'',
				array( 'response' => 403 )
			);
		}

		$bundle_id = isset( $_GET['bundle_id'] ) ? sanitize_text_field( wp_unslash( $_GET['bundle_id'] ) ) : '';
		check_admin_referer( 'blueprint_bundle_maker_delete_bundle_' . $bundle_id );

		$deleted  = $this->store->delete_job( $bundle_id );
		$redirect = add_query_arg(
			'bbm_deleted',
			$deleted ? '1' : '0',
			admin_url( 'tools.php?page=blueprint-bundle-maker' )
		);

		wp_safe_redirect( $redirect );
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
	 * Build a cache-busting asset version.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private function asset_version( $path ) {
		$file_version = file_exists( $path ) ? (string) filemtime( $path ) : '0';

		return BLUEPRINT_BUNDLE_MAKER_VERSION . '-' . $file_version;
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
			),
		);

		if ( 'completed' === $job['status'] ) {
			$bundle_path = $this->store->get_bundle_path( $job );
			$bundle      = $bundle_path ? $this->store->get_generated_bundle_by_path( $bundle_path ) : null;
			if ( $bundle ) {
				$data['bundle'] = $this->format_bundle_row( $bundle );
			}
		}

		return $data;
	}

	/**
	 * Render generated bundles.
	 */
	private function render_generated_bundles_table() {
		$bundles = $this->store->list_generated_bundles();
		?>
		<h2><?php esc_html_e( 'Generated Blueprint Bundles', 'blueprint-bundle-maker' ); ?></h2>

		<?php $deleted = isset( $_GET['bbm_deleted'] ) ? sanitize_text_field( wp_unslash( $_GET['bbm_deleted'] ) ) : ''; ?>
		<?php if ( '' !== $deleted ) : ?>
			<div class="notice <?php echo '1' === $deleted ? 'notice-success' : 'notice-error'; ?> is-dismissible">
				<p>
					<?php
					if ( '1' === $deleted ) {
						esc_html_e( 'Bundle deleted.', 'blueprint-bundle-maker' );
					} else {
						esc_html_e( 'Bundle could not be deleted.', 'blueprint-bundle-maker' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<table class="wp-list-table widefat striped bbm-generated-bundles">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Created', 'blueprint-bundle-maker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'File', 'blueprint-bundle-maker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'blueprint-bundle-maker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'blueprint-bundle-maker' ); ?></th>
				</tr>
			</thead>
			<tbody id="bbm-generated-bundles-body">
				<?php if ( empty( $bundles ) ) : ?>
					<tr id="bbm-no-generated-bundles">
						<td colspan="4"><?php esc_html_e( 'No generated bundles yet.', 'blueprint-bundle-maker' ); ?></td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $bundles as $bundle ) : ?>
					<?php $this->render_bundle_row( $this->format_bundle_row( $bundle ) ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			printf(
				/* translators: %s: WordPress timezone name. */
				esc_html__( 'Created times use this site\'s WordPress timezone: %s.', 'blueprint-bundle-maker' ),
				esc_html( wp_timezone_string() )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render one generated bundle row.
	 *
	 * @param array $bundle Formatted bundle.
	 */
	private function render_bundle_row( array $bundle ) {
		?>
		<tr data-bbm-bundle-id="<?php echo esc_attr( $bundle['id'] ); ?>">
			<td><?php echo esc_html( $bundle['created'] ); ?></td>
			<td>
				<code><?php echo esc_html( $bundle['filename'] ); ?></code>
				<?php if ( ! empty( $bundle['public_url'] ) ) : ?>
					<div class="bbm-public-url-line">
						<input type="url" class="regular-text code bbm-table-url" readonly value="<?php echo esc_url( $bundle['public_url'] ); ?>" />
					</div>
					<div class="bbm-inline-links">
						<button type="button" class="button-link bbm-copy-url" data-url="<?php echo esc_url( $bundle['public_url'] ); ?>">
							<?php esc_html_e( 'Copy URL', 'blueprint-bundle-maker' ); ?>
						</button>
						<span aria-hidden="true">|</span>
						<a href="<?php echo esc_url( $bundle['playground_url'] ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Open in Playground', 'blueprint-bundle-maker' ); ?> &rarr;
						</a>
					</div>
				<?php else : ?>
					<div class="description"><?php esc_html_e( 'Not published', 'blueprint-bundle-maker' ); ?></div>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $bundle['size_label'] ); ?></td>
			<td class="bbm-row-actions">
				<a class="button" href="<?php echo esc_url( $bundle['download_url'] ); ?>">
					<?php esc_html_e( 'Download', 'blueprint-bundle-maker' ); ?>
				</a>
				<?php if ( empty( $bundle['public_url'] ) ) : ?>
					<button type="button" class="button bbm-publish-bundle" data-bundle-id="<?php echo esc_attr( $bundle['id'] ); ?>">
						<?php esc_html_e( 'Get URL', 'blueprint-bundle-maker' ); ?>
					</button>
				<?php endif; ?>
				<a class="submitdelete bbm-delete-bundle" href="<?php echo esc_url( $bundle['delete_url'] ); ?>">
					<?php esc_html_e( 'Delete', 'blueprint-bundle-maker' ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Format a generated bundle row for PHP rendering and AJAX.
	 *
	 * @param array $bundle Bundle data.
	 * @return array
	 */
	private function format_bundle_row( array $bundle ) {
		return array(
			'id'             => $bundle['id'],
			'filename'       => $bundle['filename'],
			'created'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $bundle['modified'] ),
			'size'           => (int) $bundle['size'],
			'size_label'     => size_format( (int) $bundle['size'], 2 ),
			'download_url'   => $this->nonce_url(
				admin_url( 'admin-post.php?action=blueprint_bundle_maker_download&job_id=' . rawurlencode( $bundle['id'] ) ),
				'blueprint_bundle_maker_download_' . $bundle['id']
			),
			'public_url'     => $bundle['public_url'],
			'playground_url' => $bundle['playground_url'],
			'delete_url'     => $this->nonce_url(
				add_query_arg(
					array(
						'action'    => 'blueprint_bundle_maker_delete_bundle',
						'bundle_id' => $bundle['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'blueprint_bundle_maker_delete_bundle_' . $bundle['id']
			),
		);
	}

	/**
	 * Build a nonce URL without HTML-escaping it.
	 *
	 * @param string $url URL.
	 * @param string $action Nonce action.
	 * @return string
	 */
	private function nonce_url( $url, $action ) {
		return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url );
	}
}
