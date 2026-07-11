<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function corsivo_focal_point_acquire_uninstall_lock() {
	$lock      = get_option( 'corsivo_focal_point_migration_lock', array() );
	$lock_time = is_array( $lock ) ? absint( $lock['time'] ?? 0 ) : absint( $lock );

	if ( $lock_time && $lock_time > time() - 900 ) {
		return new WP_Error( 'corsivo_focal_point_migration_running', 'La migrazione è ancora in esecuzione. Riprova la disinstallazione tra poco.' );
	}

	if ( $lock_time ) {
		delete_option( 'corsivo_focal_point_migration_lock' );
	}

	$token = wp_generate_uuid4();
	$lock  = array(
		'token' => $token,
		'time'  => time(),
	);

	if ( ! add_option( 'corsivo_focal_point_migration_lock', $lock, '', false ) ) {
		return new WP_Error( 'corsivo_focal_point_lock_failed', 'Impossibile bloccare la migrazione durante la disinstallazione.' );
	}

	update_option( 'corsivo_focal_point_uninstalling', $token, false );

	if ( $token !== get_option( 'corsivo_focal_point_uninstalling' ) ) {
		delete_option( 'corsivo_focal_point_migration_lock' );
		return new WP_Error( 'corsivo_focal_point_uninstall_flag_failed', 'Impossibile iniziare la disinstallazione in sicurezza.' );
	}

	return $token;
}

function corsivo_focal_point_release_uninstall_lock( $token ) {
	if ( $token === get_option( 'corsivo_focal_point_uninstalling' ) ) {
		delete_option( 'corsivo_focal_point_uninstalling' );
	}

	$lock = get_option( 'corsivo_focal_point_migration_lock', array() );

	if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
		delete_option( 'corsivo_focal_point_migration_lock' );
	}
}

function corsivo_focal_point_uninstall_site() {
	$token = corsivo_focal_point_acquire_uninstall_lock();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	try {
		wp_clear_scheduled_hook( 'corsivo_focal_point_run_migration' );

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
			'_focal_point_x',
			'_focal_point_y',
		);

		foreach ( $meta_keys as $meta_key ) {
			$wpdb->last_error = '';
			delete_post_meta_by_key( $meta_key );
			$delete_error = $wpdb->last_error;
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

		$options = array(
			'corsivo_focal_point_settings',
			'corsivo_focal_point_data_version',
			'corsivo_focal_point_migration_cursor',
		);

		foreach ( $options as $option ) {
			delete_option( $option );

			if ( false !== get_option( $option, false ) ) {
				return new WP_Error( 'corsivo_focal_point_option_cleanup_failed', 'La pulizia delle impostazioni non è stata completata. La disinstallazione è stata interrotta.' );
			}
		}

		wp_clear_scheduled_hook( 'corsivo_focal_point_run_migration' );

		return true;
	} finally {
		corsivo_focal_point_release_uninstall_lock( $token );
	}
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
