<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_get_post_types() {
	$settings   = corsivo_focal_point_get_settings();
	$post_types = $settings['post_types'];

	if ( $settings['woocommerce_enabled'] && corsivo_focal_point_is_woocommerce_active() ) {
		$post_types[] = 'product';
	}

	$post_types = apply_filters( 'corsivo_focal_point_post_types', $post_types );
	$post_types = corsivo_focal_point_sanitize_post_types( $post_types );

	return array_values(
		array_filter(
			$post_types,
			function ( $post_type ) {
				return post_type_exists( $post_type ) && post_type_supports( $post_type, 'thumbnail' );
			}
		)
	);
}

function corsivo_focal_point_post_type_supports_autosave( $post_type ) {
	global $wp_version;

	if ( post_type_supports( $post_type, 'autosave' ) ) {
		return true;
	}

	if ( version_compare( $wp_version, '6.6', '>=' ) ) {
		return false;
	}

	$post_type_object = get_post_type_object( $post_type );

	return $post_type_object && 'attachment' !== $post_type;
}

function corsivo_focal_point_sanitize_coordinate( $value ) {
	if ( ! is_numeric( $value ) ) {
		return 50;
	}

	$coordinate = (float) $value;

	if ( ! is_finite( $coordinate ) ) {
		return 50;
	}

	return max( 0, min( 100, (int) round( $coordinate ) ) );
}

function corsivo_focal_point_sanitize_attachment_id( $value ) {
	return is_scalar( $value ) ? absint( $value ) : 0;
}

function corsivo_focal_point_position_meta_keys() {
	return array(
		CORSIVO_FOCAL_POINT_META_X,
		CORSIVO_FOCAL_POINT_META_Y,
		CORSIVO_FOCAL_POINT_META_ATTACHMENT,
	);
}

function corsivo_focal_point_log_failure( $event, $context = array() ) {
	$context = is_array( $context ) ? $context : array();
	$payload = array_merge( array( 'event' => sanitize_key( $event ) ), $context );
	$message = wp_json_encode( $payload );

	error_log( '[Corsivo Focal Point] ' . ( is_string( $message ) ? $message : sanitize_key( $event ) ) );
	do_action( 'corsivo_focal_point_failure', $event, $context );
}

function corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key ) {
	$values = get_metadata_raw( 'post', absint( $post_id ), $meta_key, false );

	return is_array( $values ) ? $values : array();
}

function corsivo_focal_point_get_position_meta_snapshot( $post_id ) {
	$snapshot = array();

	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		$snapshot[ $meta_key ] = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );
	}

	return $snapshot;
}

function corsivo_focal_point_get_snapshot_mismatches( $post_id, $snapshot ) {
	$failed_meta_keys = array();

	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		$meta_values    = $snapshot[ $meta_key ] ?? array();
		$meta_values    = is_array( $meta_values ) ? $meta_values : array( $meta_values );
		$current_values = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );
		$matches        = $meta_values
			? 1 === count( $current_values ) && (string) reset( $current_values ) === (string) reset( $meta_values )
			: ! $current_values;

		if ( ! $matches ) {
			$failed_meta_keys[] = $meta_key;
		}
	}

	return $failed_meta_keys;
}

function corsivo_focal_point_authorize_meta( $allowed, $meta_key, $post_id, $user_id ) {
	return $post_id > 0 && user_can( $user_id, 'edit_post', $post_id );
}

function corsivo_focal_point_register_meta() {
	foreach ( corsivo_focal_point_get_post_types() as $post_type ) {
		if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
			add_post_type_support( $post_type, 'custom-fields' );
		}

		$revisions_enabled = post_type_supports( $post_type, 'revisions' );
		$coordinate_args   = array(
			'show_in_rest'      => array(
				'schema' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
			),
			'single'            => true,
			'type'              => 'integer',
			'default'           => 50,
			'sanitize_callback' => 'corsivo_focal_point_sanitize_coordinate',
			'auth_callback'     => 'corsivo_focal_point_authorize_meta',
			'revisions_enabled' => $revisions_enabled,
		);

		register_post_meta( $post_type, CORSIVO_FOCAL_POINT_META_X, $coordinate_args );
		register_post_meta( $post_type, CORSIVO_FOCAL_POINT_META_Y, $coordinate_args );
		register_post_meta(
			$post_type,
			CORSIVO_FOCAL_POINT_META_ATTACHMENT,
			array(
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'single'            => true,
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'corsivo_focal_point_sanitize_attachment_id',
				'auth_callback'     => 'corsivo_focal_point_authorize_meta',
				'revisions_enabled' => $revisions_enabled,
			)
		);
	}
}
add_action( 'init', 'corsivo_focal_point_register_meta', 20 );

