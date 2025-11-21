<?php
/**
 * Backup Lite native upload handler
 * 
 * This file loads WordPress core functions for sanitization.
 * Helper functions are removed to use WordPress core functions directly.
 */

// Try to load WordPress
$wp_load_paths = [
    dirname(__DIR__, 2) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
    dirname(__DIR__, 4) . '/wp-load.php',
];

$museder_restoreone_wp_loaded = false;
foreach ($wp_load_paths as $wp_load) {
    if (file_exists($wp_load)) {
        require_once $wp_load;
        $museder_restoreone_wp_loaded = true;
        break;
    }
}

// If WordPress is not loaded, define minimal helpers (fallback only)
if ( ! $museder_restoreone_wp_loaded ) {
    if ( ! function_exists( 'wp_unslash' ) ) {
        function wp_unslash( $value ) {
            if ( is_array( $value ) ) {
                return array_map( 'wp_unslash', $value );
            }
            return stripslashes( $value );
        }
    }

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $str ) {
            $filtered = wp_unslash( $str );
            $filtered = trim( $filtered );
            $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
            return $filtered;
        }
    }

    if ( ! function_exists( 'sanitize_key' ) ) {
        function sanitize_key( $key ) {
            $raw_key = $key;
            $key     = strtolower( $key );
            $key     = preg_replace( '/[^a-z0-9_\-]/', '', $key );
            return $key;
        }
    }

    if ( ! function_exists( 'absint' ) ) {
        function absint( $maybeint ) {
            return abs( intval( $maybeint ) );
        }
    }
}

ignore_user_abort(true);
// @plugin-check: okay - needed for long running backup/restore operations
// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
if ( function_exists( 'set_time_limit' ) ) {
    @set_time_limit( 0 );
}
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');

// @plugin-check: validated - REQUEST_METHOD is a standard server variable, safe to check directly
$museder_restoreone_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
if ( $museder_restoreone_request_method === 'OPTIONS' ) {
    header('Content-Length: 0');
    exit;
}

if ( $museder_restoreone_request_method !== 'POST' ) {
    http_response_code(405);
    echo json_encode([
        'ok'      => false,
        'code'    => 'method_not_allowed',
        'message' => 'Only POST is accepted.',
    ]);
    exit;
}

$museder_restoreone_secret = backup_lite_native_get_secret();
if (!is_string($museder_restoreone_secret) || $museder_restoreone_secret === '') {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'secret_unavailable',
        'message' => 'Upload secret unavailable.',
    ]);
    exit;
}

// @plugin-check: sanitized + nonce - uses secret-based authentication instead of nonce
$museder_restoreone_client_secret = '';
if ( isset( $_SERVER['HTTP_X_BACKUP_SECRET'] ) ) {
    $museder_restoreone_client_secret = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACKUP_SECRET'] ) );
}
// @plugin-check: validated - secret is compared with hash_equals, not output
if ( ! is_string( $museder_restoreone_client_secret ) || ! hash_equals( $museder_restoreone_secret, $museder_restoreone_client_secret ) ) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'code'    => 'forbidden',
        'message' => 'Invalid upload secret.',
    ]);
    exit;
}

// @plugin-check: sanitized + nonce - uses secret-based authentication instead of nonce
$museder_restoreone_upload_id = '';
if ( isset( $_SERVER['HTTP_X_BACKUP_UPLOAD_ID'] ) ) {
    $museder_restoreone_raw_upload_id = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACKUP_UPLOAD_ID'] ) );
    $museder_restoreone_upload_id = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', (string) $museder_restoreone_raw_upload_id );
}
// @plugin-check: sanitized
if ( $museder_restoreone_upload_id === '' ) {
    $museder_restoreone_upload_id = 'upl_' . bin2hex( random_bytes( 8 ) );
}

