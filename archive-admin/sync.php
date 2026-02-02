<?php

    require_once __DIR__ . '/progress.php';

/**
 * List all uploaded YouTube videos (returns array of titles and IDs)
 */
function adamson_youtube_list_uploaded_videos($access_token) {
    $videos = [];
    $pageToken = '';
    do {
        $endpoint = 'https://www.googleapis.com/youtube/v3/videos?part=snippet&mine=true&maxResults=50';
        if ($pageToken) $endpoint .= '&pageToken=' . $pageToken;
        $headers = [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json; charset=UTF-8'
        ];
        $response = wp_remote_get($endpoint, [ 'headers' => $headers ]);
        if (is_wp_error($response)) break;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['items'])) {
            foreach ($body['items'] as $item) {
                $videos[] = [
                    'id' => $item['id'],
                    'title' => $item['snippet']['title']
                ];
            }
        }
        $pageToken = isset($body['nextPageToken']) ? $body['nextPageToken'] : '';
    } while ($pageToken);
    return $videos;
}

/**
 * Adamson Archive Sync & Process Logic
 */

function adamson_archive_sync_and_process() {
    $uploads_dir = WP_CONTENT_DIR . '/uploads/albums/';
    adamson_archive_clear_progress();
    $access_token = get_youtube_access_token();
    if (!is_dir($uploads_dir)) {
        $progress[] = 'Albums directory not found: ' . $uploads_dir;
        return $progress;
    }

    $album_folders = array_filter(glob($uploads_dir . '*'), 'is_dir');
    global $wpdb;

    $any_processed = false;
    foreach ($album_folders as $album_path) {
        $album_folder = basename($album_path);
        $config_path = $album_path . '/config.json';
        adamson_archive_add_progress("Processing album: $album_folder");

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
            adamson_archive_add_progress("Created config.json for $album_folder");
        }

        // Check processed status in DB
        $existing_album = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM adamson_archive_albums WHERE folder = %s AND processed = 1",
            $album_folder
        ));
        if ($existing_album) {
            adamson_archive_add_progress("$album_folder already processed, skipping.");
            continue;
        }
        $any_processed = true;

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

        // Collect video files for YouTube upload
        $video_files = [];
        foreach ($media as $item) {
            $file = $item['file'];
            $ext = $item['ext'];
            if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                $video_files[] = $file;
            }
        }
        // YouTube: create playlist if there are videos
        $youtube_playlist_id = null;
        if ($access_token && count($video_files) > 0) {
            $playlist_title = $year . ' ' . $clean_name;
            $youtube_playlist_id = adamson_youtube_find_playlist($playlist_title, $access_token);
            if ($youtube_playlist_id) {
                adamson_archive_add_progress("Found existing YouTube playlist: $playlist_title ($youtube_playlist_id)");
            } else {
                $youtube_playlist_id = adamson_youtube_create_playlist($playlist_title, $access_token, 'unlisted');
                if ($youtube_playlist_id) {
                    adamson_archive_add_progress("Created YouTube playlist: $playlist_title ($youtube_playlist_id)");
                } else {
                    adamson_archive_add_progress("Failed to create YouTube playlist for $playlist_title.");
                }
            }
        }
        // YouTube: upload each video and add to playlist
        $video_db_rows = [];
        $video_count = 1;
        foreach ($video_files as $file) {
            $video_title = $year . ' ' . $clean_name . ' Video' . $video_count;
            $file_path = $album_path . '/' . $file;
            $youtube_id = null;
            // Check DB for existing YouTube ID for this file in this album
            $existing_video = $wpdb->get_row($wpdb->prepare(
                "SELECT youtube_id FROM adamson_archive_media WHERE filename = %s AND album_id = (
                    SELECT id FROM adamson_archive_albums WHERE folder = %s
                ) AND youtube_id IS NOT NULL AND youtube_id != ''",
                $file, $album_folder
            ));
            if ($existing_video && !empty($existing_video->youtube_id)) {
                adamson_archive_add_progress("Already uploaded to YouTube: $file ($existing_video->youtube_id), skipping.");
                $youtube_id = $existing_video->youtube_id;
            } elseif ($access_token) {
                $result = adamson_youtube_upload_video($file_path, $video_title, $access_token, 'unlisted');
                if (is_array($result) && isset($result['error'])) {
                    adamson_archive_add_progress("Failed to upload $file to YouTube: " . $result['error']);
                } elseif ($result) {
                    $youtube_id = $result;
                    adamson_archive_add_progress("Uploaded to YouTube: $file as $video_title ($youtube_id)");
                    // Add to playlist
                    if ($youtube_playlist_id) {
                        $added = adamson_youtube_add_video_to_playlist($youtube_id, $youtube_playlist_id, $access_token);
                        if ($added) {
                            adamson_archive_add_progress("Added $video_title to playlist.");
                        } else {
                            adamson_archive_add_progress("Failed to add $video_title to playlist.");
                        }
                    }
                } else {
                        adamson_archive_add_progress("Failed to upload $file to YouTube: Unknown error.");
                }
            } else {
                adamson_archive_add_progress("No YouTube access token. Skipping upload for $file.");
            }
            $video_db_rows[] = [
                'album_id' => 0, // Set below after album insert
                'filename' => $file,
                'type' => $ext,
                'youtube_id' => $youtube_id,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
            $video_count++;
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
        adamson_archive_add_progress("$album_folder processed and indexed.");
    }
    if (!$any_processed) {
        adamson_archive_add_progress('No new or updated albums found. Everything is up to date.');
    }
    // Only add SCAN COMPLETE! if not already present
    $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
    $progress_key = 'adamson_archive_scan_progress_' . $user_id;
    $progress = function_exists('get_transient') ? get_transient($progress_key) : [];
    if (!$progress || !in_array('SCAN COMPLETE!', $progress)) {
        adamson_archive_add_progress('SCAN COMPLETE!');
    }
    return true;
}

