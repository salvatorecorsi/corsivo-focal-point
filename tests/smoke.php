<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
	define( 'WP_RUN_CORE_TESTS', true );
}

$failures = array();
$assert   = function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( 0 === corsivo_focal_point_sanitize_coordinate( -5 ), 'Coordinate below zero must clamp to zero.' );
$assert( 100 === corsivo_focal_point_sanitize_coordinate( 150 ), 'Coordinate above one hundred must clamp to one hundred.' );
$assert( 13 === corsivo_focal_point_sanitize_coordinate( 12.6 ), 'Coordinates must be rounded to integers.' );
$assert( 50 === corsivo_focal_point_sanitize_coordinate( 'invalid' ), 'Invalid coordinates must use the center.' );
$assert( 50 === corsivo_focal_point_sanitize_coordinate( INF ), 'Infinite coordinates must use the center.' );

$html   = '<a style="color:red"><img SRC="image.jpg" style="display:block; object-position:20% 30%; height:auto"></a><img src="second.jpg">';
$result = corsivo_focal_point_apply_position_to_html( $html, array( 'x' => 0, 'y' => 100 ) );
$parser = new WP_HTML_Tag_Processor( $result );

$assert( $parser->next_tag( array( 'tag_name' => 'A' ) ), 'The wrapper must remain available.' );
$assert( 'color:red' === $parser->get_attribute( 'style' ), 'Wrapper styles must not be modified.' );
$assert( $parser->next_tag( array( 'tag_name' => 'IMG' ) ), 'The first image must remain available.' );

$first_image_style = (string) $parser->get_attribute( 'style' );
$assert( str_contains( $first_image_style, 'display:block' ), 'Existing image styles must be preserved.' );
$assert( str_contains( $first_image_style, 'height:auto' ), 'Styles after object-position must be preserved.' );
$assert( str_contains( $first_image_style, 'object-position:0% 100%;' ), 'Zero and one hundred must be rendered.' );
$assert( 1 === substr_count( $first_image_style, 'object-position' ), 'Object position must not be duplicated.' );
$assert( $parser->next_tag( array( 'tag_name' => 'IMG' ) ), 'The second image must remain available.' );
$assert( null === $parser->get_attribute( 'style' ), 'Only the first image may be modified.' );
$assert( '<span>Text</span>' === corsivo_focal_point_apply_position_to_html( '<span>Text</span>', array( 'x' => 10, 'y' => 20 ) ), 'Markup without images must not change.' );
$assert( $html === corsivo_focal_point_apply_position_to_html( $html, array( 'x' => 50, 'y' => 50 ) ), 'The centered default must not change markup.' );

$registered_meta = get_registered_meta_keys( 'post', 'post' );
$coordinate_meta = $registered_meta[ CORSIVO_FOCAL_POINT_META_X ] ?? array();
$schema          = $coordinate_meta['show_in_rest']['schema'] ?? array();

$assert( 'integer' === ( $coordinate_meta['type'] ?? '' ), 'Coordinate meta must use the integer type.' );
$assert( 0 === ( $schema['minimum'] ?? null ), 'REST schema must expose the minimum.' );
$assert( 100 === ( $schema['maximum'] ?? null ), 'REST schema must expose the maximum.' );
$assert( is_callable( $coordinate_meta['sanitize_callback'] ?? null ), 'Coordinate meta must have a sanitizer.' );
$assert( is_callable( $coordinate_meta['auth_callback'] ?? null ), 'Coordinate meta must have an authorization callback.' );

if ( post_type_supports( 'post', 'revisions' ) ) {
	$assert( true === ( $coordinate_meta['revisions_enabled'] ?? false ), 'Coordinate meta must follow post revisions.' );
}

$sanitized_settings = corsivo_focal_point_sanitize_settings(
	array(
		'post_types'             => array( 'post', 'product', 'invalid_type', array( 'nested' ) ),
		'woocommerce_enabled'    => 1,
		'wpml_copy_once_enabled' => 1,
		'delete_on_uninstall'    => 1,
	)
);
$assert( ! in_array( 'product', $sanitized_settings['post_types'], true ), 'Products must only be enabled through the WooCommerce module.' );
$assert( ! in_array( 'invalid_type', $sanitized_settings['post_types'], true ), 'Unknown post types must be rejected.' );
$assert( true === $sanitized_settings['woocommerce_enabled'], 'The WooCommerce toggle must be normalized to boolean.' );

$administrator_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$administrator_id = $administrator_ids ? (int) reset( $administrator_ids ) : 0;

$assert( 0 < $administrator_id, 'An administrator is required for REST smoke tests.' );

$autosave_post_type = 'corsivo_fp_autosave';
register_post_type(
	$autosave_post_type,
	array(
		'public'       => false,
		'show_ui'      => false,
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'thumbnail' ),
	)
);
$autosave_post_types_filter = function ( $post_types ) use ( $autosave_post_type ) {
	$post_types[] = $autosave_post_type;
	return $post_types;
};
add_filter( 'corsivo_focal_point_post_types', $autosave_post_types_filter );
corsivo_focal_point_register_meta();