function corsivo_focal_point_get_stored_state( $post_id ) {
	$post_id = absint( $post_id );

	if ( ! $post_id ) {
		return array(
			'x'                   => 50,
			'y'                   => 50,
			'attachment_id'       => 0,
			'has_position'        => false,
			'has_attachment_link' => false,
		);
	}

	$has_x = metadata_exists( 'post', $post_id, CORSIVO_FOCAL_POINT_META_X );
	$has_y = metadata_exists( 'post', $post_id, CORSIVO_FOCAL_POINT_META_Y );
	$x     = get_post_meta( $post_id, CORSIVO_FOCAL_POINT_META_X, true );
	$y     = get_post_meta( $post_id, CORSIVO_FOCAL_POINT_META_Y, true );

	$has_attachment_link = metadata_exists( 'post', $post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT );

	return array(
		'x'                   => $has_x ? corsivo_focal_point_sanitize_coordinate( $x ) : 50,
		'y'                   => $has_y ? corsivo_focal_point_sanitize_coordinate( $y ) : 50,
		'attachment_id'       => $has_attachment_link ? corsivo_focal_point_sanitize_attachment_id( get_post_meta( $post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, true ) ) : 0,
		'has_position'        => $has_x || $has_y,
		'has_attachment_link' => $has_attachment_link,
	);
}

function corsivo_focal_point_get_state( $post_id ) {
	$state                           = corsivo_focal_point_get_stored_state( $post_id );
	$state['featured_attachment_id'] = get_post_thumbnail_id( $post_id );
	$state['matches_featured_image'] = ! $state['has_attachment_link'] || $state['attachment_id'] === $state['featured_attachment_id'];

	return $state;
}

function corsivo_focal_point_get_position_array( $post_id = null ) {
	$post_id = $post_id ? absint( $post_id ) : get_the_ID();
	$state   = corsivo_focal_point_get_state( $post_id );

	if ( ! $state['has_position'] || ! $state['matches_featured_image'] ) {
		return array(
			'x' => 50,
			'y' => 50,
		);
	}

	return array(
		'x' => $state['x'],
		'y' => $state['y'],
	);
}

function corsivo_focal_point_get_position( $post_id = null ) {
	$position = corsivo_focal_point_get_position_array( $post_id );

	return $position['x'] . '% ' . $position['y'] . '%';
}

function corsivo_focal_point_write_single_meta( $post_id, $meta_key, $value ) {
	global $wpdb;

	$values = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );

	if ( count( $values ) > 1 ) {
		$meta_ids = array_map(
			'absint',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
					$post_id,
					$meta_key
				)
			)
		);

		if ( $wpdb->last_error || count( $meta_ids ) < 2 ) {
			return false;
		}

		$primary_meta_id = array_shift( $meta_ids );
		update_metadata_by_mid( 'post', $primary_meta_id, $value );
		$primary_meta = get_metadata_by_mid( 'post', $primary_meta_id );

		if ( ! $primary_meta || (string) $primary_meta->meta_value !== (string) $value ) {
			return false;
		}

		foreach ( $meta_ids as $meta_id ) {
			delete_metadata_by_mid( 'post', $meta_id );
		}
	} else {
		update_metadata( 'post', $post_id, $meta_key, $value );
	}

	$stored_values = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );

	return metadata_exists( 'post', $post_id, $meta_key )
		&& 1 === count( $stored_values )
		&& (string) reset( $stored_values ) === (string) $value;
}

