<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_boot_woocommerce_integration() {
	$settings = corsivo_focal_point_get_settings();

	if ( ! $settings['woocommerce_enabled'] || ! corsivo_focal_point_is_woocommerce_active() ) {
		return;
	}

	add_filter( 'woocommerce_product_get_image', 'corsivo_focal_point_filter_woocommerce_product_image', 10, 2 );
	add_filter( 'woocommerce_single_product_image_thumbnail_html', 'corsivo_focal_point_filter_woocommerce_gallery_image', 10, 2 );
	add_filter( 'render_block_woocommerce/product-image', 'corsivo_focal_point_filter_woocommerce_product_image_block', 10, 3 );
}
add_action( 'plugins_loaded', 'corsivo_focal_point_boot_woocommerce_integration', 20 );

function corsivo_focal_point_filter_woocommerce_product_image( $html, $product ) {
	if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
		return $html;
	}

	$post_id       = $product->get_parent_id() ?: $product->get_id();
	$attachment_id = $product->get_image_id();

	return corsivo_focal_point_apply_to_post_image( $html, $post_id, $attachment_id );
}

function corsivo_focal_point_filter_woocommerce_gallery_image( $html, $attachment_id ) {
	global $product;

	if ( ! is_object( $product )
		|| ! is_a( $product, 'WC_Product' )
		|| $product->is_type( 'variable' )
		|| absint( $attachment_id ) !== absint( $product->get_image_id() )
	) {
		return $html;
	}

	return corsivo_focal_point_apply_to_post_image( $html, $product->get_id(), $attachment_id );
}

function corsivo_focal_point_filter_woocommerce_product_image_block( $block_content, $parsed_block, $instance ) {
	if ( ! is_object( $instance ) || ! is_a( $instance, 'WP_Block' ) ) {
		return $block_content;
	}

	$post_id       = absint( $instance->context['postId'] ?? 0 );
	$attachment_id = absint( $instance->context['imageId'] ?? 0 );
	$product       = $post_id ? wc_get_product( $post_id ) : false;

	if ( ! $attachment_id
		|| ! is_object( $product )
		|| ! is_a( $product, 'WC_Product' )
		|| $product->is_type( 'variable' )
		|| $attachment_id !== absint( $product->get_image_id() )
	) {
		return $block_content;
	}

	return corsivo_focal_point_apply_to_post_image( $block_content, $product->get_id(), $attachment_id );
}

function corsivo_focal_point_boot_wpml_integration() {
	$settings = corsivo_focal_point_get_settings();

	if ( ! $settings['wpml_copy_once_enabled'] || ! corsivo_focal_point_is_wpml_active() ) {
		return;
	}

	add_action( 'save_post', 'corsivo_focal_point_maybe_copy_wpml_position_on_save', 10 );
	add_action( 'added_post_meta', 'corsivo_focal_point_maybe_copy_wpml_position_after_thumbnail_update', 10, 3 );
	add_action( 'updated_post_meta', 'corsivo_focal_point_maybe_copy_wpml_position_after_thumbnail_update', 10, 3 );
	add_action( 'wpml_pro_translation_completed', 'corsivo_focal_point_maybe_copy_wpml_position_after_automatic_translation' );
}
add_action( 'plugins_loaded', 'corsivo_focal_point_boot_wpml_integration', 20 );

