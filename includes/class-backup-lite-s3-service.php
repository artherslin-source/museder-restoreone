<?php
/**
 * S3 Service
 * 
 * Handles S3 cloud storage operations for backup files.
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * S3 cloud storage: upload backup archive
 * Museder_Restoreone_S3_Service class.
 */
class Museder_Restoreone_S3_Service {

    /**
     * File size threshold for simple PUT upload (50MB).
     * Files <= this size will use simple PUT, files > this size will use multipart upload.
     *
     * @var int
     */
    const SIMPLE_PUT_THRESHOLD = 50 * 1024 * 1024; // 50MB

    /**
     * Chunk size for multipart upload (8MB).
     * Each part should be between 5MB and 5GB (except the last part).
     *
     * @var int
     */
    const MULTIPART_CHUNK_SIZE = 8 * 1024 * 1024; // 8MB

    /**
     * Sanitize S3 error message to remove sensitive information.
     * 
     * Removes Access Keys, Signatures, and other sensitive data from error messages
     * before displaying them to users.
     *
     * @param string $message Raw error message.
     * @return string Sanitized error message.
     */
    public static function sanitize_s3_error_message( $message ) {
        $message = (string) $message;
        
        // Remove sensitive patterns: Access Keys, Signatures, etc.
        $patterns = array(
            '/AWSAccessKeyId=[^&\s]+/i',
            '/Signature=[^&\s]+/i',
            '/X-Amz-Signature=[^&\s]+/i',
            '/X-Amz-Credential=[^&\s]+/i',
            '/access[_-]?key[=:\s]+[^\s]+/i',
            '/secret[_-]?key[=:\s]+[^\s]+/i',
            '/secret[_-]?access[_-]?key[=:\s]+[^\s]+/i',
        );
        
        foreach ( $patterns as $pattern ) {
            $message = preg_replace( $pattern, '***', $message );
        }
        
        // Truncate long messages to avoid overwhelming the UI
        if ( strlen( $message ) > 300 ) {
            $message = substr( $message, 0, 300 ) . '...';
        }
        
        return $message;
    }

    /**
     * Uploads a local backup file to S3.
     * 
     * Automatically chooses between simple PUT (for files <= 50MB) and multipart upload (for files > 50MB).
     *
     * @param string $file_path Absolute path to local backup file.
     * @return array|WP_Error On success, array with 'status' => 'success' and 'object_key'. On failure, WP_Error.
     */
    public static function upload_backup( $file_path ) {
        $file_path = wp_normalize_path( $file_path );

        // Log that function was called
        museder_restoreone_log( 'info', 'S3 upload_backup() called.', [
            'file' => $file_path,
        ] );

        // Validate file exists and is readable
        if ( ! file_exists( $file_path ) ) {
            $error_msg = __( 'Backup file not found.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 upload failed: backup file not found.', [
                'file' => $file_path,
                'reason' => 'file_not_found',
            ] );
            return new WP_Error( 's3_missing_file', $error_msg );
        }
        
        if ( ! is_readable( $file_path ) ) {
            museder_restoreone_log( 'error', 'S3 upload failed: backup file is not readable.', [
                'file' => $file_path,
                'reason' => 'file_not_readable',
            ] );
            return new WP_Error( 's3_open_failed', __( 'Unable to open backup file for reading.', 'museder-restoreone' ) );
        }
        
        // Get file size
        $file_size = filesize( $file_path );
        if ( false === $file_size || $file_size <= 0 ) {
            museder_restoreone_log( 'error', 'S3 upload failed: backup file size is invalid.', [
                'file' => $file_path,
                'size' => $file_size,
                'reason' => 'file_size_invalid',
            ] );
            return new WP_Error( 's3_filesize_failed', __( 'Unable to determine backup file size.', 'museder-restoreone' ) );
        }

        // Get S3 settings
        $settings = museder_restoreone_get_s3_settings();

        // Validate S3 settings
        if ( empty( $settings['enabled'] ) ) {
            museder_restoreone_log( 'info', 'S3 upload skipped: S3 is disabled in settings.', array() );
            return new WP_Error( 's3_disabled', __( 'S3 is disabled in settings.', 'museder-restoreone' ) );
        }

        if ( empty( $settings['access_key_id'] ) || empty( $settings['secret_access_key'] ) || empty( $settings['region'] ) || empty( $settings['bucket'] ) ) {
            museder_restoreone_log( 'error', 'S3 upload skipped: missing required settings.', array() );
            return new WP_Error( 'missing_s3_settings', __( 'S3 settings are incomplete. Please configure S3 in plugin settings.', 'museder-restoreone' ) );
        }

        // Build object key
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
            $prefix      = isset( $settings['prefix'] ) ? $settings['prefix'] : '';
            $object_key  = $prefix 
                ? trailingslashit( $prefix ) . trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name
                : trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name;
        } catch ( Throwable $key_error ) {
            museder_restoreone_log( 'error', 'S3 upload failed: error building object key.', [
                'reason' => 'object_key_error',
                'message' => $key_error->getMessage(),
                'file_name' => $file_name,
            ] );
            return new WP_Error( 'object_key_error', __( 'Failed to build S3 object key.', 'museder-restoreone' ) );
        }

        // Choose upload method based on file size.
        if ( $file_size > self::SIMPLE_PUT_THRESHOLD ) {
            museder_restoreone_log( 'info', 'S3 upload: using multipart upload method.', [
                'file_size' => $file_size,
                'file_name' => basename( $file_path ),
            ] );
            return self::upload_multipart( $file_path, $file_size, $object_key, $settings );
        }

        museder_restoreone_log( 'info', 'S3 upload: using simple PUT method.', [
            'file_size' => $file_size,
            'file_name' => basename( $file_path ),
        ] );

