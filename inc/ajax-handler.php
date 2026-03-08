<?php
/**
 * Adamson Archive: AJAX Handler
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'wp_ajax_adamson_archive_scan_albums', 'adamson_archive_ajax_scan_albums' );
add_action( 'wp_ajax_adamson_archive_process_album', 'adamson_archive_ajax_process_album' );
add_action( 'wp_ajax_adamson_archive_start_queue_worker', 'adamson_archive_ajax_start_queue_worker' );
add_action( 'wp_ajax_adamson_archive_load_albums', 'adamson_archive_ajax_load_albums' );
add_action( 'wp_ajax_adamson_archive_get_album_media', 'adamson_archive_ajax_get_album_media' );
add_action( 'wp_ajax_adamson_archive_delete_all_media', 'adamson_archive_ajax_delete_all_media' );
add_action( 'wp_ajax_adamson_archive_get_dashboard_counts', 'adamson_archive_ajax_get_dashboard_counts' );
add_action( 'wp_ajax_adamson_archive_get_pending_videos', 'adamson_archive_ajax_get_pending_videos' );
add_action( 'wp_ajax_adamson_archive_save_settings', 'adamson_archive_ajax_save_settings' );
add_action( 'wp_ajax_adamson_archive_delete_media_item', 'adamson_archive_ajax_delete_media_item' );
add_action( 'wp_ajax_adamson_archive_delete_album', 'adamson_archive_ajax_delete_album' );
add_action( 'wp_ajax_adamson_archive_get_album_details', 'adamson_archive_ajax_get_album_details' );

/**
 * Helper: Ensure required database columns exist.
 */
function adamson_archive_ensure_is_removed_column() {
	if ( false !== get_transient( 'adamson_archive_db_verified' ) ) {
		return;
	}

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';

	// Ensure is_removed exists on both tables
	foreach ( array( $table_albums, $table_media ) as $table ) {
		$has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'is_removed' ) );
		if ( ! $has_column ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN is_removed TINYINT(1) DEFAULT 0" );
		}
	}

	// Ensure yt_playlist_id exists on albums table
	$has_playlist_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table_albums LIKE %s", 'yt_playlist_id' ) );
	if ( ! $has_playlist_col ) {
		$wpdb->query( "ALTER TABLE $table_albums ADD COLUMN yt_playlist_id VARCHAR(255) DEFAULT NULL" );
	}

	// Ensure album_id exists on queue table for accurate, high-speed lookups
	$has_queue_album_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table_queue LIKE %s", 'album_id' ) );
	if ( ! $has_queue_album_col ) {
		$wpdb->query( "ALTER TABLE $table_queue ADD COLUMN album_id BIGINT(20) UNSIGNED DEFAULT NULL, ADD INDEX (album_id)" );
	}

	// Ensure file_hash is indexed for high-speed duplicate checking
	$wpdb->query( "ALTER TABLE $table_queue MODIFY COLUMN file_hash VARCHAR(32), ADD INDEX IF NOT EXISTS (file_hash)" );
	$wpdb->query( "ALTER TABLE $table_media ADD INDEX IF NOT EXISTS (filename)" );

	// Cache verification for 24 hours
	set_transient( 'adamson_archive_db_verified', time(), DAY_IN_SECONDS );
}

/**
 * AJAX Handler: Manually trigger database table verification.
 */
function adamson_archive_ajax_setup_database() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	
	// Call the column helper. 
	// Note: If you have a main table creation function in database.php, call it here too.
	adamson_archive_ensure_is_removed_column();

	wp_send_json_success( array( 'message' => 'Database tables and columns verified.' ) );
}
add_action( 'wp_ajax_adamson_archive_setup_database', 'adamson_archive_ajax_setup_database' );

/**
 * AJAX Handler: Delete an entire album and all its contents.
 */