/**
 * Upload a video to YouTube and return the video ID
 */
function adamson_youtube_upload_video($file_path, $title, $access_token, $privacy = 'unlisted', $description = '') {
    $endpoint = 'https://www.googleapis.com/upload/youtube/v3/videos?part=snippet,status&uploadType=resumable';
    $metadata = [
        'snippet' => [
            'title' => $title,
            'description' => $description,
        ],
        'status' => [
            'privacyStatus' => $privacy
        ]
    ];
    $headers = [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json; charset=UTF-8'
    ];
    // Step 1: Initiate resumable upload
    $init = wp_remote_post($endpoint, [
        'headers' => $headers,
        'body' => json_encode($metadata),
        'method' => 'POST',
    ]);
    if (is_wp_error($init)) return ['error' => $init->get_error_message()];
    $location = wp_remote_retrieve_header($init, 'location');
    if (!$location) return ['error' => 'No upload location returned by YouTube.'];
    // Step 2: Upload video file
    $video_data = file_get_contents($file_path);
    $mime_type = mime_content_type($file_path);
    if (!$mime_type) $mime_type = 'video/mp4';
    $upload = wp_remote_request($location, [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Length' => strlen($video_data),
            'Content-Type' => $mime_type,
        ],
        'body' => $video_data,
        'method' => 'PUT',
    ]);
    if (is_wp_error($upload)) return ['error' => $upload->get_error_message()];
    $body = json_decode(wp_remote_retrieve_body($upload), true);
    if (isset($body['id'])) return $body['id'];
    return ['error' => isset($body['error']['message']) ? $body['error']['message'] : 'Unknown upload error.'];
}

/**
 * Create a YouTube playlist and return the playlist ID
 */
function adamson_youtube_create_playlist($title, $access_token, $privacy = 'unlisted', $description = '') {
    $endpoint = 'https://www.googleapis.com/youtube/v3/playlists?part=snippet,status';
    $data = [
        'snippet' => [
            'title' => $title,
            'description' => $description,
        ],
        'status' => [
            'privacyStatus' => $privacy
        ]
    ];
    $headers = [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json; charset=UTF-8'
    ];
    $response = wp_remote_post($endpoint, [
        'headers' => $headers,
        'body' => json_encode($data),
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['id'] ?? false;
}

/**
 * Add a video to a YouTube playlist
 */
function adamson_youtube_add_video_to_playlist($video_id, $playlist_id, $access_token) {
    $endpoint = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet';
    $data = [
        'snippet' => [
            'playlistId' => $playlist_id,
            'resourceId' => [
                'kind' => 'youtube#video',
                'videoId' => $video_id
            ]
        ]
    ];
    $headers = [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json; charset=UTF-8'
    ];
    $response = wp_remote_post($endpoint, [
        'headers' => $headers,
        'body' => json_encode($data),
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($body['id']);
}

/**
 * Find a YouTube playlist by title and return its ID
 */
function adamson_youtube_find_playlist($title, $access_token) {
    $endpoint = 'https://www.googleapis.com/youtube/v3/playlists?part=snippet&mine=true&maxResults=50';
    $headers = [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json; charset=UTF-8'
    ];
    $response = wp_remote_get($endpoint, [
        'headers' => $headers,
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['items'])) {
        foreach ($body['items'] as $item) {
            if (isset($item['snippet']['title']) && $item['snippet']['title'] === $title) {
                return $item['id'];
            }
        }
    }
    return false;
}

// Get YouTube access token
function get_youtube_access_token() {
    return get_option('adamson_youtube_access_token');
}