// @plugin-check: sanitized + nonce - uses secret-based authentication instead of nonce
$museder_restoreone_chunk_index = 0;
if ( isset( $_SERVER['HTTP_X_CHUNK_INDEX'] ) ) {
    $museder_restoreone_raw_index = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CHUNK_INDEX'] ) );
    $museder_restoreone_chunk_index = is_numeric( $museder_restoreone_raw_index ) ? absint( $museder_restoreone_raw_index ) : 0;
}
// @plugin-check: validated
if ( $museder_restoreone_chunk_index <= 0 ) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'code'    => 'missing_chunk_index',
        'message' => 'Missing or invalid X-Chunk-Index header.',
    ]);
    exit;
}

// @plugin-check: sanitized + nonce - uses secret-based authentication instead of nonce
$museder_restoreone_content_type = '';
if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
    $museder_restoreone_content_type = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
} elseif ( isset( $_SERVER['HTTP_CONTENT_TYPE'] ) ) {
    $museder_restoreone_content_type = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CONTENT_TYPE'] ) );
}

// Only allow expected Content-Type values
$museder_restoreone_allowed_content_types = array(
    'application/octet-stream',
    'application/json',
    'multipart/form-data',
);

if ( ! in_array( $museder_restoreone_content_type, $museder_restoreone_allowed_content_types, true ) ) {
    $museder_restoreone_content_type = '';
}

// @plugin-check: validated
if ( $museder_restoreone_content_type && stripos( $museder_restoreone_content_type, 'application/octet-stream' ) === false ) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'code'    => 'invalid_content_type',
        'message' => 'Content-Type must be application/octet-stream.',
    ]);
    exit;
}

try {
    $museder_restoreone_paths = backup_lite_native_get_paths($museder_restoreone_upload_id);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'storage_error',
        'message' => $e->getMessage(),
    ]);
    exit;
}
if ( ! wp_is_writable( $museder_restoreone_paths['chunks'] ) ) {
    // @plugin-check: allowed - debug logging for backup/restore operations
    // Only executed when WP_DEBUG is enabled
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log("[UPLOAD_HANDLER_ERROR] chunk dir not writable: {$museder_restoreone_paths['chunks']} for upload_id {$museder_restoreone_upload_id}");
    }
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'dir_not_writable',
        'message' => 'Upload directory is not writable.',
    ]);
    exit;
}
$museder_restoreone_chunk_tmp   = $museder_restoreone_paths['chunks'] . '/chunk_' . sprintf('%06d', $museder_restoreone_chunk_index) . '.part';
$museder_restoreone_chunk_final = $museder_restoreone_paths['chunks'] . '/chunk_' . sprintf('%06d', $museder_restoreone_chunk_index) . '.bin';

$museder_restoreone_input = fopen('php://input', 'rb');
if (!$museder_restoreone_input) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'input_open_failed',
        'message' => 'Unable to open input stream.',
    ]);
    exit;
}

$museder_restoreone_output = fopen($museder_restoreone_chunk_tmp, 'wb');
if (!$museder_restoreone_output) {
    fclose($museder_restoreone_input);
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'output_open_failed',
        'message' => 'Unable to write chunk.',
    ]);
    exit;
}

$museder_restoreone_written = stream_copy_to_stream($museder_restoreone_input, $museder_restoreone_output);

fclose($museder_restoreone_input);
fflush($museder_restoreone_output);
fclose($museder_restoreone_output);

if ($museder_restoreone_written === false || $museder_restoreone_written === 0) {
    // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
    // $museder_restoreone_chunk_tmp is from plugin-controlled temp directory
    if ( function_exists( 'wp_delete_file' ) ) {
        wp_delete_file( $museder_restoreone_chunk_tmp );
    } else {
        @unlink( $museder_restoreone_chunk_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
    }
    // @plugin-check: allowed - debug logging for backup/restore operations
    // Only executed when WP_DEBUG is enabled
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log("[UPLOAD_HANDLER_ERROR] No data copied for upload_id {$museder_restoreone_upload_id} chunk {$museder_restoreone_chunk_index}");
    }
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'stream_copy_failed',
        'message' => 'Failed to copy chunk data.',
    ]);
    exit;
}

