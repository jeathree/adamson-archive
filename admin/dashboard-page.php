<?php
/**
 * Adamson Archive: Admin Dashboard Page
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renders the main admin page for The Adamson Archive.
 */
function adamson_archive_render_dashboard_page() {
	?>
	<div class="wrap adamson-archive-dashboard">
		<h1>The Adamson Archive</h1>
		<p>Welcome to your private media library.</p>

		<div class="card">
			<h2 class="title">Library Scanner</h2>
			<p>
				Scan the <code>/wp-content/uploads/albums/</code> directory to discover new albums and media.
				This process runs in the background and may take a while.
			</p>
			<button class="button button-primary" id="adamson-archive-scan-button">
				<?php esc_html_e( 'Scan for New Media', 'the-adamson-archive' ); ?>
			</button>
		</div>

		<div class="card">
			<h2 class="title">Activity Log</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col">Timestamp</th>
						<th scope="col">Event</th>
						<th scope="col">Details</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s' ) ); ?></td>
						<td>System Initialized</td>
						<td>The Adamson Archive dashboard is ready.</td>
					</tr>
					<!-- Log entries will be added here via AJAX -->
				</tbody>
			</table>
		</div>
	</div>
	<?php
}
