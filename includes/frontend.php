<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_apply_position_to_html( $html, $position ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	$position = is_array( $position ) ? $position : array();

	$x = corsivo_focal_point_sanitize_coordinate( $position['x'] ?? 50 );
	$y = corsivo_focal_point_sanitize_coordinate( $position['y'] ?? 50 );

	if ( 50 === $x && 50 === $y ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		return $html;
	}

	$style = $processor->get_attribute( 'style' );
	$style = is_string( $style ) ? trim( $style ) : '';
	$style = preg_replace( '/(^|;)\s*object-position\s*:[^;]*(?=;|$)/i', '$1', $style );
	$style = trim( $style, " \t\n\r\0\x0B;" );

	if ( '' !== $style ) {
		$style .= ';';
	}

	$style .= sprintf( 'object-position:%d%% %d%%;', $x, $y );
	$processor->set_attribute( 'style', $style );

	return $processor->get_updated_html();
}

function corsivo_focal_point_apply_to_post_image( $html, $post_id, $attachment_id = 0 ) {
	$post_id = absint( $post_id );

	if ( ! $post_id || ! in_array( get_post_type( $post_id ), corsivo_focal_point_get_post_types(), true ) ) {
		return $html;
	}

	$state = corsivo_focal_point_get_state( $post_id );

	if ( ! $state['has_position'] || ! $state['matches_featured_image'] ) {
		return $html;
	}

	if ( $attachment_id && $state['featured_attachment_id'] !== absint( $attachment_id ) ) {
		return $html;
	}

	return corsivo_focal_point_apply_position_to_html( $html, $state );
}

function corsivo_focal_point_filter_post_thumbnail_html( $html, $post_id, $post_thumbnail_id ) {
	return corsivo_focal_point_apply_to_post_image( $html, $post_id, $post_thumbnail_id );
}
add_filter( 'post_thumbnail_html', 'corsivo_focal_point_filter_post_thumbnail_html', 10, 3 );