function corsivo_focal_point_restore_meta_snapshot( $post_id, $snapshot, $expected_values = null ) {
	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		$meta_values = $snapshot[ $meta_key ] ?? array();
		$meta_values = is_array( $meta_values ) ? $meta_values : array( $meta_values );

		if ( $meta_values ) {
			$current_values   = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );
			$snapshot_value   = reset( $meta_values );
			$matches_snapshot = 1 === count( $current_values ) && (string) reset( $current_values ) === (string) $snapshot_value;
			$matches_expected = is_array( $expected_values )
				&& array_key_exists( $meta_key, $expected_values )
				&& 1 === count( $current_values )
				&& (string) reset( $current_values ) === (string) $expected_values[ $meta_key ];

			if ( ! $matches_snapshot && ( ! is_array( $expected_values ) || $matches_expected ) ) {
				corsivo_focal_point_write_single_meta( $post_id, $meta_key, $snapshot_value );
			}
		} else {
			$current_values = corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key );
			$can_delete     = ! is_array( $expected_values )
				|| ! $current_values
				|| ( array_key_exists( $meta_key, $expected_values )
					&& 1 === count( $current_values )
					&& (string) reset( $current_values ) === (string) $expected_values[ $meta_key ] );

			if ( $can_delete ) {
				$meta_value = is_array( $expected_values ) ? $expected_values[ $meta_key ] ?? '' : '';
				delete_metadata( 'post', $post_id, $meta_key, $meta_value );
			}
		}
	}

	return corsivo_focal_point_get_snapshot_mismatches( $post_id, $snapshot );
}

function corsivo_focal_point_update_position( $post_id, $x, $y, $attachment_id ) {
	$post_id = absint( $post_id );

	if ( ! $post_id ) {
		return false;
	}

	$values = array(
		CORSIVO_FOCAL_POINT_META_X          => corsivo_focal_point_sanitize_coordinate( $x ),
		CORSIVO_FOCAL_POINT_META_Y          => corsivo_focal_point_sanitize_coordinate( $y ),
		CORSIVO_FOCAL_POINT_META_ATTACHMENT => corsivo_focal_point_sanitize_attachment_id( $attachment_id ),
	);
	$previous_values  = corsivo_focal_point_get_position_meta_snapshot( $post_id );
	$attempted_values = array();

	$failed_meta_key = '';

	foreach ( $values as $meta_key => $value ) {
		$attempted_values[ $meta_key ] = $value;

		if ( ! corsivo_focal_point_write_single_meta( $post_id, $meta_key, $value ) ) {
			$failed_meta_key = $meta_key;
			break;
		}
	}

	if ( ! $failed_meta_key ) {
		$failed_meta_keys = corsivo_focal_point_get_snapshot_mismatches( $post_id, $values );

		if ( ! $failed_meta_keys ) {
			return true;
		}

		$failed_meta_key = reset( $failed_meta_keys );
	}

	$rollback_failed_meta_keys = corsivo_focal_point_restore_meta_snapshot( $post_id, $previous_values, $attempted_values );

	corsivo_focal_point_log_failure(
		'position_update_failed',
		array(
			'post_id'                   => $post_id,
			'failed_meta_key'           => $failed_meta_key,
			'rollback_succeeded'         => ! $rollback_failed_meta_keys,
			'rollback_failed_meta_keys' => $rollback_failed_meta_keys,
		)
	);

	return false;
}

function corsivo_focal_point_mark_revision( $revision_id, $post_id ) {
	$post_type = get_post_type( $post_id );

	if ( ! wp_is_post_autosave( $revision_id )
		&& in_array( $post_type, corsivo_focal_point_get_post_types(), true )
		&& post_type_supports( $post_type, 'revisions' )
		&& corsivo_focal_point_revision_has_complete_position( $revision_id )
		&& corsivo_focal_point_revision_matches_post( $revision_id, $post_id )
	) {
		if ( ! corsivo_focal_point_write_revision_marker( $revision_id ) ) {
			corsivo_focal_point_log_failure(
				'revision_marker_write_failed',
				array(
					'post_id'     => $post_id,
					'revision_id' => $revision_id,
				)
			);
		}
	}
}
add_action( '_wp_put_post_revision', 'corsivo_focal_point_mark_revision', 20, 2 );