$autosave_registered_meta = get_registered_meta_keys( 'post', $autosave_post_type );
$assert( corsivo_focal_point_post_type_supports_autosave( $autosave_post_type ), 'The test post type must expose autosaves.' );
$assert( ! post_type_supports( $autosave_post_type, 'revisions' ), 'The autosave test post type must not expose revisions.' );
$assert( isset( $autosave_registered_meta[ CORSIVO_FOCAL_POINT_META_X ] ), 'Focal point meta must be registered for autosave-only post types.' );

global $wp_version;

$installed_wp_version = $wp_version;
remove_post_type_support( $autosave_post_type, 'autosave' );
remove_post_type_support( $autosave_post_type, 'editor' );
$autosave_post_type_object               = get_post_type_object( $autosave_post_type );
$autosave_post_type_object->show_in_rest = false;
$wp_version = '6.5.5';
$assert( corsivo_focal_point_post_type_supports_autosave( $autosave_post_type ), 'WordPress 6.5 editable post types must retain autosave compatibility.' );
$wp_version = $installed_wp_version;
$autosave_post_type_object->show_in_rest = true;
add_post_type_support( $autosave_post_type, 'editor' );
add_post_type_support( $autosave_post_type, 'autosave' );

$temporary_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point smoke test',
		'post_status' => 'draft',
		'post_type'   => 'post',
		'post_author' => $administrator_id,
	),
	true
);

$assert( ! is_wp_error( $temporary_post_id ), 'A temporary post must be created for data tests.' );

