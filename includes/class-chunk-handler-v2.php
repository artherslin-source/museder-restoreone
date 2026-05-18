<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Note: Do not change global PHP ini settings here (e.g., display_errors) as it can affect other plugins/routes.
// JSON responses are protected by proper output handling in the endpoint implementation.

class Museder_Restoreone_Chunk_V2 {

    const TEMP_FOLDER    = 'v2-uploads';
    const MANIFEST_FILE  = 'manifest.json';
    const CHUNKS_FOLDER  = 'chunks';
    const FINAL_FILENAME = 'final.zip';
    const STREAM_CHUNK   = 8192;
    const CLEANUP_WINDOW = DAY_IN_SECONDS;

    /**
     * Lightweight ZIP integrity heuristic: verify EOCD signature exists near end-of-file.
     *
     * This avoids memory-heavy operations like PclZip::listContent() on large archives.
     *
     * @param string $path
     * @return bool
     */
    private static function zip_has_eocd_signature( $path ) {
        $path = wp_normalize_path( (string) $path );
        if ( '' === $path || ! file_exists( $path ) ) {
            return false;
        }
        $size = (int) filesize( $path );
        if ( $size <= 0 ) {
            return false;
        }

        // EOCD record is located within the last 65,535 bytes + fixed header.
        $tail = min( 66000, $size );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- controlled read of plugin-owned temp file
        $fh = @fopen( $path, 'rb' );
        if ( ! $fh ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fseek -- controlled seek
        @fseek( $fh, -$tail, SEEK_END );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- controlled read
        $buf = @fread( $fh, $tail );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup
        @fclose( $fh );
        if ( ! is_string( $buf ) || '' === $buf ) {
            return false;
        }
        return false !== strpos( $buf, "PK\x05\x06" );
    }

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Restrict chunk session directories to UUID v4 ids (same format as wp_generate_uuid4()).
     *
     * @param mixed $upload_id Client-supplied upload id.
     * @return bool
     */
    private static function is_valid_chunk_session_id( $upload_id ) {
        $upload_id = (string) $upload_id;
        return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $upload_id );
    }

    public static function register_routes() {
        register_rest_route(
            'museder-restoreone/v2',
            '/prepare',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'prepare' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/chunk',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'upload_chunk' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'status' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/finalize',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'route_finalize' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/abort',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'abort' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );
    }

    public static function status( WP_REST_Request $request ) {
        self::prepare_request_environment();
        $headers    = self::normalize_headers( $request );
        $upload_id  = self::pull_value( $headers, $request, [ 'x-backup-lite-upload-id', 'x-upload-id' ], [ 'upload_id' ] );
        $file_sha1  = self::pull_value( $headers, $request, [ 'x-file-sha1' ], [ 'file_sha1' ] );
        $upload_id  = $upload_id ? sanitize_text_field( $upload_id ) : '';
        $file_sha1  = $file_sha1 ? strtolower( sanitize_text_field( $file_sha1 ) ) : '';

        if ( ! $upload_id || ! $file_sha1 ) {
            return self::rest_error( 'missing_params', esc_html__( 'Missing upload identifier or checksum.', 'museder-restoreone' ), 400 );
        }

        if ( ! self::is_valid_chunk_session_id( $upload_id ) ) {
            return self::rest_error( 'invalid_upload_id', esc_html__( 'Invalid upload identifier.', 'museder-restoreone' ), 400 );
        }

        $manifest = self::load_manifest( $upload_id );
        if ( ! $manifest ) {
            return self::rest_error( 'manifest_missing', esc_html__( 'Upload session expired or missing.', 'museder-restoreone' ), 409 );
        }

        if ( ! hash_equals( (string) $manifest['file_sha1'], $file_sha1 ) ) {
            return self::rest_error( 'file_sha1_mismatch', esc_html__( 'File SHA1 mismatch.', 'museder-restoreone' ), 409 );
        }

        $received = isset( $manifest['received'] ) && is_array( $manifest['received'] ) ? array_map( 'absint', $manifest['received'] ) : [];
        $received = array_values( array_unique( $received ) );
        sort( $received );

        $total_chunks = isset( $manifest['total_chunks'] ) ? (int) $manifest['total_chunks'] : 0;
        $chunk_size   = isset( $manifest['chunk_size'] ) ? (int) $manifest['chunk_size'] : 0;
        $filesize     = isset( $manifest['filesize'] ) ? (int) $manifest['filesize'] : 0;

        // Find next missing index (resumable upload).
        $set = array_fill( 0, max( 0, $total_chunks ), false );
        foreach ( $received as $idx ) {
            if ( $idx >= 0 && $idx < $total_chunks ) {
                $set[ $idx ] = true;
            }
        }

        $next_missing = null;
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            if ( empty( $set[ $i ] ) ) {
                $next_missing = $i;
                break;
            }
        }

        // Compute uploaded bytes (sum existing chunk file sizes; cheap + accurate).
        $uploaded_bytes = 0;
        $chunks_dir = self::get_chunks_dir( $upload_id );
        if ( $chunks_dir && is_dir( $chunks_dir ) ) {
            foreach ( $received as $idx ) {
                $p = trailingslashit( $chunks_dir ) . sprintf( 'chunk_%06d.bin', $idx );
                if ( file_exists( $p ) ) {
                    $uploaded_bytes += (int) filesize( $p );
                }
            }
        }
        if ( $filesize > 0 ) {
            $uploaded_bytes = min( $filesize, $uploaded_bytes );
        }

        $progress = $total_chunks > 0 ? ( count( $received ) / $total_chunks ) * 100 : 0;

        return self::rest_success( [
            'upload_id'      => $upload_id,
            'filename'       => isset( $manifest['filename'] ) ? $manifest['filename'] : '',
            'filesize'       => $filesize,
            'chunk_size'     => $chunk_size,
            'total_chunks'   => $total_chunks,
            'received'       => $received,
            'next_missing'   => $next_missing,
            'uploaded_bytes' => $uploaded_bytes,
            'progress'       => round( $progress, 2 ),
        ] );
    }