function adamson_archive_ajax_delete_album() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID.' ) );
	}

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';

	$album = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_albums WHERE id = %d", $album_id ) );

	if ( ! $album ) {
		wp_send_json_error( array( 'message' => 'Album not found.' ) );
	}

	try {
		// Soft delete: Mark album and all its media as removed.
		$wpdb->update( $table_albums, array( 'is_removed' => 1 ), array( 'id' => $album_id ) );
		$wpdb->update( $table_media, array( 'is_removed' => 1 ), array( 'album_id' => $album_id ) );

		// Also cancel any pending background tasks for this album
		$wpdb->update( $table_queue, array( 'status' => 'cancelled' ), array( 'album_id' => $album_id, 'status' => 'pending' ) );

		wp_send_json_success( array( 'message' => 'Album and its media moved to removed list.' ) );

	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => 'Error deleting album: ' . $e->getMessage() ) );
	}
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Directory path.
 * @return bool
 */
function adamson_archive_delete_directory( $dir ) {
	if ( ! file_exists( $dir ) ) {
		return true;
	}
	if ( ! is_dir( $dir ) ) {
		return unlink( $dir );
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		if ( ! adamson_archive_delete_directory( $dir . DIRECTORY_SEPARATOR . $item ) ) {
			return false;
		}
	}
	return rmdir( $dir );
}

/**
 * AJAX Handler: Delete a single media item.
 */
function adamson_archive_ajax_delete_media_item() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	$media_id = isset( $_POST['media_id'] ) ? intval( $_POST['media_id'] ) : 0;

	if ( ! $media_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Media ID.' ) );
	}

	global $wpdb;
	$table_media = 'adamson_archive_media';
	$media_item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_media WHERE id = %d", $media_id ) );

	if ( ! $media_item ) {
		wp_send_json_error( array( 'message' => 'Media item not found.' ) );
	}

	try {
		if ( 'video' === $media_item->file_type && ! empty( $media_item->yt_video_id ) ) {
			// Delete YouTube video
			if ( ! adamson_archive_is_youtube_connected() ) {
				wp_send_json_error( array( 'message' => 'YouTube account is not connected.' ) );
			}
			$client = adamson_archive_get_google_client();
			if ( is_wp_error( $client ) ) {
				wp_send_json_error( array( 'message' => 'Could not connect to YouTube: ' . $client->get_error_message() ) );
			}
			$youtube = new Google_Service_YouTube( $client );
			$youtube->videos->delete( $media_item->yt_video_id );
		}

		// Soft delete: Update the media record
		$wpdb->update( $table_media, array( 'is_removed' => 1 ), array( 'id' => $media_id ) );

		wp_send_json_success( array( 'message' => 'Media item removed.' ) );

	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => 'Error deleting media item: ' . $e->getMessage() ) );
	}
}

/**
 * AJAX Handler: Get a summary of albums containing removed content.
 */
function adamson_archive_ajax_get_removed_media() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	global $wpdb;
	$table_media  = 'adamson_archive_media';
	$table_albums = 'adamson_archive_albums';

	// Get albums that are either removed themselves or contain removed media
	$results = $wpdb->get_results( "
		SELECT a.id, a.display_name, a.is_removed as album_removed, COUNT(m.id) as removed_count
		FROM $table_albums a
		LEFT JOIN $table_media m ON a.id = m.album_id AND m.is_removed = 1
		WHERE a.is_removed = 1 OR m.is_removed = 1
		GROUP BY a.id
		ORDER BY a.display_name ASC
	" );

	wp_send_json_success( array( 'albums' => $results ) );
}

/**
 * AJAX Handler: Get removed media items for a specific album.
 */
function adamson_archive_ajax_get_removed_media_details() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	
	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;
	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID.' ) );
	}

	global $wpdb;
	$table_media = 'adamson_archive_media';
	
	$media = $wpdb->get_results( $wpdb->prepare( 
		"SELECT * FROM $table_media WHERE album_id = %d AND is_removed = 1 ORDER BY filename ASC", 
		$album_id 
	) );

	wp_send_json_success( array( 'media' => $media ) );
}

/**
 * AJAX Handler: Permanently delete a single media item.
 */