if ( ! is_wp_error( $temporary_post_id ) ) {
	$temporary_post_id = absint( $temporary_post_id );
	$events             = array();
	$event_listener     = function ( $post_id, $current_state, $previous_state ) use ( $temporary_post_id, &$events ) {
		if ( $temporary_post_id === $post_id ) {
			$events[] = array( $current_state, $previous_state );
		}
	};

	add_action( 'corsivo_focal_point_position_updated', $event_listener, 10, 3 );

	try {
		update_post_meta( $temporary_post_id, '_thumbnail_id', 321 );
		add_post_meta( $temporary_post_id, '_focal_point_x', 0 );
		add_post_meta( $temporary_post_id, '_focal_point_y', 100 );
		add_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, -20 );
		add_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, 80 );

		corsivo_focal_point_migrate_post( $temporary_post_id );

		$assert( 0 === (int) get_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X, true ), 'Legacy zero must survive migration.' );
		$assert( 0 === (int) get_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, true ), 'Existing Corsivo values must take precedence and clamp.' );
		$assert( 1 === count( get_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, false ) ), 'Single meta values must be deduplicated.' );
		$assert( 321 === (int) get_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, true ), 'Migration must bind the current featured image.' );
		$assert( 100 === (int) get_post_meta( $temporary_post_id, '_focal_point_y', true ), 'Legacy values must remain available for rollback.' );
		$assert( array( 'x' => 0, 'y' => 0 ) === corsivo_focal_point_get_position_array( $temporary_post_id ), 'A top-left focal point must remain valid.' );

		update_post_meta( $temporary_post_id, '_thumbnail_id', 654 );
		$assert( array( 'x' => 50, 'y' => 50 ) === corsivo_focal_point_get_position_array( $temporary_post_id ), 'Coordinates from another attachment must remain inactive.' );
		update_post_meta( $temporary_post_id, '_thumbnail_id', 321 );
		$assert( array( 'x' => 0, 'y' => 0 ) === corsivo_focal_point_get_position_array( $temporary_post_id ), 'Re-selecting the original attachment must restore its focal point.' );

		$assert( false === corsivo_focal_point_authorize_meta( false, CORSIVO_FOCAL_POINT_META_X, $temporary_post_id, 0 ), 'Anonymous users must not edit focal point meta.' );

		if ( $administrator_id ) {
			$assert(
				user_can( $administrator_id, 'edit_post', $temporary_post_id ) === corsivo_focal_point_authorize_meta( false, CORSIVO_FOCAL_POINT_META_X, $temporary_post_id, $administrator_id ),
				'Meta authorization must follow the object-specific edit_post capability.'
			);
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $temporary_post_id );
		corsivo_focal_point_before_rest_update( get_post( $temporary_post_id ), $request );
		$event_count = count( $events );
		corsivo_focal_point_after_rest_update( get_post( $temporary_post_id ), $request );
		$assert( $event_count === count( $events ), 'An unchanged REST state must not emit an update event.' );

		corsivo_focal_point_before_rest_update( get_post( $temporary_post_id ), $request );
		corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X, 10 );
		corsivo_focal_point_after_rest_update( get_post( $temporary_post_id ), $request );
		$assert( $event_count + 1 === count( $events ), 'A REST state change must emit one update event.' );

		if ( $administrator_id ) {
			$previous_user_id = get_current_user_id();
			wp_set_current_user( $administrator_id );

			try {
				$normal_rest_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $temporary_post_id );
				$normal_rest_request->set_param(
					'meta',
					array(
						CORSIVO_FOCAL_POINT_META_X          => 11,
						CORSIVO_FOCAL_POINT_META_Y          => 22,
						CORSIVO_FOCAL_POINT_META_ATTACHMENT => 321,
					)
				);
				$normal_rest_response = rest_do_request( $normal_rest_request );
				$matching_revision_id = 0;

				foreach ( wp_get_post_revisions( $temporary_post_id ) as $revision ) {
					if ( 11 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_X, true )
						&& 22 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_Y, true )
					) {
						$matching_revision_id = $revision->ID;
						break;
					}
				}

				$assert( 200 === $normal_rest_response->get_status(), 'Normal REST updates must persist focal point metadata.' );
				$assert( $matching_revision_id && corsivo_focal_point_revision_is_compatible( $matching_revision_id ), 'Normal REST focal point updates must create a coherent revision.' );

				$direct_autosave_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $temporary_post_id . '/autosaves' );
				$direct_autosave_request->set_param(
					'meta',
					array(
						CORSIVO_FOCAL_POINT_META_X          => 12,
						CORSIVO_FOCAL_POINT_META_Y          => 34,
						CORSIVO_FOCAL_POINT_META_ATTACHMENT => 321,
					)
				);
				$direct_autosave_response = rest_do_request( $direct_autosave_request );
				$direct_autosave_state = corsivo_focal_point_get_stored_state( $temporary_post_id );
				$response_meta         = $direct_autosave_response->get_data()['meta'] ?? array();

				$assert( 200 === $direct_autosave_response->get_status(), 'The direct draft autosave route must succeed.' );
				$assert( 12 === $direct_autosave_state['x'] && 34 === $direct_autosave_state['y'], 'Direct draft autosaves must persist focal point metadata.' );
				$assert( 12 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_X ] ?? null ) && 34 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_Y ] ?? null ), 'Direct draft autosave responses must expose the persisted focal point.' );

				$blocked_autosave_write = function ( $check, $post_id, $meta_key ) use ( $temporary_post_id ) {
					return $temporary_post_id === absint( $post_id ) && CORSIVO_FOCAL_POINT_META_X === $meta_key ? false : $check;
				};
				add_filter( 'update_post_metadata', $blocked_autosave_write, 10, 3 );

				try {
					$failed_autosave_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $temporary_post_id . '/autosaves' );
					$failed_autosave_request->set_param(
						'meta',
						array(
							CORSIVO_FOCAL_POINT_META_X          => 13,
							CORSIVO_FOCAL_POINT_META_Y          => 35,
							CORSIVO_FOCAL_POINT_META_ATTACHMENT => 321,
						)
					);
					$failed_autosave_response = rest_do_request( $failed_autosave_request );
					$state_after_failure      = corsivo_focal_point_get_stored_state( $temporary_post_id );

					$assert( 500 === $failed_autosave_response->get_status(), 'A failed focal point autosave must return a REST error.' );
					$assert( 12 === $state_after_failure['x'] && 34 === $state_after_failure['y'], 'A failed autosave rollback must preserve the previous focal point.' );
				} finally {
					remove_filter( 'update_post_metadata', $blocked_autosave_write, 10 );
				}
			} finally {
				wp_set_current_user( $previous_user_id );
			}
		}

		$wpml_target_id = wp_insert_post(
			array(
				'post_title'  => 'Corsivo Focal Point WPML target',
				'post_status' => 'draft',
				'post_type'   => 'post',
			),
			true
		);
		$assert( ! is_wp_error( $wpml_target_id ), 'A temporary WPML target must be created.' );

		if ( ! is_wp_error( $wpml_target_id ) ) {
			$wpml_target_id = absint( $wpml_target_id );
			$wpml_settings  = function ( $value ) {
				return array(
					'post_types'             => array( 'post' ),
					'woocommerce_enabled'    => false,
					'wpml_copy_once_enabled' => true,
					'delete_on_uninstall'    => false,
				);
			};
			$wpml_element_type = function ( $post_type ) {
				return 'post_' . $post_type;
			};
			$wpml_trid = function ( $trid, $post_id ) use ( $temporary_post_id, $wpml_target_id ) {
				return in_array( absint( $post_id ), array( $temporary_post_id, $wpml_target_id ), true ) ? 987 : $trid;
			};
			$wpml_translations = function ( $translations, $trid ) use ( $temporary_post_id, $wpml_target_id ) {
				if ( 987 !== (int) $trid ) {
					return $translations;
				}

				return array(
					'en' => (object) array( 'element_id' => $temporary_post_id, 'original' => true ),
					'it' => (object) array( 'element_id' => $wpml_target_id, 'original' => false ),
				);
			};

			corsivo_focal_point_update_position( $temporary_post_id, 25, 75, 321 );
			update_post_meta( $wpml_target_id, '_thumbnail_id', 654 );
			add_filter( 'pre_option_' . CORSIVO_FOCAL_POINT_SETTINGS_OPTION, $wpml_settings );
			add_filter( 'wpml_element_type', $wpml_element_type );
			add_filter( 'wpml_element_trid', $wpml_trid, 10, 3 );
			add_filter( 'wpml_get_element_translations', $wpml_translations, 10, 3 );

			try {
				corsivo_focal_point_maybe_copy_wpml_position_after_automatic_translation( $wpml_target_id );
				$wpml_target_state = corsivo_focal_point_get_stored_state( $wpml_target_id );
				$assert( 25 === $wpml_target_state['x'] && 75 === $wpml_target_state['y'] && 654 === $wpml_target_state['attachment_id'], 'WPML must copy coordinates and bind the target featured image.' );
				$assert( ! empty( wp_get_post_revisions( $wpml_target_id ) ), 'Automatic WPML initialization must create a revision for the copied focal point.' );
				corsivo_focal_point_update_position( $temporary_post_id, 90, 10, 321 );
				$assert( false === corsivo_focal_point_maybe_copy_wpml_position( $wpml_target_id, false ), 'WPML copy-once must not overwrite an initialized translation.' );
				$assert( 25 === corsivo_focal_point_get_stored_state( $wpml_target_id )['x'], 'WPML translations must remain independent after initialization.' );
			} finally {
				remove_filter( 'pre_option_' . CORSIVO_FOCAL_POINT_SETTINGS_OPTION, $wpml_settings );
				remove_filter( 'wpml_element_type', $wpml_element_type );
				remove_filter( 'wpml_element_trid', $wpml_trid, 10 );
				remove_filter( 'wpml_get_element_translations', $wpml_translations, 10 );
				wp_delete_post( $wpml_target_id, true );
			}
		}

		if ( wp_revisions_enabled( get_post( $temporary_post_id ) ) ) {
			$previous_post_data = $_POST;
			$autosave_id        = _wp_put_post_revision( $temporary_post_id, true );

			if ( ! is_wp_error( $autosave_id ) && $autosave_id ) {
				delete_metadata( 'post', $autosave_id, CORSIVO_FOCAL_POINT_META_X );
				delete_metadata( 'post', $autosave_id, CORSIVO_FOCAL_POINT_META_Y );
				delete_metadata( 'post', $autosave_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT );
				$_POST = array(
					CORSIVO_FOCAL_POINT_META_X          => 0,
					CORSIVO_FOCAL_POINT_META_Y          => 0,
					CORSIVO_FOCAL_POINT_META_ATTACHMENT => 321,
				);
				corsivo_focal_point_sync_autosave( get_post( $autosave_id, ARRAY_A ), false );
				$assert( 0 === (int) get_post_meta( $autosave_id, CORSIVO_FOCAL_POINT_META_X, true ), 'Autosaves must preserve a zero X coordinate.' );
				$assert( 0 === (int) get_post_meta( $autosave_id, CORSIVO_FOCAL_POINT_META_Y, true ), 'Autosaves must preserve a zero Y coordinate.' );
				$assert( metadata_exists( 'post', $autosave_id, CORSIVO_FOCAL_POINT_REVISION_MARKER ), 'Complete autosaves must carry the compatibility marker.' );
				$assert( corsivo_focal_point_revision_is_compatible( $autosave_id ), 'Autosave compatibility markers must match their metadata snapshot.' );
				wp_restore_post_revision( $autosave_id );
				$assert( array( 'x' => 0, 'y' => 0 ) === corsivo_focal_point_get_position_array( $temporary_post_id ), 'Restoring an autosave must preserve zero coordinates.' );
			}

			$_POST = $previous_post_data;
			corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X, 10 );
			corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, 20 );
			corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, 321 );
			wp_update_post( array( 'ID' => $temporary_post_id, 'post_title' => 'Focal revision one' ) );

			$first_revision_id = 0;

			foreach ( wp_get_post_revisions( $temporary_post_id ) as $revision ) {
				if ( 10 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_X, true )
					&& 20 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_Y, true )
				) {
					$first_revision_id = $revision->ID;
					break;
				}
			}

			$assert( 0 < $first_revision_id, 'Revision metadata must be stored.' );
			$assert( metadata_exists( 'post', $first_revision_id, CORSIVO_FOCAL_POINT_REVISION_MARKER ), 'New revisions must carry a compatibility marker.' );
			$assert( corsivo_focal_point_revision_is_compatible( $first_revision_id ), 'Revision compatibility markers must match their metadata snapshot.' );

			if ( $first_revision_id ) {
				add_metadata( 'post', $first_revision_id, CORSIVO_FOCAL_POINT_META_X, 99 );
				$assert( ! corsivo_focal_point_revision_is_compatible( $first_revision_id ), 'Revisions with duplicate focal point meta must be rejected.' );
				delete_metadata( 'post', $first_revision_id, CORSIVO_FOCAL_POINT_META_X, 99 );
				$assert( corsivo_focal_point_revision_is_compatible( $first_revision_id ), 'Removing duplicate revision meta must restore checksum compatibility.' );
			}

			corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X, 70 );
			corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, 80 );
			wp_update_post( array( 'ID' => $temporary_post_id, 'post_title' => 'Focal revision two' ) );

			$second_revision_id = 0;

			foreach ( wp_get_post_revisions( $temporary_post_id ) as $revision ) {
				if ( 70 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_X, true )
					&& 80 === (int) get_post_meta( $revision->ID, CORSIVO_FOCAL_POINT_META_Y, true )
				) {
					$second_revision_id = $revision->ID;
					break;
				}
			}

			$assert( 0 < $second_revision_id, 'A second focal point revision must be stored.' );

			if ( $first_revision_id ) {
				wp_restore_post_revision( $first_revision_id );
				$restored_state = corsivo_focal_point_get_stored_state( $temporary_post_id );
				$assert( 10 === $restored_state['x'] && 20 === $restored_state['y'] && 321 === $restored_state['attachment_id'], 'Revision restore must restore all focal point meta.' );
			}

			if ( $second_revision_id ) {
				delete_metadata( 'post', $second_revision_id, CORSIVO_FOCAL_POINT_REVISION_MARKER );
				$assert(
					str_contains( corsivo_focal_point_get_revision_field( '', 'corsivo_focal_point_position', get_post( $second_revision_id ) ), 'non disponibile' ),
					'Pre-support revisions must disclose that focal point history is unavailable.'
				);
				delete_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X );
				delete_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y );
				delete_post_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT );
				wp_restore_post_revision( $second_revision_id );
				$assert(
					! metadata_exists( 'post', $temporary_post_id, CORSIVO_FOCAL_POINT_META_X )
					&& ! metadata_exists( 'post', $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y )
					&& ! metadata_exists( 'post', $temporary_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT ),
					'Pre-support revisions must preserve an intentionally empty current focal point.'
				);
				corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_X, 33 );
				corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_Y, 44 );
				corsivo_focal_point_write_single_meta( $temporary_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, 321 );
				wp_update_post( array( 'ID' => $temporary_post_id, 'post_title' => 'Focal revision three' ) );
				wp_restore_post_revision( $second_revision_id );
				$preserved_state = corsivo_focal_point_get_stored_state( $temporary_post_id );
				$assert( 33 === $preserved_state['x'] && 44 === $preserved_state['y'], 'Pre-support revisions must preserve the current focal point.' );
			}
		}
	} finally {
		remove_action( 'corsivo_focal_point_position_updated', $event_listener, 10 );
		wp_delete_post( $temporary_post_id, true );
	}
}