function corsivo_focal_point_revision_matches_post( $revision_id, $post_id ) {
	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		$post_values     = array_map( 'strval', corsivo_focal_point_get_raw_meta_values( $post_id, $meta_key ) );
		$revision_values = array_map( 'strval', corsivo_focal_point_get_raw_meta_values( $revision_id, $meta_key ) );

		if ( $post_values !== $revision_values ) {
			return false;
		}
	}

	return true;
}

function corsivo_focal_point_revision_has_complete_position( $revision_id ) {
	$x_count          = count( corsivo_focal_point_get_raw_meta_values( $revision_id, CORSIVO_FOCAL_POINT_META_X ) );
	$y_count          = count( corsivo_focal_point_get_raw_meta_values( $revision_id, CORSIVO_FOCAL_POINT_META_Y ) );
	$attachment_count = count( corsivo_focal_point_get_raw_meta_values( $revision_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT ) );

	return ( 0 === $x_count && 0 === $y_count && 0 === $attachment_count )
		|| ( 1 === $x_count && 1 === $y_count && 1 === $attachment_count );
}

function corsivo_focal_point_revision_marker_value( $revision_id ) {
	$state = array();

	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		$state[ $meta_key ] = array_map( 'strval', corsivo_focal_point_get_raw_meta_values( $revision_id, $meta_key ) );
	}

	return CORSIVO_FOCAL_POINT_REVISION_SCHEMA_VERSION . ':' . hash( 'sha256', wp_json_encode( $state ) );
}

function corsivo_focal_point_revision_has_recognized_marker( $revision_id ) {
	$markers = corsivo_focal_point_get_raw_meta_values( $revision_id, CORSIVO_FOCAL_POINT_REVISION_MARKER );
	$marker  = 1 === count( $markers ) ? reset( $markers ) : '';

	return is_string( $marker )
		&& 1 === preg_match( '/^' . preg_quote( CORSIVO_FOCAL_POINT_REVISION_SCHEMA_VERSION, '/' ) . ':[a-f0-9]{64}$/D', $marker );
}

function corsivo_focal_point_revision_is_coherent( $revision_id ) {
	$markers = corsivo_focal_point_get_raw_meta_values( $revision_id, CORSIVO_FOCAL_POINT_REVISION_MARKER );
	$marker  = 1 === count( $markers ) ? reset( $markers ) : '';

	return corsivo_focal_point_revision_has_recognized_marker( $revision_id )
		&& corsivo_focal_point_revision_has_complete_position( $revision_id )
		&& hash_equals( corsivo_focal_point_revision_marker_value( $revision_id ), $marker );
}

function corsivo_focal_point_write_revision_marker( $revision_id ) {
	return corsivo_focal_point_write_single_meta(
		$revision_id,
		CORSIVO_FOCAL_POINT_REVISION_MARKER,
		corsivo_focal_point_revision_marker_value( $revision_id )
	) && corsivo_focal_point_revision_is_coherent( $revision_id );
}

