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

if ($file === '' || $token === '' || $expires <= time()) {
    $respond(403, ['ok' => false, 'code' => 'invalid_signature', 'message' => 'Download signature invalid or expired.']);
}

$file = basename($file);

$wp_content_dir = dirname(__DIR__, 2);
$uploads_root = $wp_content_dir . '/uploads/museder-restoreone';
$backup_dir = $uploads_root . '/backups';
$secret_path = $uploads_root . '/upload-secret.php';

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
    $respond(403, ['ok' => false, 'code' => 'signature_mismatch', 'message' => 'Signature mismatch.']);
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