$partial_autosave_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point partial autosave',
		'post_status' => 'publish',
		'post_type'   => 'post',
		'post_author' => $administrator_id,
	),
	true
);

if ( ! is_wp_error( $partial_autosave_post_id ) && $administrator_id ) {
	$partial_autosave_post_id = absint( $partial_autosave_post_id );
	$previous_user_id         = get_current_user_id();
	wp_set_current_user( $administrator_id );

	try {
		corsivo_focal_point_update_position( $partial_autosave_post_id, 10, 20, 0 );
		$full_autosave_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $partial_autosave_post_id . '/autosaves' );
		$full_autosave_request->set_param( 'content', 'First unsaved version' );
		$full_autosave_request->set_param(
			'meta',
			array(
				CORSIVO_FOCAL_POINT_META_X          => 30,
				CORSIVO_FOCAL_POINT_META_Y          => 70,
				CORSIVO_FOCAL_POINT_META_ATTACHMENT => 0,
			)
		);
		$full_autosave_response = rest_do_request( $full_autosave_request );
		$partial_autosave       = wp_get_post_autosave( $partial_autosave_post_id, $administrator_id );

		$assert( 200 === $full_autosave_response->get_status() && $partial_autosave instanceof WP_Post, 'A full REST autosave revision must be created.' );

		$partial_autosave_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $partial_autosave_post_id . '/autosaves' );
		$partial_autosave_request->set_param( 'content', 'Second unsaved version' );
		$partial_autosave_request->set_param( 'meta', array( CORSIVO_FOCAL_POINT_META_X => 35 ) );
		$partial_autosave_response = rest_do_request( $partial_autosave_request );
		$partial_autosave          = wp_get_post_autosave( $partial_autosave_post_id, $administrator_id );

		if ( $partial_autosave instanceof WP_Post ) {
			$partial_state = corsivo_focal_point_get_stored_state( $partial_autosave->ID );
			$response_meta = $partial_autosave_response->get_data()['meta'] ?? array();

			$assert( 200 === $partial_autosave_response->get_status(), 'Partial REST autosave updates must succeed.' );
			$assert( 35 === $partial_state['x'] && 70 === $partial_state['y'], 'Partial REST autosaves must preserve unsubmitted autosave coordinates.' );
			$assert( 35 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_X ] ?? null ) && 70 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_Y ] ?? null ), 'Partial REST autosave responses must expose the complete persisted state.' );
			$assert( corsivo_focal_point_revision_is_compatible( $partial_autosave->ID ), 'Partial REST autosaves must refresh the compatibility marker.' );
		}
	} finally {
		wp_set_current_user( $previous_user_id );
		wp_delete_post( $partial_autosave_post_id, true );
	}
} elseif ( ! is_wp_error( $partial_autosave_post_id ) ) {
	wp_delete_post( $partial_autosave_post_id, true );
}

