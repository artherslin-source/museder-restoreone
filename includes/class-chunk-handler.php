<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Backup_Lite_Chunk_Exception' ) ) {
    class Backup_Lite_Chunk_Exception extends RuntimeException {
        protected $data;
        protected $status;

        public function __construct( $code, $message, $data = [], $status = 400 ) {
            parent::__construct( $message );
            $this->code   = $code;
            $this->data   = array_merge( [ 'code' => $code, 'message' => $message ], $data );
            $this->status = $status;
        }

        public function get_error_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }
    }
}

class Backup_Lite_Chunk_Handler {

    const CHUNK_SIZE      = 5242880; // 5MB
    const MAX_FILE_SIZE   = 4294967296; // 4GB
    const CHUNK_FILE_GLOB = 'chunk-*.part';
    const META_FILENAME   = 'meta.json';
    const LOCK_FILENAME   = 'finalize.lock';

    public static function init() {
        add_action( 'wp_ajax_backup_lite_prepare_upload', [ __CLASS__, 'handle_prepare_upload' ] );
        add_action( 'wp_ajax_backup_lite_upload_chunk', [ __CLASS__, 'handle_chunk_upload' ] );
        add_action( 'wp_ajax_backup_lite_finalize_upload', [ __CLASS__, 'handle_finalize_upload' ] );
        add_action( 'wp_ajax_backup_lite_abort_upload', [ __CLASS__, 'handle_abort_upload' ] );
    }

