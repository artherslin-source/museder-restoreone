<?php

ignore_user_abort(true);
@set_time_limit(0);

header('X-Robots-Tag: noindex');

$respond = function($status, $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
$expires = isset($_GET['expires']) ? (int) $_GET['expires'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

if ($file === '' || $token === '') {
    $respond(403, ['ok' => false, 'code' => 'invalid_signature', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

// Allow a small grace period (30 seconds) for clock skew and network delays
$current_time = time();
if ($expires <= 0 || $expires < ($current_time - 30)) {
    $respond(403, ['ok' => false, 'code' => 'download_expired', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

$file = basename($file);

// Try to load WordPress to get the correct storage path
$wp_load_paths = [
    dirname(__DIR__, 2) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $wp_load) {
    if (file_exists($wp_load)) {
        require_once $wp_load;
        $wp_loaded = true;
        break;
    }
}

// Load plugin helpers if WordPress is loaded
if ($wp_loaded && defined('BACKUP_LITE_PATH')) {
    $helpers_path = BACKUP_LITE_PATH . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

// Determine storage path
if ($wp_loaded && function_exists('backup_lite_get_storage_root')) {
    // Use WordPress function to get correct path
    $storage_root = backup_lite_get_storage_root();
    $backup_dir = wp_normalize_path(trailingslashit($storage_root['path']) . 'backups');
    $secret_path = wp_normalize_path(trailingslashit($storage_root['path']) . 'upload-secret.php');
} else {
    // Fallback to hardcoded path if WordPress is not available
    $wp_content_dir = dirname(__DIR__, 2);
    $uploads_root = $wp_content_dir . '/uploads/museder-restoreone';
    $backup_dir = $uploads_root . '/backups';
    $secret_path = $uploads_root . '/upload-secret.php';
}

if (!is_file($secret_path)) {
    $respond(500, ['ok' => false, 'code' => 'missing_secret', 'message' => 'Secret file missing.']);
}

$secret_data = include $secret_path;
if (is_string($secret_data)) {
    $secret = $secret_data;
} elseif (is_array($secret_data) && !empty($secret_data['secret'])) {
    $secret = (string) $secret_data['secret'];
} else {
    $secret = '';
}

if (!is_string($secret) || $secret === '') {
    $respond(500, ['ok' => false, 'code' => 'secret_unavailable', 'message' => 'Download secret unavailable.']);
}

$expected = hash_hmac('sha256', $file . '|' . $expires, $secret);
if (!hash_equals($expected, $token)) {
    $respond(403, ['ok' => false, 'code' => 'signature_mismatch', 'message' => 'Your download link has expired. Please download from the backup library.']);
}

$base_dir = realpath($backup_dir);
$target = realpath($backup_dir . '/' . $file);

if ($base_dir === false || $target === false || strpos($target, $base_dir) !== 0 || !is_file($target)) {
    $respond(404, ['ok' => false, 'code' => 'file_not_found', 'message' => 'Backup file not found.']);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'zip') {
    $mime = 'application/zip';
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($target) . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
header('Expires: 0');

$size = filesize($target);
if ($size !== false) {
    header('Content-Length: ' . $size);
}

$handle = fopen($target, 'rb');
if (! $handle) {
    $respond(500, ['ok' => false, 'code' => 'read_failed', 'message' => 'Unable to read backup file.']);
}

if (function_exists('fpassthru')) {
    fpassthru($handle);
} else {
    $chunk = 1024 * 1024;
    while (!feof($handle)) {
        echo fread($handle, $chunk);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
    fclose($handle);
}

exit;

