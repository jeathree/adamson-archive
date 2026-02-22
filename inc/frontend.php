<?php
/**
 * Adamson Archive: Frontend Logic
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the [adamson_archive] shortcode.
 */
function adamson_archive_shortcode() {
	adamson_archive_enqueue_frontend_assets();
	ob_start();
	?>
	<div id="adamson-archive-container">
		<div class="adamson-archive-list">
			<!-- Albums will be loaded here via AJAX -->
		</div>
		<div class="adamson-archive-load-more-container">
			<button id="adamson-archive-load-more" class="button" style="display:none;">Load More Albums</button>
		</div>
		<div id="adamson-archive-loading" style="display:none;">Loading...</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'adamson_archive', 'adamson_archive_shortcode' );

/**
 * Enqueues frontend scripts and styles.
 */
function adamson_archive_enqueue_frontend_assets() {
	wp_enqueue_style(
		'adamson-archive-frontend-style',
		get_theme_file_uri( '/assets/css/archive-viewer.css' ),
		array(),
		'1.0'
	);

	wp_enqueue_script(
		'adamson-archive-frontend-script',
		get_theme_file_uri( '/assets/js/archive-viewer.js' ),
		array( 'jquery' ),
		'1.0',
		true
	);

	wp_localize_script(
		'adamson-archive-frontend-script',
		'adamsonArchiveFrontend',
		array( 'ajax_url' => admin_url( 'admin-ajax.php' ) )
	);
}