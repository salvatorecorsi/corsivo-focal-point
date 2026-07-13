<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_default_settings() {
	return array(
		'post_types'             => array( 'post' ),
		'woocommerce_enabled'    => false,
		'wpml_copy_once_enabled' => false,
		'delete_on_uninstall'    => false,
	);
}

function corsivo_focal_point_sanitize_post_types( $post_types ) {
	$post_types = array_filter( (array) $post_types, 'is_scalar' );
	$post_types = array_map(
		function ( $post_type ) {
			return sanitize_key( (string) $post_type );
		},
		$post_types
	);

	return array_values( array_unique( array_filter( $post_types ) ) );
}

function corsivo_focal_point_existing_data_post_types() {
	global $wpdb;

	static $post_types_by_site = array();

	$site_id = get_current_blog_id();

	if ( isset( $post_types_by_site[ $site_id ] ) ) {
		return $post_types_by_site[ $site_id ];
	}

	$meta_keys    = array( CORSIVO_FOCAL_POINT_META_X, CORSIVO_FOCAL_POINT_META_Y, CORSIVO_FOCAL_POINT_META_ATTACHMENT );
	$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
	$query        = $wpdb->prepare(
		"SELECT DISTINCT posts.post_type
		FROM {$wpdb->posts} AS posts
		INNER JOIN {$wpdb->postmeta} AS postmeta ON postmeta.post_id = posts.ID
		WHERE postmeta.meta_key IN ({$placeholders})
			AND posts.post_type NOT IN ('revision', 'attachment')",
		...$meta_keys
	);
	$post_types   = $wpdb->get_col( $query );

	if ( $wpdb->last_error ) {
		return null;
	}

	$post_types_by_site[ $site_id ] = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

	return $post_types_by_site[ $site_id ];
}

function corsivo_focal_point_initial_settings() {
	$settings            = corsivo_focal_point_default_settings();
	$existing_post_types = corsivo_focal_point_existing_data_post_types();

	if ( null === $existing_post_types ) {
		return null;
	}

	$settings['post_types'] = array_values(
		array_unique( array_merge( $settings['post_types'], array_diff( $existing_post_types, array( 'product' ) ) ) )
	);
	$settings['woocommerce_enabled'] = in_array( 'product', $existing_post_types, true );

	return $settings;
}

function corsivo_focal_point_initialize_site() {
	if ( false === get_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, false ) ) {
		$settings = corsivo_focal_point_initial_settings();

		if ( null !== $settings ) {
			add_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, $settings, '', true );
		}
	}
}
add_action( 'plugins_loaded', 'corsivo_focal_point_initialize_site', 5 );

function corsivo_focal_point_get_settings() {
	$defaults = corsivo_focal_point_default_settings();
	$saved    = get_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, false );

	if ( false === $saved ) {
		return corsivo_focal_point_initial_settings() ?? $defaults;
	}

	if ( ! is_array( $saved ) ) {
		return $defaults;
	}

	$settings                           = array_replace( $defaults, $saved );
	$settings['post_types']             = array_values( array_diff( corsivo_focal_point_sanitize_post_types( $settings['post_types'] ), array( 'product' ) ) );
	$settings['woocommerce_enabled']    = ! empty( $settings['woocommerce_enabled'] );
	$settings['wpml_copy_once_enabled'] = ! empty( $settings['wpml_copy_once_enabled'] );
	$settings['delete_on_uninstall']    = ! empty( $settings['delete_on_uninstall'] );

	return $settings;
}

function corsivo_focal_point_available_post_types() {
	$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );

	$post_types = array_filter(
		$post_types,
		function ( $post_type ) {
			return 'product' !== $post_type->name && post_type_supports( $post_type->name, 'thumbnail' );
		}
	);

	uasort(
		$post_types,
		function ( $first, $second ) {
			return strcasecmp( $first->labels->name, $second->labels->name );
		}
	);

	return $post_types;
}

function corsivo_focal_point_sanitize_settings( $input ) {
	$input      = is_array( $input ) ? $input : array();
	$available  = array_keys( corsivo_focal_point_available_post_types() );
	$post_types = corsivo_focal_point_sanitize_post_types( $input['post_types'] ?? array() );

	return array(
		'post_types'             => array_values( array_intersect( $post_types, $available ) ),
		'woocommerce_enabled'    => ! empty( $input['woocommerce_enabled'] ),
		'wpml_copy_once_enabled' => ! empty( $input['wpml_copy_once_enabled'] ),
		'delete_on_uninstall'    => ! empty( $input['delete_on_uninstall'] ),
	);
}

function corsivo_focal_point_register_settings() {
	register_setting(
		'corsivo_focal_point_settings',
		CORSIVO_FOCAL_POINT_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'corsivo_focal_point_sanitize_settings',
			'default'           => corsivo_focal_point_default_settings(),
		)
	);
}
add_action( 'admin_init', 'corsivo_focal_point_register_settings' );

