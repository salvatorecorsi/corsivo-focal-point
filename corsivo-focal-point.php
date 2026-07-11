<?php
/**
 * Plugin Name: Corsivo Focal Point
 * Description: Seleziona un punto focale per le immagini in evidenza e applica object-position al rendering frontend.
 * Version: 1.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Salvatore Corsi
 * Text Domain: corsivo-focal-point
 * Update URI: https://plugins.corsivo.dev/corsivo-focal-point
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CORSIVO_FOCAL_POINT_VERSION', '1.2.0' );
define( 'CORSIVO_FOCAL_POINT_DATA_VERSION', '1.2.0' );
define( 'CORSIVO_FOCAL_POINT_FILE', __FILE__ );
define( 'CORSIVO_FOCAL_POINT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CORSIVO_FOCAL_POINT_URL', plugin_dir_url( __FILE__ ) );
define( 'CORSIVO_FOCAL_POINT_META_X', '_corsivo_focal_point_x' );
define( 'CORSIVO_FOCAL_POINT_META_Y', '_corsivo_focal_point_y' );
define( 'CORSIVO_FOCAL_POINT_META_ATTACHMENT', '_corsivo_focal_point_attachment_id' );
define( 'CORSIVO_FOCAL_POINT_SETTINGS_OPTION', 'corsivo_focal_point_settings' );
define( 'CORSIVO_FOCAL_POINT_DATA_VERSION_OPTION', 'corsivo_focal_point_data_version' );
define( 'CORSIVO_FOCAL_POINT_MIGRATION_CURSOR_OPTION', 'corsivo_focal_point_migration_cursor' );
define( 'CORSIVO_FOCAL_POINT_MIGRATION_LOCK_OPTION', 'corsivo_focal_point_migration_lock' );
define( 'CORSIVO_FOCAL_POINT_UNINSTALLING_OPTION', 'corsivo_focal_point_uninstalling' );
define( 'CORSIVO_FOCAL_POINT_MIGRATION_HOOK', 'corsivo_focal_point_run_migration' );
define( 'CORSIVO_FOCAL_POINT_REVISION_MARKER', '_corsivo_focal_point_revision_version' );
define( 'CORSIVO_FOCAL_POINT_REVISION_SCHEMA_VERSION', '1' );

require_once CORSIVO_FOCAL_POINT_PATH . 'includes/settings.php';
require_once CORSIVO_FOCAL_POINT_PATH . 'includes/data.php';
require_once CORSIVO_FOCAL_POINT_PATH . 'includes/editor.php';
require_once CORSIVO_FOCAL_POINT_PATH . 'includes/frontend.php';
require_once CORSIVO_FOCAL_POINT_PATH . 'includes/integrations.php';

register_activation_hook( CORSIVO_FOCAL_POINT_FILE, 'corsivo_focal_point_activate' );
