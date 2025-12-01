<?php
/**
 * S3 Uploader
 * 
 * Handles S3 upload operations with support for both single-part and multipart uploads.
 * This class provides a clean architecture for future multipart upload implementation.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * S3 Uploader class for handling backup file uploads to S3.
 * 
 * This class provides a unified interface for uploading backup files to S3,
 * with support for both single-part (current) and multipart (future) uploads.
 */
class Backup_Lite_S3_Uploader {

    /**
     * Instance of this class.
     *
     * @var Backup_Lite_S3_Uploader|null
     */
    private static $instance = null;

    /**
     * File size threshold for multipart upload (10MB).
     * Files larger than this will use multipart upload.
     *
     * @var int
     */
    const MULTIPART_THRESHOLD = 10 * 1024 * 1024; // 10MB

    /**
     * Chunk size for multipart upload (8MB).
     * Each part should be between 5MB and 5GB (except the last part).
     * Using 8MB for better balance between memory usage and upload efficiency.
     *
     * @var int
     */
    const MULTIPART_CHUNK_SIZE = 8 * 1024 * 1024; // 8MB

    /**
     * Get instance of this class (singleton pattern).
     *
     * @return Backup_Lite_S3_Uploader
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Upload a backup file to S3.
     * 
     * This method automatically chooses between single-part and multipart upload
     * based on file size and available features.
     *
     * @param string $file_path Absolute path to local backup file.
     * @return array|WP_Error On success, returns array with 'status' => 'success' and 'object_key'.
     *                        On failure, returns WP_Error.
     */
    public function upload_backup_file( $file_path ) {
        $file_path = wp_normalize_path( $file_path );

        // Validate file exists and is readable
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 's3_file_not_found', __( 'Backup file does not exist.', 'museder-restoreone' ) );
        }

        if ( ! is_readable( $file_path ) ) {
            return new WP_Error( 's3_file_not_readable', __( 'Backup file is not readable.', 'museder-restoreone' ) );
        }

        $file_size = filesize( $file_path );
        if ( false === $file_size || $file_size <= 0 ) {
            return new WP_Error( 's3_invalid_file_size', __( 'Unable to determine backup file size.', 'museder-restoreone' ) );
        }

        // Check S3 settings
        $settings = backup_lite_get_s3_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['bucket'] ) ) {
            return new WP_Error( 's3_not_configured', __( 'S3 is not configured. Please configure S3 settings first.', 'museder-restoreone' ) );
        }

        // Build object key
        $object_key = $this->build_object_key( $file_path, $settings );

        // Choose upload method based on file size
        // Files <= 10MB: use single-part upload (faster for small files)
        // Files > 10MB: use multipart upload (avoids memory issues)
        if ( $file_size > self::MULTIPART_THRESHOLD ) {
            return $this->upload_multipart( $file_path, $object_key, $settings );
        }

        return $this->upload_single_part( $file_path, $object_key, $settings );
    }

    /**
     * Upload a file using single-part upload.
     * 
     * This method reads the entire file into memory and uploads it in one request.
     * Suitable for files <= 10MB (threshold defined by MULTIPART_THRESHOLD).
     * For larger files, use upload_multipart() instead.
     *
     * @param string $file_path   Absolute path to local backup file.
     * @param string $object_key  S3 object key.
     * @param array  $settings    S3 settings array.
     * @return array|WP_Error On success, returns array with 'status' => 'success' and 'object_key'.
     *                        On failure, returns WP_Error.
     */
    protected function upload_single_part( $file_path, $object_key, $settings ) {
        $file_size = filesize( $file_path );

        backup_lite_log( 'info', 'S3 upload: starting single-part upload.', [
            'file' => $file_path,
            'size' => $file_size,
            'key'  => $object_key,
        ] );

        // Read entire file content as string
        // Note: This loads the entire file into memory, but wp_remote_request() requires string body
        // This method is only called for files <= 10MB, so memory usage is acceptable
        // For larger files, upload_multipart() is used instead
        $body = @file_get_contents( $file_path ); // @plugin-check: allowed - reading local backup file
        if ( false === $body ) {
            $error = error_get_last();
            $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error reading file.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 upload failed: could not read file.', [
                'file' => $file_path,
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_read_file_error', __( 'Could not read backup file for S3 upload.', 'museder-restoreone' ) );
        }

        // Verify read size matches file size
        $read_size = strlen( $body );
        if ( $read_size !== $file_size ) {
            backup_lite_log( 'error', 'S3 upload failed: file read size mismatch.', [
                'file' => $file_path,
                'expected_size' => $file_size,
                'actual_size' => $read_size,
            ] );
            unset( $body );
            return new WP_Error( 's3_read_file_error', __( 'File read size mismatch. File may be corrupted or in use.', 'museder-restoreone' ) );
        }

        // Use existing Backup_Lite_S3_Service::put_object_via_sigv4() method
        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = $settings['endpoint'];
        $use_path    = $settings['use_path_style_endpoint'];

        // Build target URL
        try {
            $target_url = Backup_Lite_S3_Service::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $object_key );
        } catch ( Throwable $url_error ) {
            unset( $body );
            backup_lite_log( 'error', 'S3 upload failed: error building target URL.', [
                'reason' => 'url_build_error',
                'message' => $url_error->getMessage(),
            ] );
            return new WP_Error( 'url_build_error', __( 'Failed to build S3 target URL.', 'museder-restoreone' ) );
        }

        // Prepare arguments for put_object_via_sigv4
        $put_args = array(
            'bucket'         => $bucket,
            'region'         => $region,
            'key'            => $object_key,
            'body'           => $body, // string content (entire file read into memory)
            'content_type'   => 'application/zip',
            'content_length' => (int) $file_size,
            'url'            => $target_url,
            'access_key'     => $settings['access_key_id'],
            'secret_key'     => $settings['secret_access_key'],
        );

        // Call put_object_via_sigv4 (now public static)
        try {
            $result = Backup_Lite_S3_Service::put_object_via_sigv4( $put_args );
        } catch ( Throwable $e ) {
            unset( $body );
            backup_lite_log( 'error', 'S3 upload failed: exception in put_object_via_sigv4.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            return new WP_Error( 's3_upload_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
        }

        // Clear body from memory
        unset( $body );

        // Check result
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( true === $result ) {
            backup_lite_log( 'info', 'S3 upload completed successfully.', [
                'bucket' => $bucket,
                'key'    => $object_key,
            ] );
            return array(
                'status'    => 'success',
                'object_key' => $object_key,
            );
        }

        return new WP_Error( 's3_unexpected_result', __( 'S3 upload returned unexpected result.', 'museder-restoreone' ) );
    }

    /**
     * Upload a file using multipart upload.
     * 
     * This method implements S3 multipart upload for large files (>10MB).
     * It splits the file into chunks and uploads them separately without loading
     * the entire file into memory.
     * 
     * Flow:
     * 1. CreateMultipartUpload - Initialize multipart upload, get UploadId
     * 2. UploadPart - Upload each chunk (8MB each, read as string)
     * 3. CompleteMultipartUpload - Finalize upload with all part ETags
     * 4. AbortMultipartUpload - Cleanup on error (if needed)
     *
     * @param string $file_path   Absolute path to local backup file.
     * @param string $object_key  S3 object key.
     * @param array  $settings    S3 settings array.
     * @return array|WP_Error On success, returns array with 'status' => 'success' and 'object_key'.
     *                        On failure, returns WP_Error.
     */
    protected function upload_multipart( $file_path, $object_key, $settings ) {
        $file_size = filesize( $file_path );
        if ( false === $file_size || $file_size <= 0 ) {
            return new WP_Error( 's3_invalid_file_size', __( 'Unable to determine backup file size.', 'museder-restoreone' ) );
        }

        backup_lite_log( 'info', 'S3 upload: starting multipart upload.', [
            'file' => $file_path,
            'size' => $file_size,
            'key'  => $object_key,
            'chunk_size' => self::MULTIPART_CHUNK_SIZE,
        ] );

        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = $settings['endpoint'];
        $use_path    = $settings['use_path_style_endpoint'];
        $access_key  = $settings['access_key_id'];
        $secret_key  = $settings['secret_access_key'];

        // Step 1: Create multipart upload
        $upload_id = Backup_Lite_S3_Service::create_multipart_upload(
            $bucket,
            $region,
            $object_key,
            $endpoint,
            $use_path,
            $access_key,
            $secret_key,
            'application/zip'
        );

        if ( is_wp_error( $upload_id ) ) {
            backup_lite_log( 'error', 'S3 multipart upload failed: could not create multipart upload.', [
                'error_code' => $upload_id->get_error_code(),
                'error_message' => $upload_id->get_error_message(),
            ] );
            return $upload_id;
        }

        // Step 2: Upload parts
        $parts = array();
        $part_number = 0;
        $file_handle = @fopen( $file_path, 'rb' ); // @plugin-check: allowed - reading local backup file
        if ( false === $file_handle ) {
            // Abort multipart upload if we can't open the file
            Backup_Lite_S3_Service::abort_multipart_upload(
                $bucket,
                $region,
                $object_key,
                $endpoint,
                $use_path,
                $upload_id,
                $access_key,
                $secret_key
            );
            return new WP_Error( 's3_open_failed', __( 'Unable to open backup file for reading.', 'museder-restoreone' ) );
        }

        try {
            while ( ! feof( $file_handle ) ) {
                $part_number++;
                
                // Read chunk as string (NOT resource)
                $part_data = @fread( $file_handle, self::MULTIPART_CHUNK_SIZE );
                if ( false === $part_data ) {
                    // Error reading chunk
                    fclose( $file_handle );
                    // Abort multipart upload
                    Backup_Lite_S3_Service::abort_multipart_upload(
                        $bucket,
                        $region,
                        $object_key,
                        $endpoint,
                        $use_path,
                        $upload_id,
                        $access_key,
                        $secret_key
                    );
                    return new WP_Error( 's3_read_chunk_failed', __( 'Failed to read file chunk for multipart upload.', 'museder-restoreone' ) );
                }

                // If we read 0 bytes, we're done (shouldn't happen with feof check, but be safe)
                if ( strlen( $part_data ) === 0 ) {
                    break;
                }

                // Upload this part
                $etag = Backup_Lite_S3_Service::upload_part(
                    $bucket,
                    $region,
                    $object_key,
                    $endpoint,
                    $use_path,
                    $upload_id,
                    $part_number,
                    $part_data, // String body (NOT resource)
                    $access_key,
                    $secret_key
                );

                // Clear part_data from memory immediately after upload
                unset( $part_data );

                if ( is_wp_error( $etag ) ) {
                    // Upload part failed
                    fclose( $file_handle );
                    // Abort multipart upload
                    Backup_Lite_S3_Service::abort_multipart_upload(
                        $bucket,
                        $region,
                        $object_key,
                        $endpoint,
                        $use_path,
                        $upload_id,
                        $access_key,
                        $secret_key
                    );
                    backup_lite_log( 'error', 'S3 multipart upload failed: part upload failed.', [
                        'part_number' => $part_number,
                        'error_code' => $etag->get_error_code(),
                        'error_message' => $etag->get_error_message(),
                    ] );
                    return new WP_Error(
                        's3_part_upload_failed',
                        sprintf(
                            /* translators: %d: part number */
                            __( 'Failed to upload part %d of multipart upload.', 'museder-restoreone' ),
                            $part_number
                        )
                    );
                }

                // Store part info for CompleteMultipartUpload
                $parts[] = array(
                    'PartNumber' => $part_number,
                    'ETag'       => $etag,
                );

                backup_lite_log( 'debug', 'S3 multipart upload: part uploaded.', [
                    'part_number' => $part_number,
                    'etag' => $etag,
                ] );
            }

            fclose( $file_handle );

            // Step 3: Complete multipart upload
            $complete_result = Backup_Lite_S3_Service::complete_multipart_upload(
                $bucket,
                $region,
                $object_key,
                $endpoint,
                $use_path,
                $upload_id,
                $parts,
                $access_key,
                $secret_key
            );

            if ( is_wp_error( $complete_result ) ) {
                // Complete failed - try to abort (best effort)
                Backup_Lite_S3_Service::abort_multipart_upload(
                    $bucket,
                    $region,
                    $object_key,
                    $endpoint,
                    $use_path,
                    $upload_id,
                    $access_key,
                    $secret_key
                );
                backup_lite_log( 'error', 'S3 multipart upload failed: complete failed.', [
                    'error_code' => $complete_result->get_error_code(),
                    'error_message' => $complete_result->get_error_message(),
                    'parts_count' => count( $parts ),
                ] );
                return $complete_result;
            }

            backup_lite_log( 'info', 'S3 multipart upload completed successfully.', [
                'bucket' => $bucket,
                'key'    => $object_key,
                'parts_count' => count( $parts ),
            ] );

            return array(
                'status'    => 'success',
                'object_key' => $object_key,
            );

        } catch ( Throwable $e ) {
            // Ensure file handle is closed
            if ( isset( $file_handle ) && is_resource( $file_handle ) ) {
                @fclose( $file_handle );
            }

            // Abort multipart upload on exception
            Backup_Lite_S3_Service::abort_multipart_upload(
                $bucket,
                $region,
                $object_key,
                $endpoint,
                $use_path,
                $upload_id,
                $access_key,
                $secret_key
            );

            backup_lite_log( 'error', 'S3 multipart upload failed: exception.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr( $e->getTraceAsString(), 0, 1000 ),
            ] );

            return new WP_Error( 's3_multipart_exception', __( 'S3 multipart upload failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Build S3 object key for a backup file.
     * 
     * Format: {prefix}/{site-domain}/{Y-m-d}/{filename}
     *
     * @param string $file_path Absolute path to local backup file.
     * @param array  $settings  S3 settings array.
     * @return string S3 object key.
     */
    protected function build_object_key( $file_path, $settings ) {
        $prefix    = isset( $settings['prefix'] ) ? $settings['prefix'] : '';
        $file_name = basename( $file_path );
        
        try {
            $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( empty( $site_domain ) ) {
                $site_domain = 'unknown';
            }
            $site_domain = sanitize_file_name( $site_domain );
            if ( empty( $site_domain ) ) {
                $site_domain = 'unknown';
            }
            $date_path   = gmdate( 'Y-m-d' );
            $object_key  = $prefix 
                ? trailingslashit( $prefix ) . trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name
                : trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name;
            
            return $object_key;
        } catch ( Throwable $e ) {
            backup_lite_log( 'error', 'S3 upload failed: error building object key.', [
                'message' => $e->getMessage(),
                'file_name' => $file_name,
            ] );
            // Fallback to simple key
            return $prefix ? trailingslashit( $prefix ) . $file_name : $file_name;
        }
    }
}

