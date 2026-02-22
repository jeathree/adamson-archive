<?php
/**
 * Adamson Archive: YouTube OAuth 2.0 and API Integration
 *
 * @package TheAdamsonArchive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gets the Google API client, configured for the YouTube API.
 *
 * This function is the central point for interacting with the Google Client Library.
 * It retrieves the API key and client secret from `wp-config.php`, sets up the
 * redirect URI, and configures the necessary scopes for YouTube API access.
 *
 * It will return a WP_Error if the required constants are not defined in wp-config.php.
 *
 * @return Google_Client|WP_Error The configured Google client or a WP_Error on failure.
 */
function adamson_archive_get_google_client() {
	if ( ! defined( 'ADAMSON_ARCHIVE_GOOGLE_API_KEY' ) || ! defined( 'ADAMSON_ARCHIVE_GOOGLE_CLIENT_SECRET' ) ) {
		return new WP_Error(
			'missing_keys',
			__( 'Google API key and client secret are not defined in wp-config.php.', 'the-adamson-archive' )
		);
	}

	$client = new Google_Client();
	$client->setApplicationName( 'The Adamson Archive' );
	$client->setDeveloperKey( ADAMSON_ARCHIVE_GOOGLE_API_KEY );
	$client->setClientId( ADAMSON_ARCHIVE_GOOGLE_CLIENT_ID );
	$client->setClientSecret( ADAMSON_ARCHIVE_GOOGLE_CLIENT_SECRET );
	$client->setRedirectUri( admin_url( 'admin.php?page=adamson-archive' ) );
	$client->setScopes(
		array(
			'https://www.googleapis.com/auth/youtube.upload',
			'https://www.googleapis.com/auth/youtube',
		)
	);
	$client->setAccessType( 'offline' );
	$client->setPrompt( 'select_account consent' );

	// Use a nonce for the state token to prevent CSRF.
	$state_nonce = wp_create_nonce( 'adamson_archive_youtube_oauth_state' );
	$client->setState( $state_nonce );
	set_transient( 'adamson_archive_youtube_oauth_state', $state_nonce, HOUR_IN_SECONDS );

	// Load the stored access token if it exists.
	$access_token = get_option( 'adamson_archive_youtube_access_token' );
	if ( ! empty( $access_token ) ) {
		$client->setAccessToken( $access_token );
	}

	// Refresh the token if it's expired.
	if ( $client->isAccessTokenExpired() ) {
		$refresh_token = get_option( 'adamson_archive_youtube_refresh_token' );
		if ( ! empty( $refresh_token ) ) {
			$client->fetchAccessTokenWithRefreshToken( $refresh_token );
			$new_access_token = $client->getAccessToken();
			if ( ! empty( $new_access_token ) ) {
				update_option( 'adamson_archive_youtube_access_token', $new_access_token );
			}
		}
	}

	return $client;
}

/**
 * Checks if the site is connected to a YouTube account.
 *
 * @return bool True if a refresh token exists, false otherwise.
 */
function adamson_archive_is_youtube_connected() {
	return (bool) get_option( 'adamson_archive_youtube_refresh_token' );
}

/**
 * Handles the OAuth 2.0 callback from Google.
 *
 * When the user authorizes the application, Google redirects them back to this action.
 * The function validates the state, exchanges the authorization code for an access token,
 * and securely stores the access and refresh tokens in the `wp_options` table.
 */
function adamson_archive_handle_oauth_callback() {
	// Check if this is the OAuth callback from Google.
	if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) || ! isset( $_GET['page'] ) || 'adamson-archive' !== $_GET['page'] ) {
		return;
	}

	// Verify the state nonce.
	$state_nonce = get_transient( 'adamson_archive_youtube_oauth_state' );
	if ( ! $state_nonce || ! hash_equals( $state_nonce, sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) ) {
		add_settings_error(
			'adamson_archive_messages',
			'oauth_state_mismatch',
			__( 'Invalid state token. The authorization request could not be validated. Please try again.', 'the-adamson-archive' ),
			'error'
		);
		return;
	}

	// The state is valid, so we can delete the transient.
	delete_transient( 'adamson_archive_youtube_oauth_state' );

	if ( ! current_user_can( 'manage_options' ) ) {
		// Although we've validated the state, a user context is still required to proceed.
		// This prevents the action from running for non-logged-in users who might intercept the code.
		add_settings_error(
			'adamson_archive_messages',
			'oauth_permission_denied',
			__( 'You do not have permission to perform this action.', 'the-adamson-archive' ),
			'error'
		);
		return;
	}

	$client = adamson_archive_get_google_client();
	if ( is_wp_error( $client ) ) {
		add_settings_error(
			'adamson_archive_messages',
			'google_client_error',
			'Error initializing Google Client: ' . $client->get_error_message(),
			'error'
		);
		return;
	}

	try {
		$client->authenticate( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
		$access_token = $client->getAccessToken();

		// A refresh token is only provided on the first authorization.
		// If we get a new one, store it.
		if ( ! empty( $access_token['refresh_token'] ) ) {
			update_option( 'adamson_archive_youtube_refresh_token', $access_token['refresh_token'] );
		}

		// Always update the access token itself.
		if ( ! empty( $access_token ) ) {
			update_option( 'adamson_archive_youtube_access_token', $access_token );
		}

		// After attempting to store tokens, check if we are in a valid connected state.
		if ( adamson_archive_is_youtube_connected() ) {
			add_settings_error(
				'adamson_archive_messages',
				'youtube_connected',
				__( 'Successfully connected to your YouTube account.', 'the-adamson-archive' ),
				'success'
			);
		} else {
			// This occurs if authentication succeeded but we never received a refresh token.
			throw new Exception( __( 'Could not retrieve refresh token. Please go to your Google account settings, remove access for "The Adamson Archive", and then try connecting again.', 'the-adamson-archive' ) );
		}
	} catch ( Exception $e ) {
		add_settings_error(
			'adamson_archive_messages',
			'youtube_connection_error',
			'Error connecting to YouTube: ' . $e->getMessage(),
			'error'
		);
	}

	// Redirect back to the main dashboard page to clear the URL params.
	wp_safe_redirect( admin_url( 'admin.php?page=adamson-archive' ) );
	exit;
}
add_action( 'admin_init', 'adamson_archive_handle_oauth_callback' );


/**
 * Handles the request to disconnect the YouTube account.
 */
function adamson_archive_handle_disconnect_youtube() {
	if ( isset( $_POST['adamson_archive_disconnect_yt'] ) && isset( $_POST['adamson_archive_disconnect_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_key( $_POST['adamson_archive_disconnect_nonce'] ), 'adamson_archive_disconnect_yt_action' ) ) {
			wp_die( 'Invalid nonce.' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		$client = adamson_archive_get_google_client();

		if ( ! is_wp_error( $client ) ) {
			$token = get_option( 'adamson_archive_youtube_access_token' );
			if ( is_array( $token ) && isset( $token['access_token'] ) ) {
				$client->revokeToken( $token['access_token'] );
			}
		}

		// Delete the stored tokens from the database.
		delete_option( 'adamson_archive_youtube_access_token' );
		delete_option( 'adamson_archive_youtube_refresh_token' );

		add_settings_error(
			'adamson_archive_messages',
			'youtube_disconnected',
			__( 'Successfully disconnected from your YouTube account.', 'the-adamson-archive' ),
			'info'
		);

		// The 'settings_errors' will be displayed on the next page load.
	}
}
add_action( 'admin_init', 'adamson_archive_handle_disconnect_youtube' );

