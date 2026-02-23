<?php

	if ( ! defined( 'ABSPATH' ) ) {
		exit; // Exit if accessed directly.
	}

	// Load Composer dependencies if present.
	if ( file_exists( get_theme_file_path( '/vendor/autoload.php' ) ) ) {
		require_once get_theme_file_path( '/vendor/autoload.php' );
	}

	// Include core files.
	require_once get_theme_file_path( '/admin/dashboard-page.php' );
	require_once get_theme_file_path( '/inc/ajax-handler.php' );
	require_once get_theme_file_path( '/admin/database.php' );
	require_once get_theme_file_path( '/admin/batch-processing.php' );
	require_once get_theme_file_path( '/inc/frontend.php' );
	require_once get_theme_file_path( '/inc/youtube-auth.php' );

	// Include scanner or show an admin notice if it's missing.
	$scanner_file = get_theme_file_path( '/inc/scanner.php' );
	if ( file_exists( $scanner_file ) ) {
		require_once $scanner_file;
	} else {
		add_action( 'admin_notices', 'adamson_archive_scanner_missing_notice' );
	}

	/**
	 * Display an admin notice if the scanner file is missing.
	 */
	function adamson_archive_scanner_missing_notice() {
		?>
		<div class="error">
			<p>
				<strong><?php esc_html_e( 'The Adamson Archive Theme Error:', 'the-adamson-archive' ); ?></strong>
				<?php esc_html_e( 'The core scanner file is missing. Please make sure ', 'the-adamson-archive' ); ?>
				<code>inc/scanner.php</code>
				<?php esc_html_e( ' is uploaded to your theme directory.', 'the-adamson-archive' ); ?>
			</p>
		</div>
		<?php
	}


	// Add the main admin page for the theme.
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

	// Enqueue styles and scripts for the admin dashboard.
	function adamson_archive_enqueue_admin_assets( $hook ) {
		// Only load on our admin page.
		if ( 'toplevel_page_adamson-archive' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'adamson-archive-dashboard-style',
			get_theme_file_uri( '/admin/css/dashboard.css' ),
			array(),
			'1.0'
		);

		wp_enqueue_style(
			'adamson-archive-lightbox-style',
			get_theme_file_uri( '/admin/css/lightbox.css' ),
			array(),
			'1.0'
		);

		// Enqueue Toastr CSS from CDN
		wp_enqueue_style(
			'toastr-style',
			'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css',
			array(),
			'2.1.4'
		);

		// Enqueue Style - A dedicated file is better than the main style.css for the admin.
		wp_enqueue_style(
			'adamson-archive-viewer-style',
			get_theme_file_uri( '/admin/css/archive-viewer.css' ),
			array(),
			'1.0'
		);

		// Enqueue Toastr JS from CDN
		wp_enqueue_script(
			'toastr-script',
			'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.js',
			array( 'jquery' ),
			'2.1.4',
			true // Load in footer
		);

		// Enqueue Isotope JS from CDN
		wp_enqueue_script(
			'isotope-script',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery.isotope/3.0.6/isotope.pkgd.min.js',
			array( 'jquery' ),
			'3.0.6',
			true
		);

		// Enqueue imagesLoaded JS from CDN
		wp_enqueue_script(
			'imagesloaded-script',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery.imagesloaded/5.0.0/imagesloaded.pkgd.min.js',
			array( 'jquery', 'isotope-script' ),
			'5.0.0',
			true
		);

		// Enqueue Script
		wp_enqueue_script(
			'adamson-archive-admin-script',
			get_theme_file_uri( '/admin/js/dashboard.js' ),
			array( 'jquery', 'toastr-script', 'imagesloaded-script' ),
			'1.0',
			true // Load in footer
		);

		wp_enqueue_script(
			'adamson-archive-viewer-script',
			get_theme_file_uri( '/admin/js/archive-viewer.js' ),
			array( 'jquery' ),
			'1.0',
			true // Load in footer
		);

		// Localize script to pass PHP data like AJAX URL and nonces.
		wp_localize_script(
			'adamson-archive-admin-script',
			'adamsonArchive',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'adamson_archive_scan_nonce' ),
			)
		);
	}
	add_action( 'admin_enqueue_scripts', 'adamson_archive_enqueue_admin_assets' );

	add_action( 'wp_ajax_adamson_archive_get_removed_media', 'adamson_archive_ajax_get_removed_media' );
	add_action( 'wp_ajax_adamson_archive_restore_media_item', 'adamson_archive_ajax_restore_media_item' );
	add_action( 'wp_ajax_adamson_archive_restore_album', 'adamson_archive_ajax_restore_album' );
	add_action( 'wp_ajax_adamson_archive_get_removed_media_details', 'adamson_archive_ajax_get_removed_media_details' );
	add_action( 'wp_ajax_adamson_archive_permanent_delete_media', 'adamson_archive_ajax_permanent_delete_media' );
	add_action( 'wp_ajax_adamson_archive_permanent_delete_album', 'adamson_archive_ajax_permanent_delete_album' );