        return self::upload_simple_put( $file_path, $file_size, $object_key, $settings );
    }

    /**
     * Upload a file using simple PUT method (for files <= 50MB).
     * 
     * @param string $file_path   Absolute path to local backup file.
     * @param int    $file_size   File size in bytes.
     * @param string $object_key  S3 object key.
     * @param array  $settings    S3 settings array.
     * @return array|WP_Error On success, array with 'status' => 'success' and 'object_key'. On failure, WP_Error.
     */
    protected static function upload_simple_put( $file_path, $file_size, $object_key, $settings ) {
        museder_restoreone_log( 'info', 'S3 simple PUT upload starting.', [
            'file' => $file_path,
            'size' => $file_size,
            'key'  => $object_key,
        ] );

        // Build target URL
        $bucket   = $settings['bucket'];
        $region   = $settings['region'];
        $endpoint = isset( $settings['endpoint'] ) ? $settings['endpoint'] : '';
        $use_path = isset( $settings['use_path_style_endpoint'] ) ? $settings['use_path_style_endpoint'] : false;

        try {
            $target_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $object_key );
        } catch ( Throwable $url_error ) {
            museder_restoreone_log( 'error', 'S3 simple PUT failed: error building target URL.', [
                'reason' => 'url_build_error',
                'message' => $url_error->getMessage(),
            ] );
            return new WP_Error( 'url_build_error', __( 'Failed to build S3 target URL.', 'museder-restoreone' ) );
        }

        museder_restoreone_log( 'info', 'S3 simple PUT: using wp_remote_request method.', [
            'file' => $file_path,
            'size' => $file_size,
        ] );
        $result = self::put_object_via_wp_http( $file_path, $object_key, $target_url, $settings );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        if ( true === $result || ( is_array( $result ) && isset( $result['status'] ) && 'success' === $result['status'] ) ) {
            museder_restoreone_log( 'info', 'S3 simple PUT upload completed successfully.', [
                'bucket' => $bucket,
                'key'    => $object_key,
                'method' => 'wp_http',
            ] );
            return array(
                'success'   => true,
                'status'    => 'success',
                'object_key' => $object_key,
            );
        }
        
        museder_restoreone_log( 'error', 'S3 simple PUT failed: unexpected result type.', [
            'result_type' => gettype( $result ),
        ] );
        return new WP_Error( 's3_unexpected_result', __( 'S3 upload returned unexpected result.', 'museder-restoreone' ) );
    }

    /**
     * Upload a file to S3 using wp_remote_request() with hash_file() for payload hash.
     * 
     * This method uses WordPress HTTP API and calculates payload hash using hash_file()
     * to avoid loading the entire file into memory for hash calculation.
     *
     * @param string $file_path   Absolute path to local backup file.
     * @param string $object_key  S3 object key.
     * @param string $target_url  Full S3 object URL.
     * @param array  $settings    S3 settings array.
     * @return true|WP_Error On success returns true, on failure returns WP_Error.
     */
    protected static function put_object_via_wp_http( $file_path, $object_key, $target_url, $settings ) {
        $file_size = filesize( $file_path );
        if ( false === $file_size || $file_size <= 0 ) {
            museder_restoreone_log( 'error', 'S3 wp_http upload failed: invalid file size.', [
                'file' => $file_path,
            ] );
            return new WP_Error( 's3_invalid_file_size', __( 'Invalid file size.', 'museder-restoreone' ) );
        }

        museder_restoreone_log( 'info', 'S3 wp_http upload: starting upload.', [
            'file' => $file_path,
            'size' => $file_size,
            'key'  => $object_key,
        ] );

        // Read entire file content as string (for wp_remote_request)
        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $body = @file_get_contents( $file_path );
        if ( false === $body ) {
            $error = error_get_last();
            $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error reading file.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 wp_http upload failed: could not read file.', [
                'file' => $file_path,
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_read_file_error', __( 'Could not read backup file for S3 upload.', 'museder-restoreone' ) );
        }

        // Verify read size matches file size
        $read_size = strlen( $body );
        if ( $read_size !== $file_size ) {
            unset( $body );
            museder_restoreone_log( 'error', 'S3 wp_http upload failed: file read size mismatch.', [
                'file' => $file_path,
                'expected_size' => $file_size,
                'actual_size' => $read_size,
            ] );
            return new WP_Error( 's3_read_file_error', __( 'File read size mismatch. File may be corrupted or in use.', 'museder-restoreone' ) );
        }

        // Calculate payload hash using hash_file() instead of hash() for better memory efficiency
        // For large files (>100MB), use UNSIGNED-PAYLOAD
        $unsigned_payload = false;
        if ( $file_size > 100 * 1024 * 1024 ) { // 100MB threshold
            $payload_hash = 'UNSIGNED-PAYLOAD';
            $unsigned_payload = true;
        } else {
            // Use hash_file() to calculate SHA256 without loading entire file into memory for hash
            $payload_hash = @hash_file( 'sha256', $file_path );
            if ( false === $payload_hash ) {
                unset( $body );
                museder_restoreone_log( 'error', 'S3 wp_http upload failed: hash_file() calculation failed.', [
                    'file' => $file_path,
                ] );
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate payload hash.', 'museder-restoreone' ) );
            }
        }

        // Parse URL to get host and path
        $url_parts = wp_parse_url( $target_url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            unset( $body );
            museder_restoreone_log( 'error', 'S3 wp_http upload failed: invalid URL format.', [
                'url' => $target_url,
            ] );
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';
        $bucket = $settings['bucket'];
        $region = $settings['region'];
        $access_key = $settings['access_key_id'];
        $secret_key = $settings['secret_access_key'];

        try {
            // AWS Signature Version 4
            $amz_date = gmdate( 'Ymd\THis\Z' );
            $date_stamp = gmdate( 'Ymd' );
            $content_sha256 = $payload_hash;

            // Build canonical request
            $canonical_uri = $path;
            $canonical_querystring = $query;
            $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
            $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
            $payload_hash_for_request = $content_sha256;

            $canonical_request = sprintf(
                "PUT\n%s\n%s\n%s\n%s\n%s",
                $canonical_uri,
                $canonical_querystring,
                $canonical_headers,
                $signed_headers,
                $payload_hash_for_request
            );

            // Build string to sign
            $algorithm = 'AWS4-HMAC-SHA256';
            $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
            $canonical_request_hash = hash( 'sha256', $canonical_request );
            if ( false === $canonical_request_hash ) {
                unset( $body );
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
            }

            $string_to_sign = sprintf(
                "%s\n%s\n%s\n%s",
                $algorithm,
                $amz_date,
                $credential_scope,
                $canonical_request_hash
            );

            // Calculate signature
            $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
            if ( false === $k_date ) {
                unset( $body );
                return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
            }

            $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
            if ( false === $k_region ) {
                unset( $body );
                return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
            }

            $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
            if ( false === $k_service ) {
                unset( $body );
                return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
            }

            $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
            if ( false === $k_signing ) {
                unset( $body );
                return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
            }

            $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
            if ( false === $signature ) {
                unset( $body );
                return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
            }

            // Build authorization header
            $authorization = sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $algorithm,
                $access_key,
                $credential_scope,
                $signed_headers,
                $signature
            );

            // Prepare request headers
            $headers = array(
                'Host'               => $host,
                'Content-Type'       => 'application/zip',
                'Content-Length'     => (string) $file_size,
                'x-amz-date'         => $amz_date,
                'x-amz-content-sha256' => $content_sha256,
                'Authorization'      => $authorization,
            );

            // Timeout for large backup file uploads (minimum 300 seconds, default 600 seconds)
            // This is necessary for large backup archives that may take several minutes to upload
            // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            // Reason: Hook name is already prefixed with "museder_restoreone_".
            $timeout = apply_filters( 'museder_restoreone_s3_upload_timeout', 600 );
            // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            if ( $timeout < 300 ) {
                $timeout = 300; // Minimum 300 seconds for large files
            }

            $request_args = array(
                'method'      => 'PUT',
                'timeout'     => $timeout,
                'headers'     => $headers,
                'body'        => $body, // Must be string (wp_remote_request() does not support resource)
                'data_format' => 'body',
            );

            // Log before request
            museder_restoreone_log( 'info', 'S3 wp_http upload: sending PUT request.', [
                'file' => $file_path,
                'size' => $file_size,
                'key'  => $object_key,
                'method' => 'wp_remote_request',
            ] );

            // Send request
            try {
                $response = @wp_remote_request( $target_url, $request_args );
            } catch ( Throwable $request_error ) {
                unset( $body );
                $error_message = self::sanitize_s3_error_message( $request_error->getMessage() );
                museder_restoreone_log( 'error', 'S3 wp_http upload failed: exception during wp_remote_request.', [
                    'file' => $file_path,
                    'message' => $request_error->getMessage(),
                ] );
                return new WP_Error(
                    'museder_restoreone_s3_upload_error',
                    sprintf(
                        /* translators: %s: Error message from S3 upload. */
                        __( 'S3 upload failed: %s', 'museder-restoreone' ),
                        $error_message
                    )
                );
            }

            // Clear body from memory
            unset( $body );

            // Check response
            if ( false === $response ) {
                $error = error_get_last();
                $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error.', 'museder-restoreone' );
                museder_restoreone_log( 'error', 'S3 wp_http upload failed: wp_remote_request returned false.', [
                    'file' => $file_path,
                    'error' => $error_msg,
                ] );
                return new WP_Error( 's3_request_failed', __( 'S3 upload request failed: wp_remote_request returned false.', 'museder-restoreone' ) );
            }

            if ( is_wp_error( $response ) ) {
                $error_code = $response->get_error_code();
                $error_message = $response->get_error_message();
                
                // Mask bucket name in URL for security
                $masked_url = $target_url;
                if ( ! empty( $bucket ) ) {
                    $masked_url = str_replace( $bucket, substr( $bucket, 0, 3 ) . '***', $masked_url );
                }
                
                museder_restoreone_log( 'error', 'S3 wp_http upload failed (wp_remote_request error).', [
                    'file' => $file_path,
                    'file_name' => basename( $file_path ),
                    'method' => 'PUT',
                    'url' => $masked_url,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                ] );
                return new WP_Error( 's3_http_error', $response->get_error_message() );
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $body_snippet = substr( (string) $response_body, 0, 4096 ); // First 4KB

            // Log response details
            museder_restoreone_log( 'info', 'S3 wp_http upload: request completed.', [
                'file' => $file_path,
                'size' => $file_size,
                'key'  => $object_key,
                'method' => 'wp_remote_request',
                'status_code' => $status_code,
                'body_preview' => $body_snippet,
            ] );

            if ( $status_code < 200 || $status_code >= 300 ) {
                // Mask bucket name in URL for security
                $masked_url = $target_url;
                if ( ! empty( $bucket ) ) {
                    $masked_url = str_replace( $bucket, substr( $bucket, 0, 3 ) . '***', $masked_url );
                }
                
                // Get first few hundred characters of response body for debugging
                $body_preview = substr( (string) $response_body, 0, 500 );
                
                museder_restoreone_log( 'error', 'S3 wp_http upload failed (non-2xx response).', [
                    'file' => $file_path,
                    'file_name' => basename( $file_path ),
                    'method' => 'PUT',
                    'url' => $masked_url,
                    'status_code' => $status_code,
                    'body_preview' => $body_preview,
                ] );
                
                // Return user-friendly error message
                if ( $status_code >= 400 && $status_code < 500 ) {
                    return new WP_Error(
                        's3_4xx',
                        __( 'S3 upload failed with HTTP 4xx. Please check logs for details.', 'museder-restoreone' )
                    );
                } elseif ( $status_code >= 500 ) {
                    return new WP_Error(
                        's3_5xx',
                        __( 'S3 upload failed with HTTP 5xx. Please check logs for details.', 'museder-restoreone' )
                    );
                } else {
                    return new WP_Error(
                        's3_non_2xx',
                        sprintf(
                            /* translators: %d: HTTP status code */
                            __( 'S3 returned unexpected status code %d. Please check logs for details.', 'museder-restoreone' ),
                            (int) $status_code
                        )
                    );
                }
            }

            return true;
        } catch ( Throwable $e ) {
            if ( isset( $body ) ) {
                unset( $body );
            }
            $error_message = self::sanitize_s3_error_message( $e->getMessage() );
            museder_restoreone_log( 'error', 'S3 wp_http upload failed: exception.', [
                'file' => $file_path,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            return new WP_Error(
                'museder_restoreone_s3_upload_error',
                sprintf(
                    /* translators: %s: Error message from S3 upload. */
                    __( 'S3 upload failed: %s', 'museder-restoreone' ),
                    $error_message
                )
            );
        }
    }

    /**
     * Upload a file using S3 Multipart Upload (for files > 50MB).
     * 
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     * 
     * This method reads the file in chunks (8MB each) and uploads them separately,
     * avoiding loading the entire file into memory.
     *
     * @param string $file_path   Absolute path to local backup file.
     * @param int    $file_size   File size in bytes.
     * @param string $object_key  S3 object key.
     * @param array  $settings    S3 settings array.
     * @return array|WP_Error On success, array with 'status' => 'success' and 'object_key'. On failure, WP_Error.
     */
    protected static function upload_multipart( $file_path, $file_size, $object_key, $settings ) {
        museder_restoreone_log( 'info', 'S3 multipart upload starting.', [
            'file' => $file_path,
            'size' => $file_size,
            'key'  => $object_key,
            'chunk_size' => self::MULTIPART_CHUNK_SIZE,
        ] );

        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = isset( $settings['endpoint'] ) ? $settings['endpoint'] : '';
        $use_path    = isset( $settings['use_path_style_endpoint'] ) ? $settings['use_path_style_endpoint'] : false;
        $access_key  = $settings['access_key_id'];
        $secret_key  = $settings['secret_access_key'];

        // Step 1: Create multipart upload
        museder_restoreone_log( 'info', 'S3 multipart upload: creating multipart upload.', [
            'bucket' => $bucket,
            'key'    => $object_key,
        ] );

        $upload_id = self::create_multipart_upload(
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
            museder_restoreone_log( 'error', 'S3 multipart upload failed: could not create multipart upload.', [
                'error_code' => $upload_id->get_error_code(),
                'error_message' => $upload_id->get_error_message(),
            ] );
            return $upload_id;
        }

        museder_restoreone_log( 'info', 'S3 multipart upload: multipart upload created.', [
            'upload_id' => $upload_id,
        ] );

        // Step 2: Upload parts
        $parts = array();
        $part_number = 0;
		// 備份/還原過程需要串流讀大檔案，WP_Filesystem 在這個情境不安全或效能不足，只能使用原生檔案函式。
		$file_handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming backup file for multipart upload.
        if ( false === $file_handle ) {
            // Abort multipart upload if we can't open the file
            self::abort_multipart_upload(
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
                
                // Read chunk as string (NOT resource) - maximum 8MB
				$part_data = @fread( $file_handle, self::MULTIPART_CHUNK_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming multipart chunk.
                if ( false === $part_data ) {
					fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streaming file on read error.
                    // Abort multipart upload
                    self::abort_multipart_upload(
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

                // If we read 0 bytes, we're done
                if ( strlen( $part_data ) === 0 ) {
                    break;
                }

                $part_size = strlen( $part_data );
                museder_restoreone_log( 'debug', 'S3 multipart upload: uploading part.', [
                    'part_number' => $part_number,
                    'part_size'   => $part_size,
                ] );

                // Upload this part
                $etag = self::upload_part(
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
					fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streaming file on upload error.
                    // Abort multipart upload
                    self::abort_multipart_upload(
                        $bucket,
                        $region,
                        $object_key,
                        $endpoint,
                        $use_path,
                        $upload_id,
                        $access_key,
                        $secret_key
                    );
                    museder_restoreone_log( 'error', 'S3 multipart upload failed: part upload failed.', [
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

                museder_restoreone_log( 'info', 'S3 multipart upload: part uploaded successfully.', [
                    'part_number' => $part_number,
                    'etag' => $etag,
                ] );
            }

			fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streaming file.

            // Step 3: Complete multipart upload
            museder_restoreone_log( 'info', 'S3 multipart upload: completing multipart upload.', [
                'parts_count' => count( $parts ),
            ] );

            $complete_result = self::complete_multipart_upload(
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
                self::abort_multipart_upload(
                    $bucket,
                    $region,
                    $object_key,
                    $endpoint,
                    $use_path,
                    $upload_id,
                    $access_key,
                    $secret_key
                );
                museder_restoreone_log( 'error', 'S3 multipart upload failed: complete failed.', [
                    'error_code' => $complete_result->get_error_code(),
                    'error_message' => $complete_result->get_error_message(),
                    'parts_count' => count( $parts ),
                ] );
                return $complete_result;
            }

            museder_restoreone_log( 'info', 'S3 multipart upload completed successfully.', [
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
                // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                // Reason: Exception cleanup - ensure file handle is closed even on error.
                @fclose( $file_handle );
                // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }

            // Abort multipart upload on exception
            self::abort_multipart_upload(
                $bucket,
                $region,
                $object_key,
                $endpoint,
                $use_path,
                $upload_id,
                $access_key,
                $secret_key
            );

            museder_restoreone_log( 'error', 'S3 multipart upload failed: exception.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr( $e->getTraceAsString(), 0, 1000 ),
            ] );

            return new WP_Error( 's3_multipart_exception', __( 'S3 multipart upload failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Build S3 object URL.
     *
     * This method is public to allow Museder_Restoreone_S3_Uploader to use it.
     *
     * @param string $bucket   Bucket name.
     * @param string $region   AWS region.
     * @param string $endpoint Custom endpoint (optional).
     * @param bool   $use_path Use path-style endpoint.
     * @param string $object_key Object key.
     * @return string
     */
    public static function build_s3_object_url( $bucket, $region, $endpoint, $use_path, $object_key ) {
        // If custom endpoint is provided, use it
        if ( $endpoint ) {
            $endpoint = rtrim( $endpoint, '/' );
            if ( $use_path ) {
                return $endpoint . '/' . rawurlencode( $bucket ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
            }
            return $endpoint . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
        }

        // Default Amazon S3 endpoint
        $host = $bucket . '.s3.' . $region . '.amazonaws.com';
        if ( $use_path ) {
            $host = 's3.' . $region . '.amazonaws.com';
            return 'https://' . $host . '/' . rawurlencode( $bucket ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
        }

        return 'https://' . $host . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
    }

    /**
     * AWS SigV4 encoding for query string keys/values (RFC 3986).
     *
     * @param string $value Raw value.
     * @return string Encoded value.
     */
    private static function aws_sigv4_encode( $value ) {
        return str_replace( '%7E', '~', rawurlencode( (string) $value ) );
    }

    /**
     * Build canonical query string for AWS SigV4 signing.
     *
     * @param string $query Raw query string (without leading '?', may already be url-encoded).
     * @return string Canonical query string.
     */
    private static function aws_sigv4_canonical_querystring( $query ) {
        $query = (string) $query;
        if ( '' === $query ) {
            return '';
        }

        $pairs = [];
        foreach ( explode( '&', $query ) as $chunk ) {
            if ( '' === $chunk ) {
                continue;
            }
            $kv = explode( '=', $chunk, 2 );
            $k  = rawurldecode( $kv[0] );
            $v  = isset( $kv[1] ) ? rawurldecode( $kv[1] ) : '';
            $pairs[] = [ $k, $v ];
        }

        usort(
            $pairs,
            static function ( $a, $b ) {
                if ( $a[0] === $b[0] ) {
                    return strcmp( (string) $a[1], (string) $b[1] );
                }
                return strcmp( (string) $a[0], (string) $b[0] );
            }
        );

        $out = [];
        foreach ( $pairs as $pair ) {
            $out[] = self::aws_sigv4_encode( $pair[0] ) . '=' . self::aws_sigv4_encode( $pair[1] );
        }

        return implode( '&', $out );
    }

    /**
     * Puts an object to S3 using Signature Version 4.
     *
     * @param array $args {
     *     @type string            $bucket         Bucket name.
     *     @type string            $region         AWS region.
     *     @type string            $key            Object key.
     *     @type string            $body           Request body (must be string, wp_remote_request() does not support resource).
     *     @type string            $content_type   Content type (e.g., 'application/zip').
     *     @type int               $content_length Content length in bytes.
     *     @type string            $url            Target S3 URL.
     *     @type string            $access_key     Access key ID.
     *     @type string            $secret_key     Secret access key.
     * }
     * @return true|WP_Error On success returns true, on failure returns WP_Error.
     */
    /**
     * Puts an object to S3 using Signature Version 4.
     * 
     * This method is public to allow Museder_Restoreone_S3_Uploader to use it.
     * 
     * @param array $args {
     *     @type string            $bucket         Bucket name.
     *     @type string            $region         AWS region.
     *     @type string            $key            Object key.
     *     @type string            $body           File content as string (NOT resource).
     *     @type string            $content_type   Content type.
     *     @type int               $content_length Content length in bytes.
     *     @type string            $url            Target S3 URL.
     *     @type string            $access_key     Access key ID.
     *     @type string            $secret_key     Secret access key.
     * }
     * @return true|WP_Error On success returns true, on failure returns WP_Error.
     */
    public static function put_object_via_sigv4( array $args ) {
        // Validate required parameters
        $required_keys = array( 'bucket', 'region', 'key', 'body', 'content_type', 'content_length', 'url', 'access_key', 'secret_key' );
        foreach ( $required_keys as $required_key ) {
            if ( ! isset( $args[ $required_key ] ) ) {
                return new WP_Error(
                    's3_missing_arg',
                    sprintf(
                        /* translators: %s: missing argument name */
                        __( 'Missing required S3 argument: %s.', 'museder-restoreone' ),
                        esc_html( $required_key )
                    )
                );
            }
        }
        
        $bucket         = $args['bucket'];
        $region         = $args['region'];
        $key            = $args['key'];
        $body           = $args['body'];
        $content_type   = $args['content_type'];
        $content_length = (int) $args['content_length'];
        $url            = $args['url'];
        $access_key     = $args['access_key'];
        $secret_key     = $args['secret_key'];
        
        // Validate content_length
        if ( $content_length <= 0 ) {
            return new WP_Error( 's3_invalid_content_length', __( 'Content length must be greater than 0.', 'museder-restoreone' ) );
        }
        
        // Validate body is string (wp_remote_request() requires string, not resource)
        if ( ! is_string( $body ) ) {
            museder_restoreone_log( 'error', 'S3 upload failed: body must be a string.', [
                'body_type' => gettype( $body ),
            ] );
            return new WP_Error( 's3_invalid_body_type', __( 'Body must be a string. Resource handles are not supported by wp_remote_request().', 'museder-restoreone' ) );
        }
        
        // Calculate payload hash for signature
        // For large files (>100MB), use UNSIGNED-PAYLOAD to avoid hash calculation overhead
        // For smaller files, calculate SHA256 hash for better security
        $unsigned_payload = false;
        if ( $content_length > 100 * 1024 * 1024 ) { // 100MB threshold
            // For very large files, use UNSIGNED-PAYLOAD to avoid hash calculation overhead
            $payload_hash = 'UNSIGNED-PAYLOAD';
            $unsigned_payload = true;
            museder_restoreone_log( 'debug', 'S3 upload: using UNSIGNED-PAYLOAD for large file.', [
                'content_length' => $content_length,
            ] );
        } else {
            // For smaller files, calculate SHA256 hash
            $payload_hash = hash( 'sha256', $body );
            if ( false === $payload_hash ) {
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate payload hash.', 'museder-restoreone' ) );
            }
        }
        
        // Parse URL to get host
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            museder_restoreone_log( 'error', 'S3 upload failed: invalid URL format.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL.', 'museder-restoreone' ) );
        }

        $host = isset( $url_parts['host'] ) ? $url_parts['host'] : '';
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';
        
        // Validate host is not empty
        if ( empty( $host ) ) {
            museder_restoreone_log( 'error', 'S3 upload failed: empty host in URL.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL: missing host.', 'museder-restoreone' ) );
        }
        
        try {

            // AWS Signature Version 4
            // Validate required credentials
            if ( empty( $access_key ) || empty( $secret_key ) || empty( $region ) ) {
                museder_restoreone_log( 'error', 'S3 upload failed: missing required credentials for signature.', [
                    'has_access_key' => ! empty( $access_key ),
                    'has_secret_key' => ! empty( $secret_key ),
                    'has_region' => ! empty( $region ),
                ] );
                return new WP_Error( 's3_missing_credentials', __( 'Missing required S3 credentials for signature calculation.', 'museder-restoreone' ) );
            }
            
            $amz_date = gmdate( 'Ymd\THis\Z' );
            $date_stamp = gmdate( 'Ymd' );
            $content_sha256 = $payload_hash; // Use calculated hash or 'UNSIGNED-PAYLOAD'

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = self::aws_sigv4_canonical_querystring( $query );
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;
        
        $canonical_request = sprintf(
            "PUT\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            museder_restoreone_log( 'error', 'S3 upload failed: hash calculation failed.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }
        
        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature - wrap each hash_hmac call in error checking
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            museder_restoreone_log( 'error', 'S3 upload failed: k_date hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }
        
        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            museder_restoreone_log( 'error', 'S3 upload failed: k_region hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }
        
        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            museder_restoreone_log( 'error', 'S3 upload failed: k_service hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }
        
        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            museder_restoreone_log( 'error', 'S3 upload failed: k_signing hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }
        
        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            museder_restoreone_log( 'error', 'S3 upload failed: signature hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

            // Prepare request headers
            $headers = array(
                'Host'               => $host,
                'Content-Type'       => $content_type,
                'Content-Length'     => (string) $content_length,
                'x-amz-date'         => $amz_date,
                'x-amz-content-sha256' => $content_sha256,
                'Authorization'      => $authorization,
            );

            // Log before making request
            museder_restoreone_log( 'debug', 'S3 upload: sending PUT request.', [
                'bucket'         => $bucket,
                'region'         => $region,
                'key'            => $key,
                'content_length' => $content_length,
                'unsigned'       => $unsigned_payload,
            ] );
            
            $request_args = array(
                'method'      => 'PUT',
                'timeout'     => 600, // 10 minutes for large files
                'headers'     => $headers,
                'body'        => $body, // Must be string (wp_remote_request() does not support resource)
                'data_format' => 'body',
            );
            
            // Use try-catch around wp_remote_request to catch any exceptions
            try {
                $response = @wp_remote_request( $url, $request_args );
            } catch ( Throwable $request_error ) {
                museder_restoreone_log( 'error', 'S3 upload failed: exception during wp_remote_request.', [
                    'url' => $url,
                    'message' => $request_error->getMessage(),
                    'file' => $request_error->getFile(),
                    'line' => $request_error->getLine(),
                ] );
                // Only log to PHP error log if debug mode is enabled
                if ( defined( 'MUSEDER_RESTOREONE_DEBUG' ) && MUSEDER_RESTOREONE_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( '[Museder RestoreOne] S3 wp_remote_request exception: ' . $request_error->getMessage() . ' in ' . $request_error->getFile() . ':' . $request_error->getLine() );
                }
                return new WP_Error( 's3_request_exception', __( 'S3 upload request failed with exception.', 'museder-restoreone' ) );
            }
            
            // Check if wp_remote_request returned false or invalid result
            if ( false === $response ) {
                $error = error_get_last();
                $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error.', 'museder-restoreone' );
                museder_restoreone_log( 'error', 'S3 upload failed: wp_remote_request returned false.', [
                    'url' => $url,
                    'error' => $error_msg,
                ] );
                return new WP_Error( 's3_request_failed', __( 'S3 upload request failed: wp_remote_request returned false.', 'museder-restoreone' ) );
            }
            
            // Check if response is WP_Error
            if ( is_wp_error( $response ) ) {
                museder_restoreone_log( 'error', 'S3 upload failed (wp_remote_request error).', [
                    'error' => $response->get_error_message(),
                ] );
                return new WP_Error( 's3_http_error', $response->get_error_message() );
            }
            
            // Log after request completes
            museder_restoreone_log( 'debug', 'S3 upload: wp_remote_request completed.', [
                'response_type' => gettype( $response ),
            ] );
            
            $status_code = wp_remote_retrieve_response_code( $response );
            
            if ( $status_code < 200 || $status_code >= 300 ) {
                $body_text = wp_remote_retrieve_body( $response );
                
                museder_restoreone_log( 'error', 'S3 upload failed (non-2xx response).', [
                    'status_code'  => $status_code,
                    'body_snippet' => substr( (string) $body_text, 0, 200 ),
                ] );
                
                return new WP_Error(
                    's3_non_2xx',
                    sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'S3 returned unexpected status code %d.', 'museder-restoreone' ),
                        (int) $status_code
                    )
                );
            }
            
            museder_restoreone_log( 'info', 'S3 upload completed.', [
                'bucket' => $bucket,
                'key'    => $key,
            ] );
            
            return true;
        } catch ( Throwable $e ) {
            // Catch any unhandled exceptions during S3 upload preparation
            $error_message = $e->getMessage();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            
            // Sanitize error message to avoid exposing sensitive info
            if ( stripos( $error_message, 'secret' ) !== false 
                 || stripos( $error_message, 'key' ) !== false 
                 || stripos( $error_message, 'authorization' ) !== false 
                 || stripos( $error_message, 'access' ) !== false
            ) {
                $error_message = __( 'Authentication error', 'museder-restoreone' );
            }
            
            museder_restoreone_log( 'error', 'S3 upload preparation failed: unhandled exception.', [
                'message' => $error_message,
                'file' => $error_file,
                'line' => $error_line,
                'class' => get_class( $e ),
                'trace' => substr( $e->getTraceAsString(), 0, 1000 ), // Limit trace to first 1000 chars
            ] );
            
            // Also log to PHP error log for easier debugging (only if debug mode is enabled)
            if ( defined( 'MUSEDER_RESTOREONE_DEBUG' ) && MUSEDER_RESTOREONE_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[Backup Lite] S3 upload preparation fatal error: ' . $error_message . ' in ' . $error_file . ':' . $error_line );
            }
            
            return new WP_Error( 's3_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Delete an S3 object using Signature V4.
     *
     * @param string $url         Full S3 object URL.
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $access_key  AWS access key.
     * @param string $secret_key  AWS secret key.
     * @return WP_Error|array WP_Error on failure, response array on success.
     */
    protected static function delete_object_via_sigv4( $url, $bucket, $region, $access_key, $secret_key ) {
        if ( ! function_exists( 'wp_parse_url' ) ) {
            return new WP_Error( 'wp_parse_url_missing', 'wp_parse_url() function is not available.' );
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Canonical Request
        $canonical_uri = wp_parse_url( $url, PHP_URL_PATH );
        $canonical_querystring = wp_parse_url( $url, PHP_URL_QUERY ) ?: '';
        $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$content_sha256}\nx-amz-date:{$amz_date}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $canonical_request = "DELETE\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$content_sha256}";

        // String to Sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$amz_date}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

        // Signing Key
        $k_date = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        $k_region = hash_hmac( 'sha256', $region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

        // Signature
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        // Authorization Header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send DELETE request
        $args = array(
            'method'  => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        );

        $response = wp_remote_request( $url, $args );
        
        // Check if wp_remote_request returned false or invalid result
        if ( false === $response ) {
            museder_restoreone_log( 'error', 'S3 upload failed: wp_remote_request returned false.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_request_failed', __( 'S3 upload request failed: wp_remote_request returned false.', 'museder-restoreone' ) );
        }
        
        return $response;
    }

    /**
     * Test S3 connection by uploading a small test object.
     *
     * @param array $settings S3 settings.
     * @return array {
     *     @type bool   $success Whether test succeeded.
     *     @type string $message Result message.
     * }
     */
    public static function test_connection( $settings = null ) {
        if ( null === $settings ) {
            $settings = museder_restoreone_get_s3_settings();
        }

        if ( empty( $settings['access_key_id'] )
             || empty( $settings['secret_access_key'] )
             || empty( $settings['region'] )
             || empty( $settings['bucket'] )
        ) {
            return array(
                'success' => false,
                'message' => __( 'S3 settings are incomplete.', 'museder-restoreone' ),
            );
        }

        // Create a small test object
        $test_content = 'Backup Lite S3 connectivity test.';
        $test_key = ( $settings['prefix'] ? trailingslashit( $settings['prefix'] ) : '' ) . '.backup-lite-test-' . time() . '.txt';

        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = $settings['endpoint'];
        $use_path    = $settings['use_path_style_endpoint'];

        $target_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $test_key );

        // Prepare arguments for put_object_via_sigv4
        $put_args = array(
            'bucket'         => $bucket,
            'region'         => $region,
            'key'            => $test_key,
            'body'           => $test_content, // String for small test content
            'content_type'   => 'text/plain',
            'content_length' => strlen( $test_content ),
            'url'            => $target_url,
            'access_key'     => $settings['access_key_id'],
            'secret_key'     => $settings['secret_access_key'],
        );

        $result = self::put_object_via_sigv4( $put_args );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $error_msg = $result->get_error_message();
            
            // Sanitize error message to avoid exposing sensitive info for user display
            $user_error_msg = $error_msg;
            if ( stripos( $user_error_msg, 'secret' ) !== false || stripos( $user_error_msg, 'key' ) !== false || stripos( $user_error_msg, 'authorization' ) !== false ) {
                $user_error_msg = __( 'Authentication failed', 'museder-restoreone' );
            }
            
            // Log detailed error info (sanitize message to avoid exposing sensitive info in log)
            $log_message = $error_msg;
            // Remove potential sensitive info from log message
            $log_message = preg_replace( '/secret[=\s:]+[^\s]+/i', 'secret=***', $log_message );
            $log_message = preg_replace( '/key[=\s:]+[^\s]+/i', 'key=***', $log_message );
            $log_message = preg_replace( '/authorization[=\s:]+[^\s]+/i', 'authorization=***', $log_message );
            
            if ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log(
                    'error',
                    sprintf(
                        'S3: test connection failed - authentication or connection error. code=%s message=%s',
                        $error_code,
                        $log_message
                    )
                );
            }
            
            return array(
                'success' => false,
                /* translators: %s: User-friendly error message. */
                'message' => sprintf( __( 'Could not upload test file to S3: %s', 'museder-restoreone' ), $user_error_msg ),
            );
        }

        // Result should be true on success
        if ( true === $result ) {
            // Test successful - delete the test object immediately
            $delete_result = self::delete_object_via_sigv4(
                $target_url,
                $bucket,
                $region,
                $settings['access_key_id'],
                $settings['secret_access_key']
            );
            
            // Log deletion result (but don't fail the test if deletion fails)
            if ( is_wp_error( $delete_result ) ) {
                // Don't log error message (may contain sensitive info)
                museder_restoreone_log( 'warning', 'S3: test file uploaded but deletion failed' );
            } else {
                // delete_object_via_sigv4 returns response array, check status code
                $delete_code = wp_remote_retrieve_response_code( $delete_result );
                if ( $delete_code < 200 || $delete_code >= 300 ) {
                    museder_restoreone_log( 'warning', 'S3: test file uploaded but deletion failed with HTTP ' . $delete_code );
                }
            }
            
            return array(
                'success' => true,
                'message' => __( 'Successfully connected to S3 bucket.', 'museder-restoreone' ),
            );
        }
        
        // Unexpected result type (should not happen)
        museder_restoreone_log( 'error', 'S3: test connection returned unexpected result type: ' . gettype( $result ) );
        return array(
            'success' => false,
            'message' => __( 'Unexpected error during S3 connection test.', 'museder-restoreone' ),
        );
    }

    /**
     * Create a multipart upload on S3.
     *
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @param string $content_type Content type (e.g., 'application/zip').
     * @return string|WP_Error UploadId on success, WP_Error on failure.
     */
    public static function create_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $access_key, $secret_key, $content_type = 'application/zip' ) {
        // Build URL with ?uploads query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploads', '', $base_url );

        // Parse URL to get host and path
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for POST request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = self::aws_sigv4_canonical_querystring( $query );
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "POST\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Type'       => $content_type,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send POST request
        $request_args = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'headers'     => $headers,
            'body'        => '', // Empty body for CreateMultipartUpload
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            museder_restoreone_log( 'error', 'S3 create_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 create multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 create_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            /* translators: %s: Error message from S3 multipart upload. */
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 create multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $body_text = wp_remote_retrieve_body( $response );
            museder_restoreone_log( 'error', 'S3 create_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 create multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        // Parse XML response to extract UploadId
        $body_text = wp_remote_retrieve_body( $response );
        $xml = @simplexml_load_string( $body_text );
        if ( false === $xml || ! isset( $xml->UploadId ) ) {
            museder_restoreone_log( 'error', 'S3 create_multipart_upload failed: could not parse UploadId from response.', [
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error( 's3_parse_error', __( 'Failed to parse UploadId from S3 response.', 'museder-restoreone' ) );
        }

        $upload_id = (string) $xml->UploadId;
        museder_restoreone_log( 'info', 'S3 multipart upload created.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
        ] );

        return $upload_id;
    }

    /**
     * Upload a part in a multipart upload.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param int    $part_number Part number (1-based).
     * @param string $part_data   Part data as string (NOT resource).
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return string|WP_Error ETag on success, WP_Error on failure.
     */
    /**
     * Upload a part in a multipart upload.
     *
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param int    $part_number Part number (1-based).
     * @param string $part_data   Part data as string (NOT resource).
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return string|WP_Error ETag on success, WP_Error on failure.
     */
    public static function upload_part( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $part_number, $part_data, $access_key, $secret_key ) {
        // Validate part_data is string
        if ( ! is_string( $part_data ) ) {
            return new WP_Error( 's3_invalid_part_data', __( 'Part data must be a string.', 'museder-restoreone' ) );
        }

        $part_size = strlen( $part_data );
        if ( $part_size <= 0 ) {
            return new WP_Error( 's3_invalid_part_size', __( 'Part data size must be greater than 0.', 'museder-restoreone' ) );
        }

        // Build URL with partNumber and uploadId query parameters
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg(
            array(
                'partNumber' => $part_number,
                'uploadId'   => $upload_id,
            ),
            $base_url
        );

        // Parse URL to get host and path
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for part upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for PUT request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        
        // For multipart upload parts, use UNSIGNED-PAYLOAD to avoid hash calculation overhead
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = self::aws_sigv4_canonical_querystring( $query );
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "PUT\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Length'     => (string) $part_size,
            'Content-Type'       => 'application/zip',
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send PUT request
        $request_args = array(
            'method'      => 'PUT',
            'timeout'     => 300, // 5 minutes per part
            'headers'     => $headers,
            'body'        => $part_data, // String body (NOT resource)
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            museder_restoreone_log( 'error', 'S3 upload_part failed: exception during wp_remote_request.', [
                'url' => $url,
                'part_number' => $part_number,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 part upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 upload_part failed.', [
                'part_number' => $part_number,
                'error' => $error_msg,
            ] );
            /* translators: %s: Error message from S3 part upload. */
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 part upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $body_text = wp_remote_retrieve_body( $response );
            museder_restoreone_log( 'error', 'S3 upload_part failed (non-200 response).', [
                'status_code'  => $status_code,
                'part_number' => $part_number,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_200',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 part upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        // Extract ETag from response headers
        $etag = wp_remote_retrieve_header( $response, 'etag' );
        if ( empty( $etag ) ) {
            museder_restoreone_log( 'error', 'S3 upload_part failed: missing ETag in response.', [
                'part_number' => $part_number,
            ] );
            return new WP_Error( 's3_missing_etag', __( 'S3 part upload response missing ETag.', 'museder-restoreone' ) );
        }

        // Remove quotes from ETag if present
        $etag = trim( $etag, '"' );

        museder_restoreone_log( 'debug', 'S3 part uploaded successfully.', [
            'part_number' => $part_number,
            'part_size'   => $part_size,
            'etag'        => $etag,
        ] );

        return $etag;
    }

    /**
     * Complete a multipart upload on S3.
     *
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param array  $parts       Array of parts, each with 'PartNumber' and 'ETag'.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return true|WP_Error True on success, WP_Error on failure.
     * 
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     */
    public static function complete_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $parts, $access_key, $secret_key ) {
        // Build XML body for CompleteMultipartUpload
        $xml_parts = '';
        foreach ( $parts as $part ) {
            if ( ! isset( $part['PartNumber'] ) || ! isset( $part['ETag'] ) ) {
                return new WP_Error( 's3_invalid_part', __( 'Invalid part data: missing PartNumber or ETag.', 'museder-restoreone' ) );
            }
            $xml_parts .= sprintf(
                '<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
                (int) $part['PartNumber'],
                esc_xml( $part['ETag'] )
            );
        }

        $xml_body = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>' . $xml_parts . '</CompleteMultipartUpload>';

        // Build URL with uploadId query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploadId', $upload_id, $base_url );

        // Parse URL to get host and path
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for complete multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for POST request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        
        // Calculate SHA256 hash of XML body
        $content_sha256 = hash( 'sha256', $xml_body );
        if ( false === $content_sha256 ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate body hash.', 'museder-restoreone' ) );
        }

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = self::aws_sigv4_canonical_querystring( $query );
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "POST\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Type'       => 'application/xml',
            'Content-Length'     => (string) strlen( $xml_body ),
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send POST request
        $request_args = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'headers'     => $headers,
            'body'        => $xml_body, // XML body as string
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            museder_restoreone_log( 'error', 'S3 complete_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 complete multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 complete_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            /* translators: %s: Error message from S3 complete multipart upload. */
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 complete multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $body_text = wp_remote_retrieve_body( $response );
            museder_restoreone_log( 'error', 'S3 complete_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 complete multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        museder_restoreone_log( 'info', 'S3 multipart upload completed.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
            'parts_count' => count( $parts ),
        ] );

        return true;
    }

    /**
     * Abort a multipart upload on S3.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return true|WP_Error True on success, WP_Error on failure.
     * 
     * NOTE: Currently not used in production.
     * Multipart uploads are temporarily disabled due to signature issues (SignatureDoesNotMatch).
     * All S3 uploads are handled via single PUT for now (file size < 5GB).
     *
     * Once we have full test coverage against AWS S3, we can re-enable multipart uploads.
     */
    public static function abort_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $access_key, $secret_key ) {
        // Build URL with uploadId query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploadId', $upload_id, $base_url );

        // Parse URL to get host and path
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for abort multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for DELETE request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "DELETE\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send DELETE request
        $request_args = array(
            'method'      => 'DELETE',
            'timeout'     => 30,
            'headers'     => $headers,
            'body'        => '', // Empty body for DELETE
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            museder_restoreone_log( 'error', 'S3 abort_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 abort multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            museder_restoreone_log( 'error', 'S3 abort_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            /* translators: %s: Error message from S3 abort multipart upload. */
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 abort multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        // 204 No Content is also acceptable for DELETE
        if ( $status_code !== 204 && ( $status_code < 200 || $status_code >= 300 ) ) {
            $body_text = wp_remote_retrieve_body( $response );
            museder_restoreone_log( 'error', 'S3 abort_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 abort multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        museder_restoreone_log( 'info', 'S3 multipart upload aborted.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
        ] );

        return true;
    }

    /**
     * List backup files from S3.
     * 
     * @param string $prefix Optional prefix to filter objects (e.g., 'backups/').
     * @return array|WP_Error Array of backup objects on success, WP_Error on failure.
     */
    public static function list_backups( $prefix = '' ) {
        $settings = museder_restoreone_get_s3_settings();
        
        if ( empty( $settings['enabled'] ) || empty( $settings['bucket'] ) ) {
            return new WP_Error(
                's3_not_configured',
                __( 'S3 is not configured or enabled.', 'museder-restoreone' )
            );
        }

        $bucket = $settings['bucket'];
        $region = $settings['region'];
        $access_key = $settings['access_key_id'];
        $secret_key = $settings['secret_access_key'];
        $endpoint = $settings['endpoint'];
        $use_path_style = $settings['use_path_style_endpoint'];
        
        // Build prefix (combine S3 prefix setting with optional parameter)
        $object_prefix = ! empty( $settings['prefix'] ) ? rtrim( $settings['prefix'], '/' ) . '/' : '';
        if ( ! empty( $prefix ) ) {
            $object_prefix .= ltrim( $prefix, '/' );
        }
        
        // Build S3 URL for ListObjectsV2
        $object_key = $object_prefix; // For list, we use prefix as key
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path_style, '' );
        
        // Parse base URL to get host
        $url_parts = wp_parse_url( $base_url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL.', 'museder-restoreone' ) );
        }
        
        $host = $url_parts['host'];
        $scheme = isset( $url_parts['scheme'] ) ? $url_parts['scheme'] : 'https';
        
        // Build path for ListObjectsV2
        if ( $use_path_style && $endpoint ) {
            $path = '/' . rawurlencode( $bucket ) . '/';
        } elseif ( $use_path_style ) {
            $path = '/' . rawurlencode( $bucket ) . '/';
        } else {
            $path = '/';
        }
        
        // Add query parameters for ListObjectsV2
        $query_params = array(
            'list-type' => '2',
        );
        if ( ! empty( $object_prefix ) ) {
            $query_params['prefix'] = $object_prefix;
        }
        // Only list .zip and .wpress files
        $query_params['prefix'] = $object_prefix;
        
        $query_string = http_build_query( $query_params );
        $url = $scheme . '://' . $host . $path . '?' . $query_string;
        
        try {
            // Build SigV4 signature for GET request
            $amz_date = gmdate( 'Ymd\THis\Z' );
            $date_stamp = gmdate( 'Ymd' );
            $payload_hash = hash( 'sha256', '' ); // Empty body for GET
            
            $canonical_uri = $path;
            $canonical_querystring = $query_string;
            $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $payload_hash, $amz_date );
            $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
            
            $canonical_request = sprintf(
                "GET\n%s\n%s\n%s\n%s\n%s",
                $canonical_uri,
                $canonical_querystring,
                $canonical_headers,
                $signed_headers,
                $payload_hash
            );
            
            $algorithm = 'AWS4-HMAC-SHA256';
            $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
            $canonical_request_hash = hash( 'sha256', $canonical_request );
            if ( false === $canonical_request_hash ) {
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
            }
            
            $string_to_sign = sprintf(
                "%s\n%s\n%s\n%s",
                $algorithm,
                $amz_date,
                $credential_scope,
                $canonical_request_hash
            );
            
            // Calculate signature
            $k_date = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
            $k_region = hash_hmac( 'sha256', $region, $k_date, true );
            $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
            $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
            $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );
            
            $authorization = sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $algorithm,
                $access_key,
                $credential_scope,
                $signed_headers,
                $signature
            );
            
            $headers = array(
                'Host' => $host,
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => $payload_hash,
                'Authorization' => $authorization,
            );
            
            $request_args = array(
                'method' => 'GET',
                'timeout' => 30,
                'headers' => $headers,
            );
            
            $response = wp_remote_request( $url, $request_args );
            
            if ( is_wp_error( $response ) ) {
                museder_restoreone_log( 'error', 'S3 list backups failed (wp_remote_request error).', [
                    'error' => $response->get_error_message(),
                ] );
                return new WP_Error( 's3_http_error', $response->get_error_message() );
            }
            
            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code < 200 || $status_code >= 300 ) {
                $body_text = wp_remote_retrieve_body( $response );
                museder_restoreone_log( 'error', 'S3 list backups failed (non-2xx response).', [
                    'status_code' => $status_code,
                    'body_snippet' => substr( (string) $body_text, 0, 200 ),
                ] );
                return new WP_Error(
                    's3_list_error',
                    /* translators: %d: HTTP status code. */
                    sprintf( __( 'S3 list failed with status code %d.', 'museder-restoreone' ), $status_code )
                );
            }
            
            $body = wp_remote_retrieve_body( $response );
            $xml = simplexml_load_string( $body );
            if ( false === $xml ) {
                return new WP_Error( 's3_xml_parse_error', __( 'Failed to parse S3 response XML.', 'museder-restoreone' ) );
            }
            
            $backups = array();
            if ( isset( $xml->Contents ) ) {
                foreach ( $xml->Contents as $object ) {
                    $key = (string) $object->Key;
                    // Only include .zip and .wpress files
                    if ( preg_match( '/\.(zip|wpress)$/i', $key ) ) {
                        $backups[] = array(
                            'key' => $key,
                            'name' => basename( $key ),
                            'size' => (int) $object->Size,
                            'last_modified' => (string) $object->LastModified,
                        );
                    }
                }
            }
            
            // Sort by last modified (newest first)
            usort( $backups, function( $a, $b ) {
                return strtotime( $b['last_modified'] ) - strtotime( $a['last_modified'] );
            } );
            
            museder_restoreone_log( 'info', 'S3 list backups completed.', [
                'count' => count( $backups ),
            ] );
            
            return $backups;
            
        } catch ( Throwable $e ) {
            $error_message = self::sanitize_s3_error_message( $e->getMessage() );
            museder_restoreone_log( 'error', 'S3 list backups failed: exception.', [
                'message' => $error_message,
            ] );
            return new WP_Error( 's3_exception', __( 'S3 list backups failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Download a backup file from S3.
     * 
     * @param string $key S3 object key.
     * @param string $target_path Local file path to save the downloaded file.
     * @param int    $offset Optional byte offset to start download from (for resuming).
     * @param int    $length Optional number of bytes to download (for chunked downloads).
     * @return array|WP_Error Array with 'downloaded' bytes on success, WP_Error on failure.
     */
    public static function download_backup( $key, $target_path, $offset = 0, $length = 0 ) {
        $settings = museder_restoreone_get_s3_settings();
        
        if ( empty( $settings['enabled'] ) || empty( $settings['bucket'] ) ) {
            return new WP_Error(
                's3_not_configured',
                __( 'S3 is not configured or enabled.', 'museder-restoreone' )
            );
        }

        $bucket = $settings['bucket'];
        $region = $settings['region'];
        $access_key = $settings['access_key_id'];
        $secret_key = $settings['secret_access_key'];
        $endpoint = $settings['endpoint'];
        $use_path_style = $settings['use_path_style_endpoint'];
        
        // Build S3 URL
        $url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path_style, $key );
        
        // Parse URL
        $url_parts = wp_parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL.', 'museder-restoreone' ) );
        }
        
        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';
        
        try {
            // Build SigV4 signature for GET request
            $amz_date = gmdate( 'Ymd\THis\Z' );
            $date_stamp = gmdate( 'Ymd' );
            $payload_hash = hash( 'sha256', '' ); // Empty body for GET
            
            // Add Range header if offset/length specified
            $range_header = '';
            if ( $offset > 0 || $length > 0 ) {
                if ( $length > 0 ) {
                    $range_header = sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 );
                } else {
                    $range_header = sprintf( 'bytes=%d-', $offset );
                }
            }
            
            $canonical_uri = $path;
            $canonical_querystring = $query;
            $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $payload_hash, $amz_date );
            if ( ! empty( $range_header ) ) {
                $canonical_headers = sprintf( "host:%s\nrange:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $range_header, $payload_hash, $amz_date );
            }
            $signed_headers = empty( $range_header ) ? 'host;x-amz-content-sha256;x-amz-date' : 'host;range;x-amz-content-sha256;x-amz-date';
            
            $canonical_request = sprintf(
                "GET\n%s\n%s\n%s\n%s\n%s",
                $canonical_uri,
                $canonical_querystring,
                $canonical_headers,
                $signed_headers,
                $payload_hash
            );
            
            $algorithm = 'AWS4-HMAC-SHA256';
            $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
            $canonical_request_hash = hash( 'sha256', $canonical_request );
            if ( false === $canonical_request_hash ) {
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
            }
            
            $string_to_sign = sprintf(
                "%s\n%s\n%s\n%s",
                $algorithm,
                $amz_date,
                $credential_scope,
                $canonical_request_hash
            );
            
            // Calculate signature
            $k_date = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
            $k_region = hash_hmac( 'sha256', $region, $k_date, true );
            $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
            $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
            $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );
            
            $authorization = sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $algorithm,
                $access_key,
                $credential_scope,
                $signed_headers,
                $signature
            );
            
            $headers = array(
                'Host' => $host,
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => $payload_hash,
                'Authorization' => $authorization,
            );
            
            if ( ! empty( $range_header ) ) {
                $headers['Range'] = $range_header;
            }
            
            $request_args = array(
                'method' => 'GET',
                'timeout' => 300, // 5 minutes
                'headers' => $headers,
            );
            
            $response = wp_remote_request( $url, $request_args );
            
            if ( is_wp_error( $response ) ) {
                museder_restoreone_log( 'error', 'S3 download failed (wp_remote_request error).', [
                    'key' => $key,
                    'error' => $response->get_error_message(),
                ] );
                return new WP_Error( 's3_http_error', $response->get_error_message() );
            }
            
            $status_code = wp_remote_retrieve_response_code( $response );
            
            // 206 is Partial Content (for Range requests)
            if ( ( $status_code < 200 || $status_code >= 300 ) && 206 !== $status_code ) {
                $body_text = wp_remote_retrieve_body( $response );
                museder_restoreone_log( 'error', 'S3 download failed (non-2xx response).', [
                    'key' => $key,
                    'status_code' => $status_code,
                    'body_snippet' => substr( (string) $body_text, 0, 200 ),
                ] );
                return new WP_Error(
                    's3_download_error',
                    /* translators: %d: HTTP status code. */
                    sprintf( __( 'S3 download failed with status code %d.', 'museder-restoreone' ), $status_code )
                );
            }
            
            // Write response body to file
			// 說明：以下程式碼用於 S3 串流上傳/下載的必要底層操作。路徑與檔名皆非使用者輸入，來自白名單目錄或 sanitize_file_name() 處理後的值。
            $body = wp_remote_retrieve_body( $response );
			$file_handle = fopen( $target_path, $offset > 0 ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Write downloaded bytes to disk.
            if ( false === $file_handle ) {
                return new WP_Error( 's3_file_open_error', __( 'Failed to open target file for writing.', 'museder-restoreone' ) );
            }
            
			$written = fwrite( $file_handle, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write downloaded bytes to disk.
			fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close downloaded file handle.
            
            if ( false === $written ) {
                return new WP_Error( 's3_file_write_error', __( 'Failed to write downloaded data to file.', 'museder-restoreone' ) );
            }
            
            // Get downloaded bytes
            $downloaded = 0;
            if ( file_exists( $target_path ) ) {
                $downloaded = filesize( $target_path );
            }
            
            museder_restoreone_log( 'info', 'S3 download completed.', [
                'key' => $key,
                'downloaded' => $downloaded,
            ] );
            
            return array(
                'success' => true,
                'downloaded' => $downloaded,
            );
            
        } catch ( Throwable $e ) {
            $error_message = self::sanitize_s3_error_message( $e->getMessage() );
            museder_restoreone_log( 'error', 'S3 download failed: exception.', [
                'key' => $key,
                'message' => $error_message,
            ] );
            return new WP_Error( 's3_exception', __( 'S3 download failed due to an internal error.', 'museder-restoreone' ) );
        }
    }
}

