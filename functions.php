<?php
/**
 * The Adamson Archive Theme Functions
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Include core files.
require_once __DIR__ . '/admin/dashboard-page.php';


/**
 * Add the main admin page for the theme.
 */
function adamson_archive_add_admin_menu() {
	add_menu_page(
		__( 'The Adamson Archive', 'the-adamson-archive' ),
		__( 'Adamson Archive', 'the-adamson-archive' ),
		'manage_options',
		'adamson-archive',
		'adamson_archive_render_dashboard_page',
		'dashicons-format-gallery',
		20
	);
}
add_action( 'admin_menu', 'adamson_archive_add_admin_menu' );

/**
 * Enqueue styles for the admin dashboard.
 *
 * @param string $hook The current admin page hook.
 */
function adamson_archive_enqueue_admin_styles( $hook ) {
	// Only load on our admin page.
	if ( 'toplevel_page_adamson-archive' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'adamson-archive-admin-style',
		get_stylesheet_uri(),
		array(),
		'1.0'
	);
}
add_action( 'admin_enqueue_scripts', 'adamson_archive_enqueue_admin_styles' );