function corsivo_focal_point_sync_autosave( $autosave, $is_update = true ) {
	$revision_id = absint( $autosave['ID'] ?? 0 );
	$post_id     = absint( $autosave['post_parent'] ?? 0 );
	$post_type   = $post_id ? get_post_type( $post_id ) : '';

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ! $revision_id
		|| ! $post_id
		|| ! in_array( $post_type, corsivo_focal_point_get_post_types(), true )
		|| ! corsivo_focal_point_post_type_supports_autosave( $post_type )
	) {
		return;
	}

	$posted_data = $_POST['data']['wp_autosave'] ?? $_POST;
	$posted_data = is_array( $posted_data ) ? $posted_data : array();
	$meta_keys   = corsivo_focal_point_position_meta_keys();
	$was_coherent = corsivo_focal_point_revision_is_coherent( $revision_id );
	$has_updates = (bool) array_intersect( $meta_keys, array_keys( $posted_data ) );
	$complete    = true;

	foreach ( $meta_keys as $meta_key ) {
		if ( ! array_key_exists( $meta_key, $posted_data ) ) {
			continue;
		}

		if ( ! is_scalar( $posted_data[ $meta_key ] ) ) {
			$complete = false;
			continue;
		}

		$value = wp_unslash( $posted_data[ $meta_key ] );
		$value = CORSIVO_FOCAL_POINT_META_ATTACHMENT === $meta_key
			? corsivo_focal_point_sanitize_attachment_id( $value )
			: corsivo_focal_point_sanitize_coordinate( $value );

		delete_metadata( 'post', $revision_id, $meta_key );
		add_metadata( 'post', $revision_id, $meta_key, $value, true );

		$complete = metadata_exists( 'post', $revision_id, $meta_key )
			&& (string) get_post_meta( $revision_id, $meta_key, true ) === (string) $value
			&& $complete;
	}

	if ( ! $is_update ) {
		foreach ( $meta_keys as $meta_key ) {
			if ( array_key_exists( $meta_key, $posted_data ) ) {
				continue;
			}

			$parent_has_value   = metadata_exists( 'post', $post_id, $meta_key );
			$revision_has_value = metadata_exists( 'post', $revision_id, $meta_key );
			$complete           = $parent_has_value === $revision_has_value && $complete;

			if ( $parent_has_value && $revision_has_value ) {
				$complete = (string) get_post_meta( $post_id, $meta_key, true ) === (string) get_post_meta( $revision_id, $meta_key, true ) && $complete;
			}
		}
	}

	if ( $is_update && ! $was_coherent && ! $has_updates ) {
		$complete = corsivo_focal_point_revision_matches_post( $revision_id, $post_id ) && $complete;
	}

	$complete = corsivo_focal_point_revision_has_complete_position( $revision_id ) && $complete;

	if ( $complete && ! corsivo_focal_point_write_revision_marker( $revision_id ) ) {
		corsivo_focal_point_log_failure(
			'autosave_marker_write_failed',
			array(
				'post_id'     => $post_id,
				'revision_id' => $revision_id,
			)
		);
	}
}
add_action( 'wp_creating_autosave', 'corsivo_focal_point_sync_autosave', 20, 2 );

function corsivo_focal_point_update_autosave_response( $response, $state ) {
	if ( ! $response instanceof WP_REST_Response ) {
		return $response;
	}

	$response_data         = $response->get_data();
	$response_data         = is_array( $response_data ) ? $response_data : array();
	$response_data['meta'] = is_array( $response_data['meta'] ?? null ) ? $response_data['meta'] : array();

	$response_data['meta'][ CORSIVO_FOCAL_POINT_META_X ]          = $state['x'];
	$response_data['meta'][ CORSIVO_FOCAL_POINT_META_Y ]          = $state['y'];
	$response_data['meta'][ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] = $state['attachment_id'];
	$response->set_data( $response_data );

	return $response;
}