function corsivo_focal_point_add_settings_page() {
	add_options_page(
		__( 'Corsivo Focal Point', 'corsivo-focal-point' ),
		__( 'Focal Point', 'corsivo-focal-point' ),
		'manage_options',
		'corsivo-focal-point',
		'corsivo_focal_point_render_settings_page'
	);
}
add_action( 'admin_menu', 'corsivo_focal_point_add_settings_page' );

function corsivo_focal_point_is_woocommerce_active() {
	return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
}

function corsivo_focal_point_is_wpml_active() {
	return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) || has_filter( 'wpml_element_trid' );
}

function corsivo_focal_point_is_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
}

function corsivo_focal_point_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings   = corsivo_focal_point_get_settings();
	$post_types = corsivo_focal_point_available_post_types();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Corsivo Focal Point', 'corsivo-focal-point' ); ?></h1>
		<p><?php echo esc_html__( 'Configura dove usare il focal point. Il plugin resta autonomo: le integrazioni si attivano solo quando richieste.', 'corsivo-focal-point' ); ?></p>
		<?php settings_errors(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'corsivo_focal_point_settings' ); ?>

			<h2><?php echo esc_html__( 'Contenuti', 'corsivo-focal-point' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Post type abilitati', 'corsivo-focal-point' ); ?></th>
					<td>
						<fieldset>
						<?php foreach ( $post_types as $post_type ) : ?>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[post_types][]"
									value="<?php echo esc_attr( $post_type->name ); ?>"
									<?php checked( in_array( $post_type->name, $settings['post_types'], true ) ); ?>
								>
								<?php echo esc_html( $post_type->labels->name ); ?>
								<code><?php echo esc_html( $post_type->name ); ?></code>
							</label>
							<br>
						<?php endforeach; ?>
						<p class="description"><?php echo esc_html__( 'Sono elencati solo i post type con interfaccia amministrativa e immagine in evidenza.', 'corsivo-focal-point' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Integrazioni', 'corsivo-focal-point' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">WooCommerce</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[woocommerce_enabled]"
								value="1"
								<?php checked( $settings['woocommerce_enabled'] ); ?>
							>
							<?php echo esc_html__( 'Abilita prodotti e loop catalogo; applica la gallery principale solo ai prodotti non variabili', 'corsivo-focal-point' ); ?>
						</label>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_woocommerce_active()
									? __( 'WooCommerce rilevato.', 'corsivo-focal-point' )
									: __( 'Il modulo resta inattivo finché WooCommerce non è disponibile.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">WPML</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[wpml_copy_once_enabled]"
								value="1"
								<?php checked( $settings['wpml_copy_once_enabled'] ); ?>
							>
							<?php echo esc_html__( 'Copia una volta le coordinate nelle traduzioni che non hanno ancora un focal point', 'corsivo-focal-point' ); ?>
						</label>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_wpml_active()
									? __( 'WPML rilevato. Ogni traduzione resta poi modificabile in modo indipendente.', 'corsivo-focal-point' )
									: __( 'Il modulo resta inattivo finché WPML non è disponibile.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Yoast SEO</th>
					<td>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_yoast_active()
									? __( 'Rilevato. Non richiede un modulo: le coordinate CSS non modificano i file immagine usati nei meta social.', 'corsivo-focal-point' )
									: __( 'Nessun modulo necessario: le coordinate CSS non modificano i file immagine usati nei meta social.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Dati', 'corsivo-focal-point' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Storico', 'corsivo-focal-point' ); ?></th>
					<td><p class="description"><?php echo esc_html__( 'Le coordinate seguono le revisioni native e compaiono nel confronto quando il post type le supporta. Il ripristino non cambia la featured image: coordinate riferite a un altro media restano inattive.', 'corsivo-focal-point' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Disinstallazione', 'corsivo-focal-point' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[delete_on_uninstall]"
								value="1"
								<?php checked( $settings['delete_on_uninstall'] ); ?>
							>
							<?php echo esc_html__( 'Elimina coordinate e impostazioni quando il plugin viene disinstallato', 'corsivo-focal-point' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function corsivo_focal_point_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'options-general.php?page=corsivo-focal-point' ) ) . '">' . esc_html__( 'Impostazioni', 'corsivo-focal-point' ) . '</a>'
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( CORSIVO_FOCAL_POINT_FILE ), 'corsivo_focal_point_plugin_action_links' );

function corsivo_focal_point_activate_site() {
	corsivo_focal_point_initialize_site();
}

function corsivo_focal_point_activate( $network_wide = false ) {
	if ( ! is_multisite() || ! $network_wide ) {
		corsivo_focal_point_activate_site();
		return;
	}

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
				corsivo_focal_point_activate_site();
			} finally {
				restore_current_blog();
			}
		}

		$offset += count( $site_ids );
	} while ( $limit === count( $site_ids ) );
}
