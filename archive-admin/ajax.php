<?php

// AJAX: Return media list for an album (with failed status)
add_action('wp_ajax_adamson_archive_album_media', function() {
    global $wpdb;
    $album_id = intval($_POST['album_id'] ?? 0);
    $media = $wpdb->get_results($wpdb->prepare("SELECT id, filename, youtube_id, type FROM adamson_archive_media WHERE album_id = %d ORDER BY filename", $album_id));
    $result = [];
    foreach ($media as $m) {
        $failed = ($m->type === 'mp4' || $m->type === 'mov' || $m->type === 'avi' || $m->type === 'mkv' || $m->type === 'webm') && (empty($m->youtube_id));
        $result[] = [
            'id' => $m->id,
            'filename' => $m->filename,
            'failed' => $failed,
        ];
    }
    wp_send_json_success(['media' => $result]);
});


// AJAX handler for summary dashboard
add_action('wp_ajax_adamson_archive_summary', function() {
    global $wpdb;
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums");
    $processed = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 1");
    $failed = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 0 AND id IN (SELECT album_id FROM adamson_archive_media WHERE type IN ('mp4','mov','avi','mkv','webm') AND (youtube_id IS NULL OR youtube_id = ''))");
    $pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums WHERE processed = 0 AND id NOT IN (SELECT album_id FROM adamson_archive_media WHERE type IN ('mp4','mov','avi','mkv','webm') AND (youtube_id IS NULL OR youtube_id = ''))");
    $images = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_media WHERE type IN ('jpg','jpeg','png','gif','bmp')");
    $videos = (int)$wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_media WHERE type IN ('mp4','mov','avi','mkv','webm') AND youtube_id IS NOT NULL AND youtube_id != ''");
    $html = '<div id="adamson-archive-summary">';
    $html .= '<strong>Media Overview:</strong>';
    $html .= '<span class="adamson-summary-total adamson-summary-tooltip" data-tip="Total number of albums in the archive.">Albums: <b>' . $total . '</b></span>';
    $html .= '<span class="adamson-summary-processed adamson-summary-tooltip" data-tip="Albums where all YouTube uploads and playlist actions succeeded (fully complete).">Processed: <b>' . $processed . '</b></span>';
    $html .= '<span class="adamson-summary-failed adamson-summary-tooltip" data-tip="Albums that have at least one video or playlist that failed to upload to YouTube. These albums are not fully processed and will be retried.">Failed: <b>' . $failed . '</b></span>';
    $html .= '<span class="adamson-summary-pending adamson-summary-tooltip" data-tip="Albums that are not yet processed and have no failed YouTube uploads—typically new albums waiting to be processed, or albums with only non-video media.">Pending: <b>' . $pending . '</b></span>';
    $html .= '<span class="adamson-summary-images adamson-summary-tooltip" data-tip="Total number of images in the archive (jpg, jpeg, png, gif, bmp).">Images: <b>' . $images . '</b></span>';
    $html .= '<span class="adamson-summary-videos adamson-summary-tooltip" data-tip="Total number of videos in the archive (mp4, mov, avi, mkv, webm) with a YouTube ID.">Videos: <b>' . $videos . '</b></span>';
    $html .= '</div>';
    wp_send_json_success(['html' => $html]);
});

// AJAX handler for scan & process
    add_action('wp_ajax_adamson_archive_scan', function() {
        require_once(__DIR__ . '/sync.php');
        adamson_archive_sync_and_process();
        wp_send_json_success(['started' => true]);
    });

    // AJAX handler for admin album search
    add_action('wp_ajax_adamson_archive_search_albums', function() {
        global $wpdb;
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';
        $sort = isset($_POST['sort']) ? $_POST['sort'] : 'date_created';
        $order = isset($_POST['order']) && strtolower($_POST['order']) === 'asc' ? 'ASC' : 'DESC';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $per_page = 5;
        // Support custom row_count for preserving loaded rows after sort
        if (isset($_POST['row_count']) && intval($_POST['row_count']) > 0) {
            $per_page = intval($_POST['row_count']);
        }
        $validSorts = ['name','year','visible','processed','images','videos','date_created','date_updated'];
        if (!in_array($sort, $validSorts)) $sort = 'date_created';
        $searchSql = $search ? $wpdb->prepare("WHERE name LIKE %s OR year LIKE %s", "%$search%", "%$search%") : '';
        $albums = $wpdb->get_results("SELECT * FROM adamson_archive_albums $searchSql ORDER BY $sort $order LIMIT $per_page OFFSET $offset");
        $total_albums = $wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums $searchSql");
        $rows = [];
        foreach ($albums as $album) {
            $media = $wpdb->get_results($wpdb->prepare("SELECT type, youtube_id FROM adamson_archive_media WHERE album_id = %d", $album->id));
            $image_count = 0;
            $video_count = 0;
            foreach ($media as $m) {
                if (isset($m->youtube_id) && $m->youtube_id) {
                    $video_count++;
                } else {
                    $image_count++;
                }
            }
            // Format date_created as MM/DD/YYYY
            $date_created = '';
            if (!empty($album->date_created)) {
                $ts = strtotime($album->date_created);
                if ($ts) {
                    $date_created = date('m/d/Y', $ts);
                }
            }
            $rows[] = [
                'name' => $album->name,
                'year' => $album->year,
                'visible' => $album->visible ? 'Yes' : 'No',
                'processed' => $album->processed ? 'Yes' : 'No',
                'images' => $image_count,
                'videos' => $video_count,
                'date_created' => $date_created,
                'id' => $album->id
            ];
        }
        wp_send_json([
            'rows' => $rows,
            'total' => $total_albums
        ]);
        wp_die();
    });