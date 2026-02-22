<?php
/**
 * Adamson Archive: Batch Processing
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Processes the next pending item in the queue.
 *
 * @param bool $disable_delete Whether to skip deleting the local file after upload.
 * @return array|WP_Error Result array with message and remaining count, or WP_Error on failure.
 */
function adamson_archive_process_next_queue_item( $disable_delete = false ) {
	global $wpdb;
	$table_queue  = 'adamson_archive_queue';
	$table_albums = 'adamson_archive_albums';
	$table_media  = 'adamson_archive_media';

	// Check if the new attempts column exists. Cache the result.
	static $attempts_column_exists = null;
	if ( null === $attempts_column_exists ) {
		$attempts_column_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_queue . ' LIKE %s', 'attempts' ) );
	}

	// 1. Fetch the next pending item.
	$item = $wpdb->get_row( "SELECT * FROM $table_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1" );

	if ( ! $item ) {
		return array(
			'processed' => false,
			'message'   => 'Queue is empty.',
			'remaining' => 0,
		);
	}

	// Check attempts and fail permanently if over the threshold.
	if ( $attempts_column_exists && $item->attempts >= 3 ) {
		$wpdb->update(
			$table_queue,
			array(
				'status'  => 'failed',
				'message' => 'Failed after 3 attempts. Last error: ' . $item->message,
			),
			array( 'id' => $item->id )
		);
		// This item is now permanently failed. Return a "processed" response
		// so the front-end continues with the next item in the queue.
		$remaining = $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending'" );
		return array(
			'processed' => true,
			'message'   => 'Skipped item ' . $item->id . ' after 3 failed attempts.',
			'remaining' => $remaining,
		);
	}

	$data = json_decode( $item->data, true );
	if ( ! $data || ! isset( $data['album_id'], $data['file_path'] ) ) {
		$wpdb->update( $table_queue, array( 'status' => 'failed', 'message' => 'Invalid data.' ), array( 'id' => $item->id ) );
		// This item has invalid data. Return a "processed" response
		// so the front-end continues with the next item in the queue.
		$remaining = $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending'" );
		return array(
			'processed' => true,
			'message'   => 'Skipped item ' . $item->id . ' due to invalid data.',
			'remaining' => $remaining,
		);
	}

	try {
		// 3. Check YouTube Connection.
		if ( ! adamson_archive_is_youtube_connected() ) {
			// This is a systemic error, don't increment attempts. Just return an error for the front-end.
			// The item remains 'pending' and will be retried on the next batch run.
			return new WP_Error( 'yt_auth', 'YouTube is not connected. Please connect your account and try again.' );
		}

		$client = adamson_archive_get_google_client();
		if ( is_wp_error( $client ) ) {
			// Systemic error, item remains pending.
			return $client;
		}

		// Defensively check for the required class to prevent a fatal error.
		if ( ! class_exists( 'Google_Service_YouTube' ) ) {
			return new WP_Error( 'google_lib_missing', 'Google API Client library is incomplete. The YouTube service class is missing. Please try reinstalling the theme dependencies.' );
		}

		$youtube = new Google_Service_YouTube( $client );

		// 4. Get Album Info (for Playlist).
		$album = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_albums WHERE id = %d", $data['album_id'] ) );
		if ( ! $album ) {
			throw new Exception( 'Album not found.' );
		}

		// Prepare a consistent playlist title.
		$playlist_title = $album->display_name;
		if ( ! empty( $album->album_date ) ) {
			$playlist_title = $album->album_date . ' - ' . $album->display_name;
		}

		$playlist_id = $album->yt_playlist_id;

		// 5. Create Playlist if missing.
		if ( empty( $playlist_id ) ) {
			$playlist_snippet = new Google_Service_YouTube_PlaylistSnippet();
			$playlist_snippet->setTitle( $playlist_title );
			$playlist_snippet->setDescription( 'Archived on ' . date( 'Y-m-d' ) );

			$playlist_status = new Google_Service_YouTube_PlaylistStatus();
			$playlist_status->setPrivacyStatus( 'private' ); // Default to private.

			$playlist = new Google_Service_YouTube_Playlist();
			$playlist->setSnippet( $playlist_snippet );
			$playlist->setStatus( $playlist_status );

			$playlist_response = $youtube->playlists->insert( 'snippet,status', $playlist );
			$playlist_id       = $playlist_response['id'];

			// Save to DB.
			$wpdb->update( $table_albums, array( 'yt_playlist_id' => $playlist_id ), array( 'id' => $album->id ) );
		}

		// 6. Upload Video.
		if ( ! file_exists( $data['file_path'] ) ) {
			throw new Exception( 'File not found: ' . esc_html( $data['file_path'] ) );
		}

		// Count existing videos in this album to create an indexed title.
		$video_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM $table_media WHERE album_id = %d AND file_type = 'video' AND yt_video_id IS NOT NULL AND yt_video_id <> ''",
				$album->id
			)
		);
		$video_title = $playlist_title . ' - Video ' . ( $video_count + 1 );

		$video_snippet = new Google_Service_YouTube_VideoSnippet();
		$video_snippet->setTitle( $video_title );
		$video_snippet->setDescription( 'Uploaded from Adamson Archive.' );

		$video_status = new Google_Service_YouTube_VideoStatus();
		$video_status->setPrivacyStatus( 'private' );

		$video = new Google_Service_YouTube_Video();
		$video->setSnippet( $video_snippet );
		$video->setStatus( $video_status );

		// Chunk size: 1MB.
		$chunk_size_bytes = 1 * 1024 * 1024;
		$client->setDefer( true );

		$insert_request = $youtube->videos->insert(
			'status,snippet',
			$video,
			array(
				'uploadType' => 'resumable',
			)
		);

		$media = new Google_Http_MediaFileUpload(
			$client,
			$insert_request,
			'video/*',
			null,
			true,
			$chunk_size_bytes
		);
		$media->setFileSize( filesize( $data['file_path'] ) );

		$status = false;
		$handle = fopen( $data['file_path'], 'rb' );
		while ( ! feof( $handle ) && false === $status ) {
			$chunk  = fread( $handle, $chunk_size_bytes );
			$status = $media->nextChunk( $chunk );
		}
		fclose( $handle );

		$client->setDefer( false );
		$video_id = $status['id'];

		// 7. Add to Playlist.
		$playlist_item_snippet = new Google_Service_YouTube_PlaylistItemSnippet();
		$playlist_item_snippet->setPlaylistId( $playlist_id );
		$playlist_item_snippet->setResourceId( new Google_Service_YouTube_ResourceId( array(
			'kind' => 'youtube#video',
			'videoId' => $video_id,
		) ) );
		$playlist_item = new Google_Service_YouTube_PlaylistItem();
		$playlist_item->setSnippet( $playlist_item_snippet );
		$youtube->playlistItems->insert( 'snippet', $playlist_item );

		// 8. Success: Update Media Table, Delete Local File, Update Queue.
		$wpdb->insert( $table_media, array( 'album_id' => $album->id, 'filename' => $data['filename'], 'file_path' => $data['file_path'], 'file_type' => 'video', 'yt_video_id' => $video_id ) );
		
		// Delete local file (Hybrid Storage).
		if ( ! $disable_delete ) {
			@unlink( $data['file_path'] );
		}

		// Mark item as completed
		$wpdb->update( $table_queue, array( 'status' => 'completed', 'message' => "Uploaded: $video_id" ), array( 'id' => $item->id ) );

		$remaining = $wpdb->get_var( "SELECT COUNT(id) FROM $table_queue WHERE status = 'pending'" );

		return array(
			'processed' => true,
			'message'   => "Uploaded {$data['filename']} to YouTube.",
			'remaining' => $remaining,
			'album_id'  => $album->id,
		);

	} catch ( Throwable $e ) { // Catch any throwable
		$error_message = 'Error processing item ' . $item->id . ': ' . $e->getMessage();
		$update_data = array(
			'message' => $error_message,
		);

		if ( $attempts_column_exists ) {
			$current_attempts = isset( $item->attempts ) ? (int) $item->attempts : 0;
			$update_data['attempts'] = $current_attempts + 1;
		} else {
			// Add a notice that the schema is out of date.
			$update_data['message'] .= ' (DB schema outdated)';
		}

		// Update the message and attempts, but leave status as 'pending'.
		$wpdb->update( $table_queue, $update_data, array( 'id' => $item->id ) );

		return new WP_Error( 'upload_failed', $error_message );
	}
}
