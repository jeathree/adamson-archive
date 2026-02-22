<?php
/**
 * Adamson Archive: Scanner
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scans the target directory for album folders.
 *
 * It attempts to parse 'YYYY-MM-DD - [Album Name]' to extract metadata,
 * but any folder is considered a potential album.
 *
 * @return array An array of associative arrays, each representing an album.
 */
function adamson_archive_scan_album_directories() {
	$upload_dir   = wp_upload_dir();
	$albums_path  = trailingslashit( $upload_dir['basedir'] ) . 'albums';
	$found_albums = array();

	// Ensure the main 'albums' directory exists.
	if ( ! is_dir( $albums_path ) ) {
		// In the future, we could create this or show a notice. For now, return empty.
		return $found_albums;
	}

	$folders = new DirectoryIterator( $albums_path );

	foreach ( $folders as $fileinfo ) {
		if ( $fileinfo->isDir() && ! $fileinfo->isDot() ) {
			$filename   = $fileinfo->getFilename();
			$album_data = array(
				'path'        => $fileinfo->getPathname(),
				'source_name' => $filename,
				'album_name'  => '',
				'album_date'  => null,
			);

			// Regex to match 'YYYY-MM-DD - [Anything]' and capture parts.
			$pattern = '/^(\d{4}-\d{2}-\d{2}) - (.+)$/';

			if ( preg_match( $pattern, $filename, $matches ) ) {
				// We have a date and a name from the folder.
				$album_data['album_date'] = $matches[1];
				$album_data['album_name'] = $matches[2];
			} else {
				// No match, so use the folder name as the album name.
				$album_data['album_name'] = $filename;
			}

			$found_albums[] = $album_data;
		}
	}

	return $found_albums;
}

/**
 * Scans all album directories and returns a flat array of all media files found.
 *
 * @return array A flat array of media file data.
 */
function adamson_archive_get_all_media_files() {
	$albums      = adamson_archive_scan_album_directories();
	$all_files   = array();
	$allowed_ext = array(
		// Images
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'bmp',
		// Videos
		'mov',
		'mp4',
		'avi',
		'mkv',
	);

	foreach ( $albums as $album ) {
		$files = new DirectoryIterator( $album['path'] );

		foreach ( $files as $fileinfo ) {
			if ( $fileinfo->isFile() && ! $fileinfo->isDot() ) {
				$extension = strtolower( $fileinfo->getExtension() );

				if ( in_array( $extension, $allowed_ext, true ) ) {
					$all_files[] = array(
						'album_source_name' => $album['source_name'],
						'album_name'        => $album['album_name'],
						'album_date'        => $album['album_date'],
						'file_path'         => $fileinfo->getPathname(),
						'file_name'         => $fileinfo->getFilename(),
						'file_type'         => in_array( $extension, array( 'mov', 'mp4', 'avi', 'mkv' ) ) ? 'video' : 'photo',
					);
				}
			}
		}
	}

	return $all_files;
}
