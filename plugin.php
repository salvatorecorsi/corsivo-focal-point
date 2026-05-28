<?php
/**
 * Plugin Name: Focal Point
 * Description: Seleziona un punto di fuoco per le immagini in evidenza — inietta object-position nel rendering della featured image, con utility per il background-position nei template.
 * Version: 1.0.0
 * Author: Salvatore Corsi
 * Text Domain: focal-point
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FP_VERSION', '1.0.0' );
define( 'FP_URL', plugin_dir_url( __FILE__ ) );
define( 'FP_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Filtro per i post type abilitati.
 * Usa: add_filter('focal_point_post_types', fn($types) => [...$types, 'product']);
 */
function fp_get_post_types() {
	return apply_filters( 'focal_point_post_types', array( 'post', 'project', 'experiment' ) );
}

// ── Meta registration (priority 20 so external filters on focal_point_post_types are registered first) ──
add_action( 'init', function () {
	foreach ( fp_get_post_types() as $pt ) {
		register_post_meta( $pt, '_focal_point_x', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'number',
			'default'       => 50,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		) );
		register_post_meta( $pt, '_focal_point_y', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'number',
			'default'       => 50,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		) );
	}
}, 20 );

// ── Gutenberg sidebar panel ──
add_action( 'enqueue_block_editor_assets', function () {
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' || ! in_array( $screen->post_type, fp_get_post_types(), true ) ) return;

	wp_enqueue_style( 'fp-editor', FP_URL . 'assets/editor.css', array(), FP_VERSION );
	wp_enqueue_script( 'fp-editor-sidebar', FP_URL . 'assets/editor.js', array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data' ), FP_VERSION, true );
} );

// ── Frontend: inietta object-position su post_thumbnail_html ──
add_filter( 'post_thumbnail_html', function ( $html, $post_id ) {
	if ( ! in_array( get_post_type( $post_id ), fp_get_post_types(), true ) ) return $html;

	$x = (int) get_post_meta( $post_id, '_focal_point_x', true );
	$y = (int) get_post_meta( $post_id, '_focal_point_y', true );

	// Se non impostato o default center, non fare nulla
	if ( ( ! $x && ! $y ) || ( $x === 50 && $y === 50 ) ) return $html;

	$pos = $x . '% ' . $y . '%';

	// Inietta object-position nell'attributo style dell'<img>
	if ( preg_match( '/style="/', $html ) ) {
		$html = preg_replace( '/style="/', 'style="object-position:' . $pos . ';', $html );
	} else {
		$html = preg_replace( '/<img /', '<img style="object-position:' . $pos . ';" ', $html );
	}

	return $html;
}, 10, 2 );

// ── Utility: ottenere il focal point come stringa CSS ──
function fp_get_position( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	$x = (int) get_post_meta( $post_id, '_focal_point_x', true ) ?: 50;
	$y = (int) get_post_meta( $post_id, '_focal_point_y', true ) ?: 50;
	return $x . '% ' . $y . '%';
}

function fp_get_position_array( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	return array(
		'x' => (int) get_post_meta( $post_id, '_focal_point_x', true ) ?: 50,
		'y' => (int) get_post_meta( $post_id, '_focal_point_y', true ) ?: 50,
	);
}
