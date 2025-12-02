<?php
/**
 * Cloud Controller
 * 
 * Handles AJAX requests for S3 cloud backup operations (list, download).
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Backup_Lite_Cloud_Controller class.
 */
class Backup_Lite_Cloud_Controller {

    const NONCE = 'backup_lite_cloud_action';

    /**
     * Initialize the controller.
     */
    public static function init() {
        add_action( 'wp_ajax_museder_list_s3_backups', [ __CLASS__, 'ajax_list_s3_backups' ] );
        add_action( 'wp_ajax_museder_start_s3_download', [ __CLASS__, 'ajax_start_s3_download' ] );
        add_action( 'wp_ajax_museder_poll_s3_download', [ __CLASS__, 'ajax_poll_s3_download' ] );
    }

    /**
     * AJAX handler: List S3 backups.
     */
    public static function ajax_list_s3_backups() {
        // 1. Permission check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to perform this action.', 'museder-restoreone' ),
                ),
                403
            );
        }

        // 2. Nonce verification
        check_ajax_referer( self::NONCE, 'nonce' );

        // 3. Read optional prefix parameter
        $prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';

        try {
            if ( ! class_exists( 'Backup_Lite_S3_Service' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'S3 service is not available.', 'museder-restoreone' ),
                    )
                );
            }

            $result = Backup_Lite_S3_Service::list_backups( $prefix );

            if ( is_wp_error( $result ) ) {
                $error_message = Backup_Lite_S3_Service::sanitize_s3_error_message( $result->get_error_message() );
                backup_lite_log( 'error', 'S3 list backups failed (AJAX).', [
                    'error' => $error_message,
                ] );
                wp_send_json_error(
                    array(
                        'message' => $error_message,
                        'code' => $result->get_error_code(),
                    )
                );
            }

            // Check download status for each backup
            $backup_dir = backup_lite_get_backup_dir();
            $enriched_backups = array();
            
            foreach ( $result as $backup ) {
                $filename = basename( $backup['key'] );
                $local_path = wp_normalize_path( trailingslashit( $backup_dir ) . $filename );
                
                // Check if file exists locally
                $is_downloaded = file_exists( $local_path ) && is_readable( $local_path );
                
                // Check for active download state
                $download_state = null;
                $active_download_id = null;
                
                // Search for active download state by key
                global $wpdb;
                $download_options = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                        'museder_s3_download_%'
                    ),
                    ARRAY_A
                );
                
                foreach ( $download_options as $option ) {
                    $download_data = maybe_unserialize( $option['option_value'] );
                    if ( is_array( $download_data ) && isset( $download_data['key'] ) && $download_data['key'] === $backup['key'] ) {
                        // Found matching download state
                        if ( isset( $download_data['status'] ) && 'downloading' === $download_data['status'] ) {
                            $download_state = $download_data;
                            $active_download_id = str_replace( 'museder_s3_download_', '', $option['option_name'] );
                            break;
                        }
                    }
                }
                
                $backup['is_downloaded'] = $is_downloaded;
                $backup['download_state'] = $download_state;
                $backup['download_id'] = $active_download_id;
                
                $enriched_backups[] = $backup;
            }

            wp_send_json_success(
                array(
                    'backups' => $enriched_backups,
                    'count' => count( $enriched_backups ),
                )
            );

        } catch ( Throwable $e ) {
            $error_message = Backup_Lite_S3_Service::sanitize_s3_error_message( $e->getMessage() );
            backup_lite_log( 'error', 'S3 list backups AJAX exception.', [
                'message' => $error_message,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            error_log( '[Backup Lite] S3 list backups AJAX exception: ' . $error_message );
            wp_send_json_error(
                array(
                    'message' => __( 'Unexpected error while listing S3 backups. Please check logs for details.', 'museder-restoreone' ),
                )
            );
        }
    }

    /**
     * AJAX handler: Start S3 download.
     */
    public static function ajax_start_s3_download() {
        // 1. Permission check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to perform this action.', 'museder-restoreone' ),
                ),
                403
            );
        }

        // 2. Nonce verification
        check_ajax_referer( self::NONCE, 'nonce' );

        // 3. Read parameters
        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( empty( $key ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Missing S3 object key.', 'museder-restoreone' ),
                )
            );
        }

        // Optional: file size from list_backups result
        $file_size = isset( $_POST['size'] ) ? absint( $_POST['size'] ) : 0;

        try {
            // Generate download ID
            $download_id = wp_generate_password( 16, false );
            
            // Get backup directory
            $backup_dir = backup_lite_get_backup_dir();
            if ( ! is_dir( $backup_dir ) ) {
                wp_mkdir_p( $backup_dir );
            }
            
            // Build target file path
            $filename = basename( $key );
            $target_path = wp_normalize_path( trailingslashit( $backup_dir ) . $filename );
            
            // Initialize download state
            $download_state = array(
                'download_id' => $download_id,
                'key' => $key,
                'filename' => $filename,
                'target_path' => $target_path,
                'status' => 'downloading',
                'downloaded' => 0,
                'total' => $file_size, // Use provided size or 0 (will be determined during download)
                'started_at' => time(),
                'last_updated' => time(),
            );
            
            // Store download state in wp_options
            update_option( 'museder_s3_download_' . $download_id, $download_state, false );
            
            backup_lite_log( 'info', 'S3 download started.', [
                'download_id' => $download_id,
                'key' => $key,
                'target_path' => $target_path,
            ] );
            
            wp_send_json_success(
                array(
                    'download_id' => $download_id,
                    'message' => __( 'Download started.', 'museder-restoreone' ),
                )
            );

        } catch ( Throwable $e ) {
            $error_message = Backup_Lite_S3_Service::sanitize_s3_error_message( $e->getMessage() );
            backup_lite_log( 'error', 'S3 start download AJAX exception.', [
                'message' => $error_message,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            error_log( '[Backup Lite] S3 start download AJAX exception: ' . $error_message );
            wp_send_json_error(
                array(
                    'message' => __( 'Unexpected error while starting S3 download. Please check logs for details.', 'museder-restoreone' ),
                )
            );
        }
    }

    /**
     * AJAX handler: Poll S3 download progress.
     */
    public static function ajax_poll_s3_download() {
        // 1. Permission check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'You do not have permission to perform this action.', 'museder-restoreone' ),
                ),
                403
            );
        }

        // 2. Nonce verification
        check_ajax_referer( self::NONCE, 'nonce' );

        // 3. Read parameters
        $download_id = isset( $_POST['download_id'] ) ? sanitize_text_field( wp_unslash( $_POST['download_id'] ) ) : '';
        if ( empty( $download_id ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Missing download ID.', 'museder-restoreone' ),
                )
            );
        }

        try {
            // Get download state
            $download_state = get_option( 'museder_s3_download_' . $download_id, null );
            if ( null === $download_state ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Download not found.', 'museder-restoreone' ),
                    )
                );
            }

            // If already completed or failed, return status
            if ( in_array( $download_state['status'], array( 'completed', 'failed' ), true ) ) {
                wp_send_json_success(
                    array(
                        'status' => $download_state['status'],
                        'downloaded' => $download_state['downloaded'],
                        'total' => $download_state['total'],
                        'message' => 'completed' === $download_state['status'] 
                            ? __( 'Download completed.', 'museder-restoreone' )
                            : __( 'Download failed.', 'museder-restoreone' ),
                    )
                );
            }

            // Continue downloading
            if ( ! class_exists( 'Backup_Lite_S3_Service' ) ) {
                $download_state['status'] = 'failed';
                $download_state['error'] = __( 'S3 service is not available.', 'museder-restoreone' );
                update_option( 'museder_s3_download_' . $download_id, $download_state, false );
                wp_send_json_error(
                    array(
                        'status' => 'failed',
                        'message' => $download_state['error'],
                    )
                );
            }

            // Download chunk (8MB at a time) or entire file if small
            $chunk_size = 8 * 1024 * 1024; // 8MB
            $offset = $download_state['downloaded'];
            
            // If total size is unknown or file is small, download entire file
            // Otherwise, download in chunks
            $download_length = 0;
            if ( $download_state['total'] > 0 && $download_state['total'] > $chunk_size ) {
                // Large file: download in chunks
                $remaining = $download_state['total'] - $offset;
                $download_length = min( $chunk_size, $remaining );
            }
            // If total is 0 or file is small, download_length = 0 means download from offset to end
            
            $result = Backup_Lite_S3_Service::download_backup(
                $download_state['key'],
                $download_state['target_path'],
                $offset,
                $download_length
            );

            if ( is_wp_error( $result ) ) {
                $error_message = Backup_Lite_S3_Service::sanitize_s3_error_message( $result->get_error_message() );
                $download_state['status'] = 'failed';
                $download_state['error'] = $error_message;
                $download_state['last_updated'] = time();
                update_option( 'museder_s3_download_' . $download_id, $download_state, false );
                
                backup_lite_log( 'error', 'S3 download chunk failed.', [
                    'download_id' => $download_id,
                    'error' => $error_message,
                ] );
                
                wp_send_json_error(
                    array(
                        'status' => 'failed',
                        'message' => $error_message,
                    )
                );
            }

            // Update download state
            $new_downloaded = isset( $result['downloaded'] ) ? (int) $result['downloaded'] : $offset;
            
            // If total was unknown, try to get it from file size
            if ( $download_state['total'] === 0 && file_exists( $download_state['target_path'] ) ) {
                $download_state['total'] = filesize( $download_state['target_path'] );
            }
            
            $download_state['downloaded'] = $new_downloaded;
            $download_state['last_updated'] = time();
            
            // Check if download is complete
            // If total is known and we've reached it, or if we downloaded nothing (already complete)
            if ( ( $download_state['total'] > 0 && $new_downloaded >= $download_state['total'] ) || 
                 ( $download_state['total'] === 0 && $new_downloaded > 0 && $new_downloaded === filesize( $download_state['target_path'] ) ) ) {
                $download_state['status'] = 'completed';
                $download_state['downloaded'] = $new_downloaded;
                
                // Register the downloaded backup
                if ( class_exists( 'Backup_Lite_Restore_Service' ) && method_exists( 'Backup_Lite_Restore_Service', 'register_downloaded_backup' ) ) {
                    $register_result = Backup_Lite_Restore_Service::register_downloaded_backup( $download_state['target_path'], 's3' );
                    if ( is_wp_error( $register_result ) ) {
                        backup_lite_log( 'warning', 'Failed to register downloaded backup.', [
                            'file' => $download_state['target_path'],
                            'error' => $register_result->get_error_message(),
                        ] );
                    }
                }
                
                // Clean up download state after 1 hour
                wp_schedule_single_event( time() + 3600, 'backup_lite_cleanup_s3_download', array( $download_id ) );
            }
            
            update_option( 'museder_s3_download_' . $download_id, $download_state, false );
            
            // Calculate percentage, ensuring it's between 0 and 100
            $percentage = 0;
            if ( $download_state['total'] > 0 ) {
                $percentage = round( ( $download_state['downloaded'] / $download_state['total'] ) * 100, 2 );
                $percentage = min( 100, max( 0, $percentage ) ); // Limit to 0-100%
            }
            
            $response_data = array(
                'status' => $download_state['status'],
                'downloaded' => $download_state['downloaded'],
                'total' => $download_state['total'],
                'percentage' => $percentage,
                'message' => 'completed' === $download_state['status']
                    ? __( 'Download completed.', 'museder-restoreone' )
                    : __( 'Downloading...', 'museder-restoreone' ),
            );

            // Include filename when download is completed for restore functionality
            if ( 'completed' === $download_state['status'] && isset( $download_state['filename'] ) ) {
                $response_data['filename'] = $download_state['filename'];
            }

            wp_send_json_success( $response_data );

        } catch ( Throwable $e ) {
            $error_message = Backup_Lite_S3_Service::sanitize_s3_error_message( $e->getMessage() );
            backup_lite_log( 'error', 'S3 poll download AJAX exception.', [
                'message' => $error_message,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            error_log( '[Backup Lite] S3 poll download AJAX exception: ' . $error_message );
            wp_send_json_error(
                array(
                    'message' => __( 'Unexpected error while polling download progress. Please check logs for details.', 'museder-restoreone' ),
                )
            );
        }
    }
}

