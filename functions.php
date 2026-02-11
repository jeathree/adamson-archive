<?php

    // Admin UI
    function adamson_archive_admin_page() {
        require_once get_stylesheet_directory() . '/admin-ui.php';
    }

    // Frontend Styles
    function child_enqueue_styles() {
        wp_enqueue_style('child-theme', get_stylesheet_directory_uri() . '/style.css', array(), 100);
    }
    add_action('wp_enqueue_scripts', 'child_enqueue_styles', 999);

    // Admin Styles
    add_action('admin_enqueue_scripts', function($hook) {
        if ($hook === 'toplevel_page_adamson-archive') {
            wp_enqueue_style(
                'child-theme-admin-styles',
                get_stylesheet_directory_uri() . '/style.css',
                array(),
                '1.0'
            );
        }
    });

    // Add The Adamson Archive admin page to dashboard menu
    add_action('admin_menu', function() {
        add_menu_page(
            'The Adamson Archive',
            'Adamson Archive',
            'manage_options',
            'adamson-archive',    
            'adamson_archive_admin_page',
            'dashicons-archive', 
            3          
        );
    });