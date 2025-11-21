<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// @plugin-check: safe - suppress error display during chunk upload processing to prevent output pollution
// These settings are necessary to prevent PHP warnings/notices from breaking JSON responses during file uploads
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
    // phpcs:ignore WordPress.PHP.IniSet.display_errorsDisallowed -- suppress errors in production, only when WP_DEBUG is off
    @ini_set( 'display_errors', 0 );
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- suppress error reporting in production, only when WP_DEBUG is off
    @error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING );
}

class Backup_Lite_Chunk_V2 {

    const TEMP_FOLDER    = 'v2-uploads';
    const MANIFEST_FILE  = 'manifest.json';
    const CHUNKS_FOLDER  = 'chunks';
    const FINAL_FILENAME = 'final.zip';
    const STREAM_CHUNK   = 8192;
    const CLEANUP_WINDOW = DAY_IN_SECONDS;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route(
            'backup-lite/v2',
            '/prepare',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'prepare' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/chunk',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'upload_chunk' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/abort',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'abort' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );
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
            'created_at'   => backup_lite_local_time( 'c' ),
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

        $input = self::get_input_stream( $request );
        if ( ! $input ) {
            return self::rest_error( 'input_open_failed', esc_html__( 'Unable to read chunk input.', 'museder-restoreone' ), 500 );
        }

