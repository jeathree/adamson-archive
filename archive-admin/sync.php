<?php
/**
 * Adamson Archive Sync & Process Logic
 */

function adamson_archive_sync_and_process() {
    $uploads_dir = WP_CONTENT_DIR . '/uploads/albums/';
    $progress = [];
    if (!is_dir($uploads_dir)) {
        $progress[] = 'Albums directory not found: ' . $uploads_dir;
        return $progress;
    }

    $album_folders = array_filter(glob($uploads_dir . '*'), 'is_dir');
    global $wpdb;

    foreach ($album_folders as $album_path) {
        $album_folder = basename($album_path);
        $config_path = $album_path . '/config.json';
        $progress[] = "Processing album: $album_folder";

        // Load or create config.json
        if (file_exists($config_path)) {
            $config = json_decode(file_get_contents($config_path), true);
            if (!$config) $config = [];
            $raw_name = isset($config['name']) ? $config['name'] : $album_folder;
            // Parse year and date from folder name if missing in config
            if (empty($config['year']) || empty($config['date'])) {
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $album_folder, $m)) {
                    $parsed_year = $m[1];
                    $parsed_date = $m[1] . '-' . $m[2] . '-' . $m[3];
                } else {
                    $parsed_year = date('Y');
                    $parsed_date = date('Y-m-d');
                }
                $year = !empty($config['year']) ? $config['year'] : $parsed_year;
                $date = !empty($config['date']) ? $config['date'] : $parsed_date;
            } else {
                $year = $config['year'];
                $date = $config['date'];
            }
            $visible = isset($config['visible']) ? $config['visible'] : true;
        } else {
            $raw_name = $album_folder;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $album_folder, $m)) {
                $year = $m[1];
                $date = $m[1] . '-' . $m[2] . '-' . $m[3];
            } else {
                $year = date('Y');
                $date = date('Y-m-d');
            }
            $visible = true;
        }
        // Clean the album name: remove date, all leading/trailing dashes/spaces
        $clean_name = preg_replace('/^\d{4}-\d{2}-\d{2}[\s-]*/', '', $raw_name);
        $clean_name = preg_replace('/^[-\s]+/', '', $clean_name);
        $clean_name = preg_replace('/[-\s]+$/', '', $clean_name);
        // Always update config.json with the cleaned name and all values
        $config = [
            'name' => $clean_name,
            'year' => $year,
            'date' => $date,
            'visible' => $visible
        ];
        file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT));
        if (!file_exists($config_path)) {
            $progress[] = "Created config.json for $album_folder";
        }

        // Check processed status in DB
        $existing_album = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM adamson_archive_albums WHERE folder = %s AND processed = 1",
            $album_folder
        ));
        if ($existing_album) {
            $progress[] = "$album_folder already processed, skipping.";
            continue;
        }

        // Scan for media files
        $media_files = array_diff(scandir($album_path), ['.', '..', 'config.json']);
        $media = [];
        foreach ($media_files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $media[] = [
                'file' => $file,
                'ext' => $ext
            ];
        }

        // YouTube upload & playlist (placeholder, implement later)
        $youtube_playlist_id = null;
        $video_db_rows = [];
        $album_media_rows = [];
        $video_files = [];
        foreach ($media as $item) {
            $file = $item['file'];
            $ext = $item['ext'];
            // Placeholder: treat any file with a common video extension as a video for YouTube logic, otherwise as generic media
            if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                $youtube_id = 'yt_' . md5($file . time());
                $progress[] = "Simulated upload: $file as $youtube_id";
                // @unlink($album_path . '/' . $file); // NOTE - Commented out for testing
                // Remove matching image (e.g., videoName.jpg)
                $img_match = preg_replace('/\.[^.]+$/i', '.jpg', $file); // case-insensitive extension replacement
                foreach ($media as $img_item) {
                    if (strcasecmp($img_item['file'], $img_match) === 0) {
                        @unlink($album_path . '/' . $img_item['file']);
                        $progress[] = "Deleted matching image: {$img_item['file']}";
                    }
                }
                $video_db_rows[] = [
                    'album_id' => 0, // Set below after album insert
                    'filename' => $file,
                    'type' => $ext,
                    'youtube_id' => $youtube_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];
                $video_files[] = $file;
            } else {
                $album_media_rows[] = [
                    'filename' => $file,
                    'type' => $ext,
                    'youtube_id' => null,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];
            }
        }

        // Insert album into DB
        $now = current_time('mysql');
        $wpdb->insert('adamson_archive_albums', [
            'name' => $clean_name,
            'year' => $year,
            'date' => $date,
            'folder' => $album_folder,
            'visible' => $visible ? 1 : 0,
            'date_created' => $now,
            'date_updated' => $now,
            'processed' => 0
        ]);
        $album_id = $wpdb->insert_id;

        // Insert all media into DB
        foreach ($album_media_rows as $row) {
            $row['album_id'] = $album_id;
            $wpdb->insert('adamson_archive_media', $row);
        }
        foreach ($video_db_rows as $row) {
            $row['album_id'] = $album_id;
            $wpdb->insert('adamson_archive_media', $row);
        }

        // Mark album as processed in DB
        $wpdb->update('adamson_archive_albums', ['processed' => 1], ['id' => $album_id]);
        $progress[] = "$album_folder processed and indexed.";
    }
    return $progress;
}