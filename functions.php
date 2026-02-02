<?php

    // Include The Adamson Archive Admin Logic
    require_once get_stylesheet_directory() . '/archive-admin/admin.php';


    // Add Styles to Frontend
    function child_enqueue_styles() {
        wp_enqueue_style('child-theme', get_stylesheet_directory_uri() . '/style.css', array(), 100);
    }
    add_action('wp_enqueue_scripts', 'child_enqueue_styles', 999);

    // Add Styles to Admin (for custom admin pages)
    add_action('admin_enqueue_scripts', function() {
        wp_enqueue_style(
            'child-theme-admin-styles',
            get_stylesheet_directory_uri() . '/style.css',
            array(),
            '1.0'
        );
    });