function adamson_archive_ajax_permanent_delete_media() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	$media_id = isset( $_POST['media_id'] ) ? intval( $_POST['media_id'] ) : 0;
	global $wpdb;
	$table_media = 'adamson_archive_media';

	$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_media WHERE id = %d", $media_id ) );
	if ( ! $item ) {
		wp_send_json_error( array( 'message' => 'Item not found.' ) );
	}

	// If it's a photo, delete the physical file. 
	// (Videos are already handled by the hybrid model or soft-delete YT wipe).
	if ( 'photo' === $item->file_type && file_exists( $item->file_path ) ) {
		@unlink( $item->file_path );
	}

	$wpdb->delete( $table_media, array( 'id' => $media_id ) );
	wp_send_json_success( array( 'message' => 'Item permanently deleted.' ) );
}

/**
 * AJAX Handler: Permanently delete an entire album.
 */
function adamson_archive_ajax_permanent_delete_album() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;
	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';

	$album = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_albums WHERE id = %d", $album_id ) );
	if ( ! $album ) {
		wp_send_json_error( array( 'message' => 'Album not found.' ) );
	}

	try {
		$media_items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_media WHERE album_id = %d", $album_id ) );
		$youtube = null;

		foreach ( $media_items as $item ) {
			if ( 'photo' === $item->file_type ) {
				if ( file_exists( $item->file_path ) ) {
					@unlink( $item->file_path );
				}
			} elseif ( 'video' === $item->file_type && ! empty( $item->yt_video_id ) ) {
				if ( ! $youtube && adamson_archive_is_youtube_connected() ) {
					$client = adamson_archive_get_google_client();
					if ( ! is_wp_error( $client ) ) {
						$youtube = new Google_Service_YouTube( $client );
					}
				}
				if ( $youtube ) {
					try {
						$youtube->videos->delete( $item->yt_video_id );
					} catch ( Exception $e ) {
						// Continue if video already deleted from YT
					}
				}
			}
		}

		// Delete YT Playlist
		if ( ! empty( $album->yt_playlist_id ) ) {
			if ( ! $youtube && adamson_archive_is_youtube_connected() ) {
				$client = adamson_archive_get_google_client();
				if ( ! is_wp_error( $client ) ) {
					$youtube = new Google_Service_YouTube( $client );
				}
			}
			if ( $youtube ) {
				try {
					$youtube->playlists->delete( $album->yt_playlist_id );
				} catch ( Exception $e ) { }
			}
		}

		// Delete local folder
		if ( ! empty( $album->path ) && is_dir( $album->path ) ) {
			adamson_archive_delete_directory( $album->path );
		}

		// Wipe DB records
		$wpdb->delete( $table_media, array( 'album_id' => $album_id ) );
		$wpdb->delete( $table_albums, array( 'id' => $album_id ) );
		
		$like_pattern = '%"album_id":' . $wpdb->esc_like( $album_id ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table_queue WHERE data LIKE %s", $like_pattern ) );
		$wpdb->delete( $table_queue, array( 'album_id' => $album_id ) );

		wp_send_json_success( array( 'message' => 'Album and all contents permanently deleted.' ) );

	} catch ( Exception $e ) {
		wp_send_json_error( array( 'message' => 'Error during permanent delete: ' . $e->getMessage() ) );
	}
}

/**
 * AJAX Handler: Restore a removed media item.
 */
function adamson_archive_ajax_restore_media_item() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	$media_id = isset( $_POST['media_id'] ) ? intval( $_POST['media_id'] ) : 0;

	if ( ! $media_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Media ID.' ) );
	}

	global $wpdb;
	$table_media = 'adamson_archive_media';
	$table_albums = 'adamson_archive_albums';
	
	$media_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_media WHERE id = %d", $media_id ) );

	if ( ! $media_item || 'video' === $media_item->file_type ) {
		wp_send_json_error( array( 'message' => 'Only photos can be restored.' ) );
	}

	// Restore the media item.
	$updated = $wpdb->update(
		$table_media,
		array( 'is_removed' => 0 ),
		array( 'id' => $media_id )
	);

	// Automatically restore the parent album so the item becomes visible in the gallery.
	$wpdb->update( $table_albums, array( 'is_removed' => 0 ), array( 'id' => $media_item->album_id ) );

	if ( false === $updated ) {
		wp_send_json_error( array( 'message' => 'Failed to restore item.' ) );
	}

	wp_send_json_success( array( 'message' => 'Media item restored to gallery.' ) );
}