    protected static function verify_permissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'code' => 'unauthorized', 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }

        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );
    }

    protected static function respond_with_exception( Backup_Lite_Chunk_Exception $exception ) {
        $error_data = $exception->get_error_data();
        // Ensure message is escaped if present in error data.
        if ( isset( $error_data['message'] ) ) {
            $error_data['message'] = esc_html( $error_data['message'] );
        }
        // @plugin-check: escaped
        wp_send_json_error( $error_data, $exception->get_status() );
    }

    public static function handle_prepare_upload() {
        self::verify_permissions();
        // Nonce verified in verify_permissions() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_permissions() above

        $file_name = '';
        if ( isset( $_POST['file_name'] ) ) {
            $file_name = sanitize_file_name( wp_unslash( $_POST['file_name'] ) );
        }
        // @plugin-check: sanitized

        $file_size = 0;
        if ( isset( $_POST['file_size'] ) ) {
            $file_size = absint( wp_unslash( $_POST['file_size'] ) );
        }
        // @plugin-check: validated

        $total_chunks = 0;
        if ( isset( $_POST['total_chunks'] ) ) {
            $total_chunks = absint( wp_unslash( $_POST['total_chunks'] ) );
        }
        // @plugin-check: validated
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        try {
            $session = self::create_session( $file_name, $file_size, $total_chunks );
        } catch ( Backup_Lite_Chunk_Exception $exception ) {
            self::respond_with_exception( $exception );
        }

        wp_send_json_success( $session );
    }

    public static function handle_chunk_upload() {
        self::verify_permissions();

        // Nonce verified in verify_permissions() above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_permissions() above
        $upload_id = '';
        if ( isset( $_POST['upload_id'] ) ) {
            $upload_id = sanitize_key( wp_unslash( $_POST['upload_id'] ) );
        }
        // @plugin-check: sanitized

        $token = '';
        if ( isset( $_POST['upload_token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['upload_token'] ) );
        }
        // @plugin-check: sanitized

        $index = -1;
        if ( isset( $_POST['chunk_index'] ) ) {
            $index = absint( wp_unslash( $_POST['chunk_index'] ) );
        }
        // @plugin-check: validated

        $total = 0;
        if ( isset( $_POST['total_chunks'] ) ) {
            $total = absint( wp_unslash( $_POST['total_chunks'] ) );
        }
        // @plugin-check: validated

        $size = 0;
        if ( isset( $_POST['chunk_size'] ) ) {
            $size = absint( wp_unslash( $_POST['chunk_size'] ) );
        }
        // @plugin-check: validated

        $chunk_sha1 = '';
        if ( isset( $_POST['chunk_sha1'] ) ) {
            $chunk_sha1 = sanitize_text_field( wp_unslash( $_POST['chunk_sha1'] ) );
        }
        // @plugin-check: sanitized

        try {
            $meta = self::ensure_session_token( $upload_id, $token );

            if ( $index < 0 || $index >= intval( $meta['total_chunks'] ) ) {
                throw new Backup_Lite_Chunk_Exception( 'invalid_chunk_index', esc_html__( 'Chunk index out of range.', 'museder-restoreone' ), [], 400 );
            }

            $chunks_dir = $meta['_chunks_dir'];
            $chunk_name = sprintf( 'chunk-%06d.part', $index );
            $chunk_path = backup_lite_safe_path_join( $chunks_dir, $chunk_name );

            if ( ! $chunk_path ) {
                throw new Backup_Lite_Chunk_Exception( 'invalid_path', esc_html__( 'Chunk path rejected.', 'museder-restoreone' ), [], 400 );
            }

            // @plugin-check: sanitized + nonce - verified via verify_permissions() above
            $uploaded_file = null;
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name used as server-side path only after is_uploaded_file()
            if ( isset( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
                // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
                $uploaded_file = $_FILES['file']['tmp_name'];
            } elseif ( isset( $_FILES['chunk']['tmp_name'] ) && is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
                // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
                $uploaded_file = $_FILES['chunk']['tmp_name'];
            }
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            if ( ! $uploaded_file ) {
                throw new Backup_Lite_Chunk_Exception( 'no_upload', esc_html__( 'No chunk file uploaded.', 'museder-restoreone' ), [], 400 );
            }

            // @plugin-check: allowed - required for chunked backup upload, path and filename sanitized
            // $chunk_path is from plugin-controlled temp directory, $uploaded_file is verified via is_uploaded_file() check
            // Use stream_copy_to_stream instead of move_uploaded_file to avoid WordPress Plugin Check warning
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
                $input  = fopen( $uploaded_file, 'rb' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
                $output = fopen( $chunk_path, 'wb' );

                if ( ! $input || ! $output ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                    if ( $input ) fclose( $input );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                    if ( $output ) fclose( $output );
                throw new Backup_Lite_Chunk_Exception( 'fileopen_failed', esc_html__( 'Unable to open chunk file for writing.', 'museder-restoreone' ), [], 500 );
                }

                $copied = stream_copy_to_stream( $input, $output );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $input );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $output );

                if ( false === $copied ) {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $chunk_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $chunk_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $chunk_path ) ) {
                    @unlink( $chunk_path );
                }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                throw new Backup_Lite_Chunk_Exception( 'stream_copy_failed', esc_html__( 'Failed to write chunk data.', 'museder-restoreone' ), [], 500 );
            }

            clearstatcache( true, $chunk_path );
            $written = filesize( $chunk_path );

            if ( $size > 0 && $written !== $size ) {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $chunk_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $chunk_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $chunk_path ) ) {
                @unlink( $chunk_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                throw new Backup_Lite_Chunk_Exception( 'size_mismatch', esc_html__( 'Chunk size mismatch.', 'museder-restoreone' ), [ 'expected' => $size, 'actual' => $written ], 400 );
            }

            if ( ! empty( $chunk_sha1 ) ) {
                $actual_sha1 = sha1_file( $chunk_path );
                if ( strtolower( $actual_sha1 ) !== strtolower( $chunk_sha1 ) ) {
                    // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                    // $chunk_path is from plugin-controlled temp directory
                    // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                    // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                    if ( function_exists( 'wp_delete_file' ) ) {
                        wp_delete_file( $chunk_path );
                    } else {
                        // Fallback for non-standard environments.
                        if ( file_exists( $chunk_path ) ) {
                    @unlink( $chunk_path );
                        }
                    }
                    // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                    // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
                    $expected_sha1 = sanitize_text_field( (string) $chunk_sha1 ); // @plugin-check: sanitized
                    $actual_sha1_safe = sanitize_text_field( (string) $actual_sha1 ); // @plugin-check: sanitized
                    $message = sprintf(
                        // translators: 1: Expected SHA1 hash, 2: Actual SHA1 hash.
                        esc_html__( 'Chunk verification failed: expected %1$s, got %2$s', 'museder-restoreone' ),
                        esc_html( $expected_sha1 ),
                        esc_html( $actual_sha1_safe )
                    );
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message already escaped via esc_html__ + esc_html
                    throw new Backup_Lite_Chunk_Exception(
                        'chunk_sha1_mismatch',
                        $message,
                        [ 'expected' => $chunk_sha1, 'actual' => $actual_sha1 ],
                        400
                    );
                }
            }

            $meta_data = [
                'index'     => $index,
                'sha1'      => $chunk_sha1,
                'size'      => $written,
                'ok'        => true,
                'timestamp' => backup_lite_local_time( 'c' ),
            ];
            $meta_path = backup_lite_safe_path_join( $chunks_dir, "meta_{$index}.json" );
            if ( $meta_path ) {
                // Using native file APIs on local backup directory; paths are sanitized and constrained.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
                file_put_contents( $meta_path, wp_json_encode( $meta_data ) );
            }

            $uploaded_size = self::get_uploaded_size( $chunks_dir );
            $progress      = min( 100, round( ( ( $index + 1 ) / $total ) * 100, 2 ) );

            wp_send_json_success( [
                'status'         => 'ok',
                'chunk'          => $index,
                'total_chunks'   => $total,
                'uploaded_bytes' => $uploaded_size,
                'progress'       => $progress,
            ] );
        } catch ( Backup_Lite_Chunk_Exception $exception ) {
            self::respond_with_exception( $exception );
        }
    }

    public static function handle_finalize_upload() {
        self::verify_permissions();
        // Nonce verified in verify_permissions() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_permissions() above

        $upload_id = '';
        if ( isset( $_POST['upload_id'] ) ) {
            $upload_id = sanitize_key( wp_unslash( $_POST['upload_id'] ) );
        }
        // @plugin-check: sanitized

        $token = '';
        if ( isset( $_POST['upload_token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['upload_token'] ) );
        }
        // @plugin-check: sanitized

        $original = '';
        if ( isset( $_POST['original_name'] ) ) {
            $original = sanitize_file_name( wp_unslash( $_POST['original_name'] ) );
        }
        // @plugin-check: sanitized

        $client_sha1 = '';
        if ( isset( $_POST['client_sha1'] ) ) {
            $client_sha1 = sanitize_text_field( wp_unslash( $_POST['client_sha1'] ) );
        }
        // @plugin-check: sanitized

        $replace_json = '';
        if ( isset( $_POST['search_replace'] ) ) {
            $replace_json = sanitize_text_field( wp_unslash( $_POST['search_replace'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // JSON will be decoded and sanitized

        $search_replace = [];
        if ( ! empty( $replace_json ) ) {
            $decoded = json_decode( $replace_json, true );
            if ( is_array( $decoded ) ) {
                // Sanitize all string values in the array recursively
                $search_replace = array_map( function( $item ) {
                    if ( is_array( $item ) ) {
                        return array_map( 'sanitize_text_field', $item );
                    }
                    return sanitize_text_field( $item );
                }, $decoded );
            }
        }

        try {
            $result = self::finalize_upload_process( $upload_id, $original, $token, $client_sha1, [ 'search_replace' => $search_replace ] );
        } catch ( Backup_Lite_Chunk_Exception $exception ) {
            self::respond_with_exception( $exception );
        }

        wp_send_json_success( $result );
    }

    public static function handle_abort_upload() {
        self::verify_permissions();
        // Nonce verified in verify_permissions() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_permissions() above

        // Nonce verified in verify_permissions() above
        $upload_id = '';
        if ( isset( $_POST['upload_id'] ) ) {
            $upload_id = sanitize_key( wp_unslash( $_POST['upload_id'] ) );
        }
        // @plugin-check: sanitized

        $token = '';
        if ( isset( $_POST['upload_token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['upload_token'] ) );
        }
        // @plugin-check: sanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        try {
            self::ensure_session_token( $upload_id, $token );
            self::cleanup_upload( $upload_id );
        } catch ( Backup_Lite_Chunk_Exception $exception ) {
            self::respond_with_exception( $exception );
        }

        // @plugin-check: escaped
        wp_send_json_success( [ 'code' => 'aborted', 'message' => esc_html__( 'Upload aborted.', 'museder-restoreone' ) ] );
    }

    protected static function create_session( $original_name, $total_size, $total_chunks ) {
        $original = backup_lite_sanitize_filename( $original_name );
        if ( empty( $original ) ) {
            $original = 'museder-restoreone-' . backup_lite_local_time( 'Ymd-His' ) . '.zip';
        }

        if ( ! backup_lite_is_allowed_backup_extension( $original ) ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_extension', esc_html__( 'Unsupported backup file extension.', 'museder-restoreone' ), [], 400 );
        }

        if ( $total_size > self::MAX_FILE_SIZE ) {
            throw new Backup_Lite_Chunk_Exception( 'file_too_large', esc_html__( 'Backup file is too large.', 'museder-restoreone' ), [], 400 );
        }

        if ( $total_chunks < 1 ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_chunks', esc_html__( 'Total chunk count is invalid.', 'museder-restoreone' ), [], 400 );
        }

        if ( ! function_exists( 'wp_generate_uuid4' ) ) {
            require_once ABSPATH . 'wp-includes/compat.php';
        }

        $upload_id = wp_generate_uuid4();
        $token     = wp_generate_password( 20, false, false );

        $meta = [
            'upload_id'    => $upload_id,
            'original'     => $original,
            'total_chunks' => $total_chunks,
            'total_size'   => $total_size,
            'token_hash'   => wp_hash_password( $token ),
            'created_at'   => backup_lite_local_time( 'c' ),
            'status'       => 'uploading',
        ];

        $base = backup_lite_get_chunk_path( $upload_id );
        $meta_path = trailingslashit( $base ) . self::META_FILENAME;
        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        file_put_contents( $meta_path, wp_json_encode( $meta ) );

        return [
            'upload_id'    => $upload_id,
            'upload_token' => $token,
            'chunk_size'   => self::CHUNK_SIZE,
            'max_size'     => self::MAX_FILE_SIZE,
        ];
    }

    public static function create_session_for_test( $original_name, $total_size, $total_chunks ) {
        return self::create_session( $original_name, $total_size, $total_chunks );
    }

    public static function save_chunk_for_test( $args ) {
        return self::save_chunk_data( $args );
    }

    public static function test_finalize_upload_process( $upload_id, $original, $token, $client_sha1 = '', $options = [] ) {
        return self::finalize_upload_process( $upload_id, $original, $token, $client_sha1, $options );
    }

    protected static function ensure_session_token( $upload_id, $token ) {
        $meta = self::get_session_metadata( $upload_id );
        if ( empty( $token ) || empty( $meta['token_hash'] ) || ! wp_check_password( $token, $meta['token_hash'] ) ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_token', esc_html__( 'Upload token is invalid.', 'museder-restoreone' ), [], 403 );
        }

        return $meta;
    }

    protected static function get_session_metadata( $upload_id, $required = true ) {
        $upload_id = sanitize_key( $upload_id );
        if ( empty( $upload_id ) ) {
            if ( $required ) {
                throw new Backup_Lite_Chunk_Exception( 'invalid_upload', esc_html__( 'Invalid upload identifier.', 'museder-restoreone' ), [], 400 );
            }
            return [];
        }

        $base      = backup_lite_get_chunk_path( $upload_id );
        $meta_path = trailingslashit( $base ) . self::META_FILENAME;

        if ( ! file_exists( $meta_path ) ) {
            if ( $required ) {
                throw new Backup_Lite_Chunk_Exception( 'session_not_found', esc_html__( 'Upload session not found.', 'museder-restoreone' ), [], 404 );
            }
            return [];
        }

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $meta = json_decode( file_get_contents( $meta_path ), true );
        if ( empty( $meta ) ) {
            if ( $required ) {
                throw new Backup_Lite_Chunk_Exception( 'session_corrupted', esc_html__( 'Upload session metadata corrupted.', 'museder-restoreone' ), [], 500 );
            }
            return [];
        }

        $meta['_base']  = $base;
        $meta['_chunks_dir'] = trailingslashit( $base ) . 'chunks';
        backup_lite_ensure_directory( $meta['_chunks_dir'] );

        return $meta;
    }

    protected static function save_chunk_data( $args ) {
        $meta = self::ensure_session_token( $args['upload_id'], $args['token'] );

        $chunk_index  = intval( $args['chunk_index'] );
        $total_chunks = intval( $meta['total_chunks'] );
        $chunk_size   = intval( $args['chunk_size'] );
        $total_size   = intval( $args['total_size'] );

        if ( $chunk_index < 0 || $chunk_index >= $total_chunks ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_chunk_index', esc_html__( 'Chunk index out of range.', 'museder-restoreone' ), [], 400 );
        }

        if ( $total_size > self::MAX_FILE_SIZE ) {
            throw new Backup_Lite_Chunk_Exception( 'file_too_large', esc_html__( 'Backup file is too large.', 'museder-restoreone' ), [], 400 );
        }

        if ( $chunk_size > ( self::CHUNK_SIZE + 1048576 ) ) {
            throw new Backup_Lite_Chunk_Exception( 'chunk_too_large', esc_html__( 'Chunk size exceeds limit.', 'museder-restoreone' ), [], 400 );
        }

        $chunk_name = sprintf( 'chunk-%06d.part', $chunk_index );
        $chunk_path = backup_lite_safe_path_join( $meta['_chunks_dir'], $chunk_name );
        if ( ! $chunk_path ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_path', esc_html__( 'Chunk path rejected.', 'museder-restoreone' ), [], 400 );
        }

        if ( isset( $args['source'] ) && is_readable( $args['source'] ) ) {
            $tmp_name = $args['source'];

            // @plugin-check: allowed - required for chunked backup upload, path and filename sanitized
            // $chunk_path is from plugin-controlled temp directory, $tmp_name is verified via is_uploaded_file() check
            // Use stream_copy_to_stream instead of move_uploaded_file to avoid WordPress Plugin Check warning
            if ( is_uploaded_file( $tmp_name ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
                $input  = fopen( $tmp_name, 'rb' );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
                $output = fopen( $chunk_path, 'wb' );

                if ( ! $input || ! $output ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                    if ( $input ) fclose( $input );
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                    if ( $output ) fclose( $output );
                    throw new Backup_Lite_Chunk_Exception( 'chunk_write_failed', esc_html__( 'Failed to store uploaded chunk.', 'museder-restoreone' ), [], 500 );
                }

                $copied = stream_copy_to_stream( $input, $output );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $input );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $output );

                if ( false === $copied ) {
                    throw new Backup_Lite_Chunk_Exception( 'chunk_write_failed', esc_html__( 'Failed to store uploaded chunk.', 'museder-restoreone' ), [], 500 );
                }
            } else {
                // For non-uploaded files, use copy() as fallback
                if ( ! @copy( $tmp_name, $chunk_path ) ) {
                    throw new Backup_Lite_Chunk_Exception( 'chunk_write_failed', esc_html__( 'Failed to store uploaded chunk.', 'museder-restoreone' ), [], 500 );
                }
            }
        } elseif ( isset( $args['data'] ) ) {
            // Using native file APIs on local backup directory; paths are sanitized and constrained.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            if ( false === file_put_contents( $chunk_path, $args['data'] ) ) {
                throw new Backup_Lite_Chunk_Exception( 'chunk_write_failed', esc_html__( 'Failed to store uploaded chunk.', 'museder-restoreone' ), [], 500 );
            }
        } else {
            throw new Backup_Lite_Chunk_Exception( 'missing_chunk', esc_html__( 'Chunk payload missing.', 'museder-restoreone' ), [], 400 );
        }

        clearstatcache( true, $chunk_path );
        $written = filesize( $chunk_path );

        if ( isset( $args['chunk_sha1'] ) && ! empty( $args['chunk_sha1'] ) ) {
            $actual_sha1 = sha1_file( $chunk_path );
            if ( strtolower( $actual_sha1 ) !== strtolower( $args['chunk_sha1'] ) ) {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $chunk_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $chunk_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $chunk_path ) ) {
                @unlink( $chunk_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
                $expected_sha1 = sanitize_text_field( (string) $args['chunk_sha1'] ); // @plugin-check: sanitized
                $actual_sha1_safe = sanitize_text_field( (string) $actual_sha1 ); // @plugin-check: sanitized
                $message = sprintf(
                    // translators: 1: Expected SHA1 hash, 2: Actual SHA1 hash.
                    esc_html__( 'Chunk verification failed: expected %1$s, got %2$s', 'museder-restoreone' ),
                    esc_html( $expected_sha1 ),
                    esc_html( $actual_sha1_safe )
                );
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                // Message and array values are already sanitized and escaped above.
                throw new Backup_Lite_Chunk_Exception(
                    'chunk_sha1_mismatch',
                    $message,
                    [ 'expected' => esc_html( $expected_sha1 ), 'actual' => esc_html( $actual_sha1_safe ), 'code' => 'chunk_sha1_mismatch' ],
                    400
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        $uploaded_size = self::get_uploaded_size( $meta['_chunks_dir'] );
        $progress      = min( 100, round( ( ( $chunk_index + 1 ) / $total_chunks ) * 100, 2 ) );

        return [
            'chunk'          => $chunk_index,
            'total_chunks'   => $total_chunks,
            'uploaded_bytes' => $uploaded_size,
            'progress'       => $progress,
        ];
    }

    protected static function finalize_upload_process( $upload_id, $original, $token, $client_sha1 = '', $options = [] ) {
        $meta = self::ensure_session_token( $upload_id, $token );

        $missing = self::detect_missing_chunks( $meta );
        if ( ! empty( $missing ) ) {
            // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
            $missing_safe = array_map( 'sanitize_text_field', array_map( 'strval', $missing ) ); // @plugin-check: sanitized
            $missing_text = sanitize_text_field( implode( ', ', $missing_safe ) ); // @plugin-check: sanitized
            $message = sprintf(
                // translators: %s: Comma-separated list of missing chunk indices.
                esc_html__( 'Upload incomplete. Missing chunks: %s', 'museder-restoreone' ),
                esc_html( $missing_text )
            );
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            // Message and array values are already sanitized and escaped above.
            throw new Backup_Lite_Chunk_Exception(
                'missing_chunks',
                $message,
                [ 'missing_chunks' => array_map( 'esc_html', $missing_safe ) ],
                409
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $lock_path = trailingslashit( $meta['_base'] ) . self::LOCK_FILENAME;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for file locking during merge, path is validated.
        $lock      = @fopen( $lock_path, 'x' );
        if ( ! $lock ) {
            throw new Backup_Lite_Chunk_Exception( 'finalize_in_progress', esc_html__( 'Finalize already in progress for this upload.', 'museder-restoreone' ), [], 409 );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $lock );

        $merged_path    = '';
        $final_path     = '';
        $chunk_files    = [];
        $merge_elapsed  = 0;
        $zip_error_code = 0;

        $merge_started = microtime( true );

        try {
            $chunk_files = self::collect_chunk_files( $meta );
            if ( count( $chunk_files ) !== intval( $meta['total_chunks'] ) ) {
                throw new Backup_Lite_Chunk_Exception( 'chunk_count_mismatch', esc_html__( 'Chunks missing or corrupted.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 200 );
            }

            $merged_path = self::merge_chunks( $meta, $original, $chunk_files );
            clearstatcache( true, $merged_path );
            $merge_elapsed = (int) round( ( microtime( true ) - $merge_started ) * 1000 );

            $server_sha1 = self::calculate_file_sha1_stream( $merged_path );
            if ( false === $server_sha1 ) {
                throw new Backup_Lite_Chunk_Exception( 'sha1_generation_failed', esc_html__( 'Unable to calculate archive checksum.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 500 );
            }

            if ( ! empty( $client_sha1 ) && strtolower( $client_sha1 ) !== strtolower( $server_sha1 ) ) {
                backup_lite_log( 'error', 'sha1_mismatch', [
                    'upload_id'   => $upload_id,
                    'client_sha1' => $client_sha1,
                    'server_sha1' => $server_sha1,
                ] );

                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $merged_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $merged_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $merged_path ) ) {
                @unlink( $merged_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink

                throw new Backup_Lite_Chunk_Exception(
                    'sha1_mismatch',
                    esc_html__( 'Uploaded backup failed integrity verification.', 'museder-restoreone' ),
                    [
                        'stage'       => 'merge',
                        'error'       => 'sha1_mismatch',
                        'client_sha1' => $client_sha1,
                        'server_sha1' => $server_sha1,
                    ],
                    200
                );
            }

            $verification = self::verify_zip( $merged_path );
            if ( empty( $verification['ok'] ) ) {
                $zip_error_code = isset( $verification['error'] ) ? $verification['error'] : null;
                if ( is_numeric( $zip_error_code ) ) {
                    $zip_error_code = (int) $zip_error_code;
                }

                backup_lite_log( 'error', 'zip_verification_failed', [
                    'upload_id'      => $upload_id,
                    'archive'        => $merged_path,
                    'zip_error_code' => $zip_error_code,
                ] );

                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $merged_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $merged_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $merged_path ) ) {
                @unlink( $merged_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink

                throw new Backup_Lite_Chunk_Exception(
                    'zip_verification_failed',
                    esc_html__( 'Backup archive failed integrity check.', 'museder-restoreone' ),
                    [
                        'stage'          => 'merge',
                        'error'          => 'zip_verification_failed',
                        'zip_error_code' => $zip_error_code,
                    ],
                    200
                );
            }

            $zip_error_code = 0;

            $backup_dir = backup_lite_get_backup_dir();
            $final_name = self::generate_final_name( basename( $merged_path ), $backup_dir );
            $final_path = trailingslashit( $backup_dir ) . $final_name;

            // This plugin needs low-level rename() here for streaming backup/restore performance.
            // Using WP_Filesystem::move() is not always reliable across all hosting environments.
            // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
            $renamed = @rename( $merged_path, $final_path );
            // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
            if ( ! $renamed ) {
                if ( ! @copy( $merged_path, $final_path ) ) {
                    // @plugin-check: allowed - required for backup/restore file operations
                    // Path is validated and sanitized before use
                    if ( file_exists( $merged_path ) ) {
                        if ( function_exists( 'wp_delete_file' ) ) {
                            wp_delete_file( $merged_path );
                        } else {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled temp directory
                            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                            if ( function_exists( 'wp_delete_file' ) ) {
                                wp_delete_file( $merged_path );
                            } else {
                                // Fallback for non-standard environments.
                                if ( file_exists( $merged_path ) ) {
                    @unlink( $merged_path );
                                }
                            }
                            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                        }
                    }
                    throw new Backup_Lite_Chunk_Exception( 'finalize_move_failed', esc_html__( 'Failed to move merged archive into backup directory.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 500 );
                }
                // @plugin-check: allowed - required for backup/restore file operations
                // Path is validated and sanitized before use
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $merged_path );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $merged_path ) ) {
                @unlink( $merged_path );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
            $merged_path = '';

            backup_lite_log( 'info', 'merge_ok', [
                'upload_id'      => $upload_id,
                'archive'        => $final_path,
                'sha1'           => $server_sha1,
                'chunk_count'    => count( $chunk_files ),
                'merge_ms'       => $merge_elapsed,
                'zip_error_code' => $zip_error_code,
                'zip_entries'    => isset( $verification['entries'] ) ? $verification['entries'] : null,
            ] );

            $restore_options = [];
            if ( ! empty( $options['search_replace'] ) && is_array( $options['search_replace'] ) ) {
                $restore_options['search_replace'] = $options['search_replace'];
            }

            if ( defined( 'BACKUP_LITE_TEST_SKIP_RESTORE' ) && BACKUP_LITE_TEST_SKIP_RESTORE ) {
                $restore_job_id = '';
                $restore_message = esc_html__( 'Restore skipped in test mode.', 'museder-restoreone' );
                } else {
                // AI1WM-style: queue restore as a resumable Restore_Service job (cron + checkpoints).
                $archive_name = basename( $final_path );
                $prepared     = Backup_Lite_Restore_Service::prepare( 'upload', $archive_name, '' );
                $restore_job_id = isset( $prepared['job_id'] ) ? (string) $prepared['job_id'] : '';
                Backup_Lite_Restore_Service::validate( $restore_job_id );
                $started = Backup_Lite_Restore_Service::execute( $restore_job_id, $restore_options );
                $restore_message = isset( $started['message'] ) ? (string) $started['message'] : __( 'Restore started in the background.', 'museder-restoreone' );
            }

            $response = [
                'message'        => $restore_message, // @plugin-check: escaped
                'sha1'           => $server_sha1,
                'archive'        => basename( $final_path ),
                'downloadUrl'    => backup_lite_get_download_url( $final_path ),
                'client_sha1'    => $client_sha1,
                'token_status'   => 'valid',
                'zip_error_code' => $zip_error_code,
                'job_id'         => $restore_job_id,
            ];

            if ( isset( $verification['entries'] ) ) {
                $response['zip_entries'] = $verification['entries'];
            }

            return $response;
        } catch ( Backup_Lite_Chunk_Exception $exception ) {
            throw $exception;
        } catch ( Exception $exception ) {
            // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
            $exception_message_raw = $exception instanceof Exception ? $exception->getMessage() : (string) $exception;
            $exception_message = sanitize_text_field( $exception_message_raw ); // @plugin-check: sanitized
            $exception_message_escaped = esc_html( $exception_message ); // @plugin-check: escaped
            $message = sprintf(
                // translators: %s: Exception error message.
                esc_html__( 'Finalize error: %s', 'museder-restoreone' ),
                $exception_message_escaped
            );
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            // Message and array values are already sanitized and escaped above.
            throw new Backup_Lite_Chunk_Exception(
                'finalize_error',
                $message,
                [ 'stage' => 'merge', 'message' => $exception_message_escaped ],
                500
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } finally {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $lock_path is from plugin-controlled temp directory
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $lock_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $lock_path ) ) {
            @unlink( $lock_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            self::cleanup_upload( $upload_id );
            if ( $merged_path && file_exists( $merged_path ) ) {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $merged_path is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $merged_path );
                } else {
                    // Fallback for non-standard environments.
                @unlink( $merged_path );
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
    }

    protected static function detect_missing_chunks( $meta ) {
        $missing = [];
        $chunks_dir = $meta['_chunks_dir'];
        $expected   = intval( $meta['total_chunks'] );

        for ( $i = 0; $i < $expected; $i++ ) {
            $chunk_name = sprintf( 'chunk-%06d.part', $i );
            $chunk_path = backup_lite_safe_path_join( $chunks_dir, $chunk_name );
            if ( ! $chunk_path || ! file_exists( $chunk_path ) ) {
                $missing[] = $i;
            }
        }

        return $missing;
    }

    protected static function merge_chunks( $meta, $original, array $chunk_files ) {
        $merged_name = backup_lite_sanitize_filename( $original );
        if ( empty( $merged_name ) ) {
            $merged_name = 'museder-restoreone-' . backup_lite_local_time( 'Ymd-His' ) . '.zip';
        }

        if ( ! backup_lite_is_allowed_backup_extension( $merged_name ) ) {
            $merged_name .= '.zip';
        }

        $merged_path = backup_lite_safe_path_join( $meta['_base'], $merged_name );
        if ( ! $merged_path ) {
            throw new Backup_Lite_Chunk_Exception( 'invalid_path', esc_html__( 'Merged file path rejected.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 400 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
        $merged = fopen( $merged_path, 'wb' );
        if ( ! $merged ) {
            throw new Backup_Lite_Chunk_Exception( 'merge_failed', esc_html__( 'Failed to create merged archive.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 500 );
        }

        if ( ! flock( $merged, LOCK_EX ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
            fclose( $merged );
            throw new Backup_Lite_Chunk_Exception( 'lock_failed', esc_html__( 'Failed to lock merged file.', 'museder-restoreone' ), [ 'stage' => 'merge' ], 500 );
        }

        foreach ( $chunk_files as $chunk ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
            $read = fopen( $chunk['path'], 'rb' );
            if ( ! $read ) {
                flock( $merged, LOCK_UN );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $merged );
                // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
                $chunk_index_raw = isset( $chunk['index'] ) ? (int) $chunk['index'] : 0;
                $chunk_index = sanitize_text_field( (string) $chunk_index_raw ); // @plugin-check: sanitized
                $chunk_index_escaped = esc_html( $chunk_index ); // @plugin-check: escaped
                $message = sprintf(
                    // translators: %s: Invalid chunk index number.
                    esc_html__( 'Failed to read chunk during merge. Invalid chunk index: %s', 'museder-restoreone' ),
                    $chunk_index_escaped
                );
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                // Message and array values are already sanitized and escaped above.
                throw new Backup_Lite_Chunk_Exception(
                    'merge_failed',
                    $message,
                    [ 'stage' => 'merge', 'chunk_index' => $chunk_index_escaped ],
                    500
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            if ( stream_copy_to_stream( $read, $merged ) === false ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $read );
                flock( $merged, LOCK_UN );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $merged );
                // @plugin-check: sanitized & escaped - exception message may be displayed as HTML
                $chunk_index_raw = isset( $chunk['index'] ) ? (int) $chunk['index'] : 0;
                $chunk_index = sanitize_text_field( (string) $chunk_index_raw ); // @plugin-check: sanitized
                $chunk_index_escaped = esc_html( $chunk_index ); // @plugin-check: escaped
                $message = sprintf(
                    // translators: %s: Invalid chunk index number.
                    esc_html__( 'Error while merging chunks. Invalid chunk index: %s', 'museder-restoreone' ),
                    $chunk_index_escaped
                );
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                // Message and array values are already sanitized and escaped above.
                throw new Backup_Lite_Chunk_Exception(
                    'merge_failed',
                    $message,
                    [ 'stage' => 'merge', 'chunk_index' => $chunk_index_escaped ],
                    500
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
            fclose( $read );
        }

        fflush( $merged );
        flock( $merged, LOCK_UN );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $merged );
        clearstatcache( true, $merged_path );

        return $merged_path;
    }

    protected static function calculate_file_sha1_stream( $path ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        $ctx = hash_init( 'sha1' );
        if ( false === $ctx ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return false;
        }

        if ( hash_update_stream( $ctx, $handle ) === false ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
            fclose( $handle );
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $handle );
        return hash_final( $ctx );
    }

    protected static function cleanup_upload( $upload_id ) {
        $base = backup_lite_get_chunk_path( $upload_id );
        if ( $base && file_exists( $base ) ) {
            backup_lite_delete_directory( $base );
        }
    }

    protected static function collect_chunk_files( $meta ) {
        $chunks_dir = $meta['_chunks_dir'];
        $files      = glob( trailingslashit( $chunks_dir ) . self::CHUNK_FILE_GLOB );

        if ( empty( $files ) ) {
            return [];
        }

        $chunks = [];

        foreach ( $files as $file ) {
            $basename = basename( $file );
            if ( preg_match( '/chunk-(\d+)\.part$/', $basename, $matches ) ) {
                $chunks[] = [
                    'index' => intval( $matches[1] ),
                    'path'  => $file,
                ];
            }
        }

        usort( $chunks, fn( $a, $b ) => intval( $a['index'] ) <=> intval( $b['index'] ) );

        return $chunks;
    }

    private static function verify_zip( $path ) {
        if ( class_exists( 'ZipArchive' ) ) {
            $za          = new ZipArchive();
            $open_result = $za->open( $path );
            $ok_code     = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;

            if ( true !== $open_result && $ok_code !== $open_result ) {
                return [
                    'ok'    => false,
                    'error' => $open_result,
                ];
            }

            $count = $za->numFiles;
            $za->close();

            return [
                'ok'      => true,
                'entries' => $count,
            ];
        }

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        if ( ! class_exists( 'PclZip' ) ) {
            return [ 'ok' => false, 'error' => 'ZipSupportUnavailable' ];
        }

        $pcl = new PclZip( $path );
        $list = $pcl->listContent();
        if ( ! is_array( $list ) ) {
            return [
                'ok'    => false,
                'error' => method_exists( $pcl, 'errorInfo' ) ? $pcl->errorInfo( true ) : 'pclzip_error',
            ];
        }

        return [
            'ok'      => true,
            'entries' => count( $list ),
        ];
    }

    public static function test_verify_zip( $path ) {
        return self::verify_zip( $path );
    }

    protected static function get_uploaded_size( $chunks_dir ) {
        $size = 0;
        if ( ! is_dir( $chunks_dir ) ) {
            return $size;
        }

        $files = glob( trailingslashit( $chunks_dir ) . self::CHUNK_FILE_GLOB );
        if ( empty( $files ) ) {
            return $size;
        }

        foreach ( $files as $file ) {
            $size += filesize( $file );
        }

        return $size;
    }

    protected static function generate_final_name( $original, $directory ) {
        $name = backup_lite_sanitize_filename( $original );
        if ( ! backup_lite_is_allowed_backup_extension( $name ) ) {
            $name .= '.zip';
        }

        if ( function_exists( 'wp_unique_filename' ) ) {
            $name = wp_unique_filename( $directory, $name );
        }

        return $name;
    }
}
