<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function corsivo_focal_point_uninstall_site() {
	$settings = get_option( 'corsivo_focal_point_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return true;
	}

	global $wpdb;

	$meta_keys = array(
		'_corsivo_focal_point_x',
		'_corsivo_focal_point_y',
		'_corsivo_focal_point_attachment_id',
		'_corsivo_focal_point_revision_version',
	);

	foreach ( $meta_keys as $meta_key ) {
		$wpdb->last_error = '';
		delete_post_meta_by_key( $meta_key );
		$delete_error      = $wpdb->last_error;
		$remaining_meta_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
				$meta_key
			)
		);

		if ( $delete_error || $wpdb->last_error || null !== $remaining_meta_id ) {
			return new WP_Error( 'corsivo_focal_point_meta_cleanup_failed', 'La pulizia dei focal point non è stata completata. La disinstallazione è stata interrotta.' );
		}
	}

	delete_option( 'corsivo_focal_point_settings' );

	if ( false !== get_option( 'corsivo_focal_point_settings', false ) ) {
		return new WP_Error( 'corsivo_focal_point_option_cleanup_failed', 'La pulizia delle impostazioni non è stata completata. La disinstallazione è stata interrotta.' );
	}

	return true;
}

function corsivo_focal_point_assert_uninstall_result( $result ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
}

if ( is_multisite() ) {
	$offset = 0;
	$limit  = 100;

	do {
		$site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => $limit,
				'offset'  => $offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			try {
				corsivo_focal_point_assert_uninstall_result( corsivo_focal_point_uninstall_site() );
			} finally {
				restore_current_blog();
			}
		}

		$offset += count( $site_ids );
	} while ( $limit === count( $site_ids ) );
} else {
	corsivo_focal_point_assert_uninstall_result( corsivo_focal_point_uninstall_site() );
}