/**
 * AJAX Handler: Restore an entire removed album.
 */
function adamson_archive_ajax_restore_album() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID.' ) );
	}

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';

	// Restore the album
	$wpdb->update( $table_albums, array( 'is_removed' => 0 ), array( 'id' => $album_id ) );
	
	// Restore all media within that album
	$wpdb->update( 
		$table_media, 
		array( 'is_removed' => 0 ), 
		array( 
			'album_id' => $album_id,
			'file_type' => 'photo' // Only restore photos automatically; videos stay removed if they were deleted from YT
		) 
	);
	
	// Note: We don't restore videos because if they were deleted from YT, they are gone.

	wp_send_json_success( array( 'message' => 'Album restored successfully.' ) );
}

/**
 * AJAX Handler: Save admin settings.
 */
function adamson_archive_ajax_save_settings() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	if ( isset( $_POST['disable_delete'] ) ) {
		$value = '1' === $_POST['disable_delete'] ? '1' : '0';
		update_option( 'adamson_archive_disable_delete_videos', $value );
	}

	wp_send_json_success( array( 'message' => 'Settings saved.' ) );
}

/**
 * Get updated counts for the dashboard.
 */
function adamson_archive_ajax_get_dashboard_counts() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	global $wpdb;
	$table_queue = 'adamson_archive_queue';

	$pending_videos = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending' AND task_type = 'upload_video'" );
	$total_tasks    = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending'" );
	
	// Fetch the last 5 completed items to update the activity log and UI via polling.
	$recent_items = $wpdb->get_results( "SELECT id, message, data, task_type FROM $table_queue WHERE status = 'completed' ORDER BY id DESC LIMIT 20" );
	$recent_messages = array();

	foreach ( $recent_items as $item ) {
		$item_data = json_decode( $item->data, true );
		$recent_messages[] = array(
			'id'       => (int) $item->id,
			'message'  => $item->message,
			'album_id' => isset( $item_data['album_id'] ) ? (int) $item_data['album_id'] : 0,
			'type'     => $item->task_type,
		);
	}

	wp_send_json_success(
		array(
			'pending_videos'  => $pending_videos,
			'total_pending'   => $total_tasks,
			'recent_messages' => $recent_messages,
			'worker_active'   => (
				( function_exists( 'as_next_scheduled_action' ) &&
				  ( false !== as_next_scheduled_action( 'adamson_archive_run_queue' ) || false !== as_next_scheduled_action( 'adamson_archive_run_scan' ) )
				) || $total_tasks > 0
			),
		)
	);
}

/**
 * AJAX Handler: Get full details for a single album (used for real-time UI injection).
 */