$empty_revision_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point empty revision',
		'post_status' => 'draft',
		'post_type'   => 'post',
	),
	true
);

if ( ! is_wp_error( $empty_revision_post_id ) && wp_revisions_enabled( get_post( $empty_revision_post_id ) ) ) {
	$empty_revision_post_id = absint( $empty_revision_post_id );

	try {
		wp_update_post( array( 'ID' => $empty_revision_post_id, 'post_title' => 'Corsivo Focal Point empty revision saved' ) );
		$empty_revisions  = wp_get_post_revisions( $empty_revision_post_id );
		$empty_revision   = $empty_revisions ? reset( $empty_revisions ) : null;
		$empty_revision_id = $empty_revision instanceof WP_Post ? $empty_revision->ID : 0;

		$assert( 0 < $empty_revision_id, 'An empty focal point revision must be stored.' );
		$assert( $empty_revision_id && corsivo_focal_point_revision_is_compatible( $empty_revision_id ), 'Empty focal point revisions must carry a valid compatibility marker.' );

		if ( $empty_revision_id ) {
			corsivo_focal_point_update_position( $empty_revision_post_id, 40, 60, 0 );
			wp_restore_post_revision( $empty_revision_id );
			$assert(
				! metadata_exists( 'post', $empty_revision_post_id, CORSIVO_FOCAL_POINT_META_X )
				&& ! metadata_exists( 'post', $empty_revision_post_id, CORSIVO_FOCAL_POINT_META_Y )
				&& ! metadata_exists( 'post', $empty_revision_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT ),
				'Restoring an empty compatible revision must clear the current focal point.'
			);
		}
	} finally {
		wp_delete_post( $empty_revision_post_id, true );
	}
} elseif ( ! is_wp_error( $empty_revision_post_id ) ) {
	wp_delete_post( $empty_revision_post_id, true );
}