function corsivo_focal_point_update_direct_rest_autosave( $response, $post, $request ) {
	$post_id   = absint( $post->ID );
	$post_type = get_post_type( $post_id );

	if ( ! $post_id
		|| ! current_user_can( 'edit_post', $post_id )
		|| ! in_array( $post_type, corsivo_focal_point_get_post_types(), true )
		|| ! corsivo_focal_point_post_type_supports_autosave( $post_type )
	) {
		return $response;
	}

	$meta           = $request->get_param( 'meta' );
	$meta           = is_array( $meta ) ? $meta : array();
	$meta_keys      = corsivo_focal_point_position_meta_keys();
	$submitted_keys = array_values( array_intersect( $meta_keys, array_keys( $meta ) ) );

	if ( ! $submitted_keys ) {
		return $response;
	}

	foreach ( $submitted_keys as $meta_key ) {
		if ( ! is_scalar( $meta[ $meta_key ] ) ) {
			return new WP_Error(
				'corsivo_focal_point_invalid_autosave',
				__( 'Il focal point contiene valori non validi.', 'corsivo-focal-point' ),
				array( 'status' => 400 )
			);
		}
	}

	$previous_state = corsivo_focal_point_get_stored_state( $post_id );
	$x              = array_key_exists( CORSIVO_FOCAL_POINT_META_X, $meta )
		? corsivo_focal_point_sanitize_coordinate( $meta[ CORSIVO_FOCAL_POINT_META_X ] )
		: $previous_state['x'];
	$y              = array_key_exists( CORSIVO_FOCAL_POINT_META_Y, $meta )
		? corsivo_focal_point_sanitize_coordinate( $meta[ CORSIVO_FOCAL_POINT_META_Y ] )
		: $previous_state['y'];
	$attachment_id  = $previous_state['has_attachment_link']
		? $previous_state['attachment_id']
		: get_post_thumbnail_id( $post_id );

	if ( array_key_exists( CORSIVO_FOCAL_POINT_META_ATTACHMENT, $meta ) ) {
		$attachment_id = corsivo_focal_point_sanitize_attachment_id( $meta[ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] );
	}

	if ( ! corsivo_focal_point_update_position( $post_id, $x, $y, $attachment_id ) ) {
		return new WP_Error(
			'corsivo_focal_point_autosave_failed',
			__( 'Il focal point non è stato salvato. Riprova prima di chiudere l’editor.', 'corsivo-focal-point' ),
			array( 'status' => 500 )
		);
	}

	$current_state = corsivo_focal_point_get_stored_state( $post_id );

	if ( $previous_state !== $current_state ) {
		do_action( 'corsivo_focal_point_position_updated', $post_id, $current_state, $previous_state );
	}

	return corsivo_focal_point_update_autosave_response( $response, $current_state );
}

function corsivo_focal_point_mark_rest_autosave( $response, $autosave, $request ) {
	if ( 'POST' !== $request->get_method() || ! $autosave instanceof WP_Post ) {
		return $response;
	}

	if ( ! wp_is_post_autosave( $autosave ) ) {
		return corsivo_focal_point_update_direct_rest_autosave( $response, $autosave, $request );
	}

	$post_id   = absint( $autosave->post_parent );
	$post_type = $post_id ? get_post_type( $post_id ) : '';

	if ( ! in_array( $post_type, corsivo_focal_point_get_post_types(), true ) || ! corsivo_focal_point_post_type_supports_autosave( $post_type ) ) {
		return $response;
	}

	$meta             = $request->get_param( 'meta' );
	$meta             = is_array( $meta ) ? $meta : array();
	$meta_keys        = corsivo_focal_point_position_meta_keys();
	$submitted_keys   = array_values( array_intersect( $meta_keys, array_keys( $meta ) ) );
	$was_coherent     = corsivo_focal_point_revision_is_coherent( $autosave->ID );
	$can_reuse_state  = $was_coherent
		|| ( corsivo_focal_point_revision_has_recognized_marker( $autosave->ID ) && corsivo_focal_point_revision_has_complete_position( $autosave->ID ) );
	$parent_state     = corsivo_focal_point_get_stored_state( $post_id );
	$autosave_state   = corsivo_focal_point_get_stored_state( $autosave->ID );
	$position_written = true;

	foreach ( $submitted_keys as $meta_key ) {
		if ( ! is_scalar( $meta[ $meta_key ] ) ) {
			return new WP_Error(
				'corsivo_focal_point_invalid_autosave',
				__( 'Il focal point contiene valori non validi.', 'corsivo-focal-point' ),
				array( 'status' => 400 )
			);
		}
	}

	if ( $submitted_keys ) {
		$base_state    = $can_reuse_state ? $autosave_state : $parent_state;
		$x             = array_key_exists( CORSIVO_FOCAL_POINT_META_X, $meta )
			? corsivo_focal_point_sanitize_coordinate( $meta[ CORSIVO_FOCAL_POINT_META_X ] )
			: $base_state['x'];
		$y             = array_key_exists( CORSIVO_FOCAL_POINT_META_Y, $meta )
			? corsivo_focal_point_sanitize_coordinate( $meta[ CORSIVO_FOCAL_POINT_META_Y ] )
			: $base_state['y'];
		$attachment_id = $base_state['has_attachment_link']
			? $base_state['attachment_id']
			: get_post_thumbnail_id( $post_id );

		if ( array_key_exists( CORSIVO_FOCAL_POINT_META_ATTACHMENT, $meta ) ) {
			$attachment_id = corsivo_focal_point_sanitize_attachment_id( $meta[ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] );
		}

		$position_written = corsivo_focal_point_update_position( $autosave->ID, $x, $y, $attachment_id );
	} elseif ( ! $was_coherent && ( $parent_state['has_position'] || $parent_state['has_attachment_link'] ) ) {
		$attachment_id    = $parent_state['has_attachment_link']
			? $parent_state['attachment_id']
			: get_post_thumbnail_id( $post_id );
		$position_written = corsivo_focal_point_update_position( $autosave->ID, $parent_state['x'], $parent_state['y'], $attachment_id );
	} elseif ( ! $was_coherent ) {
		foreach ( $meta_keys as $meta_key ) {
			delete_metadata( 'post', $autosave->ID, $meta_key );
			$position_written = ! metadata_exists( 'post', $autosave->ID, $meta_key ) && $position_written;
		}
	}

	if ( ! $position_written || ! corsivo_focal_point_revision_has_complete_position( $autosave->ID ) ) {
		return new WP_Error(
			'corsivo_focal_point_autosave_failed',
			__( 'Il focal point non è stato salvato. Riprova prima di chiudere l’editor.', 'corsivo-focal-point' ),
			array( 'status' => 500 )
		);
	}

	if ( ! corsivo_focal_point_write_revision_marker( $autosave->ID ) ) {
		corsivo_focal_point_log_failure(
			'autosave_marker_write_failed',
			array(
				'post_id'     => $post_id,
				'revision_id' => $autosave->ID,
			)
		);

		return new WP_Error(
			'corsivo_focal_point_autosave_failed',
			__( 'Il focal point non è stato salvato. Riprova prima di chiudere l’editor.', 'corsivo-focal-point' ),
			array( 'status' => 500 )
		);
	}

	return corsivo_focal_point_update_autosave_response( $response, corsivo_focal_point_get_stored_state( $autosave->ID ) );
}
add_filter( 'rest_prepare_autosave', 'corsivo_focal_point_mark_rest_autosave', 10, 3 );

