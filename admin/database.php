<?php
/**
 * Adamson Archive: Database
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Creates or updates the custom database tables.
 */
function adamson_archive_create_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	// 1. Albums Table
	$table_albums = 'adamson_archive_albums';
	$sql_albums   = "CREATE TABLE $table_albums (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		folder_name varchar(255) NOT NULL,
		display_name varchar(255) NOT NULL,
		album_date date DEFAULT NULL,
		path text NOT NULL,
		yt_playlist_id varchar(100) DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY folder_name (folder_name)
	) $charset_collate;";

	// 2. Media Table
	$table_media = 'adamson_archive_media';
	$sql_media   = "CREATE TABLE $table_media (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		album_id mediumint(9) NOT NULL,
		filename varchar(255) NOT NULL,
		file_path text NOT NULL,
		file_type varchar(20) NOT NULL,
		yt_video_id varchar(100) DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY album_id (album_id)
	) $charset_collate;";

	// 3. Queue Table
	$table_queue = 'adamson_archive_queue';
	$sql_queue   = "CREATE TABLE $table_queue (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		task_type varchar(50) NOT NULL,
		data longtext NOT NULL,
		file_hash varchar(32) DEFAULT NULL,
		status varchar(20) DEFAULT 'pending',
		message text DEFAULT NULL,
		attempts tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY file_hash (file_hash)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_albums );
	dbDelta( $sql_media );
	dbDelta( $sql_queue );

	update_option( 'adamson_archive_db_setup_complete', true );
}