$legacy_revision_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point legacy revision',
		'post_status' => 'draft',
		'post_type'   => 'post',
	),
	true
);

if ( ! is_wp_error( $legacy_revision_post_id ) && wp_revisions_enabled( get_post( $legacy_revision_post_id ) ) ) {
	$legacy_revision_post_id = absint( $legacy_revision_post_id );
	$legacy_data_version     = function () {
		return 'legacy';
	};

	try {
		add_post_meta( $legacy_revision_post_id, '_focal_point_x', 15 );
		add_post_meta( $legacy_revision_post_id, '_focal_point_y', 85 );
		add_filter( 'pre_option_' . CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION, $legacy_data_version );
		wp_update_post( array( 'ID' => $legacy_revision_post_id, 'post_title' => 'Corsivo Focal Point legacy revision saved' ) );
		$legacy_revisions  = wp_get_post_revisions( $legacy_revision_post_id );
		$legacy_revision   = $legacy_revisions ? reset( $legacy_revisions ) : null;
		$legacy_revision_id = $legacy_revision instanceof WP_Post ? $legacy_revision->ID : 0;

		$assert( corsivo_focal_point_get_stored_state( $legacy_revision_post_id )['has_position'], 'Legacy focal points must remain readable while migration is incomplete.' );
		$assert( $legacy_revision_id && ! corsivo_focal_point_revision_is_compatible( $legacy_revision_id ), 'Legacy-only revisions must not be marked as empty compatible snapshots.' );
		remove_filter( 'pre_option_' . CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION, $legacy_data_version );
		$assert( ! corsivo_focal_point_get_stored_state( $legacy_revision_post_id )['has_position'], 'Legacy focal points must not reactivate after migration is complete.' );
	} finally {
		remove_filter( 'pre_option_' . CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION, $legacy_data_version );
		wp_delete_post( $legacy_revision_post_id, true );
	}
} elseif ( ! is_wp_error( $legacy_revision_post_id ) ) {
	wp_delete_post( $legacy_revision_post_id, true );
}

$autosave_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point autosave-only test',
		'post_status' => 'draft',
		'post_type'   => $autosave_post_type,
		'post_author' => $administrator_id,
	),
	true
);

