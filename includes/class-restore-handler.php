<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Handler {

    const OPTION_STATE = 'backup_lite_restore_state';

    public static function init() {
        add_action( 'wp_ajax_backup_lite_restore_upload', [ __CLASS__, 'upload' ] );
        add_action( 'wp_ajax_backup_lite_restore_from_backup', [ __CLASS__, 'restore_from_backup' ] );
        add_action( 'wp_ajax_backup_lite_restore_remote_url', [ __CLASS__, 'restore_remote' ] );
        add_action( 'wp_ajax_backup_lite_restore_progress', [ __CLASS__, 'progress' ] );
        add_action( 'wp_ajax_backup_lite_restore_confirm', [ __CLASS__, 'confirm' ] );
        add_action( 'wp_ajax_backup_lite_restore_cancel', [ __CLASS__, 'cancel_restore' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_prepare', [ __CLASS__, 'chunk_prepare' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_upload', [ __CLASS__, 'chunk_upload' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_finalize', [ __CLASS__, 'chunk_finalize' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_abort', [ __CLASS__, 'chunk_abort' ] );
    }

    public static function upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $file = $_FILES['file'] ?? $_FILES['restoreFile'] ?? null;
        if ( empty( $file ) ) {
            wp_send_json_error( [ 'message' => __( 'No restore file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = [ 'test_form' => false ];
        $uploaded  = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            backup_lite_log( 'error', 'restore_upload_failed', [ 'error' => $uploaded['error'] ] );
            wp_send_json_error( [ 'message' => __( 'Failed to upload restore file.', 'museder-restoreone' ) ], 500 );
        }

        $file_path = wp_normalize_path( $uploaded['file'] );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            @unlink( $file_path );
            wp_send_json_error( [ 'message' => __( 'Unsupported file type. Allowed: zip, wpress.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $file_path ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $file_path, $destination ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to store uploaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        $summary = self::prepare_session( $destination, 'upload' );

        wp_send_json_success( [
            'summary' => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function restore_from_backup() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            wp_send_json_error( [ 'message' => __( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $path       = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $filename ) );

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
        }

        $summary = self::prepare_session( $path, 'existing' );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function restore_remote() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid URL.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $temp = download_url( $url, 300 );
        if ( is_wp_error( $temp ) ) {
            backup_lite_log( 'error', 'restore_remote_download_failed', [ 'url' => $url, 'error' => $temp->get_error_message() ] );
            wp_send_json_error( [ 'message' => __( 'Unable to download remote backup.', 'museder-restoreone' ) ], 500 );
        }

        $ext = strtolower( pathinfo( $temp, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            @unlink( $temp );
            wp_send_json_error( [ 'message' => __( 'Downloaded file is not a supported backup format.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $temp ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $temp, $destination ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to store downloaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        $summary = self::prepare_session( $destination, 'remote', [ 'source_url' => $url ] );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function progress() {
        self::ensure_permission();

        $state = self::get_state();

        if ( empty( $state ) ) {
            wp_send_json( self::format_progress( 0, __( 'Waiting for action…', 'museder-restoreone' ), true ) );
        }

        $progress = self::format_progress();
        wp_send_json( $progress );
    }

    public static function confirm() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $state = self::get_state();
        if ( empty( $state ) || empty( $state['file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No restore session is active.', 'museder-restoreone' ) ], 400 );
        }

        $options = self::parse_options();
        $state['options'] = $options;
        self::set_state( $state );

        $history_entry = [
            'timestamp' => backup_lite_local_time( 'Y-m-d H:i:s' ),
            'file'      => $state['filename'],
            'result'    => 'pending',
            'log'       => '',
        ];

        $suspend_cache_state = null;

        try {
            ignore_user_abort( true );
            @set_time_limit( 0 );

            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }

            if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
                $suspend_cache_state = wp_suspend_cache_invalidation( true );
            }

            self::update_progress( 10, __( 'Preparing restore environment…', 'museder-restoreone' ) );

            if ( ! empty( $options['auto_backup'] ) ) {
                self::update_progress( 20, __( 'Creating safety backup…', 'museder-restoreone' ) );
                $backup = Backup_Lite_Backup::backup_site();
                if ( empty( $backup['success'] ) ) {
                    $history_entry['result'] = 'failed';
                    $history_entry['log']    = isset( $backup['log'] ) ? basename( $backup['log'] ) : '';
                    self::update_progress( 0, __( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ), true );
                    throw new RuntimeException( __( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ) );
                }
            }

            self::update_progress( 45, __( 'Restoring database and files…', 'museder-restoreone' ) );

            $restore = Backup_Lite_Restore::restore_site( $state['file'], $options );

            if ( ! empty( $restore['success'] ) ) {
                self::update_progress( 100, __( 'Restore completed successfully.', 'museder-restoreone' ), true );
                $history_entry['result'] = 'success';
            } else {
                $message = isset( $restore['message'] ) ? $restore['message'] : __( 'Restore failed.', 'museder-restoreone' );
                self::update_progress( 100, $message, true );
                $history_entry['result'] = 'failed';
            }

            if ( isset( $restore['log'] ) ) {
                $history_entry['log'] = basename( $restore['log'] );
            }

            backup_lite_append_restore_history( $history_entry );

            $final_state = self::get_state();
            if ( ! empty( $final_state ) ) {
                $final_state['completed'] = true;
                self::set_state( $final_state );
            }

            wp_send_json_success( [
                'result'   => $restore,
                'progress' => self::format_progress(),
                'history'  => self::history_for_js( 10 ),
            ] );
        } catch ( Throwable $e ) {
            $message = __( 'Restore failed because the server interrupted the request. Please review the error log and try again.', 'museder-restoreone' );

            if ( false !== stripos( $e->getMessage(), 'mailpoet' ) ) {
                $message = __( 'Restore was interrupted by MailPoet. Please resolve the MailPoet database error or temporarily disable it before retrying.', 'museder-restoreone' );
            }

            backup_lite_log(
                'error',
                'restore_unhandled_exception',
                [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]
            );

            $history_entry['result'] = 'failed';
            backup_lite_append_restore_history( $history_entry );

            self::update_progress( 100, $message, true );

            wp_send_json_error(
                [
                    'message' => $message,
                    'details' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getMessage() : '',
                ],
                500
            );
        } finally {
            if ( function_exists( 'wp_suspend_cache_invalidation' ) && null !== $suspend_cache_state ) {
                wp_suspend_cache_invalidation( (bool) $suspend_cache_state );
            }
        }
    }

    public static function cancel_restore() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $state = self::get_state();

        if ( ! empty( $state['file'] ) && isset( $state['source'] ) && in_array( $state['source'], [ 'upload', 'remote' ], true ) ) {
            $path = wp_normalize_path( $state['file'] );
            if ( $path && file_exists( $path ) && is_file( $path ) ) {
                @unlink( $path ); // best effort cleanup for uploaded archives.
            }
        }

        self::set_state( [] );

        wp_send_json_success( [
            'message'  => __( 'Restore process cancelled.', 'museder-restoreone' ),
            'progress' => self::format_progress( 0, __( 'Waiting for action…', 'museder-restoreone' ), false ),
        ] );
    }

    private static function parse_options() {
        $options = [];

        $options['overwrite']   = ! empty( $_POST['overwrite'] ) && 'true' === $_POST['overwrite'];
        $options['auto_backup'] = ! empty( $_POST['autoBackup'] ) && 'true' === $_POST['autoBackup'];
        $options['skip_config'] = ! empty( $_POST['skipConfig'] ) && 'true' === $_POST['skipConfig'];

        if ( ! empty( $_POST['searchReplace'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['searchReplace'] ), true );
            if ( is_array( $decoded ) ) {
                $options['search_replace'] = $decoded;
            }
        }

        return $options;
    }

    public static function chunk_prepare() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $filename     = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
        $filesize     = isset( $_POST['filesize'] ) ? absint( $_POST['filesize'] ) : 0;
        $chunk_size   = isset( $_POST['chunk_size'] ) ? absint( $_POST['chunk_size'] ) : 0;
        $total_chunks = isset( $_POST['total_chunks'] ) ? absint( $_POST['total_chunks'] ) : 0;

        if ( ! $filename || ! $filesize || ! $chunk_size || ! $total_chunks ) {
            wp_send_json_error( [ 'message' => __( 'Missing chunk upload metadata.', 'museder-restoreone' ) ], 400 );
        }

        $session_id = uniqid( 'restore_chunk_', true );
        $session_dir = self::chunk_session_dir( $session_id );

        if ( ! wp_mkdir_p( $session_dir ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to create chunk session directory.', 'museder-restoreone' ) ], 500 );
        }

        $meta = [
            'session_id'   => $session_id,
            'filename'     => $filename,
            'filesize'     => $filesize,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
            'created'      => time(),
        ];

        if ( false === file_put_contents( self::chunk_meta_path( $session_id ), wp_json_encode( $meta ), LOCK_EX ) ) {
            self::delete_chunk_session( $session_id );
            wp_send_json_error( [ 'message' => __( 'Unable to persist chunk session metadata.', 'museder-restoreone' ) ], 500 );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
        ] );
    }

    public static function chunk_upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $index      = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : -1;

        if ( ! $session_id || $index < 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid chunk upload parameters.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            wp_send_json_error( [ 'message' => __( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        if ( empty( $_FILES['chunk'] ) || empty( $_FILES['chunk']['tmp_name'] ) || ! file_exists( $_FILES['chunk']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No chunk file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        $chunk_dir = self::chunk_session_dir( $session_id );
        if ( ! file_exists( $chunk_dir ) && ! wp_mkdir_p( $chunk_dir ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to access chunk directory.', 'museder-restoreone' ) ], 500 );
        }

        $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $index );
        $tmp_name   = $_FILES['chunk']['tmp_name'];

        if ( ! @move_uploaded_file( $tmp_name, $chunk_path ) ) {
            $input  = fopen( $tmp_name, 'rb' );
            $output = fopen( $chunk_path, 'wb' );
            if ( ! $input || ! $output ) {
                if ( $input ) {
                    fclose( $input );
                }
                if ( $output ) {
                    fclose( $output );
                }
                wp_send_json_error( [ 'message' => __( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
            }
            stream_copy_to_stream( $input, $output );
            fclose( $input );
            fclose( $output );
        }

        wp_send_json_success( [
            'chunk' => $index,
            'total' => (int) $meta['total_chunks'],
        ] );
    }

    public static function chunk_finalize() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing chunk session identifier.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            wp_send_json_error( [ 'message' => __( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        $chunk_dir    = self::chunk_session_dir( $session_id );
        $total_chunks = (int) $meta['total_chunks'];

        $chunks = [];
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $i );
            if ( ! file_exists( $chunk_path ) ) {
                self::delete_chunk_session( $session_id );
                wp_send_json_error( [ 'message' => __( 'Uploaded chunks incomplete. Please retry.', 'museder-restoreone' ) ], 409 );
            }
            $chunks[] = $chunk_path;
        }

        $backup_dir = backup_lite_get_backup_dir();
        $final_name = wp_unique_filename( $backup_dir, $meta['filename'] );
        $final_path = trailingslashit( $backup_dir ) . $final_name;

        $output = fopen( $final_path, 'wb' );
        if ( ! $output ) {
            self::delete_chunk_session( $session_id );
            wp_send_json_error( [ 'message' => __( 'Unable to create merged archive.', 'museder-restoreone' ) ], 500 );
        }

        foreach ( $chunks as $chunk_path ) {
            $input = fopen( $chunk_path, 'rb' );
            if ( ! $input ) {
                fclose( $output );
                self::delete_chunk_session( $session_id );
                wp_send_json_error( [ 'message' => __( 'Unable to read uploaded chunk.', 'museder-restoreone' ) ], 500 );
            }
            stream_copy_to_stream( $input, $output );
            fclose( $input );
        }

        fflush( $output );
        fclose( $output );

        self::delete_chunk_session( $session_id );

        $summary = self::prepare_session( $final_path, 'upload' );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function chunk_abort() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        if ( $session_id ) {
            self::delete_chunk_session( $session_id );
        }

        wp_send_json_success();
    }

    private static function chunk_root_dir() {
        $root = backup_lite_get_storage_root();
        $dir  = trailingslashit( $root['path'] ) . 'restore-chunks';
        backup_lite_ensure_directory( $dir );
        return $dir;
    }

    private static function chunk_session_dir( $session_id ) {
        return trailingslashit( self::chunk_root_dir() ) . sanitize_file_name( $session_id );
    }

    private static function chunk_meta_path( $session_id ) {
        return trailingslashit( self::chunk_session_dir( $session_id ) ) . 'meta.json';
    }

    private static function load_chunk_meta( $session_id ) {
        $path = self::chunk_meta_path( $session_id );
        if ( ! file_exists( $path ) ) {
            return null;
        }
        $contents = file_get_contents( $path );
        if ( false === $contents ) {
            return null;
        }
        $meta = json_decode( $contents, true );
        return is_array( $meta ) ? $meta : null;
    }

    private static function delete_chunk_session( $session_id ) {
        $dir = self::chunk_session_dir( $session_id );
        if ( file_exists( $dir ) ) {
            backup_lite_delete_directory( $dir );
        }
    }

    private static function prepare_session( $file_path, $source, $extra = [] ) {
        $file_path = wp_normalize_path( $file_path );
        $size      = file_exists( $file_path ) ? filesize( $file_path ) : 0;
        $sha1      = file_exists( $file_path ) ? sha1_file( $file_path ) : '';

        $state = [
            'id'        => uniqid( 'restore_', true ),
            'file'      => $file_path,
            'filename'  => basename( $file_path ),
            'source'    => $source,
            'size'      => (float) $size,
            'sha1'      => $sha1,
            'created'   => current_time( 'mysql' ),
            'extra'     => $extra,
            'progress'  => self::format_progress( 10, __( 'File ready. Review summary before restoring.', 'museder-restoreone' ), true ),
            'completed' => false,
        ];

        self::set_state( $state );

        return self::compose_summary( $state );
    }

    public static function current_summary() {
        $state = self::get_state();
        if ( empty( $state['file'] ) ) {
            return null;
        }

        return self::compose_summary( $state );
    }

    public static function current_progress() {
        return self::format_progress();
    }

    public static function history_for_js( $limit = 10 ) {
        $raw      = backup_lite_get_restore_history( $limit );
        $prepared = [];

        foreach ( $raw as $entry ) {
            $row = $entry;
            if ( ! empty( $entry['log'] ) ) {
                $row['log_url'] = wp_nonce_url(
                    admin_url( 'admin-post.php?action=backup_lite_download_log&log=' . rawurlencode( $entry['log'] ) ),
                    'backup_lite_download_log_' . $entry['log']
                );
            } else {
                $row['log_url'] = '';
            }
            $prepared[] = $row;
        }

        return $prepared;
    }

    private static function ensure_permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }
    }

    private static function move_file( $source, $destination ) {
        if ( @rename( $source, $destination ) ) {
            return true;
        }

        if ( @copy( $source, $destination ) ) {
            @unlink( $source );
            return true;
        }

        return false;
    }

    private static function get_state() {
        $state = get_option( self::OPTION_STATE, [] );
        return is_array( $state ) ? $state : [];
    }

    private static function set_state( $state ) {
        update_option( self::OPTION_STATE, $state, false );
    }

    private static function update_progress( $percent, $message, $done = false ) {
        $state = self::get_state();
        if ( empty( $state ) ) {
            return;
        }

        $state['progress'] = self::format_progress( $percent, $message, $done );
        self::set_state( $state );
    }

    private static function format_progress( $percent = null, $message = null, $done = null ) {
        $state = self::get_state();

        $progress = [
            'percent' => $percent,
            'message' => $message,
            'done'    => $done,
        ];

        if ( null === $percent && isset( $state['progress']['percent'] ) ) {
            $progress['percent'] = $state['progress']['percent'];
        } elseif ( null === $percent ) {
            $progress['percent'] = 0;
        }

        if ( null === $message && isset( $state['progress']['message'] ) ) {
            $progress['message'] = $state['progress']['message'];
        } elseif ( null === $message ) {
            $progress['message'] = __( 'Waiting for action…', 'museder-restoreone' );
        }

        if ( null === $done && isset( $state['progress']['done'] ) ) {
            $progress['done'] = (bool) $state['progress']['done'];
        } elseif ( null === $done ) {
            $progress['done'] = false;
        }

        if ( isset( $state['filename'] ) ) {
            $progress['filename'] = $state['filename'];
        }
        if ( isset( $state['source'] ) ) {
            $progress['source'] = $state['source'];
        }

        return $progress;
    }

    private static function compose_summary( $state ) {
        $path = isset( $state['file'] ) ? $state['file'] : '';
        $size = ( ! empty( $state['size'] ) ) ? (float) $state['size'] : ( ( file_exists( $path ) ) ? filesize( $path ) : 0 );
        $sha1 = ! empty( $state['sha1'] ) ? $state['sha1'] : ( ( file_exists( $path ) ) ? sha1_file( $path ) : '' );

        return [
            'name'    => isset( $state['filename'] ) ? $state['filename'] : basename( $path ),
            'size'    => size_format( $size, 2 ),
            'bytes'   => (float) $size,
            'sha1'    => $sha1,
            'source'  => isset( $state['source'] ) ? $state['source'] : '',
            'created' => isset( $state['created'] ) ? $state['created'] : '',
        ];
    }
}