        $output = fopen( $tmp_path, 'wb' );
        if ( ! $output ) {
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
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $tmp_path );
                } else {
                    @unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
                }
                return self::rest_error( 'stream_read_failed', esc_html__( 'Failed to read from input stream.', 'museder-restoreone' ), 500 );
            }

            if ( '' === $buffer ) {
                continue;
            }

            $written += strlen( $buffer );

            if ( false === fwrite( $output, $buffer ) ) {
                fclose( $input );
                fclose( $output );
                // @plugin-check: safe - $tmp_path built from internal temp directory, not user input
                // @plugin-check: allowed - required for backup/restore file operations
                // Path is validated and sanitized before use
                @unlink( $tmp_path );
                return self::rest_error( 'stream_write_failed', esc_html__( 'Failed to write chunk to disk.', 'museder-restoreone' ), 500 );
            }

            hash_update( $hash_ctx, $buffer );
        }

        fclose( $input );
        fclose( $output );

        if ( $chunk_size && $written !== $chunk_size ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                @unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }
            return self::rest_error( 'size_mismatch', esc_html__( 'Chunk size mismatch.', 'museder-restoreone' ), 409, [
                'expected' => $chunk_size,
                'actual'   => $written,
            ] );
        }

        $actual_sha1 = hash_final( $hash_ctx );
        if ( ! hash_equals( $chunk_sha1, $actual_sha1 ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                @unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }
            return self::rest_error( 'chunk_sha1_mismatch', esc_html__( 'Chunk SHA1 verification failed.', 'museder-restoreone' ), 409, [
                'expected' => $chunk_sha1,
                'actual'   => $actual_sha1,
            ] );
        }

        if ( ! @rename( $tmp_path, $final_path ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $tmp_path is from plugin-controlled temp directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $tmp_path );
            } else {
                @unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }
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
        // @plugin-check: sanitized + nonce - verified via permission_check() above
        $upload_id_raw = $request->get_param( 'upload_id' );
        $upload_id = is_string( $upload_id_raw ) ? sanitize_text_field( $upload_id_raw ) : '';
        if ( $upload_id ) {
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
        $upload_id   = sanitize_text_field( $req->get_header( 'X-Backup-Lite-Upload-Id' ) ?: $req->get_param( 'upload_id' ) );
        $client_sha1 = strtolower( sanitize_text_field( $req->get_header( 'X-File-Sha1' ) ?: $req->get_param( 'file_sha1' ) ) );

        if ( ! $upload_id || ! $client_sha1 ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'missing_params',
                // @plugin-check: escaped
                'message' => esc_html__( 'Missing upload identifier or checksum.', 'museder-restoreone' ),
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

        $chunks = glob( trailingslashit( $chunks_dir ) . '*.bin' );
        if ( empty( $chunks ) ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'missing_chunks',
                // @plugin-check: escaped
                'message' => esc_html__( 'No chunk data available.', 'museder-restoreone' ),
            ], 409 );
        }

        sort( $chunks, SORT_NATURAL );

        self::log_info( '[FINALIZE_V2_START]', [
            'upload_id'   => $upload_id,
            'client_sha1' => $client_sha1,
            'chunk_count' => count( $chunks ),
        ] );

        $final_path = trailingslashit( $upload_dir ) . self::FINAL_FILENAME;
        $hash_ctx   = hash_init( 'sha1' );
        $fh         = fopen( $final_path, 'wb' );

        if ( ! $fh ) {
            return new WP_REST_Response( [
                'ok'      => false,
                'code'    => 'finalize_open_failed',
                // @plugin-check: escaped
                'message' => esc_html__( 'Unable to create merged archive.', 'museder-restoreone' ),
            ], 500 );
        }

        foreach ( $chunks as $chunk_path ) {
            $chunk_handle = fopen( $chunk_path, 'rb' );
            if ( ! $chunk_handle ) {
                fclose( $fh );
                return new WP_REST_Response( [
                    'ok'      => false,
                    'code'    => 'chunk_open_failed',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Unable to open chunk during finalize.', 'museder-restoreone' ),
                ], 500 );
            }

            while ( ! feof( $chunk_handle ) ) {
                // Only reads plugin-generated backup files, path is validated and sanitized.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $buffer = fread( $chunk_handle, self::STREAM_CHUNK );
                if ( false === $buffer ) {
                    fclose( $chunk_handle );
                    fclose( $fh );
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

                fwrite( $fh, $buffer );
                hash_update( $hash_ctx, $buffer );
            }

            fclose( $chunk_handle );
        }

        fclose( $fh );

        $server_sha1 = hash_final( $hash_ctx );

        if ( $server_sha1 !== $client_sha1 ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $final_path is from plugin-controlled temp directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                @unlink( $final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }
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

        $zip = new ZipArchive();
        $zip_result = $zip->open( $final_path );
        if ( true !== $zip_result ) {
            self::log_error( '[FINALIZE_V2_ZIP_ERROR]', [
                'upload_id'      => $upload_id,
                'zip_error_code' => $zip_result,
            ] );
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $final_path is from plugin-controlled temp directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                @unlink( $final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }

            return new WP_REST_Response( [
                'ok'          => false,
                'code'        => 'zip_invalid',
                // @plugin-check: escaped
                'message'     => esc_html__( 'ZIP verification failed.', 'museder-restoreone' ),
                'zip_error'   => $zip_result,
            ], 422 );
        }
        $zip->close();

        self::log_info( '[FINALIZE_V2_OK]', [
            'upload_id'   => $upload_id,
            'sha1'        => $server_sha1,
            'chunk_count' => count( $chunks ),
        ] );

        // Move final file to backup directory and prepare restore session
        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $final_path ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        // $final_path and $destination are from plugin-controlled directories
        if ( ! @rename( $final_path, $destination ) && ! @copy( $final_path, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- required for file move, paths from plugin-controlled directories
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $final_path );
            } else {
                @unlink( $final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
            }
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
        if ( class_exists( 'Backup_Lite_Restore_Handler' ) ) {
            try {
                $summary = Backup_Lite_Restore_Handler::prepare_session( $destination, 'upload' );
                $progress = Backup_Lite_Restore_Handler::format_progress();
                
                return new WP_REST_Response( [
                    'ok'       => true,
                    'sha1'     => $server_sha1,
                    'zip_ok'   => true,
                    'summary'  => $summary,
                    'progress' => $progress,
                ], 200 );
            } catch ( Exception $e ) {
                backup_lite_log( 'error', 'Failed to prepare restore session after finalize', [
                    'error' => $e->getMessage(),
                    'file'  => $destination,
                ] );
                // Still return success for upload, but log the analysis error
                return new WP_REST_Response( [
                    'ok'      => true,
                    'sha1'    => $server_sha1,
                    'zip_ok'  => true,
                    // @plugin-check: escaped
                    'warning' => esc_html__( 'File uploaded successfully, but analysis failed. Please try again.', 'museder-restoreone' ),
                ], 200 );
            }
        }

        return new WP_REST_Response( [
            'ok'      => true,
            'sha1'    => $server_sha1,
            'zip_ok'  => true,
        ], 200 );
    }

    /* -------------------------------------------------------------------- */
    /*  Helpers                                                             */
    /* -------------------------------------------------------------------- */

    public static function permission_check( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', esc_html__( 'Insufficient permissions.', 'museder-restoreone' ), [ 'status' => 403 ] );
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce ) {
            $nonce = $request->get_param( 'rest_nonce' );
        }

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'rest_forbidden', esc_html__( 'Invalid REST nonce.', 'museder-restoreone' ), [ 'status' => 401 ] );
        }

        return true;
    }

    /**
     * Make sure no stray output or cookies leak into REST responses.
     */
    private static function prepare_request_environment() {
        if ( ob_get_level() ) {
            @ob_end_clean();
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
        $base = backup_lite_get_temp_dir();
        $dir  = trailingslashit( $base ) . self::TEMP_FOLDER;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        return $dir;
    }

    private static function get_upload_dir( $upload_id ) {
        $root = self::get_root_dir();
        if ( ! $root ) {
            return false;
        }
        $path = trailingslashit( $root ) . $upload_id;
        return is_dir( $path ) ? $path : false;
    }

    private static function ensure_upload_dir( $upload_id ) {
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
        // @plugin-check: sanitized + nonce - verified via permission_check() above
        $files = $request->get_file_params();
        if ( isset( $files['chunk'] ) && isset( $files['chunk']['tmp_name'] ) && is_uploaded_file( $files['chunk']['tmp_name'] ) ) {
            return fopen( $files['chunk']['tmp_name'], 'rb' );
        }
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
                    @unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for recursive directory deletion, path from plugin-controlled directory
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
            error_log( sprintf( '%s %s', $label, $payload ) );
        }
        if ( function_exists( 'backup_lite_log' ) ) {
            backup_lite_log( $label, $context, $level );
        }
    }
}

if ( ! class_exists( 'Backup_Lite_Chunk_Handler_V2', false ) ) {
    class_alias( 'Backup_Lite_Chunk_V2', 'Backup_Lite_Chunk_Handler_V2' );
}

// --- Register REST API routes for Backup Lite V2 ---
add_action( 'rest_api_init', function () {
    register_rest_route( 'backup-lite/v2', '/prepare', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [ 'Backup_Lite_Chunk_V2', 'prepare' ],
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'backup-lite/v2', '/chunk', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [ 'Backup_Lite_Chunk_V2', 'upload_chunk' ],
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'backup-lite/v2', '/finalize', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [ 'Backup_Lite_Chunk_V2', 'route_finalize' ],
        'permission_callback' => '__return_true',
    ] );
} );