try {
	$assert( ! is_wp_error( $autosave_post_id ), 'An autosave-only test post must be created.' );

	if ( ! is_wp_error( $autosave_post_id ) && $administrator_id ) {
		$autosave_post_id = absint( $autosave_post_id );
		$previous_user_id = get_current_user_id();
		wp_set_current_user( $administrator_id );

		try {
			$post_type_object = get_post_type_object( $autosave_post_type );
			$rest_namespace   = $post_type_object->rest_namespace ?: 'wp/v2';
			$rest_base        = $post_type_object->rest_base ?: $autosave_post_type;
			$autosave_request = new WP_REST_Request( 'POST', '/' . $rest_namespace . '/' . $rest_base . '/' . $autosave_post_id . '/autosaves' );
			$autosave_request->set_param(
				'meta',
				array(
					CORSIVO_FOCAL_POINT_META_X          => 18,
					CORSIVO_FOCAL_POINT_META_Y          => 82,
					CORSIVO_FOCAL_POINT_META_ATTACHMENT => 0,
				)
			);
			$autosave_response = rest_do_request( $autosave_request );
			$autosave_state    = corsivo_focal_point_get_stored_state( $autosave_post_id );

			$assert( 200 === $autosave_response->get_status(), 'Autosave-only post types must accept focal point autosaves.' );
			$assert( 18 === $autosave_state['x'] && 82 === $autosave_state['y'], 'Autosave-only post types must persist focal point metadata.' );

			wp_update_post( array( 'ID' => $autosave_post_id, 'post_status' => 'publish' ) );
			$revision_request = new WP_REST_Request( 'POST', '/' . $rest_namespace . '/' . $rest_base . '/' . $autosave_post_id . '/autosaves' );
			$revision_request->set_param( 'content', 'Unsaved autosave-only content' );
			$revision_request->set_param(
				'meta',
				array(
					CORSIVO_FOCAL_POINT_META_X          => 27,
					CORSIVO_FOCAL_POINT_META_Y          => 73,
					CORSIVO_FOCAL_POINT_META_ATTACHMENT => 0,
				)
			);
			$revision_response = rest_do_request( $revision_request );
			$autosave_revision = wp_get_post_autosave( $autosave_post_id, $administrator_id );

			$assert( 200 === $revision_response->get_status(), 'Published autosave-only post types must create focal point autosaves.' );
			$assert( $autosave_revision instanceof WP_Post, 'Published autosave-only post types must create an autosave revision.' );
			$assert( 18 === corsivo_focal_point_get_stored_state( $autosave_post_id )['x'], 'Revision autosaves must not overwrite the published focal point.' );

			if ( $autosave_revision instanceof WP_Post ) {
				$revision_state = corsivo_focal_point_get_stored_state( $autosave_revision->ID );
				$response_meta  = $revision_response->get_data()['meta'] ?? array();

				$assert( 27 === $revision_state['x'] && 73 === $revision_state['y'], 'Autosave revisions must persist focal point metadata without revision support.' );
				$assert( corsivo_focal_point_revision_is_compatible( $autosave_revision->ID ), 'Autosave-only revisions must carry a valid compatibility marker.' );
				$assert( 27 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_X ] ?? null ) && 73 === ( $response_meta[ CORSIVO_FOCAL_POINT_META_Y ] ?? null ), 'Autosave revision responses must expose persisted focal point metadata.' );
				wp_restore_post_revision( $autosave_revision->ID );
				$restored_state = corsivo_focal_point_get_stored_state( $autosave_post_id );
				$assert( 27 === $restored_state['x'] && 73 === $restored_state['y'], 'Autosave-only revisions must restore focal point metadata manually.' );
			}
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}
} finally {
	if ( ! is_wp_error( $autosave_post_id ) ) {
		wp_delete_post( $autosave_post_id, true );
	}

	foreach ( corsivo_focal_point_position_meta_keys() as $meta_key ) {
		unregister_post_meta( $autosave_post_type, $meta_key );
	}

	remove_filter( 'corsivo_focal_point_post_types', $autosave_post_types_filter );
	unregister_post_type( $autosave_post_type );
}

$migration_test_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point migration retry',
		'post_status' => 'draft',
		'post_type'   => 'post',
	),
	true
);

