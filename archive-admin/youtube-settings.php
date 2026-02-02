<?php
// Adamson Archive YouTube Settings Page

function adamson_archive_youtube_settings_page() {
    ?>
    <div class="wrap">
        <h1>YouTube Integration Settings</h1>
        <?php
        $client_id = get_option('adamson_youtube_client_id');
        $client_secret = get_option('adamson_youtube_client_secret');
        $redirect_uri = get_option('adamson_youtube_redirect_uri');
        // Handle OAuth callback
        if (isset($_GET['code']) && $client_id && $client_secret && $redirect_uri) {
            $code = sanitize_text_field($_GET['code']);
            $token_url = 'https://oauth2.googleapis.com/token';
            $response = wp_remote_post($token_url, [
                'body' => [
                    'code' => $code,
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'redirect_uri' => $redirect_uri,
                    'grant_type' => 'authorization_code'
                ]
            ]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['access_token'])) {
                    update_option('adamson_youtube_access_token', $body['access_token']);
                    if (!empty($body['refresh_token'])) {
                        update_option('adamson_youtube_refresh_token', $body['refresh_token']);
                    }
                    echo '<div class="notice notice-success"><p><strong>You are now connected to YouTube!</strong></p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to retrieve access token from YouTube.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>OAuth request failed: ' . esc_html($response->get_error_message()) . '</p></div>';
            }
        }
        $access_token = get_option('adamson_youtube_access_token');
        $is_connected = !empty($access_token);
        if ($is_connected) {
            echo '<div class="notice notice-success"><p><strong>You are connected to YouTube!</strong></p></div>';
        }
        // Always show the auth button if credentials are present
        $auth_button_html = '';
        if ($client_id && $client_secret && $redirect_uri) {
            $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
                . '&client_id=' . urlencode($client_id)
                . '&redirect_uri=' . urlencode($redirect_uri)
                . '&scope=' . urlencode('https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube')
                . '&access_type=offline'
                . '&prompt=consent';
            $auth_button_html = '<a href="' . esc_url($auth_url) . '" class="button button-primary" style="margin-left:10px;vertical-align:middle;">Connect to YouTube</a>';
        }
        ?>
        <form method="post" action="options.php" style="display:inline-block;">
            <?php settings_fields('adamson_archive_youtube'); ?>
            <?php do_settings_sections('adamson-archive-youtube-settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="adamson_youtube_client_id">Client ID</label></th>
                    <td><input type="text" id="adamson_youtube_client_id" name="adamson_youtube_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="adamson_youtube_client_secret">Client Secret</label></th>
                    <td><input type="text" id="adamson_youtube_client_secret" name="adamson_youtube_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="adamson_youtube_redirect_uri">Redirect URI</label></th>
                    <td><input type="text" id="adamson_youtube_redirect_uri" name="adamson_youtube_redirect_uri" value="<?php echo esc_attr($redirect_uri); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <div style="display:flex;align-items:center;gap:10px;">
                <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
                <?php echo $auth_button_html; ?>
            </div>
        </form>
    </div>
    <?php
}

add_action('admin_init', function() {
    register_setting('adamson_archive_youtube', 'adamson_youtube_client_id');
    register_setting('adamson_archive_youtube', 'adamson_youtube_client_secret');
    register_setting('adamson_archive_youtube', 'adamson_youtube_redirect_uri');
});
