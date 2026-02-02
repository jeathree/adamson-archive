<?php
    require_once(__DIR__ . '/sync.php');

// Adamson Archive Admin Page
function adamson_archive_admin_menu() {
    add_menu_page(
        'Adamson Archive',
        'Adamson Archive',
        'manage_options',
        'adamson-archive',
        'adamson_archive_admin_page',
        'dashicons-images-alt2',
        25
    );
}
add_action('admin_menu', 'adamson_archive_admin_menu');

function adamson_archive_admin_page() {
    echo '<div class="wrap"><h1>The Adamson Archive</h1>';


    // Scan & Process Albums button
    echo '<form method="post" style="display:inline-block;margin-right:10px;">';
    echo '<input type="hidden" name="adamson_archive_scan" value="1">';
    echo '<button type="submit" class="button button-primary">Scan & Process Albums</button>';
    echo '</form>';

    // Delete All Albums button
    echo '<form method="post" style="display:inline-block;">';
    echo '<input type="hidden" name="adamson_archive_delete_all" value="1">';
    echo '<button type="submit" class="button button-danger" onclick="return confirm(\'Are you sure you want to delete all albums and media? This cannot be undone.\')">Delete All Albums</button>';
    echo '</form>';

    // Albums Table
    global $wpdb;
    // Handle sorting
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_created';
    $order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';
    $validSorts = ['name','year','visible','processed','images','videos','date_created','date_updated'];
    if (!in_array($sort, $validSorts)) $sort = 'date_created';

    // Handle search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchSql = $search ? $wpdb->prepare("WHERE name LIKE %s OR year LIKE %s", "%$search%", "%$search%") : '';

    // Handle pagination (load more)
    $per_page = 25;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $albums = $wpdb->get_results("SELECT * FROM adamson_archive_albums $searchSql ORDER BY $sort $order LIMIT $per_page OFFSET $offset");
    $total_albums = $wpdb->get_var("SELECT COUNT(*) FROM adamson_archive_albums $searchSql");
    echo '<h2 style="margin-top:30px;">Albums</h2>';
    echo '<form method="get" style="margin-bottom:10px;">';
    echo '<input type="text" name="search" value="' . esc_attr($search) . '" placeholder="Search albums..." /> ';
    echo '<input type="submit" class="button" value="Search" />';
    // Preserve sort/order in search
    echo '<input type="hidden" name="sort" value="' . esc_attr($sort) . '" />';
    echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
    echo '</form>';
    if ($albums) {
        echo '<table class="wp-list-table widefat fixed striped" style="max-width:1000px;">';
        echo '<thead><tr>';
        $columns = [
            'name' => 'Name',
            'year' => 'Year',
            'visible' => 'Visible',
            'processed' => 'Processed',
            'images' => 'Images',
            'videos' => 'Videos',
            'date_created' => 'Date Created',
            'actions' => 'Actions'
        ];
        foreach ($columns as $col => $label) {
            if ($col === 'actions') {
                echo '<th>' . $label . '</th>';
                continue;
            }
            $newOrder = ($sort === $col && $order === 'ASC') ? 'desc' : 'asc';
            $url = add_query_arg(['sort' => $col, 'order' => $newOrder, 'search' => $search, 'offset' => $offset]);
            $arrow = '';
            if ($sort === $col) {
                $arrow = $order === 'ASC' ? ' &#9650;' : ' &#9660;'; // up or down arrow
            }
            echo '<th><a href="' . esc_url($url) . '">' . $label . $arrow . '</a></th>';
        }
        echo '</tr></thead><tbody>';
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
            echo '<tr>';
            echo '<td>' . esc_html($album->name) . '</td>';
            echo '<td>' . esc_html($album->year ?? '') . '</td>';
            echo '<td>' . (isset($album->visible) && $album->visible ? 'Yes' : 'No') . '</td>';
            echo '<td>' . ($album->processed ? 'Yes' : 'No') . '</td>';
            echo '<td>' . $image_count . '</td>';
            echo '<td>' . $video_count . '</td>';
            echo '<td>' . esc_html($album->date_created ?? '') . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="adamson_archive_reprocess_album" value="' . esc_attr($album->id) . '">';
            echo '<button type="submit" class="button">Reprocess</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Load More button
        if ($offset + $per_page < $total_albums) {
            $next_offset = $offset + $per_page;
            $url = add_query_arg([
                'sort' => $sort,
                'order' => $order,
                'search' => $search,
                'offset' => $next_offset
            ]);
            echo '<form method="get" style="margin-top:10px;">';
            echo '<input type="hidden" name="sort" value="' . esc_attr($sort) . '" />';
            echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
            echo '<input type="hidden" name="search" value="' . esc_attr($search) . '" />';
            echo '<input type="hidden" name="offset" value="' . esc_attr($next_offset) . '" />';
            echo '<button type="submit" class="button">Load More</button>';
            echo '</form>';
        }
    }

    if (isset($_POST['adamson_archive_scan'])) {
        $progress = adamson_archive_sync_and_process();
        echo '<div style="margin-top:20px;padding:10px;border:1px solid #ccc;background:#fafafa;max-width:600px;">';
        echo '<strong>Sync & Process Progress:</strong><ul style="margin:0 0 0 20px;">';
        foreach ($progress as $msg) {
            echo '<li>' . esc_html($msg) . '</li>';
        }
        echo '</ul></div>';
    }

    if (isset($_POST['adamson_archive_delete_all'])) {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE adamson_archive_media');
        $wpdb->query('TRUNCATE TABLE adamson_archive_albums');
        echo '<div style="margin-top:20px;padding:10px;border:1px solid #f00;background:#fee;max-width:600px;">';
        echo '<strong>All albums and media have been deleted.</strong>';
        echo '</div>';
    }
    echo '</div>';
}

