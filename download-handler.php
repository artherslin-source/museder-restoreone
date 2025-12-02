<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

ignore_user_abort(true);
// @plugin-check: okay - needed for long running backup/restore operations
// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
if ( function_exists( 'set_time_limit' ) ) {
    @set_time_limit( 0 );
}

header('X-Robots-Tag: noindex');

$museder_restoreone_respond = function($status, $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

// Get raw parameters first (before WordPress is loaded)
$museder_restoreone_file_raw = isset( $_GET['file'] ) ? $_GET['file'] : '';
$museder_restoreone_expires_raw = isset( $_GET['expires'] ) ? $_GET['expires'] : '';
$museder_restoreone_token_raw = isset( $_GET['token'] ) ? $_GET['token'] : '';

if ($museder_restoreone_file_raw === '' || $museder_restoreone_token_raw === '') {
    $museder_restoreone_respond(403, ['ok' => false, 'code' => 'invalid_signature', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

// Allow a small grace period (30 seconds) for clock skew and network delays
$museder_restoreone_current_time = time();
$museder_restoreone_expires = (int) $museder_restoreone_expires_raw;
if ($museder_restoreone_expires <= 0 || $museder_restoreone_expires < ($museder_restoreone_current_time - 30)) {
    $museder_restoreone_respond(403, ['ok' => false, 'code' => 'download_expired', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

// Sanitize filename (basic sanitization before WordPress is loaded)
$museder_restoreone_file = basename(preg_replace('/[^a-zA-Z0-9._-]/', '', $museder_restoreone_file_raw));
$museder_restoreone_token = preg_replace('/[^a-zA-Z0-9]/', '', $museder_restoreone_token_raw);

if ($museder_restoreone_file === '' || $museder_restoreone_token === '') {
    $museder_restoreone_respond(403, ['ok' => false, 'code' => 'invalid_signature', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

// Try to load WordPress to get the correct storage path
$museder_restoreone_wp_load_paths = [
    dirname(__DIR__, 2) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
];

$museder_restoreone_wp_loaded = false;
foreach ($museder_restoreone_wp_load_paths as $museder_restoreone_wp_load) {
    if (file_exists($museder_restoreone_wp_load)) {
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- loading WordPress core file
            require_once $museder_restoreone_wp_load;
            $museder_restoreone_wp_loaded = true;
            break;
        } catch (Exception $e) {
            // Continue to next path if loading fails
            continue;
        }
    }
}

// Load plugin helpers if WordPress is loaded
if ($museder_restoreone_wp_loaded && defined('BACKUP_LITE_PATH')) {
    $museder_restoreone_helpers_path = BACKUP_LITE_PATH . 'includes/helpers.php';
    if (file_exists($museder_restoreone_helpers_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- loading plugin helper file
        require_once $museder_restoreone_helpers_path;
    }
    
    // Load Upload Secret class if available
    $museder_restoreone_upload_secret_path = BACKUP_LITE_PATH . 'includes/class-upload-secret.php';
    if (file_exists($museder_restoreone_upload_secret_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- loading plugin class file
        require_once $museder_restoreone_upload_secret_path;
    }
}

// Determine storage path
if ($museder_restoreone_wp_loaded && function_exists('backup_lite_get_storage_root')) {
    try {
        // Use WordPress function to get correct path
        $museder_restoreone_storage_root = backup_lite_get_storage_root();
        if (is_array($museder_restoreone_storage_root) && !empty($museder_restoreone_storage_root['path'])) {
            $museder_restoreone_backup_dir = wp_normalize_path(trailingslashit($museder_restoreone_storage_root['path']) . 'backups');
            $museder_restoreone_secret_path = wp_normalize_path(trailingslashit($museder_restoreone_storage_root['path']) . 'upload-secret.php');
        } else {
            throw new Exception('Invalid storage root');
        }
    } catch (Exception $e) {
        // Fallback to hardcoded path if WordPress function fails
        $museder_restoreone_wp_content_dir = dirname(__DIR__, 2);
        $museder_restoreone_uploads_root = $museder_restoreone_wp_content_dir . '/uploads/museder-restoreone';
        $museder_restoreone_backup_dir = $museder_restoreone_uploads_root . '/backups';
        $museder_restoreone_secret_path = $museder_restoreone_uploads_root . '/upload-secret.php';
    }
} else {
    // Fallback to hardcoded path if WordPress is not available
    $museder_restoreone_wp_content_dir = dirname(__DIR__, 2);
    $museder_restoreone_uploads_root = $museder_restoreone_wp_content_dir . '/uploads/museder-restoreone';
    $museder_restoreone_backup_dir = $museder_restoreone_uploads_root . '/backups';
    $museder_restoreone_secret_path = $museder_restoreone_uploads_root . '/upload-secret.php';
}

// Try to get secret from class first (if WordPress is loaded)
$museder_restoreone_secret = '';
if ($museder_restoreone_wp_loaded && class_exists('Backup_Lite_Upload_Secret')) {
    $museder_restoreone_secret = (string) Backup_Lite_Upload_Secret::get_secret();
}

// Fallback to reading secret file directly if class is not available
if (empty($museder_restoreone_secret)) {
    if (!is_file($museder_restoreone_secret_path)) {
        $museder_restoreone_respond(500, ['ok' => false, 'code' => 'missing_secret', 'message' => 'Secret file missing.']);
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading secret file for download authentication
    $museder_restoreone_secret_data = include $museder_restoreone_secret_path;
    if (is_string($museder_restoreone_secret_data)) {
        $museder_restoreone_secret = $museder_restoreone_secret_data;
    } elseif (is_array($museder_restoreone_secret_data) && !empty($museder_restoreone_secret_data['secret'])) {
        $museder_restoreone_secret = (string) $museder_restoreone_secret_data['secret'];
    } else {
        $museder_restoreone_secret = '';
    }
}

if (!is_string($museder_restoreone_secret) || $museder_restoreone_secret === '') {
    $museder_restoreone_respond(500, ['ok' => false, 'code' => 'secret_unavailable', 'message' => 'Download secret unavailable.']);
}

$museder_restoreone_expected = hash_hmac('sha256', $museder_restoreone_file . '|' . $museder_restoreone_expires, $museder_restoreone_secret);
if (!hash_equals($museder_restoreone_expected, $museder_restoreone_token)) {
    $museder_restoreone_respond(403, ['ok' => false, 'code' => 'signature_mismatch', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

$museder_restoreone_base_dir = realpath($museder_restoreone_backup_dir);
$museder_restoreone_target = realpath($museder_restoreone_backup_dir . '/' . $museder_restoreone_file);

if ($museder_restoreone_base_dir === false || $museder_restoreone_target === false || strpos($museder_restoreone_target, $museder_restoreone_base_dir) !== 0 || !is_file($museder_restoreone_target)) {
    $museder_restoreone_respond(404, ['ok' => false, 'code' => 'file_not_found', 'message' => 'Backup file not found.']);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$museder_restoreone_ext = strtolower(pathinfo($museder_restoreone_target, PATHINFO_EXTENSION));
$museder_restoreone_mime = 'application/octet-stream';
if ($museder_restoreone_ext === 'zip') {
    $museder_restoreone_mime = 'application/zip';
}

// @plugin-check: sanitized - safe whitelisted mime type
header('Content-Type: ' . $museder_restoreone_mime);
// Sanitize filename for download header
if ($museder_restoreone_wp_loaded && function_exists('sanitize_file_name')) {
    $museder_restoreone_download_filename = sanitize_file_name( basename( $museder_restoreone_target ) );
} else {
    // Fallback sanitization without WordPress
    $museder_restoreone_download_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', basename( $museder_restoreone_target ) );
}
header('Content-Disposition: attachment; filename="' . $museder_restoreone_download_filename . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
header('Expires: 0');

$museder_restoreone_size = filesize($museder_restoreone_target);
if ($museder_restoreone_size !== false) {
    header('Content-Length: ' . $museder_restoreone_size);
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large backup files requires native PHP I/O
$museder_restoreone_handle = fopen($museder_restoreone_target, 'rb');
if (! $museder_restoreone_handle) {
    $museder_restoreone_respond(500, ['ok' => false, 'code' => 'read_failed', 'message' => 'Unable to read backup file.']);
}

if (function_exists('fpassthru')) {
    fpassthru($museder_restoreone_handle);
} else {
    $museder_restoreone_chunk = 1024 * 1024;
    while (!feof($museder_restoreone_handle)) {
        // Only reads plugin-generated backup files, path is validated and sanitized.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streaming binary file contents, not HTML output
        echo fread($museder_restoreone_handle, $museder_restoreone_chunk);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($museder_restoreone_handle);
}

exit;