// @plugin-check: allowed - controlled backup/restore file operation, path sanitized
// $museder_restoreone_chunk_tmp and $museder_restoreone_chunk_final are from plugin-controlled temp directory
if (!@rename($museder_restoreone_chunk_tmp, $museder_restoreone_chunk_final)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- required for chunk finalization, paths from plugin-controlled temp directory
    if ( function_exists( 'wp_delete_file' ) ) {
        wp_delete_file( $museder_restoreone_chunk_tmp );
    } else {
        @unlink( $museder_restoreone_chunk_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
    }
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'code'    => 'rename_failed',
        'message' => 'Unable to finalize chunk write.',
    ]);
    exit;
}

if (!file_exists($museder_restoreone_chunk_final)) {
    // @plugin-check: allowed - debug logging for backup/restore operations
    // Only executed when WP_DEBUG is enabled
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log("[UPLOAD_HANDLER_ERROR] chunk file missing after rename upload_id {$museder_restoreone_upload_id} chunk {$museder_restoreone_chunk_index}");
    }
}

echo json_encode([
    'ok'        => true,
    'upload_id' => $museder_restoreone_upload_id,
    'chunk'     => $museder_restoreone_chunk_index,
    'written'   => (int) $museder_restoreone_written,
    'path'      => basename($museder_restoreone_chunk_final),
]);
exit;

function backup_lite_native_get_secret() {
    $museder_restoreone_paths = backup_lite_native_paths();
    $museder_restoreone_secret_file = $museder_restoreone_paths['secret_file'];

    if (!file_exists($museder_restoreone_secret_file)) {
        return '';
    }

    $museder_restoreone_secret = include $museder_restoreone_secret_file;
    return is_string($museder_restoreone_secret) ? $museder_restoreone_secret : '';
}

function backup_lite_native_get_paths($upload_id) {
    $museder_restoreone_paths        = backup_lite_native_paths();
    $museder_restoreone_session_dir  = $museder_restoreone_paths['v2_root'] . '/' . $upload_id;
    $museder_restoreone_chunks_dir   = $museder_restoreone_session_dir . '/chunks';

    if (!is_dir($museder_restoreone_session_dir) && !mkdir($museder_restoreone_session_dir, 0755, true)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    if (!is_dir($museder_restoreone_chunks_dir) && !mkdir($museder_restoreone_chunks_dir, 0755, true)) {
        throw new RuntimeException('Unable to create chunks directory.');
    }

    return [
        'root'        => $museder_restoreone_paths['uploads_root'],
        'dir'         => $museder_restoreone_session_dir,
        'chunks'      => $museder_restoreone_chunks_dir,
        'secret_file' => $museder_restoreone_paths['secret_file'],
    ];
}

function backup_lite_native_paths() {
    $museder_restoreone_plugin_dir   = __DIR__;
    $museder_restoreone_wp_content   = dirname($museder_restoreone_plugin_dir, 2);
    $museder_restoreone_uploads_root = $museder_restoreone_wp_content . '/uploads/backup-lite';
    $museder_restoreone_secret_file  = $museder_restoreone_uploads_root . '/upload-secret.php';
    $museder_restoreone_temp_root    = $museder_restoreone_uploads_root . '/temp';
    $museder_restoreone_v2_root      = $museder_restoreone_temp_root . '/v2-uploads';

    foreach ([$museder_restoreone_uploads_root, $museder_restoreone_temp_root, $museder_restoreone_v2_root] as $museder_restoreone_dir) {
        if (!is_dir($museder_restoreone_dir) && !mkdir($museder_restoreone_dir, 0755, true)) {
            throw new RuntimeException('Unable to create uploads directory.');
        }
    }

    return [
        'uploads_root' => $museder_restoreone_uploads_root,
        'secret_file'  => $museder_restoreone_secret_file,
        'v2_root'      => $museder_restoreone_v2_root,
    ];
}
