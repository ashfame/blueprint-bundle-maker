<?php
/**
 * Cleanup plugin data on uninstall.
 *
 * @package Blueprint_Bundle_Maker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$blueprint_bundle_maker_cleanup_site = static function () {
	global $wpdb;

	$upload_dir = wp_upload_dir( null, false );
	if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
		$base_dir = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

		blueprint_bundle_maker_uninstall_remove_directory( $base_dir . 'blueprint-bundle-maker', $base_dir );
		blueprint_bundle_maker_uninstall_remove_directory( $base_dir . 'blueprint-bundle-maker-public', $base_dir );
	}

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'blueprint_bundle_maker_job_' ) . '%'
		)
	);
};

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		$blueprint_bundle_maker_cleanup_site();
		restore_current_blog();
	}
} else {
	$blueprint_bundle_maker_cleanup_site();
}

/**
 * Remove a plugin-owned directory.
 *
 * @param string $dir Directory path.
 * @param string $allowed_base_dir Upload base directory.
 */
function blueprint_bundle_maker_uninstall_remove_directory( $dir, $allowed_base_dir ) {
	$dir              = untrailingslashit( wp_normalize_path( $dir ) );
	$allowed_base_dir = trailingslashit( wp_normalize_path( $allowed_base_dir ) );

	if ( '' === $dir || 0 !== strpos( $dir . '/', $allowed_base_dir ) || ! is_dir( $dir ) ) {
		return;
	}

	$allowed_names = array( 'blueprint-bundle-maker', 'blueprint-bundle-maker-public' );
	if ( ! in_array( basename( $dir ), $allowed_names, true ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}

	@rmdir( $dir );
}
