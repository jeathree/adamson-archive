<?php 

// Utility to get a valid YouTube access token, refreshing if needed
if (!function_exists('adamson_get_valid_youtube_access_token')) {
    function adamson_get_valid_youtube_access_token() {
        $access_token = get_option('adamson_youtube_access_token');
        $refresh_token = get_option('adamson_youtube_refresh_token');
        $client_id = get_option('adamson_youtube_client_id');
        $client_secret = get_option('adamson_youtube_client_secret');
        $token_info = get_option('adamson_youtube_token_info'); // stores ['expires_at'=>timestamp]

        // If no access token, return false
        if (!$access_token) return false;

        // If we have expiry info, check if token is expired (with 2 min buffer)
        if ($token_info && isset($token_info['expires_at']) && time() < $token_info['expires_at'] - 120) {
            return $access_token;
        }

        // If we have a refresh token, try to refresh
        if ($refresh_token && $client_id && $client_secret) {
            $response = wp_remote_post('https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type' => 'refresh_token',
                ]
            ]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['access_token'])) {
                    update_option('adamson_youtube_access_token', $body['access_token']);
                    // Save new expiry if provided
                    if (!empty($body['expires_in'])) {
                        update_option('adamson_youtube_token_info', [
                            'expires_at' => time() + intval($body['expires_in'])
                        ]);
                    }
                    return $body['access_token'];
                }
            }
        }
        // Fallback: return current token (may be expired)
        return $access_token;
    }
}

// Adamson Archive YouTube Settings Page

function adamson_archive_youtube_settings_page() {
    // --- YouTube Token/Auth Status ---
    $client_id = get_option('adamson_youtube_client_id');
    $client_secret = get_option('adamson_youtube_client_secret');
    $redirect_uri = get_option('adamson_youtube_redirect_uri');
    $access_token = get_option('adamson_youtube_access_token');
    $refresh_token = get_option('adamson_youtube_refresh_token');
    $token_info = get_option('adamson_youtube_token_info');
            $status_rows = [];
            $check = '<span style="color: #27ae60; font-size: 18px; font-weight: bold;">&#10004;</span>';
            $cross = '<span style="color: #c0392b; font-size: 18px; font-weight: bold;">&#10006;</span>';
            $status_rows[] = '<tr><th>Client ID</th><td>' . ($client_id ? $check : $cross) . '</td></tr>';
            $status_rows[] = '<tr><th>Client Secret</th><td>' . ($client_secret ? $check : $cross) . '</td></tr>';
            $status_rows[] = '<tr><th>Redirect URI</th><td>' . ($redirect_uri ? $check : $cross) . '</td></tr>';
            $status_rows[] = '<tr><th>Access Token</th><td>' . ($access_token ? $check : $cross);
            if ($access_token && $token_info && isset($token_info['expires_at'])) {
                $expires_in = $token_info['expires_at'] - time();
                $status_rows[count($status_rows)-1] .= ' (expires in ' . max(0, intval($expires_in/60)) . ' min)';
            }
            $status_rows[count($status_rows)-1] .= '</td></tr>';
            $status_rows[] = '<tr><th>Refresh Token</th><td>' . ($refresh_token ? $check : $cross) . '</td></tr>';
            echo '<h2>YouTube Auth Status</h2>';
            echo '<table class="form-table" style="max-width:400px;">' . implode('', $status_rows) . '</table>';
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
                    // Store expiry info for refresh logic
                    if (!empty($body['expires_in'])) {
                        update_option('adamson_youtube_token_info', [
                            'expires_at' => time() + intval($body['expires_in'])
                        ]);
                    }
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