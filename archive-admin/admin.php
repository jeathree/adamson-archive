<?php

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
    echo '<form method="post">';
    echo '<input type="hidden" name="adamson_archive_scan" value="1">';
    echo '<button type="submit" class="button button-primary">Scan & Process Albums</button>';
    echo '</form>';

    if (isset($_POST['adamson_archive_scan'])) {
        adamson_archive_scan_albums();
    }
    echo '</div>';
}

function adamson_archive_scan_albums() {
    echo '<p>Scanning albums in /uploads/albums/...</p>';
    // TODO: Implement scan and DB insert logic here
}