function corsivo_focal_point_get_wpml_source_state( $post_id ) {
	$post_id  = absint( $post_id );
	$settings = corsivo_focal_point_get_settings();

	if ( ! $post_id
		|| ! $settings['wpml_copy_once_enabled']
		|| ! corsivo_focal_point_is_wpml_active()
		|| wp_is_post_revision( $post_id )
		|| wp_is_post_autosave( $post_id )
	) {
		return null;
	}

	$post_type = get_post_type( $post_id );

	if ( ! in_array( $post_type, corsivo_focal_point_get_post_types(), true ) ) {
		return null;
	}

	$element_type = apply_filters( 'wpml_element_type', $post_type );
	$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );

	if ( ! $trid ) {
		return null;
	}

	$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );

	if ( ! is_array( $translations ) ) {
		return null;
	}

	$target_translation  = null;
	$source_translations = array();

	foreach ( $translations as $translation ) {
		$translation_id = absint( $translation->element_id ?? 0 );

		if ( ! empty( $translation->original ) ) {
			$source_translations[] = $translation;
		}

		if ( $translation_id === $post_id ) {
			$target_translation = $translation;
		}
	}

	if ( 1 < count( $source_translations ) ) {
		corsivo_focal_point_log_failure(
			'wpml_ambiguous_source',
			array(
				'post_id' => $post_id,
				'trid'    => $trid,
			)
		);

		return null;
	}

	$source_translation = 1 === count( $source_translations ) ? reset( $source_translations ) : null;

	if ( ! $target_translation || ! empty( $target_translation->original ) || ! $source_translation ) {
		return null;
	}

	$source_state = corsivo_focal_point_get_state( absint( $source_translation->element_id ) );

	if ( ! $source_state['has_position'] || ! $source_state['matches_featured_image'] ) {
		return null;
	}

	return $source_state;
}

function corsivo_focal_point_maybe_copy_wpml_position( $post_id, $announce = true ) {
	$post_id  = absint( $post_id );
	$settings = corsivo_focal_point_get_settings();

	if ( ! $post_id
		|| ! $settings['wpml_copy_once_enabled']
		|| ! corsivo_focal_point_is_wpml_active()
	) {
		return false;
	}

	$post_type = get_post_type( $post_id );

	if ( ! in_array( $post_type, corsivo_focal_point_get_post_types(), true )
		|| wp_is_post_revision( $post_id )
		|| wp_is_post_autosave( $post_id )
	) {
		return false;
	}

	$target_state = corsivo_focal_point_get_stored_state( $post_id );

	if ( $target_state['has_position'] ) {
		return false;
	}

	$target_attachment_id = get_post_thumbnail_id( $post_id );
	$source               = corsivo_focal_point_get_wpml_source_state( $post_id );

	if ( ! $target_attachment_id || ! $source ) {
		return false;
	}

	if ( ! corsivo_focal_point_update_position( $post_id, $source['x'], $source['y'], $target_attachment_id ) ) {
		corsivo_focal_point_log_failure(
			'wpml_copy_failed',
			array(
				'post_id'       => $post_id,
				'attachment_id' => $target_attachment_id,
			)
		);

		return false;
	}

	if ( $announce ) {
		do_action( 'corsivo_focal_point_position_updated', $post_id, corsivo_focal_point_get_stored_state( $post_id ), $target_state );
	}

	return true;
}

function corsivo_focal_point_has_classic_position_request() {
	$fields = array(
		'corsivo_focal_point_nonce',
		CORSIVO_FOCAL_POINT_META_X,
		CORSIVO_FOCAL_POINT_META_Y,
		CORSIVO_FOCAL_POINT_META_ATTACHMENT,
	);

	foreach ( $fields as $field ) {
		if ( ! isset( $_POST[ $field ] ) || ! is_scalar( $_POST[ $field ] ) ) {
			return false;
		}
	}

	return wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['corsivo_focal_point_nonce'] ) ), 'corsivo_focal_point_save' );
}

function corsivo_focal_point_maybe_copy_wpml_position_on_save( $post_id ) {
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || corsivo_focal_point_has_classic_position_request() ) {
		return;
	}

	corsivo_focal_point_maybe_copy_wpml_position( $post_id );
}

function corsivo_focal_point_maybe_copy_wpml_position_after_thumbnail_update( $meta_id, $post_id, $meta_key ) {
	if ( '_thumbnail_id' !== $meta_key
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| corsivo_focal_point_has_classic_position_request()
	) {
		return;
	}

	corsivo_focal_point_maybe_copy_wpml_position( $post_id );
}

function corsivo_focal_point_maybe_copy_wpml_position_after_automatic_translation( $post_id ) {
	if ( corsivo_focal_point_maybe_copy_wpml_position( $post_id ) && post_type_supports( get_post_type( $post_id ), 'revisions' ) ) {
		wp_save_post_revision( $post_id );
	}
}