function adamson_archive_ajax_get_album_details() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	
	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;
	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID' ) );
	}

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';

	$album = $wpdb->get_row( $wpdb->prepare( "
		SELECT 
			a.*,
			SUM(CASE WHEN m.file_type = 'photo' AND m.is_removed = 0 THEN 1 ELSE 0 END) AS photo_count,
			SUM(CASE WHEN m.file_type = 'video' AND m.is_removed = 0 THEN 1 ELSE 0 END) AS video_count
		FROM $table_albums a
		LEFT JOIN $table_media m ON a.id = m.album_id
		WHERE a.id = %d
		GROUP BY a.id
	", $album_id ) );

	if ( ! $album ) {
		wp_send_json_error( array( 'message' => 'Album not found' ) );
	}

	// Add pending count
	$album->pending_video_count = (int) $wpdb->get_var( $wpdb->prepare( "
		SELECT COUNT(id) 
		FROM $table_queue 
		WHERE status = 'pending' AND album_id = %d AND task_type = 'upload_video'
	", $album_id ) );

	wp_send_json_success( array( 'album' => $album ) );
}

/**
 * Get details for all pending videos in the queue.
 */
function adamson_archive_ajax_get_pending_videos() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	global $wpdb;
	$table_queue  = 'adamson_archive_queue';
	$table_albums = 'adamson_archive_albums';

	$pending_items = $wpdb->get_results( "SELECT data FROM $table_queue WHERE status = 'pending' ORDER BY created_at ASC" );

	$videos = array();
	if ( ! empty( $pending_items ) ) {
		// Get all album names in one query to be efficient
		$album_ids = array();
		foreach ( $pending_items as $item ) {
			$data = json_decode( $item->data, true );
			if ( ! empty( $data['album_id'] ) ) {
				$album_ids[] = (int) $data['album_id'];
			}
		}
		$album_ids = array_unique( $album_ids );
		$album_names = array();

		if ( ! empty( $album_ids ) ) {
			// Since we cast all album IDs to integers, it's safe to use them directly in the query.
			$album_ids_string = implode( ',', $album_ids );
			$album_results    = $wpdb->get_results( "SELECT id, display_name FROM $table_albums WHERE id IN ($album_ids_string)" );
			foreach ( $album_results as $album ) {
				$album_names[ $album->id ] = $album->display_name;
			}
		}

		// Now build the final video list
		$upload_dir = wp_upload_dir();
		$albums_dir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'albums' );

		foreach ( $pending_items as $item ) {
			$data = json_decode( $item->data, true );
			if ( empty( $data['filename'] ) || empty( $data['album_id'] ) || empty( $data['file_path'] ) ) {
				continue;
			}

			$relative_path = str_replace( $albums_dir, '', wp_normalize_path( $data['file_path'] ) );

			$videos[] = array(
				'filename'   => $data['filename'],
				'album_name' => isset( $album_names[ $data['album_id'] ] ) ? $album_names[ $data['album_id'] ] : 'Unknown Album',
				'file_path'  => ltrim( $relative_path, '/' ),
			);
		}
	}

	wp_send_json_success( array( 'videos' => $videos ) );
}



/**
 * Step 1: Scan for Album directories and populate the albums table.
 */
function adamson_archive_ajax_scan_albums() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		wp_send_json_error( array( 'message' => 'Background processing library not found. Please ensure the Action Scheduler files are in the theme directory.' ) );
	}

	$albums    = adamson_archive_scan_album_directories();

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_queue  = 'adamson_archive_queue';

	// Optimization: Fetch existing albums and pending tasks in bulk to avoid O(N) queries in the loop.
	$existing_albums = $wpdb->get_results( "SELECT folder_name, id FROM $table_albums", OBJECT_K );
	$pending_scans   = $wpdb->get_col( "SELECT album_id FROM $table_queue WHERE task_type = 'scan_album' AND status = 'pending'" );
	$pending_scans   = array_flip( $pending_scans );

	foreach ( $albums as $album ) {
		$folder_name = $album['source_name'];
		$album_id    = 0;

		if ( isset( $existing_albums[ $folder_name ] ) ) {
			$album_id = (int) $existing_albums[ $folder_name ]->id;
		} else {
			$wpdb->insert(
				$table_albums,
				array(
					'folder_name'  => $folder_name,
					'display_name' => $album['album_name'],
					'album_date'   => $album['album_date'],
					'path'         => $album['path'],
				)
			);
			$album_id = $wpdb->insert_id;
		}

		if ( $album_id && ! isset( $pending_scans[ $album_id ] ) ) {
			// Enqueue the album for background processing if not already in queue
				$wpdb->insert( $table_queue, array(
					'album_id'  => $album_id,
					'task_type' => 'scan_album',
					'status'    => 'pending',
					'data'      => json_encode( array( 'album_id' => $album_id, 'name' => $folder_name ) )
				) );
			}
		}

	// Start the Action Scheduler worker for scanning
	if ( function_exists( 'as_next_scheduled_action' ) ) {
		if ( false === as_next_scheduled_action( 'adamson_archive_run_scan' ) ) {
			as_enqueue_async_action( 'adamson_archive_run_scan' );
		}
	}

	wp_send_json_success( array( 'message' => 'Library scan initiated in the background.' ) );
}

/**
 * Action Scheduler Callback: Process album scans in the background.
 */