if ( ! is_wp_error( $migration_test_post_id ) ) {
	$migration_test_post_id = absint( $migration_test_post_id );
	$blocked_meta_write     = function ( $check, $post_id, $meta_key ) use ( $migration_test_post_id ) {
		return $migration_test_post_id === absint( $post_id ) && CORSIVO_FOCAL_POINT_META_X === $meta_key ? false : $check;
	};

	try {
		add_filter( 'update_post_metadata', $blocked_meta_write, 10, 3 );
		$assert( false === corsivo_focal_point_update_position( $migration_test_post_id, 25, 75, 0 ), 'A blocked initial position update must report failure.' );
		$assert(
			! metadata_exists( 'post', $migration_test_post_id, CORSIVO_FOCAL_POINT_META_X )
			&& ! metadata_exists( 'post', $migration_test_post_id, CORSIVO_FOCAL_POINT_META_Y )
			&& ! metadata_exists( 'post', $migration_test_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT ),
			'A blocked initial position update must preserve physical metadata absence.'
		);
		remove_filter( 'update_post_metadata', $blocked_meta_write, 10 );
		corsivo_focal_point_write_single_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_X, 15 );
		corsivo_focal_point_write_single_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_Y, 35 );
		corsivo_focal_point_write_single_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, 0 );
		add_filter( 'update_post_metadata', $blocked_meta_write, 10, 3 );
		$assert( false === corsivo_focal_point_update_position( $migration_test_post_id, 25, 75, 0 ), 'A blocked position update must report failure.' );
		$rollback_state = corsivo_focal_point_get_stored_state( $migration_test_post_id );
		$assert( 15 === $rollback_state['x'] && 35 === $rollback_state['y'], 'A blocked position update must preserve the original metadata.' );
		remove_filter( 'update_post_metadata', $blocked_meta_write, 10 );
		delete_post_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_X );
		delete_post_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_Y );
		delete_post_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT );
		add_post_meta( $migration_test_post_id, '_focal_point_x', 25 );
		add_post_meta( $migration_test_post_id, '_focal_point_y', 75 );
		add_filter( 'update_post_metadata', $blocked_meta_write, 10, 3 );
		$assert( false === corsivo_focal_point_migrate_post( $migration_test_post_id ), 'Migration must report a blocked metadata write.' );
		remove_filter( 'update_post_metadata', $blocked_meta_write, 10 );
		$assert( true === corsivo_focal_point_migrate_post( $migration_test_post_id ), 'Migration must succeed when the metadata write can be retried.' );
		$assert( 25 === (int) get_post_meta( $migration_test_post_id, CORSIVO_FOCAL_POINT_META_X, true ), 'A retried migration must preserve the original coordinate.' );
	} finally {
		remove_filter( 'update_post_metadata', $blocked_meta_write, 10 );
		wp_delete_post( $migration_test_post_id, true );
	}
}

$attachment_only_post_id = wp_insert_post(
	array(
		'post_title'  => 'Corsivo Focal Point attachment-only migration',
		'post_status' => 'draft',
		'post_type'   => 'post',
	),
	true
);

if ( ! is_wp_error( $attachment_only_post_id ) && false === get_option( CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION, false ) ) {
	$attachment_only_post_id = absint( $attachment_only_post_id );
	$previous_data_version   = get_option( CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION, false );
	$previous_cursor         = get_option( CORSIVO_FOCAL_POINT_MIGRATION_CURSOR_OPTION, false );
	$previous_migration      = wp_get_scheduled_event( CORSIVO_FOCAL_POINT_MIGRATION_HOOK );

	try {
		add_post_meta( $attachment_only_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, 777 );
		delete_option( CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION );
		update_option( CORSIVO_FOCAL_POINT_MIGRATION_CURSOR_OPTION, max( 0, $attachment_only_post_id - 1 ), false );
		wp_clear_scheduled_hook( CORSIVO_FOCAL_POINT_MIGRATION_HOOK );

		$assert( corsivo_focal_point_process_migration_batch(), 'The attachment-only migration batch must complete.' );
		$assert(
			50 === (int) get_post_meta( $attachment_only_post_id, CORSIVO_FOCAL_POINT_META_X, true )
			&& 50 === (int) get_post_meta( $attachment_only_post_id, CORSIVO_FOCAL_POINT_META_Y, true )
			&& 777 === (int) get_post_meta( $attachment_only_post_id, CORSIVO_FOCAL_POINT_META_ATTACHMENT, true ),
			'Batch migration must normalize attachment-only records.'
		);
	} finally {
		wp_delete_post( $attachment_only_post_id, true );
		wp_clear_scheduled_hook( CORSIVO_FOCAL_POINT_MIGRATION_HOOK );

		if ( false === $previous_data_version ) {
			delete_option( CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION );
		} else {
			update_option( CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION, $previous_data_version, false );
		}

		if ( false === $previous_cursor ) {
			delete_option( CORSIVO_FOCAL_POINT_MIGRATION_CURSOR_OPTION );
		} else {
			update_option( CORSIVO_FOCAL_POINT_MIGRATION_CURSOR_OPTION, $previous_cursor, false );
		}

		if ( $previous_migration ) {
			if ( $previous_migration->schedule ) {
				wp_schedule_event( $previous_migration->timestamp, $previous_migration->schedule, CORSIVO_FOCAL_POINT_MIGRATION_HOOK, $previous_migration->args );
			} else {
				wp_schedule_single_event( $previous_migration->timestamp, CORSIVO_FOCAL_POINT_MIGRATION_HOOK, $previous_migration->args );
			}
		}
	}
} elseif ( ! is_wp_error( $attachment_only_post_id ) ) {
	wp_delete_post( $attachment_only_post_id, true );
}

if ( false === get_option( CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION, false ) ) {
	$lock_token = corsivo_focal_point_acquire_migration_lock();

	if ( $lock_token ) {
		$replacement_lock = array(
			'token' => wp_generate_uuid4(),
			'time'  => time(),
		);
		update_option( CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION, $replacement_lock, false );
		corsivo_focal_point_release_migration_lock( $lock_token );
		$assert( $replacement_lock === get_option( CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION ), 'A worker must not release a lock owned by another process.' );
		delete_option( CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION );
	}
}

if ( $failures ) {
	throw new RuntimeException( implode( PHP_EOL, $failures ) );
}

echo "Corsivo Focal Point smoke tests passed.\n";