function corsivo_focal_point_revision_restore_snapshot( $post_id, $snapshot = false ) {
	static $snapshots = array();

	$post_id = absint( $post_id );

	if ( false !== $snapshot ) {
		$snapshots[ $post_id ] = $snapshot;
		return null;
	}

	if ( ! array_key_exists( $post_id, $snapshots ) ) {
		return null;
	}

	$snapshot = $snapshots[ $post_id ];
	unset( $snapshots[ $post_id ] );

	return $snapshot;
}

function corsivo_focal_point_capture_revision_restore( $post_id, $revision_id ) {
	$post_type        = get_post_type( $post_id );
	$supports_history = post_type_supports( $post_type, 'revisions' )
		|| ( wp_is_post_autosave( $revision_id ) && corsivo_focal_point_post_type_supports_autosave( $post_type ) );

	if ( ! in_array( $post_type, corsivo_focal_point_get_post_types(), true ) || ! $supports_history ) {
		return;
	}

	$values = corsivo_focal_point_get_position_meta_snapshot( $post_id );

	corsivo_focal_point_revision_restore_snapshot(
		$post_id,
		array(
			'previous_state'     => corsivo_focal_point_get_stored_state( $post_id ),
			'previous_values'    => $values,
			'revision_coherent' => corsivo_focal_point_revision_is_coherent( $revision_id ),
			'restore_manually'   => wp_is_post_autosave( $revision_id ) && ! post_type_supports( $post_type, 'revisions' ),
			'revision_id'        => $revision_id,
		)
	);
}
add_action( 'wp_restore_post_revision', 'corsivo_focal_point_capture_revision_restore', 9, 2 );