function adamson_archive_as_run_scan_worker() {
	global $wpdb;
	$table_queue = 'adamson_archive_queue';

	// Process up to 10 albums per run to make small scans feel instant
	for ( $i = 0; $i < 10; $i++ ) {
		$item = $wpdb->get_row( "SELECT * FROM $table_queue WHERE task_type = 'scan_album' AND status = 'pending' LIMIT 1" );
		if ( ! $item ) break;

		$result = adamson_archive_process_album_internal( $item->album_id );
		
		$message = isset( $result['message'] ) ? $result['message'] : "Processed album ID: {$item->album_id}";
		if ( isset( $result['count'] ) && $result['count'] > 0 ) {
			$message .= " (Found {$result['count']} new items)";
		}

		$wpdb->update( $table_queue, array( 'status' => 'completed', 'message' => $message ), array( 'id' => $item->id ) );
	}

	// Schedule next if more exist
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'adamson_archive_run_scan' );
	}
}
add_action( 'adamson_archive_run_scan', 'adamson_archive_as_run_scan_worker' );

/**
 * Frontend: Load paginated albums.
 */
function adamson_archive_ajax_load_albums() {
	// Optional: Add nonce check for frontend if needed, but usually open for read-only.
	
	$page   = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
	$limit  = 10;
	$offset = ( $page - 1 ) * $limit;

	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';
	adamson_archive_ensure_is_removed_column();

	// Get pending counts efficiently via SQL GROUP BY
	$pending_results = $wpdb->get_results( 
		"SELECT album_id, COUNT(id) as count FROM $table_queue WHERE status = 'pending' AND task_type = 'upload_video' GROUP BY album_id", 
		OBJECT_K 
	);

	$albums = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT 
				a.*,
				SUM(CASE WHEN m.is_removed = 0 THEN 1 ELSE 0 END) AS media_count,
				SUM(CASE WHEN m.file_type = 'photo' AND m.is_removed = 0 THEN 1 ELSE 0 END) AS photo_count,
				SUM(CASE WHEN m.file_type = 'video' AND m.is_removed = 0 THEN 1 ELSE 0 END) AS video_count
			FROM 
				$table_albums AS a
			LEFT JOIN 
				$table_media AS m ON a.id = m.album_id
			WHERE
				a.is_removed = 0
			GROUP BY 
				a.id
			ORDER BY 
				a.album_date DESC, a.display_name ASC 
			LIMIT %d OFFSET %d",
			$limit,
			$offset
		)
	);

	// Add pending counts to the album objects
    foreach( $albums as $album ) {
        $album->pending_video_count = isset( $pending_results[ $album->id ] ) ? (int) $pending_results[ $album->id ]->count : 0;
    }

	$total_albums = $wpdb->get_var( "SELECT COUNT(id) FROM $table_albums WHERE is_removed = 0" );
	$has_more     = ( $offset + $limit ) < $total_albums;

	wp_send_json_success(
		array(
			'albums'   => $albums,
			'has_more' => $has_more,
		)
	);
}

/**
 * Frontend: Get media for a specific album.
 */
