<?php
// AJAX handler for polling scan progress
add_action('wp_ajax_adamson_archive_scan_progress', function() {
    $user_id = get_current_user_id();
    $progress_key = 'adamson_archive_scan_progress_' . $user_id;
    $progress = get_transient($progress_key);
    if (!$progress) $progress = [];
    wp_send_json_success(['progress' => $progress]);
});

// Utility to add a progress message (call this from sync.php)
function adamson_archive_add_progress($msg) {
    $user_id = get_current_user_id();
    $progress_key = 'adamson_archive_scan_progress_' . $user_id;
    $progress = get_transient($progress_key);
    if (!$progress) $progress = [];
    $progress[] = $msg;
    set_transient($progress_key, $progress, 60 * 10); // 10 min expiry
}

// Utility to clear progress (call at start/end of scan)
function adamson_archive_clear_progress() {
    $user_id = get_current_user_id();
    $progress_key = 'adamson_archive_scan_progress_' . $user_id;
    delete_transient($progress_key);
}
