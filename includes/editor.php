<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_editor_config( $post_id = 0 ) {
	$config = array(
		'metaX'          => CORSIVO_FOCAL_POINT_META_X,
		'metaY'          => CORSIVO_FOCAL_POINT_META_Y,
		'metaAttachment' => CORSIVO_FOCAL_POINT_META_ATTACHMENT,
		'initialState'   => null,
		'sourcePosition' => null,
	);
	$post_id = absint( $post_id );

	if ( ! $post_id
		|| ! current_user_can( 'edit_post', $post_id )
		|| ! in_array( get_post_type( $post_id ), corsivo_focal_point_get_post_types(), true )
	) {
		return $config;
	}

	corsivo_focal_point_migrate_post( $post_id );

	$state                  = corsivo_focal_point_get_stored_state( $post_id );
	$config['initialState'] = $state;

	if ( ! $state['has_position'] ) {
		$source = corsivo_focal_point_get_wpml_source_state( $post_id );

		if ( $source ) {
			$config['sourcePosition'] = array(
				'x' => $source['x'],
				'y' => $source['y'],
			);
		}
	}

	return $config;
}

function corsivo_focal_point_enqueue_block_editor_assets() {
	$screen = get_current_screen();
	$post   = get_post();

	if ( ! $screen || ! $post || 'post' !== $screen->base || ! in_array( $screen->post_type, corsivo_focal_point_get_post_types(), true ) ) {
		return;
	}

	wp_enqueue_style(
		'corsivo-focal-point-editor',
		CORSIVO_FOCAL_POINT_URL . 'assets/editor.css',
		array( 'wp-components' ),
		CORSIVO_FOCAL_POINT_VERSION
	);
	wp_enqueue_script(
		'corsivo-focal-point-editor',
		CORSIVO_FOCAL_POINT_URL . 'assets/editor.js',
		array( 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		CORSIVO_FOCAL_POINT_VERSION,
		true
	);
	wp_localize_script( 'corsivo-focal-point-editor', 'corsivoFocalPointEditor', corsivo_focal_point_editor_config( $post->ID ) );
	wp_set_script_translations( 'corsivo-focal-point-editor', 'corsivo-focal-point' );
}
add_action( 'enqueue_block_editor_assets', 'corsivo_focal_point_enqueue_block_editor_assets' );

function corsivo_focal_point_add_classic_meta_boxes( $post_type, $post ) {
	if ( ! $post
		|| ! in_array( $post_type, corsivo_focal_point_get_post_types(), true )
		|| use_block_editor_for_post( $post )
	) {
		return;
	}

	add_meta_box(
		'corsivo-focal-point',
		__( 'Focal Point', 'corsivo-focal-point' ),
		'corsivo_focal_point_render_classic_meta_box',
		$post_type,
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'corsivo_focal_point_add_classic_meta_boxes', 10, 2 );

function corsivo_focal_point_render_classic_meta_box( $post ) {
	wp_nonce_field( 'corsivo_focal_point_save', 'corsivo_focal_point_nonce' );
	corsivo_focal_point_migrate_post( $post->ID );

	$attachment_id = get_post_thumbnail_id( $post );
	$state         = corsivo_focal_point_get_state( $post->ID );
	$source_state  = $state['has_position'] ? null : corsivo_focal_point_get_wpml_source_state( $post->ID );
	$fallback_x    = $source_state['x'] ?? 50;
	$fallback_y    = $source_state['y'] ?? 50;
	$x             = $source_state['x'] ?? ( $state['matches_featured_image'] ? $state['x'] : 50 );
	$y             = $source_state['y'] ?? ( $state['matches_featured_image'] ? $state['y'] : 50 );
	$url           = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium_large' ) : '';

	printf(
		'<div class="corsivo-focal-point-classic" data-url="%1$s" data-x="%2$d" data-y="%3$d" data-attachment-id="%4$d" data-fallback-x="%5$d" data-fallback-y="%6$d" data-persist-fallback="%7$d"></div>',
		esc_url( $url ),
		$x,
		$y,
		$attachment_id,
		$fallback_x,
		$fallback_y,
		$source_state ? 1 : 0
	);
}

function corsivo_focal_point_enqueue_classic_editor_assets( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	$post   = get_post();

	if ( ! $screen
		|| ! $post
		|| ! in_array( $screen->post_type, corsivo_focal_point_get_post_types(), true )
		|| use_block_editor_for_post( $post )
	) {
		return;
	}

	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style(
		'corsivo-focal-point-editor',
		CORSIVO_FOCAL_POINT_URL . 'assets/editor.css',
		array( 'wp-components' ),
		CORSIVO_FOCAL_POINT_VERSION
	);
	wp_enqueue_script(
		'corsivo-focal-point-classic-editor',
		CORSIVO_FOCAL_POINT_URL . 'assets/classic-editor.js',
		array( 'wp-components', 'wp-element', 'wp-i18n' ),
		CORSIVO_FOCAL_POINT_VERSION,
		true
	);
	wp_localize_script( 'corsivo-focal-point-classic-editor', 'corsivoFocalPointClassicEditor', corsivo_focal_point_editor_config( $post->ID ) );
	wp_set_script_translations( 'corsivo-focal-point-classic-editor', 'corsivo-focal-point' );
}
add_action( 'admin_enqueue_scripts', 'corsivo_focal_point_enqueue_classic_editor_assets' );

function corsivo_focal_point_save_classic_meta_box( $post_id ) {
	if ( ! isset( $_POST['corsivo_focal_point_nonce'] ) || ! is_scalar( $_POST['corsivo_focal_point_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['corsivo_focal_point_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'corsivo_focal_point_save' )
		|| wp_is_post_autosave( $post_id )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_post', $post_id )
		|| ! in_array( get_post_type( $post_id ), corsivo_focal_point_get_post_types(), true )
	) {
		return;
	}

	if ( ! isset( $_POST[ CORSIVO_FOCAL_POINT_META_X ], $_POST[ CORSIVO_FOCAL_POINT_META_Y ], $_POST[ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] )
		|| ! is_scalar( $_POST[ CORSIVO_FOCAL_POINT_META_X ] )
		|| ! is_scalar( $_POST[ CORSIVO_FOCAL_POINT_META_Y ] )
		|| ! is_scalar( $_POST[ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] )
	) {
		return;
	}

	$attachment_id = absint( wp_unslash( $_POST[ CORSIVO_FOCAL_POINT_META_ATTACHMENT ] ) );

	if ( $attachment_id !== get_post_thumbnail_id( $post_id ) ) {
		set_transient( 'corsivo_focal_point_save_notice_' . get_current_user_id(), 'attachment_changed', MINUTE_IN_SECONDS );
		return;
	}

	$previous_state = corsivo_focal_point_get_stored_state( $post_id );
	$x              = corsivo_focal_point_sanitize_coordinate( wp_unslash( $_POST[ CORSIVO_FOCAL_POINT_META_X ] ) );
	$y              = corsivo_focal_point_sanitize_coordinate( wp_unslash( $_POST[ CORSIVO_FOCAL_POINT_META_Y ] ) );
	$has_changed    = ! $previous_state['has_position']
		|| ! $previous_state['has_attachment_link']
		|| $previous_state['x'] !== $x
		|| $previous_state['y'] !== $y
		|| $previous_state['attachment_id'] !== $attachment_id;

	if ( ! $has_changed ) {
		return;
	}

	if ( ! corsivo_focal_point_update_position( $post_id, $x, $y, $attachment_id ) ) {
		set_transient( 'corsivo_focal_point_save_notice_' . get_current_user_id(), 'write_failed', MINUTE_IN_SECONDS );
		return;
	}

	do_action( 'corsivo_focal_point_position_updated', $post_id, corsivo_focal_point_get_stored_state( $post_id ), $previous_state );
}
add_action( 'save_post', 'corsivo_focal_point_save_classic_meta_box', 20 );

function corsivo_focal_point_render_save_notice() {
	$transient_key = 'corsivo_focal_point_save_notice_' . get_current_user_id();
	$reason        = get_transient( $transient_key );

	if ( ! $reason ) {
		return;
	}

	delete_transient( $transient_key );
	$message = 'write_failed' === $reason
		? __( 'Il focal point non è stato salvato perché la scrittura dei metadati non è riuscita. Verifica e salva di nuovo.', 'corsivo-focal-point' )
		: __( 'Il focal point non è stato salvato perché l’immagine in evidenza è cambiata durante la modifica. Verifica la posizione e salva di nuovo.', 'corsivo-focal-point' );

	echo '<div class="notice notice-warning is-dismissible"><p>'
		. esc_html( $message )
		. '</p></div>';
}
add_action( 'admin_notices', 'corsivo_focal_point_render_save_notice' );

function corsivo_focal_point_rest_previous_state( $request, $state = false ) {
	static $states = array();

	$request_id = spl_object_id( $request );

	if ( false !== $state ) {
		$states[ $request_id ] = $state;
		return null;
	}

	if ( ! array_key_exists( $request_id, $states ) ) {
		return null;
	}

	$state = $states[ $request_id ];
	unset( $states[ $request_id ] );

	return $state;
}

function corsivo_focal_point_before_rest_update( $post, $request ) {
	corsivo_focal_point_rest_previous_state( $request, corsivo_focal_point_get_stored_state( $post->ID ) );
}

function corsivo_focal_point_register_rest_update_hooks() {
	foreach ( corsivo_focal_point_get_post_types() as $post_type ) {
		add_action( "rest_insert_{$post_type}", 'corsivo_focal_point_before_rest_update', 10, 2 );
		add_action( "rest_after_insert_{$post_type}", 'corsivo_focal_point_after_rest_update', 10, 2 );
	}
}
add_action( 'init', 'corsivo_focal_point_register_rest_update_hooks', 21 );

function corsivo_focal_point_after_rest_update( $post, $request ) {
	$previous_state = corsivo_focal_point_rest_previous_state( $request );
	$meta           = $request->get_param( 'meta' );

	if ( is_array( $meta )
		&& array_intersect( array_keys( $meta ), array( CORSIVO_FOCAL_POINT_META_X, CORSIVO_FOCAL_POINT_META_Y, CORSIVO_FOCAL_POINT_META_ATTACHMENT ) )
	) {
		corsivo_focal_point_migrate_post( $post->ID );
	}

	corsivo_focal_point_maybe_copy_wpml_position( $post->ID, false );

	$current_state = corsivo_focal_point_get_stored_state( $post->ID );

	if ( null !== $previous_state && $previous_state !== $current_state ) {
		do_action( 'corsivo_focal_point_position_updated', $post->ID, $current_state, $previous_state );
	}
}
