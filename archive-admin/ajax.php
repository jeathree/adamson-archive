<?php
// AJAX handler for admin album search
add_action('wp_ajax_adamson_archive_search_albums', function() {
    global $wpdb;
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    $sort = isset($_POST['sort']) ? $_POST['sort'] : 'date_created';
    $order = isset($_POST['order']) && strtolower($_POST['order']) === 'asc' ? 'ASC' : 'DESC';
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $per_page = 2;
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
        $rows[] = [
            'name' => $album->name,
            'year' => $album->year,
            'visible' => $album->visible ? 'Yes' : 'No',
            'processed' => $album->processed ? 'Yes' : 'No',
            'images' => $image_count,
            'videos' => $video_count,
            'date_created' => $album->date_created,
            'id' => $album->id
        ];
    }
    wp_send_json([
        'rows' => $rows,
        'total' => $total_albums
    ]);
    wp_die();
});