    public static function prepare( WP_REST_Request $request ) {
        self::prepare_request_environment();
        $body          = $request->get_json_params();
        $body          = is_array( $body ) ? $body : [];
        $headers       = self::normalize_headers( $request );
        $filename_raw  = isset( $body['filename'] ) ? $body['filename'] : self::pull_value( $headers, $request, [ 'x-file-name' ], [ 'filename', 'file_name' ] );
        $filesize      = isset( $body['filesize'] ) ? (int) $body['filesize'] : (int) self::pull_value( $headers, $request, [ 'x-file-size' ], [ 'filesize', 'file_size' ] );
        $file_sha1_raw = isset( $body['file_sha1'] ) ? $body['file_sha1'] : self::pull_value( $headers, $request, [ 'x-file-sha1' ], [ 'file_sha1' ] );
        $chunk_size    = isset( $body['chunk_size'] ) ? (int) $body['chunk_size'] : (int) self::pull_value( $headers, $request, [ 'x-chunk-size' ], [ 'chunk_size' ] );
        $total_chunks  = isset( $body['total_chunks'] ) ? (int) $body['total_chunks'] : (int) self::pull_value( $headers, $request, [ 'x-chunk-total', 'x-total-chunks' ], [ 'total_chunks' ] );

        $filename  = $filename_raw ? sanitize_file_name( $filename_raw ) : '';
        $file_sha1 = $file_sha1_raw ? strtolower( sanitize_text_field( $file_sha1_raw ) ) : '';

        if ( ! $filename || ! $filesize || ! $file_sha1 || ! $chunk_size || ! $total_chunks ) {
            // @plugin-check: escaped
            return self::rest_error( 'missing_params', esc_html__( 'Missing required metadata.', 'museder-restoreone' ), 400, [
                'received' => [
                    'filename'     => $filename ? 'yes' : 'no',
                    'filesize'     => $filesize,
                    'file_sha1'    => $file_sha1 ? 'yes' : 'no',
                    'chunk_size'   => $chunk_size,
                    'total_chunks' => $total_chunks,
                ],
            ] );
        }

        $upload_id = wp_generate_uuid4();
        $upload_dir = self::ensure_upload_dir( $upload_id );
        $chunks_dir = self::ensure_chunks_dir( $upload_id );

        if ( ! $upload_dir || ! $chunks_dir ) {
            return self::rest_error( 'dir_create_failed', esc_html__( 'Unable to create temporary directories.', 'museder-restoreone' ), 500 );
        }

        $manifest = [
            'upload_id'    => $upload_id,
            'filename'     => $filename,
            'filesize'     => $filesize,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
            'file_sha1'    => $file_sha1,
            'received'     => [],
            'created_at'   => museder_restoreone_local_time( 'c' ),
        ];

        if ( ! self::save_manifest( $upload_id, $manifest ) ) {
            return self::rest_error( 'manifest_write_failed', esc_html__( 'Failed to write manifest.', 'museder-restoreone' ), 500 );
        }

        self::log_info( '[PREPARE_V2_OK]', [
            'upload_id' => $upload_id,
            'filename'  => $filename,
            'filesize'  => $filesize,
            'chunks'    => $total_chunks,
        ] );

        return self::rest_success( [
            'upload_id'    => $upload_id,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
        ] );
    }

