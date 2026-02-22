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
add_action( 'wp_ajax_adamson_archive_process_queue_item', 'adamson_archive_ajax_process_queue_item' );
add_action( 'wp_ajax_adamson_archive_load_albums', 'adamson_archive_ajax_load_albums' );
add_action( 'wp_ajax_nopriv_adamson_archive_load_albums', 'adamson_archive_ajax_load_albums' );
add_action( 'wp_ajax_adamson_archive_get_album_media', 'adamson_archive_ajax_get_album_media' );
add_action( 'wp_ajax_nopriv_adamson_archive_get_album_media', 'adamson_archive_ajax_get_album_media' );
add_action( 'wp_ajax_adamson_archive_delete_all_media', 'adamson_archive_ajax_delete_all_media' );
add_action( 'wp_ajax_adamson_archive_get_dashboard_counts', 'adamson_archive_ajax_get_dashboard_counts' );
add_action( 'wp_ajax_adamson_archive_get_pending_videos', 'adamson_archive_ajax_get_pending_videos' );
add_action( 'wp_ajax_adamson_archive_save_settings', 'adamson_archive_ajax_save_settings' );

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

	global $wpdb;
	$table_queue = 'adamson_archive_queue';

	$pending_videos = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending'" );

	wp_send_json_success(
		array(
			'pending_videos' => $pending_videos,
		)
	);
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

	$albums    = adamson_archive_scan_album_directories();
	$processed = 0;
	$album_ids = array();

	global $wpdb;
	$table_albums = 'adamson_archive_albums';

	foreach ( $albums as $album ) {
		// Check if album exists by folder name.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table_albums WHERE folder_name = %s", $album['source_name'] ) );

		if ( $existing ) {
			$album_id = $existing->id;
		} else {
			$wpdb->insert(
				$table_albums,
				array(
					'folder_name'  => $album['source_name'],
					'display_name' => $album['album_name'],
					'album_date'   => $album['album_date'],
					'path'         => $album['path'],
				)
			);
			$album_id = $wpdb->insert_id;
		}

		if ( $album_id ) {
			$album_ids[] = array(
				'id'   => $album_id,
				'path' => $album['path'],
				'name' => $album['source_name'],
			);
			$processed++;
		}
	}

	wp_send_json_success(
		array(
			'message' => "Found {$processed} albums.",
			'albums'  => $album_ids,
		)
	);
}

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

	// Get all pending items and count them by album_id in PHP
	$pending_items = $wpdb->get_results( "SELECT data FROM $table_queue WHERE status = 'pending'" );
	$pending_counts = array();
	foreach ( $pending_items as $item ) {
		$data = json_decode( $item->data, true );
		if ( ! empty( $data['album_id'] ) ) {
			$album_id = (int) $data['album_id'];
			if ( ! isset( $pending_counts[ $album_id ] ) ) {
				$pending_counts[ $album_id ] = 0;
			}
			$pending_counts[ $album_id ]++;
		}
	}

	$albums = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT 
				a.*,
				COUNT(m.id) AS media_count,
				SUM(CASE WHEN m.file_type = 'photo' THEN 1 ELSE 0 END) AS photo_count,
				SUM(CASE WHEN m.file_type = 'video' THEN 1 ELSE 0 END) AS video_count
			FROM 
				$table_albums AS a
			LEFT JOIN 
				$table_media AS m ON a.id = m.album_id
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
        $album->pending_video_count = isset( $pending_counts[ $album->id ] ) ? $pending_counts[ $album->id ] : 0;
    }

	$total_albums = $wpdb->get_var( "SELECT COUNT(id) FROM $table_albums" );
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
	$album_id = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;

	if ( ! $album_id ) {
		wp_send_json_error( array( 'message' => 'Invalid Album ID' ) );
	}

	global $wpdb;
	$table_media = 'adamson_archive_media';

	$media = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table_media WHERE album_id = %d ORDER BY created_at ASC",
			$album_id
		)
	);

	$counts = $wpdb->get_row( $wpdb->prepare( "SELECT 
		COUNT(*) as total_media,
		SUM(CASE WHEN file_type = 'photo' THEN 1 ELSE 0 END) as photo_count,
		SUM(CASE WHEN file_type = 'video' THEN 1 ELSE 0 END) as video_count
		FROM $table_media WHERE album_id = %d", $album_id ), ARRAY_A );

	// Prepare URLs for local files.
	$upload_dir = wp_upload_dir();
	$base_url   = $upload_dir['baseurl'] . '/albums';

	foreach ( $media as $item ) {
		// We need to construct the public URL. 
		// Note: This assumes the file_path stored in DB is the absolute server path.
		// We need to convert it to a URL.
		// A robust way is to store relative paths, but for now we can do a string replace if needed,
		// or rely on the fact that we know the structure is /uploads/albums/{folder}/{file}.
		
		// Get relative path from 'uploads'
		// Normalize paths to handle Windows backslashes correctly.
		$file_path_norm = wp_normalize_path( $item->file_path );
		$base_dir_norm  = wp_normalize_path( $upload_dir['basedir'] );
		$relative_path  = str_replace( $base_dir_norm, '', $file_path_norm );
		$item->file_url = $upload_dir['baseurl'] . $relative_path;
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

	$album_id   = isset( $_POST['album_id'] ) ? intval( $_POST['album_id'] ) : 0;
	$notices    = array();

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
		wp_send_json_error( array( 'message' => 'Invalid album data.' ) );
	}

	$files       = new DirectoryIterator( $album_path );
	$allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mov', 'mp4', 'avi', 'mkv' );
	$count       = 0;

	foreach ( $files as $fileinfo ) {
		if ( $fileinfo->isFile() && ! $fileinfo->isDot() ) {
			$ext = strtolower( $fileinfo->getExtension() );
			if ( in_array( $ext, $allowed_ext, true ) ) {
				$filename  = $fileinfo->getFilename();
				$file_path = $fileinfo->getPathname();
				$type      = in_array( $ext, array( 'mov', 'mp4', 'avi', 'mkv' ), true ) ? 'video' : 'photo';

				if ( 'photo' === $type ) {
					// For photos, check if it's already in the main media table.
					$media_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_media WHERE album_id = %d AND filename = %s", $album_id, $filename ) );
					if ( ! $media_exists ) {
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

					// Check if the file is already in the queue.
					if ( $hash_column_exists ) {
						// Method 1: Check by hash (preferred, most reliable, and performant).
						$file_hash    = md5( $file_path );
						$is_duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_queue WHERE file_hash = %s", $file_hash ) );
					} else {
						// Method 2: Fallback for older schemas. Check for the file path in the JSON data.
						$json_substring = '"file_path":' . wp_json_encode( $file_path );
						$like_pattern   = '%' . $wpdb->esc_like( $json_substring ) . '%';
						$is_duplicate   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_queue WHERE data LIKE %s AND ( status = 'pending' OR status = 'in-progress' )", $like_pattern ) );
						$notices[]      = 'Database schema is outdated. Please deactivate and reactivate the theme to improve performance and reliability.';
					}

					// As a final check, see if this video has already been fully processed and added to the media table.
					if ( ! $is_duplicate ) {
						$is_duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_media WHERE file_path = %s", $file_path ) );
					}

					// Only insert into queue if it doesn't already exist.
					if ( ! $is_duplicate ) {
						$insert_data = array(
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

	wp_send_json_success(
		array(
			'message' => "Processed album. Added {$count} new files.",
			'count'   => $count,
			'notices' => array_unique( $notices ),
		)
	);
}

/**
 * AJAX Handler: Process the next item in the queue.
 */
function adamson_archive_ajax_process_queue_item() {
	check_ajax_referer( 'adamson_archive_scan_nonce', 'nonce' );

	$disable_delete = isset( $_POST['disable_delete'] ) && '1' === $_POST['disable_delete'];
	$result         = adamson_archive_process_next_queue_item( $disable_delete );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	} else {
		wp_send_json_success( $result );
	}
}

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