function adamson_archive_ajax_get_album_media() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	adamson_archive_ensure_is_removed_column();

	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID' ) );
	}

	global $wpdb;
	$table_media = 'adamson_archive_media';
	$table_queue = 'adamson_archive_queue';

	$media = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table_media WHERE album_id = %d AND is_removed = 0 ORDER BY created_at ASC",
			$album_id
		)
	);

	$counts = $wpdb->get_row( $wpdb->prepare( "SELECT 
		COUNT(*) as total_media,
		SUM(CASE WHEN file_type = 'photo' AND is_removed = 0 THEN 1 ELSE 0 END) as photo_count,
		SUM(CASE WHEN file_type = 'video' AND is_removed = 0 THEN 1 ELSE 0 END) as video_count
		FROM $table_media WHERE album_id = %d AND is_removed = 0", $album_id ), ARRAY_A );

	// Get pending count for this specific album
	$counts['pending_count'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending' AND album_id = %d AND task_type = 'upload_video'", $album_id ) );

	// Prepare URLs for local files and backfill YT data if needed.
	$upload_dir = wp_upload_dir();

	foreach ( $media as $item ) {
		// 1. Construct the public URL for photos
		if ( 'photo' === $item->file_type ) {
			$file_path_norm = wp_normalize_path( $item->file_path );
			$base_dir_norm  = wp_normalize_path( $upload_dir['basedir'] );
			$relative_path  = str_replace( $base_dir_norm, '', $file_path_norm );
			$item->file_url = $upload_dir['baseurl'] . $relative_path;
		}

		// 2. Backfill YouTube data for older records if missing
		if ( 'video' === $item->file_type && ! empty( $item->yt_video_id ) ) {
			if ( empty( $item->yt_thumbnail_url ) ) {
				$item->yt_thumbnail_url = 'https://img.youtube.com/vi/' . $item->yt_video_id . '/hqdefault.jpg';
			}
			if ( empty( $item->yt_embed_html ) ) {
				$item->yt_embed_html = '<iframe src="https://www.youtube.com/embed/' . $item->yt_video_id . '?autoplay=1" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
			}
		}
	}

	wp_send_json_success( array( 
		'media' => $media,
		'counts' => $counts
	) );
}

/**
 * Step 2: Scan a specific album for files and populate the media table.
 */
function adamson_archive_ajax_process_album() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );
	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;
	
	$result = adamson_archive_process_album_internal( $album_id );
	
	if ( isset( $result['error'] ) ) {
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}
	
	wp_send_json_success( $result );
}

/**
 * Internal Helper: Core logic for scanning an album folder.
 */
function adamson_archive_process_album_internal( $album_id ) {
	global $wpdb;
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';
	$table_queue  = 'adamson_archive_queue';

	// Check if the new file_hash column exists. Cache the result.
	static $hash_column_exists = null;
	if ( null === $hash_column_exists ) {
		$hash_column_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_queue . ' LIKE %s', 'file_hash' ) );
	}

	// Security: Fetch path from DB instead of trusting user input.
	$album_path = $wpdb->get_var( $wpdb->prepare( "SELECT path FROM $table_albums WHERE id = %d", $album_id ) );

	if ( ! $album_id || ! $album_path || ! is_dir( $album_path ) ) {
		return array( 'error' => 'Invalid album data.' );
	}

	$files       = new DirectoryIterator( $album_path );
	$allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mov', 'mp4', 'avi', 'mkv' );
	$count       = 0;
	$notices     = array();

	// Optimization: Fetch all existing filenames and hashes for this album in bulk
	$existing_media = $wpdb->get_col( $wpdb->prepare( "SELECT filename FROM $table_media WHERE album_id = %d", $album_id ) );
	$existing_media = array_flip( $existing_media );

	$existing_queue = $wpdb->get_col( $wpdb->prepare( "SELECT file_hash FROM $table_queue WHERE album_id = %d AND task_type = 'upload_video'", $album_id ) );
	$existing_queue = array_flip( $existing_queue );


	foreach ( $files as $fileinfo ) {
		if ( $fileinfo->isFile() && ! $fileinfo->isDot() ) {
			$ext = strtolower( $fileinfo->getExtension() );
			if ( in_array( $ext, $allowed_ext, true ) ) {
				$filename  = $fileinfo->getFilename();
				$file_path = $fileinfo->getPathname();
				$type      = in_array( $ext, array( 'mov', 'mp4', 'avi', 'mkv' ), true ) ? 'video' : 'photo';

				if ( 'photo' === $type ) {
					// Bulk check against our flipped array
					if ( ! isset( $existing_media[ $filename ] ) ) {
						// If it doesn't exist, insert it.
						$wpdb->insert(
							$table_media,
							array(
								'album_id'  => $album_id,
								'filename'  => $filename,
								'file_path' => $file_path,
								'file_type' => $type,
							)
						);
						$count++;
					}
				} elseif ( 'video' === $type ) {
					$is_duplicate = false;
					$file_hash    = md5( $file_path );

					// Check if the file is already in the queue.
					if ( $hash_column_exists ) {
						$is_duplicate = isset( $existing_queue[ $file_hash ] );
					} else {
						// Method 2: Fallback for older schemas. Check for the file path in the JSON data.
						$json_substring = '"file_path":' . wp_json_encode( $file_path );
						$like_pattern   = '%' . $wpdb->esc_like( $json_substring ) . '%';
						$is_duplicate   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_queue WHERE data LIKE %s AND ( status = 'pending' OR status = 'in-progress' )", $like_pattern ) );
						$notices[]      = 'Database schema is outdated. Please deactivate and reactivate the theme to improve performance and reliability.';
					}

					// As a final check, see if this video has already been fully processed and added to the media table.
					if ( ! $is_duplicate && isset( $existing_media[ $filename ] ) ) {
						$is_duplicate = true;
					}

					// Only insert into queue if it doesn't already exist.
					if ( ! $is_duplicate ) {
						$insert_data = array(
							'album_id'  => $album_id,
							'task_type' => 'upload_video',
							'data'      => json_encode(
								array(
									'album_id'  => $album_id,
									'file_path' => $file_path,
									'filename'  => $filename,
								)
							),
							'status'    => 'pending',
						);
						if ( $hash_column_exists ) {
							$insert_data['file_hash'] = md5( $file_path );
						}
						$wpdb->insert( $table_queue, $insert_data );
						$count++;
					}
				}
			}
		}
	}

	return array(
		'message' => "Processed album. Added {$count} new files.",
		'count'   => $count,
		'notices' => array_unique( $notices ),
	);
}

