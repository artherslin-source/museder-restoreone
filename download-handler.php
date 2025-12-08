<?php
/**
 * Download handler for backup files.
 *
 * This endpoint handles secure download of backup files using HMAC token verification.
 * It supports both direct access (with token) and WordPress admin-post.php redirects.
 *
 * @package MusederRestoreOne
 */

// If WordPress isn't loaded yet, bootstrap it so we can use its APIs safely.
if ( ! defined( 'ABSPATH' ) ) {
    // phpcs:ignore WordPress.Security.ABSPATH_CONSTANTS.NotCheckingConstantName
    $wp_load = dirname( __FILE__, 4 ) . '/wp-load.php';

    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        // If we really cannot locate WordPress, fail with 500.
        header( 'HTTP/1.1 500 Internal Server Error' );
        exit;
    }
}

// Ensure WordPress is fully loaded
if ( ! function_exists( 'backup_lite_get_backup_path' ) ) {
    // Load plugin helpers if not already loaded
    $plugin_path = dirname( __FILE__ );
    if ( file_exists( $plugin_path . '/includes/helpers.php' ) ) {
        require_once $plugin_path . '/includes/helpers.php';
    }
    if ( file_exists( $plugin_path . '/includes/class-upload-secret.php' ) ) {
        require_once $plugin_path . '/includes/class-upload-secret.php';
    }
}

// Read and sanitize parameters
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token-based verification below
$file    = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
$expires = isset( $_GET['expires'] ) ? absint( $_GET['expires'] ) : 0;
$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// Validate required parameters
if ( '' === $file || 0 === $expires || '' === $token ) {
    status_header( 400 );
    exit;
}

// Verify token
if ( ! function_exists( 'backup_lite_verify_download_token' ) ) {
    /**
     * Verify download token using HMAC.
     *
     * @param string $file    Filename.
     * @param int    $expires Expiration timestamp.
     * @param string $token   Provided token.
     * @return bool True if token is valid and not expired.
     */
    function backup_lite_verify_download_token( $file, $expires, $token ) {
        // Check expiration
        if ( $expires < time() ) {
            return false;
        }

        // Get secret
        $secret = '';
        if ( class_exists( 'Backup_Lite_Upload_Secret' ) ) {
            $secret = (string) Backup_Lite_Upload_Secret::get_secret();
        }

        if ( empty( $secret ) ) {
            return false;
        }

        // Generate expected token
        $expected_token = hash_hmac( 'sha256', $file . '|' . $expires, $secret );

        // Compare tokens using hash_equals to prevent timing attacks
        return hash_equals( $expected_token, $token );
    }
}

if ( ! backup_lite_verify_download_token( $file, $expires, $token ) ) {
    status_header( 403 );
    exit;
}

// Get absolute path using helper function
$archive_path = backup_lite_get_backup_path( $file );

if ( ! $archive_path || ! is_readable( $archive_path ) ) {
    status_header( 404 );
    exit;
}

// Determine MIME type based on file extension
$ext  = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );
$mime = 'application/zip';
if ( 'wpress' === $ext ) {
    $mime = 'application/octet-stream';
}

// Allow longer execution time for large file downloads
ignore_user_abort( true );
// phpcs:disable WordPress.PHP.NoSetTimeLimit -- long-running file download operation
if ( function_exists( 'set_time_limit' ) ) {
    @set_time_limit( 0 );
}
// phpcs:enable WordPress.PHP.NoSetTimeLimit

// Clear any output buffers
if ( function_exists( 'ob_get_level' ) ) {
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
}

// Set download headers
nocache_headers();
status_header( 200 );
header( 'Content-Type: ' . $mime );
$download_filename = sanitize_file_name( basename( $archive_path ) );
header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
header( 'Content-Length: ' . (string) filesize( $archive_path ) );
header( 'Content-Transfer-Encoding: binary' );
header( 'X-Content-Type-Options: nosniff' );

// Stream file content
$chunk_size = 1024 * 1024; // 1MB chunks

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.Security.EscapeOutput.OutputNotEscaped
// 說明：備份檔案下載需要串流讀取大檔案，路徑已通過 backup_lite_get_backup_path() 驗證，檔案內容為二進位資料不需 HTML 轉義。
$handle = fopen( $archive_path, 'rb' );
if ( false === $handle ) {
    status_header( 500 );
    exit;
}

while ( ! feof( $handle ) ) {
    echo fread( $handle, $chunk_size );
    flush();
}

fclose( $handle );
// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.Security.EscapeOutput.OutputNotEscaped

exit;