function corsivo_focal_point_finalize_revision_restore( $post_id ) {
	$snapshot = corsivo_focal_point_revision_restore_snapshot( $post_id );

	if ( ! $snapshot ) {
		return;
	}

	$restore_mode      = 'native';
	$restore_succeeded = true;
	$failed_meta_keys  = array();

	if ( ! $snapshot['revision_coherent'] ) {
		$restore_mode      = 'rejected';
		$failed_meta_keys  = corsivo_focal_point_restore_meta_snapshot( $post_id, $snapshot['previous_values'] );
		$restore_succeeded = ! $failed_meta_keys;
	} elseif ( $snapshot['restore_manually'] ) {
		$restore_mode   = 'manual';
		$revision_state = corsivo_focal_point_get_stored_state( $snapshot['revision_id'] );

		if ( $revision_state['has_position'] || $revision_state['has_attachment_link'] ) {
			$restore_succeeded = corsivo_focal_point_update_position(
				$post_id,
				$revision_state['x'],
				$revision_state['y'],
				$revision_state['attachment_id']
			);

			if ( ! $restore_succeeded ) {
				$revision_snapshot = corsivo_focal_point_get_position_meta_snapshot( $snapshot['revision_id'] );
				$failed_meta_keys  = corsivo_focal_point_get_snapshot_mismatches( $post_id, $revision_snapshot );
			}
		} else {
			$failed_meta_keys  = corsivo_focal_point_restore_meta_snapshot( $post_id, array() );
			$restore_succeeded = ! $failed_meta_keys;
		}
	} elseif ( ! corsivo_focal_point_revision_matches_post( $snapshot['revision_id'], $post_id ) ) {
		$revision_snapshot = corsivo_focal_point_get_position_meta_snapshot( $snapshot['revision_id'] );
		$failed_meta_keys  = corsivo_focal_point_restore_meta_snapshot( $post_id, $revision_snapshot );
		$restore_succeeded = ! $failed_meta_keys;
	}

	if ( ! $restore_succeeded ) {
		corsivo_focal_point_log_failure(
			'revision_restore_failed',
			array(
				'post_id'          => $post_id,
				'revision_id'      => $snapshot['revision_id'],
				'mode'             => $restore_mode,
				'failed_meta_keys' => $failed_meta_keys,
			)
		);

		if ( get_current_user_id() ) {
			set_transient( 'corsivo_focal_point_save_notice_' . get_current_user_id(), 'revision_restore_failed', MINUTE_IN_SECONDS );
		}

		return false;
	}

	$current_state = corsivo_focal_point_get_stored_state( $post_id );

	if ( $snapshot['previous_state'] !== $current_state ) {
		do_action( 'corsivo_focal_point_position_updated', $post_id, $current_state, $snapshot['previous_state'] );
	}

	return true;
}
add_action( 'wp_restore_post_revision', 'corsivo_focal_point_finalize_revision_restore', 11 );

function corsivo_focal_point_add_revision_field( $fields, $post ) {
	$post_type = $post['post_type'] ?? '';

	if ( in_array( $post_type, corsivo_focal_point_get_post_types(), true ) && post_type_supports( $post_type, 'revisions' ) ) {
		$fields['corsivo_focal_point_position'] = __( 'Focal point', 'corsivo-focal-point' );
	} else {
		unset( $fields['corsivo_focal_point_position'] );
	}

	return $fields;
}
add_filter( '_wp_post_revision_fields', 'corsivo_focal_point_add_revision_field', 10, 2 );

function corsivo_focal_point_get_revision_field( $revision_field, $field, $revision ) {
	if ( wp_is_post_revision( $revision->ID )
		&& ! corsivo_focal_point_revision_is_coherent( $revision->ID )
	) {
		return __( 'Snapshot focal point non valido', 'corsivo-focal-point' );
	}

	$state = corsivo_focal_point_get_stored_state( $revision->ID );

	if ( ! $state['has_position'] ) {
		return __( 'Non impostato', 'corsivo-focal-point' );
	}

	return sprintf(
		'%1$d%% × %2$d%% · attachment #%3$d',
		$state['x'],
		$state['y'],
		$state['attachment_id']
	);
}
add_filter( '_wp_post_revision_field_corsivo_focal_point_position', 'corsivo_focal_point_get_revision_field', 10, 3 );