/**
 * AJAX Handler: Start the background queue worker via Action Scheduler.
 */
function adamson_archive_ajax_start_queue_worker() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		wp_send_json_error( array( 'message' => 'Background processing library not found. Please ensure the Action Scheduler files are in the theme directory.' ) );
	}

	$disable_delete = isset( $_POST['disable_delete'] ) && '1' === $_POST['disable_delete'] ? true : false;

	// Enqueue the background action if it's not already scheduled.
	if ( function_exists( 'as_next_scheduled_action' ) ) {
		if ( false === as_next_scheduled_action( 'adamson_archive_run_queue' ) ) {
			as_enqueue_async_action( 'adamson_archive_run_queue', array( 'disable_delete' => $disable_delete ) );
		}
	}

	wp_send_json_success( array( 'message' => 'Background upload worker started.' ) );
}
add_action( 'wp_ajax_adamson_archive_start_queue_worker', 'adamson_archive_ajax_start_queue_worker' );

/**
 * Action Scheduler Callback: Process queue items in the background.
 */
function adamson_archive_as_run_queue_worker( $disable_delete ) {
	// Process a batch of 3 items per background run to stay within server limits.
	for ( $i = 0; $i < 3; $i++ ) {
		$result = adamson_archive_process_next_queue_item( $disable_delete );
		
		// If queue is empty or we hit a systemic error, stop.
		if ( is_wp_error( $result ) || ( isset( $result['processed'] ) && ! $result['processed'] ) ) {
			return;
		}
	}

	// If there are still items, schedule the next batch immediately.
	if ( false === as_next_scheduled_action( 'adamson_archive_run_queue' ) ) {
		as_enqueue_async_action( 'adamson_archive_run_queue', array( 'disable_delete' => $disable_delete ) );
	}
}
add_action( 'adamson_archive_run_queue', 'adamson_archive_as_run_queue_worker' );

/**
 * AJAX Handler: Delete all media, albums, and queue items.
 */
function adamson_archive_ajax_delete_all_media() {
    check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

    global $wpdb;
    $table_albums = 'adamson_archive_albums';
    $table_media = 'adamson_archive_media';
    $table_queue = 'adamson_archive_queue';

    // Using TRUNCATE is faster than DELETE FROM.
    $wpdb->query( "TRUNCATE TABLE $table_media" );
    $wpdb->query( "TRUNCATE TABLE $table_albums" );
    $wpdb->query( "TRUNCATE TABLE $table_queue" );

    wp_send_json_success( array( 'message' => 'All media, albums, and queue items have been deleted.' ) );
}