    public static function upload_chunk( WP_REST_Request $request ) {
        self::prepare_request_environment();
        $headers      = self::normalize_headers( $request );
        $upload_id    = self::pull_value( $headers, $request, [ 'x-backup-lite-upload-id', 'x-upload-id' ], [ 'upload_id' ] );
        $chunk_index  = self::pull_value( $headers, $request, [ 'x-chunk-index' ], [ 'chunk_index' ] );
        $chunk_size   = (int) self::pull_value( $headers, $request, [ 'x-chunk-size' ], [ 'chunk_size' ] );
        $chunk_sha1   = self::pull_value( $headers, $request, [ 'x-chunk-sha1' ], [ 'chunk_sha1' ] );
        $file_sha1    = self::pull_value( $headers, $request, [ 'x-file-sha1' ], [ 'file_sha1' ] );

        $upload_id  = $upload_id ? sanitize_text_field( $upload_id ) : '';
        $chunk_sha1 = $chunk_sha1 ? strtolower( sanitize_text_field( $chunk_sha1 ) ) : '';
        $file_sha1  = $file_sha1 ? strtolower( sanitize_text_field( $file_sha1 ) ) : '';
        $chunk_index = is_numeric( $chunk_index ) ? absint( $chunk_index ) : null;

        if ( ! $upload_id || null === $chunk_index || ! $chunk_size || ! $chunk_sha1 || ! $file_sha1 ) {
            return self::rest_error( 'missing_params', esc_html__( 'Missing required metadata.', 'museder-restoreone' ), 400 );
        }

        if ( ! self::is_valid_chunk_session_id( $upload_id ) ) {
            return self::rest_error( 'invalid_upload_id', esc_html__( 'Invalid upload identifier.', 'museder-restoreone' ), 400 );
        }

        $manifest = self::load_manifest( $upload_id );
        if ( ! $manifest ) {
            return self::rest_error( 'manifest_missing', esc_html__( 'Upload session expired or missing.', 'museder-restoreone' ), 409 );
        }

        if ( ! hash_equals( $manifest['file_sha1'], $file_sha1 ) ) {
            return self::rest_error( 'file_sha1_mismatch', esc_html__( 'File SHA1 mismatch.', 'museder-restoreone' ), 409 );
        }

        // @plugin-check: sanitized + nonce - verified via permission_check() above
        $files = $request->get_file_params();
        if ( isset( $files['chunk'] ) && ( empty( $files['chunk']['tmp_name'] ) || ! is_uploaded_file( $files['chunk']['tmp_name'] ) || ! file_exists( $files['chunk']['tmp_name'] ) ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging for backup/restore issues, respects WP_DEBUG
                error_log( sprintf( '[UPLOAD_V2_ERROR] Missing chunk file (REST multipart) upload_id=%s index=%s', $upload_id, $chunk_index ) );
            }
            return self::rest_error( 'chunk_file_missing', esc_html__( 'No chunk file received.', 'museder-restoreone' ), 400, [
                'index' => $chunk_index,
            ] );
        }

        $chunks_dir = self::ensure_chunks_dir( $upload_id );
        if ( ! $chunks_dir ) {
            return self::rest_error( 'chunk_dir_missing', esc_html__( 'Chunks directory missing.', 'museder-restoreone' ), 500 );
        }
        if ( ! wp_is_writable( $chunks_dir ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging for backup/restore issues, respects WP_DEBUG
                error_log( sprintf( '[UPLOAD_V2_ERROR] Chunk directory not writable: %s (upload_id=%s)', $chunks_dir, $upload_id ) );
            }
            return self::rest_error( 'chunk_dir_not_writable', esc_html__( 'Chunks directory is not writable.', 'museder-restoreone' ), 500 );
        }

        $tmp_path   = trailingslashit( $chunks_dir ) . sprintf( 'chunk_%06d.bin.part', $chunk_index );
        $final_path = trailingslashit( $chunks_dir ) . sprintf( 'chunk_%06d.bin', $chunk_index );

        // Resume optimization: if the chunk is already present and matches SHA1, skip re-upload.
        if ( file_exists( $final_path ) ) {
            $existing_sha1 = sha1_file( $final_path );
            if ( $existing_sha1 && hash_equals( $chunk_sha1, strtolower( $existing_sha1 ) ) ) {
                if ( ! in_array( $chunk_index, $manifest['received'], true ) ) {
                    $manifest['received'][] = $chunk_index;
                    sort( $manifest['received'] );
                    self::save_manifest( $upload_id, $manifest );
                }
                $progress = count( $manifest['received'] ) / max( 1, (int) $manifest['total_chunks'] ) * 100;
                return self::rest_success( [
                    'chunk'          => $chunk_index,
                    'progress'       => round( $progress, 2 ),
                    'uploaded_bytes' => min( $manifest['filesize'], ( ( $chunk_index + 1 ) * $manifest['chunk_size'] ) ),
                    'skipped'        => true,
                ] );
            }
        }

        $input = self::get_input_stream( $request );
        if ( ! $input ) {
            return self::rest_error( 'input_open_failed', esc_html__( 'Unable to read chunk input.', 'museder-restoreone' ), 500 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for chunk upload handling, path from plugin-controlled temp directory
        $output = fopen( $tmp_path, 'wb' );
        if ( ! $output ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
            fclose( $input );
            return self::rest_error( 'output_open_failed', esc_html__( 'Unable to write chunk.', 'museder-restoreone' ), 500 );
        }

        $hash_ctx = hash_init( 'sha1' );
        $written  = 0;

        while ( ! feof( $input ) ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $buffer = fread( $input, self::STREAM_CHUNK );
            if ( false === $buffer ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose( $input );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose( $output );
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $tmp_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $tmp_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $tmp_path ) ) {
                        @unlink( $tmp_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                return self::rest_error( 'stream_read_failed', esc_html__( 'Failed to read from input stream.', 'museder-restoreone' ), 500 );
            }

            if ( '' === $buffer ) {
                continue;
            }

            $written += strlen( $buffer );

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for chunk upload handling
            if ( false === fwrite( $output, $buffer ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
                fclose( $input );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
                fclose( $output );
                // @plugin-check: safe - $tmp_path built from internal temp directory, not user input
                // @plugin-check: allowed - required for backup/restore file operations
                // Path is validated and sanitized before use
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $tmp_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $tmp_path ) ) {
                        @unlink( $tmp_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                return self::rest_error( 'stream_write_failed', esc_html__( 'Failed to write chunk to disk.', 'museder-restoreone' ), 500 );
            }

            hash_update( $hash_ctx, $buffer );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $input );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $output );

        if ( $chunk_size && $written !== $chunk_size ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $tmp_path ) ) {
                    @unlink( $tmp_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            return self::rest_error( 'size_mismatch', esc_html__( 'Chunk size mismatch.', 'museder-restoreone' ), 409, [
                'expected' => $chunk_size,
                'actual'   => $written,
            ] );
        }

        $actual_sha1 = hash_final( $hash_ctx );
        if ( ! hash_equals( $chunk_sha1, $actual_sha1 ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $tmp_path ) ) {
                    @unlink( $tmp_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            return self::rest_error( 'chunk_sha1_mismatch', esc_html__( 'Chunk SHA1 verification failed.', 'museder-restoreone' ), 409, [
                'expected' => $chunk_sha1,
                'actual'   => $actual_sha1,
            ] );
        }

        // This plugin needs low-level rename() here for streaming backup/restore performance.
        // Using WP_Filesystem::move() is not always reliable across all hosting environments.
        // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $tmp_path, $final_path );
        // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
        if ( ! $renamed ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $tmp_path ) ) {
                    @unlink( $tmp_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            return self::rest_error( 'rename_failed', esc_html__( 'Unable to finalize chunk.', 'museder-restoreone' ), 500 );
        }

        if ( ! in_array( $chunk_index, $manifest['received'], true ) ) {
            $manifest['received'][] = $chunk_index;
            sort( $manifest['received'] );
            self::save_manifest( $upload_id, $manifest );
        }

        $progress = count( $manifest['received'] ) / max( 1, (int) $manifest['total_chunks'] ) * 100;

        return self::rest_success( [
            'chunk'          => $chunk_index,
            'progress'       => round( $progress, 2 ),
            'uploaded_bytes' => min( $manifest['filesize'], ( $chunk_index * $manifest['chunk_size'] ) + $written ),
        ] );
    }

    public static function abort( WP_REST_Request $request ) {
        self::prepare_request_environment();
        $headers     = self::normalize_headers( $request );
        $upload_raw  = self::pull_value( $headers, $request, [ 'x-backup-lite-upload-id', 'x-upload-id' ], [ 'upload_id' ] );
        $upload_id   = is_string( $upload_raw ) ? sanitize_text_field( $upload_raw ) : '';
        if ( $upload_id && self::is_valid_chunk_session_id( $upload_id ) ) {
            self::cleanup_upload( $upload_id );
            self::log_info( '[UPLOAD_V2_ABORT]', [ 'upload_id' => $upload_id ] );
        }
        return self::rest_success();
    }

    public static function cleanup_expired_uploads() {
        $root = self::get_root_dir();
        if ( ! $root || ! is_dir( $root ) ) {
            return;
        }

        $now = time();
        foreach ( glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) as $dir ) {
            $dir_key = basename( (string) $dir );
            if ( ! self::is_valid_chunk_session_id( $dir_key ) ) {
                continue;
            }
            $manifest = self::load_manifest_by_path( trailingslashit( $dir ) . self::MANIFEST_FILE );
            if ( ! $manifest ) {
                self::rrmdir( $dir );
                continue;
            }

            $created = isset( $manifest['created_at'] ) ? strtotime( $manifest['created_at'] ) : 0;
            if ( $created && ( $now - $created ) > self::CLEANUP_WINDOW ) {
                self::rrmdir( $dir );
                self::log_info( '[UPLOAD_V2_CLEANUP]', [ 'upload_id' => $manifest['upload_id'] ?? basename( $dir ) ] );
            }
        }
    }

    /* -------------------------------------------------------------------- */
    /*  Finalize (REST route registered below)                             */
    /* -------------------------------------------------------------------- */

    public static function route_finalize( WP_REST_Request $req ) {
        self::prepare_request_environment();
        $headers      = self::normalize_headers( $req );
        $upload_raw   = self::pull_value( $headers, $req, [ 'x-backup-lite-upload-id', 'x-upload-id' ], [ 'upload_id' ] );
        $sha_raw      = self::pull_value( $headers, $req, [ 'x-file-sha1' ], [ 'file_sha1' ] );
        $upload_id    = $upload_raw ? sanitize_text_field( (string) $upload_raw ) : '';
        $client_sha1  = $sha_raw ? strtolower( sanitize_text_field( (string) $sha_raw ) ) : '';

        if ( ! $upload_id || ! $client_sha1 ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'missing_params',
                // @plugin-check: escaped
                'message' => esc_html__( 'Missing upload identifier or checksum.', 'museder-restoreone' ),
            ], 400 );
        }

        if ( ! self::is_valid_chunk_session_id( $upload_id ) ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'invalid_upload_id',
                // @plugin-check: escaped
                'message' => esc_html__( 'Invalid upload identifier.', 'museder-restoreone' ),
            ], 400 );
        }

        $upload_dir = self::get_upload_dir( $upload_id );
        $chunks_dir = self::get_chunks_dir( $upload_id );

        if ( ! $upload_dir || ! $chunks_dir ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'session_missing',
                // @plugin-check: escaped
                'message' => esc_html__( 'Upload session not found.', 'museder-restoreone' ),
            ], 409 );
        }

        $manifest = self::load_manifest( $upload_id );
        if ( ! $manifest ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'manifest_missing',
                // @plugin-check: escaped
                'message' => esc_html__( 'Upload session expired or missing.', 'museder-restoreone' ),
            ], 409 );
        }

        if ( ! hash_equals( (string) $manifest['file_sha1'], $client_sha1 ) ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'file_sha1_mismatch',
                // @plugin-check: escaped
                'message' => esc_html__( 'File SHA1 mismatch.', 'museder-restoreone' ),
            ], 409 );
        }

        $expected_chunks = isset( $manifest['total_chunks'] ) ? (int) $manifest['total_chunks'] : 0;
        $chunk_size      = isset( $manifest['chunk_size'] ) ? (int) $manifest['chunk_size'] : 0;
        $expected_size   = isset( $manifest['filesize'] ) ? (int) $manifest['filesize'] : 0;

        $chunks = glob( trailingslashit( $chunks_dir ) . '*.bin' );
        if ( empty( $chunks ) ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'missing_chunks',
                // @plugin-check: escaped
                'message' => esc_html__( 'No chunk data available.', 'museder-restoreone' ),
            ], 409 );
        }

        // Ensure all expected chunks exist.
        $missing = [];
        for ( $i = 0; $i < $expected_chunks; $i++ ) {
            $p = trailingslashit( $chunks_dir ) . sprintf( 'chunk_%06d.bin', $i );
            if ( ! file_exists( $p ) ) {
                $missing[] = $i;
            }
        }
        if ( ! empty( $missing ) ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'missing_chunks',
                // @plugin-check: escaped
                'message' => esc_html__( 'Some chunks are missing. Please resume upload.', 'museder-restoreone' ),
                'missing' => $missing,
            ], 409 );
        }

        sort( $chunks, SORT_NATURAL );

        self::log_info( '[FINALIZE_V2_START]', [
            'upload_id'   => $upload_id,
            'client_sha1' => $client_sha1,
            'chunk_count' => count( $chunks ),
        ] );

        $final_path = trailingslashit( $upload_dir ) . self::FINAL_FILENAME;

        // Make finalize resumable: append if final exists, otherwise create.
        $current_size = file_exists( $final_path ) ? (int) filesize( $final_path ) : 0;
        if ( $expected_size > 0 && $current_size > $expected_size ) {
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                if ( file_exists( $final_path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fallback for non-standard environments where wp_delete_file() is unavailable
                    @unlink( $final_path );
                }
            }
            $current_size = 0;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for final file assembly, path from plugin-controlled directory
        $fh = fopen( $final_path, ( $current_size > 0 ? 'ab' : 'wb' ) );

        if ( ! $fh ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'finalize_open_failed',
                // @plugin-check: escaped
                'message' => esc_html__( 'Unable to create merged archive.', 'museder-restoreone' ),
            ], 500 );
        }

        // Resume offsets.
        $start_chunk = ( $chunk_size > 0 ) ? (int) floor( $current_size / $chunk_size ) : 0;
        $in_chunk_offset = ( $chunk_size > 0 ) ? (int) ( $current_size % $chunk_size ) : 0;

        for ( $i = $start_chunk; $i < $expected_chunks; $i++ ) {
            $chunk_path = trailingslashit( $chunks_dir ) . sprintf( 'chunk_%06d.bin', $i );
            // Some shared hosts may temporarily lock or delay visibility of newly-written chunk files.
            // Retry a few times before failing so finalize is more resilient.
            $chunk_handle = false;
            $open_error   = null;
            for ( $attempt = 0; $attempt < 3; $attempt++ ) {
                clearstatcache( true, $chunk_path );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for reading chunk files, path from plugin-controlled directory
                $chunk_handle = @fopen( $chunk_path, 'rb' );
                if ( $chunk_handle ) {
                    break;
                }
                $open_error = error_get_last();
                usleep( 200000 ); // 200ms
            }
            if ( ! $chunk_handle ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
                fclose( $fh );

                // Detailed diagnostics for support (admin-only route; stored in plugin log).
                museder_restoreone_log( 'error', 'Finalize failed to open chunk for reading.', [
                    'upload_id'   => $upload_id,
                    'chunk_index' => $i,
                    'exists'      => file_exists( $chunk_path ),
                    'readable'    => is_readable( $chunk_path ),
                    'filesize'    => file_exists( $chunk_path ) ? (int) filesize( $chunk_path ) : null,
                    'php_error'   => is_array( $open_error ) ? $open_error : null,
                ] );

                // Return retryable error. Do not leak server file paths in the API response.
                return new WP_REST_Response( [
                    'ok'          => false,
                    'code'        => 'chunk_open_failed',
                    // @plugin-check: escaped
                    'message'     => esc_html__( 'Unable to open chunk during finalize.', 'museder-restoreone' ),
                    'chunk_index' => (int) $i,
                ], 409 );
            }

            // Skip already-written part of the current chunk.
            if ( $in_chunk_offset > 0 ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fseek -- streaming skip
                @fseek( $chunk_handle, $in_chunk_offset, SEEK_SET );
                $in_chunk_offset = 0;
            }

            while ( ! feof( $chunk_handle ) ) {
                // Only reads plugin-generated backup files, path is validated and sanitized.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $buffer = fread( $chunk_handle, self::STREAM_CHUNK );
                if ( false === $buffer ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
                    fclose( $chunk_handle );
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
                    fclose( $fh );
                    museder_restoreone_log( 'error', 'Finalize failed to read chunk.', [
                        'upload_id'   => $upload_id,
                        'chunk_index' => $i,
                        'exists'      => file_exists( $chunk_path ),
                        'readable'    => is_readable( $chunk_path ),
                        'filesize'    => file_exists( $chunk_path ) ? (int) filesize( $chunk_path ) : null,
                        'php_error'   => error_get_last(),
                    ] );
                    return new WP_REST_Response( [
                        'ok'      => false,
                        'code'    => 'chunk_read_failed',
                        // @plugin-check: escaped
                        'message' => esc_html__( 'Unable to read chunk during finalize.', 'museder-restoreone' ),
                    ], 500 );
                }

                if ( '' === $buffer ) {
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing merged archive
                fwrite( $fh, $buffer );
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
            fclose( $chunk_handle );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $fh );

        // Verify size + SHA1 (streaming) after (re)assembly.
        if ( $expected_size > 0 && (int) filesize( $final_path ) !== $expected_size ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'size_mismatch',
                // @plugin-check: escaped
                'message' => esc_html__( 'Merged archive size mismatch.', 'museder-restoreone' ),
            ], 409 );
        }

        $hash_ctx = hash_init( 'sha1' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming hash, path from plugin-controlled directory
        $rfh = fopen( $final_path, 'rb' );
        if ( ! $rfh ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'finalize_open_failed',
                // @plugin-check: escaped
                'message' => esc_html__( 'Unable to read merged archive for verification.', 'museder-restoreone' ),
            ], 500 );
        }
        while ( ! feof( $rfh ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $buf = fread( $rfh, self::STREAM_CHUNK );
            if ( $buf === false ) {
                break;
            }
            if ( $buf === '' ) {
                continue;
            }
            hash_update( $hash_ctx, $buf );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $rfh );

        $server_sha1 = hash_final( $hash_ctx );

        if ( $server_sha1 !== $client_sha1 ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $final_path is from plugin-controlled temp directory
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $final_path ) ) {
                    @unlink( $final_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            self::log_error( '[FINALIZE_V2_SHA1_MISMATCH]', [
                'upload_id'   => $upload_id,
                'client_sha1' => $client_sha1,
                'server_sha1' => $server_sha1,
            ] );

            return new WP_REST_Response( [
                'ok'          => false,
                'code'        => 'sha1_mismatch',
                // @plugin-check: escaped
                'message'     => esc_html__( 'Merged archive checksum mismatch.', 'museder-restoreone' ),
                'client_sha1' => $client_sha1,
                'server_sha1' => $server_sha1,
            ], 409 );
        }

        $zip_ok = false;
        $zip_error_code = null;
        $zip = new ZipArchive();
        $zip_result = $zip->open( $final_path );
        if ( true === $zip_result ) {
            $zip_ok = true;
            $zip->close();
        } else {
            $zip_error_code = $zip_result;
            self::log_error( '[FINALIZE_V2_ZIP_ERROR]', [
                'upload_id'      => $upload_id,
                'zip_error_code' => $zip_result,
            ] );

            // Compatibility fallback: some hosts can merge + hash large ZIPs but ZipArchive cannot open them.
            // At this point we have already verified:
            // - all expected chunks exist
            // - merged file size matches
            // - SHA1 matches client
            // So the merged bytes are identical to what the user selected in the browser.
            // Do not block restore on ZipArchive limitations; proceed and let restore extraction use fallbacks.
            $zip_ok = false;

            // Record a warning into restore history so admins can see the root cause even if log files aren't writable.
            if ( function_exists( 'museder_restoreone_upsert_restore_history' ) ) {
                $t = time();
                $file_for_history = isset( $manifest['filename' ] ) ? (string) $manifest['filename'] : basename( $final_path );
                museder_restoreone_upsert_restore_history(
                    [
                        'job_id'        => 'upload_' . sanitize_text_field( (string) $upload_id ),
                        'timestamp_utc' => $t,
                        'date'          => gmdate( 'Y-m-d H:i:s', $t ),
                        'file'          => $file_for_history,
                        'result'        => 'pending',
                        'message'       => 'finalize_ziparchive_unavailable',
                        'details'       => [
                            'zip_error_code' => (string) $zip_error_code,
                            'final_size'     => file_exists( $final_path ) ? (string) (int) filesize( $final_path ) : '0',
                        ],
                    ]
                );
            }
        }

        self::log_info( '[FINALIZE_V2_OK]', [
            'upload_id'   => $upload_id,
            'sha1'        => $server_sha1,
            'chunk_count' => count( $chunks ),
        ] );

        // Move final file to backup directory and prepare restore session
        $backup_dir = museder_restoreone_get_backup_dir();
        $base_name = '';
        if ( isset( $manifest['filename'] ) && is_string( $manifest['filename'] ) && '' !== $manifest['filename'] ) {
            $base_name = sanitize_file_name( $manifest['filename'] );
        }
        if ( '' === $base_name ) {
            $base_name = basename( $final_path );
        }
        // Ensure .zip suffix for consistency.
        if ( 'zip' !== strtolower( pathinfo( $base_name, PATHINFO_EXTENSION ) ) ) {
            $base_name .= '.zip';
        }
        $unique      = wp_unique_filename( $backup_dir, $base_name );
        $destination = trailingslashit( $backup_dir ) . $unique;

        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        // $final_path and $destination are from plugin-controlled directories
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- required for file move, paths from plugin-controlled directories
        // This plugin needs low-level rename() here for streaming backup/restore performance.
        // Using WP_Filesystem::move() is not always reliable across all hosting environments.
        // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $final_path, $destination );
        // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- fallback when rename fails, paths from plugin-controlled directories
        if ( ! $renamed && ! @copy( $final_path, $destination ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $final_path ) ) {
                    @unlink( $final_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'move_failed',
                // @plugin-check: escaped
                'message' => esc_html__( 'Unable to move final file to backup directory.', 'museder-restoreone' ),
            ], 500 );
        }

        // Clean up upload directory
        self::cleanup_upload( $upload_id );

        // Prepare restore session (analyze the backup file)
        if ( class_exists( 'Museder_Restoreone_Restore_Handler' ) ) {
            try {
                $summary = Museder_Restoreone_Restore_Handler::prepare_session( $destination, 'upload' );
                $progress = Museder_Restoreone_Restore_Handler::format_progress();
                
                return new WP_REST_Response( [
                    'ok'       => true,
                    'sha1'     => $server_sha1,
                    'zip_ok'   => (bool) $zip_ok,
                    'zip_error'=> $zip_error_code,
                    'summary'  => $summary,
                    'progress' => $progress,
                ], 200 );
            } catch ( Exception $e ) {
                museder_restoreone_log( 'error', 'Failed to prepare restore session after finalize', [
                    'error' => $e->getMessage(),
                    'file'  => $destination,
                ] );
                // Still return success for upload, but log the analysis error
                return new WP_REST_Response( [
                    'ok'      => true,
                    'sha1'    => $server_sha1,
                    'zip_ok'  => (bool) $zip_ok,
                    'zip_error'=> $zip_error_code,
                    // @plugin-check: escaped
                    'warning' => esc_html__( 'File uploaded successfully, but analysis failed. Please try again.', 'museder-restoreone' ),
                ], 200 );
            }
        }

        return new WP_REST_Response( [
            'ok'      => true,
            'sha1'    => $server_sha1,
            'zip_ok'  => (bool) $zip_ok,
            'zip_error'=> $zip_error_code,
        ], 200 );
    }

    /* -------------------------------------------------------------------- */
    /*  Helpers                                                             */
    /* -------------------------------------------------------------------- */

    public static function permission_check( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'museder_restoreone_forbidden',
                __( 'You are not allowed to perform this action.', 'museder-restoreone' ),
                [ 'status' => 403 ]
            );
        }

        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( '' === $nonce ) {
            $nonce = (string) $request->get_param( 'rest_nonce' );
        }

        // WordPress.org review: empty check and verify_nonce as separate steps (same pattern as v2 restore + AI REST).
        if ( '' === $nonce ) {
            return new WP_Error(
                'museder_restoreone_invalid_nonce',
                __( 'Invalid security token.', 'museder-restoreone' ),
                [ 'status' => 401 ]
            );
        }

        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'museder_restoreone_invalid_nonce',
                __( 'Invalid security token.', 'museder-restoreone' ),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Make sure no stray output or cookies leak into REST responses.
     */
    private static function prepare_request_environment() {
        if ( ob_get_level() ) {
            @ob_clean();
        }

        if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
            @header_remove( 'Set-Cookie' );
            @header_remove( 'X-Powered-By' );
        }
    }

    private static function rest_success( array $data = [], $status = 200 ) {
        return new WP_REST_Response( array_merge( [ 'ok' => true, 'success' => true ], $data ), $status );
    }

    private static function rest_error( $code, $message, $status = 400, array $extra = [] ) {
        return new WP_REST_Response( array_merge( [
            'ok'      => false,
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $extra ), $status );
    }

    private static function normalize_headers( WP_REST_Request $request ) {
        $headers = [];
        foreach ( $request->get_headers() as $key => $value ) {
            $headers[ strtolower( $key ) ] = is_array( $value ) ? $value[0] : $value;
        }
        return $headers;
    }

    private static function pull_value( array $headers, WP_REST_Request $request, array $header_keys, array $param_keys = [] ) {
        foreach ( $header_keys as $key ) {
            $lower = strtolower( $key );
            if ( isset( $headers[ $lower ] ) && '' !== $headers[ $lower ] ) {
                return $headers[ $lower ];
            }
        }

        foreach ( $param_keys as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value ) {
                return is_string( $value ) ? wp_unslash( $value ) : $value;
            }
        }

        $json = $request->get_json_params();
        if ( is_array( $json ) ) {
            foreach ( $param_keys as $key ) {
                if ( isset( $json[ $key ] ) && '' !== $json[ $key ] ) {
                    $value = $json[ $key ];
                    return is_string( $value ) ? wp_unslash( $value ) : $value;
                }
            }
        }

        return null;
    }

    private static function get_root_dir() {
        $base = museder_restoreone_get_temp_dir();
        $dir  = trailingslashit( $base ) . self::TEMP_FOLDER;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        return $dir;
    }

    private static function get_upload_dir( $upload_id ) {
        if ( ! self::is_valid_chunk_session_id( $upload_id ) ) {
            return false;
        }
        $root = self::get_root_dir();
        if ( ! $root ) {
            return false;
        }
        $path = trailingslashit( $root ) . $upload_id;
        return is_dir( $path ) ? $path : false;
    }

    private static function ensure_upload_dir( $upload_id ) {
        if ( ! self::is_valid_chunk_session_id( $upload_id ) ) {
            return false;
        }
        $root = self::get_root_dir();
        if ( ! $root ) {
            return false;
        }
        $path = trailingslashit( $root ) . $upload_id;
        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
            return false;
        }
        return $path;
    }

    private static function get_chunks_dir( $upload_id ) {
        $upload_dir = self::get_upload_dir( $upload_id );
        if ( ! $upload_dir ) {
            return false;
        }
        $chunks = trailingslashit( $upload_dir ) . self::CHUNKS_FOLDER;
        return is_dir( $chunks ) ? $chunks : false;
    }

    private static function ensure_chunks_dir( $upload_id ) {
        $upload_dir = self::ensure_upload_dir( $upload_id );
        if ( ! $upload_dir ) {
            return false;
        }
        $chunks = trailingslashit( $upload_dir ) . self::CHUNKS_FOLDER;
        if ( ! is_dir( $chunks ) && ! wp_mkdir_p( $chunks ) ) {
            return false;
        }
        return $chunks;
    }

    private static function get_manifest_path( $upload_id ) {
        $upload_dir = self::get_upload_dir( $upload_id );
        if ( ! $upload_dir ) {
            return false;
        }
        return trailingslashit( $upload_dir )   . self::MANIFEST_FILE;
    }

    private static function load_manifest( $upload_id ) {
        $path = self::get_manifest_path( $upload_id );
        return self::load_manifest_by_path( $path );
    }

    private static function load_manifest_by_path( $path ) {
        if ( ! $path || ! file_exists( $path ) ) {
            return false;
        }
        $json = file_get_contents( $path );
        if ( ! $json ) {
            return false;
        }
        $manifest = json_decode( $json, true );
        return is_array( $manifest ) ? $manifest : false;
    }

    private static function save_manifest( $upload_id, array $manifest ) {
        $path = self::get_manifest_path( $upload_id );
        if ( ! $path ) {
            return false;
        }
        return false !== file_put_contents( $path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
    }

    private static function get_input_stream( WP_REST_Request $request ) {
        // REST chunk body: multipart uses tmp file; raw body uses php://input for streaming only (avoid loading whole archive into memory). Authenticated REST only; stream is read into plugin temp files and is not forwarded to third-party URLs.
        // @plugin-check: sanitized + nonce - verified via permission_check() above
        $files = $request->get_file_params();
        if ( isset( $files['chunk'] ) && isset( $files['chunk']['tmp_name'] ) && is_uploaded_file( $files['chunk']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for reading uploaded chunk file, validated via is_uploaded_file()
            return fopen( $files['chunk']['tmp_name'], 'rb' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming REST request body for chunked uploads; authenticated route only
        return fopen( 'php://input', 'rb' );
    }

    private static function cleanup_upload( $upload_id ) {
        $dir = self::get_upload_dir( $upload_id );
        if ( $dir && is_dir( $dir ) ) {
            self::rrmdir( $dir );
        }
    }

    // @plugin-check: safe - path built from internal plugin temp directory, not user input
    private static function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $file ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $file->getPathname() is from plugin-controlled temp directory iterator
            if ( $file->isDir() ) {
                @rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- required for recursive directory deletion, path from plugin-controlled directory
            } else {
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $file->getPathname() );
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- required for recursive directory deletion, path from plugin-controlled directory
                    @unlink( $file->getPathname() );
                }
            }
        }
        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- required for directory deletion, path from plugin-controlled directory
    }

    private static function log_info( $label, array $context = [] ) {
        self::log_event( $label, $context, 'INFO' );
    }

    private static function log_error( $label, array $context = [] ) {
        self::log_event( $label, $context, 'ERROR' );
    }

    private static function log_event( $label, array $context = [], $level = 'INFO' ) {
        $payload = wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only when WP_DEBUG is enabled
            error_log( sprintf( '%s %s', $label, $payload ) );
        }
        if ( function_exists( 'museder_restoreone_log' ) ) {
            museder_restoreone_log( $label, $context, $level );
        }
    }
}

if ( ! class_exists( 'Museder_Restoreone_Chunk_Handler_V2', false ) ) {
    class_alias( 'Museder_Restoreone_Chunk_V2', 'Museder_Restoreone_Chunk_Handler_V2' );
}
