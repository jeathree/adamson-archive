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
	// Handle the one-time database setup action.
	if ( isset( $_POST['adamson_archive_setup_db'] ) && isset( $_POST['adamson_archive_db_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( $_POST['adamson_archive_db_nonce'] ), 'adamson_archive_db_setup_action' ) ) {
			adamson_archive_create_tables();
		}
	}
	?>
	<div class="wrap adamson-archive-dashboard">
		<h1>The Adamson Archive</h1>
		<p>Welcome to your private media library.</p>

		<?php settings_errors( 'adamson_archive_messages' ); ?>

		<h2 class="nav-tab-wrapper">
			<a href="#media-explorer" class="nav-tab nav-tab-active">Media Explorer</a>
			<a href="#settings" class="nav-tab">Settings</a>
			<a href="#activity-log" class="nav-tab">Activity Log</a>
			<a href="#testing" class="nav-tab">Testing</a>
		</h2>

		<div id="media-explorer" class="tab-content active">
			<div class="card">
				<div class="archive-controls">
					<div class="control-group">
						<button class="button button-primary" id="adamson-archive-scan-button">
							<?php esc_html_e( 'Scan for New Media', 'the-adamson-archive' ); ?>
						</button>
						<p class="description">Scan the <code>/wp-content/uploads/albums/</code> directory.</p>
					</div>
					<div class="control-group">
						<?php
						global $wpdb;
						$queue_count = $wpdb->get_var( "SELECT COUNT(id) FROM adamson_archive_queue WHERE status = 'pending'" );
						?>
						<button class="button button-secondary" id="adamson-archive-process-queue-button" <?php disabled( $queue_count, 0 ); ?>>
							<?php esc_html_e( 'Process Upload Queue', 'the-adamson-archive' ); ?>
						</button>
						<p class="description">
							<a href="#" id="view-pending-videos-link">Pending Videos: <strong id="adamson-queue-count"><?php echo intval( $queue_count ); ?></strong></a>
						</p>
					</div>
				</div>

				<div id="pending-videos-container" class="card" style="display: none;">
					<h2 class="title">Pending Videos for Upload</h2>
					<div id="pending-videos-table-wrapper">
						<!-- Pending videos table will be injected here -->
					</div>
					<button class="button button-secondary" id="close-pending-videos">Close</button>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-cb check-column"></th>
							<th scope="col">Album</th>
							<th scope="col">Date</th>
							<th scope="col">Media Count</th>
							<th scope="col">YouTube Playlist</th>
						</tr>
					</thead>
					<tbody id="adamson-archive-album-list">
						<!-- Album rows will be added here via AJAX -->
					</tbody>
				</table>
				<button class="button button-secondary" id="adamson-archive-load-more-albums">Load More</button>
			</div>
		</div>

		<div id="settings" class="tab-content">
			<div class="card">
				<h2 class="title">Initial Setup</h2>
				<p>
					Create or verify the necessary database tables for the media library. This is a safe, one-time action.
				</p>
				<form method="post">
					<input type="hidden" name="adamson_archive_setup_db" value="1">
					<?php wp_nonce_field( 'adamson_archive_db_setup_action', 'adamson_archive_db_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Create / Verify Database Tables', 'the-adamson-archive' ); ?>
					</button>
				</form>
			</div>
			<div class="card">
				<h2 class="title">YouTube Integration</h2>
				<?php
				$is_connected = adamson_archive_is_youtube_connected();
				$client       = adamson_archive_get_google_client();
				$auth_url     = '#';
				$client_error = false;

				if ( is_wp_error( $client ) ) {
					echo '<div class="notice notice-error inline"><p>' . esc_html( $client->get_error_message() ) . '</p></div>';
					$client_error = true;
				} else {
					$auth_url = $client->createAuthUrl();
				}

				if ( $is_connected ) :
					?>
					<div class="yt-status is-connected">
						<p><span class="dashicons dashicons-yes-alt"></span> Connected to YouTube</p>
					</div>
					<p>Your account is authorized. Videos will be uploaded to the associated YouTube account.</p>
					<form method="post" class="actions">
						<?php wp_nonce_field( 'adamson_archive_disconnect_yt_action', 'adamson_archive_disconnect_nonce' ); ?>
						<input type="hidden" name="adamson_archive_disconnect_yt" value="1">
						<button type="submit" class="button button-secondary">Disconnect Account</button>
					</form>
					<?php
				else :
					?>
					<div class="yt-status is-disconnected">
						<p><span class="dashicons dashicons-warning"></span> Not Connected</p>
					</div>
					<p>Connect your Google account to enable automatic video uploads to YouTube. A refresh token is required and is only granted on the first authorization.</p>
					<p><strong>If you are having trouble connecting:</strong> Go to your <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">Google Account permissions</a>, remove access for "The Adamson Archive", and then try connecting again below.</p>
					<div class="actions">
						<?php if ( ! $client_error ) : ?>
							<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
								<span class="dashicons dashicons-video-alt3" style="vertical-align: middle; margin-top: -2px;"></span>
								Connect to YouTube
							</a>
						<?php else : ?>
							<button class="button button-primary" disabled>Connect to YouTube</button>
						<?php endif; ?>
					</div>
					<?php
				endif;
				?>
			</div>
			<div class="card">
				<h2 class="title">Upload Settings</h2>
				<form id="adamson-archive-settings-form">
					<p>
						<label>
							<input type="checkbox" id="adamson-archive-disable-delete" name="adamson_archive_disable_delete" value="1" <?php checked( get_option( 'adamson_archive_disable_delete_videos', 0 ), '1' ); ?>>
							<strong>Disable deleting videos on YouTube upload</strong>
						</label>
					</p>
					<p class="description">
						By default, local video files are deleted after a successful YouTube upload to save server space (Hybrid Storage model). Check this box to keep the local files instead.
					</p>
				</form>
			</div>
		</div>

		<div id="activity-log" class="tab-content">
			<div class="card">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">Timestamp</th>
							<th scope="col">Event</th>
							<th scope="col">Details</th>
						</tr>
					</thead>
					<tbody id="adamson-archive-activity-log">
						<tr class="no-items">
							<td colspan="3">No activity yet. Click a button to begin.</td>
						</tr>
						<!-- Log entries will be added here via AJAX -->
					</tbody>
				</table>
			</div>
		</div>

		<div id="testing" class="tab-content">
			<div class="card">
				<h2 class="title">Testing Tools</h2>
				<p class="description">Clears all albums and media from the database tables (<code>adamson_archive_albums</code>, <code>adamson_archive_media</code>, and <code>adamson_archive_queue</code>). The original files are not affected.<br><br></p>
				<div>
					<button class="button button-danger" id="adamson-archive-delete-all-media">
						<?php esc_html_e( 'Delete All Media', 'the-adamson-archive' ); ?>
					</button>					
				</div>
			</div>
		</div>
	</div>
	<?php
}
