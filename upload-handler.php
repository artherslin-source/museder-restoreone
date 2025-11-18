<?php
/**
 * Backup Lite native upload handler (standalone, no WordPress)
 */

ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'      => false,
        'code'    => 'method_not_allowed',
        'message' => 'Only POST is accepted.',
    ]);
    exit;
}

$secret = backup_lite_native_get_secret();
if (!is_string($secret) || $secret === '') {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'secret_unavailable',
        'message' => 'Upload secret unavailable.',
    ]);
    exit;
}

$client_secret = $_SERVER['HTTP_X_BACKUP_SECRET'] ?? '';
if (!is_string($client_secret) || !hash_equals($secret, $client_secret)) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'code'    => 'forbidden',
        'message' => 'Invalid upload secret.',
    ]);
    exit;
}

$upload_id = $_SERVER['HTTP_X_BACKUP_UPLOAD_ID'] ?? '';
$upload_id = preg_replace('/[^a-zA-Z0-9\-_.]/', '', (string) $upload_id);
if ($upload_id === '') {
    $upload_id = 'upl_' . bin2hex(random_bytes(8));
}

$chunk_index_header = $_SERVER['HTTP_X_CHUNK_INDEX'] ?? null;
if ($chunk_index_header === null || !is_numeric($chunk_index_header)) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'code'    => 'missing_chunk_index',
        'message' => 'Missing X-Chunk-Index header.',
    ]);
    exit;
}

$chunk_index = (int) $chunk_index_header;
if ($chunk_index < 0) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'code'    => 'invalid_chunk_index',
        'message' => 'Chunk index must be non-negative.',
    ]);
    exit;
}

$content_type = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if ($content_type && stripos($content_type, 'application/octet-stream') === false) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'code'    => 'invalid_content_type',
        'message' => 'Content-Type must be application/octet-stream.',
    ]);
    exit;
}

try {
    $paths = backup_lite_native_get_paths($upload_id);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'storage_error',
        'message' => $e->getMessage(),
    ]);
    exit;
}
if (!is_writable($paths['chunks'])) {
    error_log("[UPLOAD_HANDLER_ERROR] chunk dir not writable: {$paths['chunks']} for upload_id {$upload_id}");
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'dir_not_writable',
        'message' => 'Upload directory is not writable.',
    ]);
    exit;
}
$chunk_tmp   = $paths['chunks'] . '/chunk_' . sprintf('%06d', $chunk_index) . '.part';
$chunk_final = $paths['chunks'] . '/chunk_' . sprintf('%06d', $chunk_index) . '.bin';

$input = fopen('php://input', 'rb');
if (!$input) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'input_open_failed',
        'message' => 'Unable to open input stream.',
    ]);
    exit;
}

$output = fopen($chunk_tmp, 'wb');
if (!$output) {
    fclose($input);
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'output_open_failed',
        'message' => 'Unable to write chunk.',
    ]);
    exit;
}

$written = stream_copy_to_stream($input, $output);

fclose($input);
fflush($output);
fclose($output);

if ($written === false || $written === 0) {
    @unlink($chunk_tmp);
    error_log("[UPLOAD_HANDLER_ERROR] No data copied for upload_id {$upload_id} chunk {$chunk_index}");
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'stream_copy_failed',
        'message' => 'Failed to copy chunk data.',
    ]);
    exit;
}

if (!@rename($chunk_tmp, $chunk_final)) {
    @unlink($chunk_tmp);
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'rename_failed',
        'message' => 'Unable to finalize chunk write.',
    ]);
    exit;
}

if (!file_exists($chunk_final)) {
    error_log("[UPLOAD_HANDLER_ERROR] chunk file missing after rename upload_id {$upload_id} chunk {$chunk_index}");
}

echo json_encode([
    'ok'        => true,
    'upload_id' => $upload_id,
    'chunk'     => $chunk_index,
    'written'   => (int) $written,
    'path'      => basename($chunk_final),
]);
exit;

function backup_lite_native_get_secret() {
    $paths = backup_lite_native_paths();
    $secret_file = $paths['secret_file'];

    if (!file_exists($secret_file)) {
        return '';
    }

    $secret = include $secret_file;
    return is_string($secret) ? $secret : '';
}

function backup_lite_native_get_paths($upload_id) {
    $paths        = backup_lite_native_paths();
    $session_dir  = $paths['v2_root'] . '/' . $upload_id;
    $chunks_dir   = $session_dir . '/chunks';

    if (!is_dir($session_dir) && !mkdir($session_dir, 0755, true)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    if (!is_dir($chunks_dir) && !mkdir($chunks_dir, 0755, true)) {
        throw new RuntimeException('Unable to create chunks directory.');
    }

    return [
        'root'        => $paths['uploads_root'],
        'dir'         => $session_dir,
        'chunks'      => $chunks_dir,
        'secret_file' => $paths['secret_file'],
    ];
}

function backup_lite_native_paths() {
    $plugin_dir   = __DIR__;
    $wp_content   = dirname($plugin_dir, 2);
    $uploads_root = $wp_content . '/uploads/backup-lite';
    $secret_file  = $uploads_root . '/upload-secret.php';
    $temp_root    = $uploads_root . '/temp';
    $v2_root      = $temp_root . '/v2-uploads';

    foreach ([$uploads_root, $temp_root, $v2_root] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Unable to create uploads directory.');
        }
    }

    return [
        'uploads_root' => $uploads_root,
        'secret_file'  => $secret_file,
        'v2_root'      => $v2_root,
    ];
}
