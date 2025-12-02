<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Backup_Lite_Backup {

    const CHUNK_SIZE = 500;
    /**
     * Cached list of internal directories/files that must never be included in a backup archive.
     *
     * @var array<string>|null
     */
    private static $internal_exclusions = null;

    /**
     * Create a complete site backup containing database, meta and wp-content.
     *
     * @param array $options Optional backup options {
     *     @type string $label Backup label (PRO).
     *     @type bool   $encrypt Whether to encrypt backup (PRO).
     *     @type bool   $dual_version Whether to create dual version (PRO).
     *     @type array  $cloud_destinations Cloud storage destinations (PRO).
     * }
     * @return array{success:bool,message:string,file?:string,url?:string}
     */
    public static function backup_site( $options = [] ) {
        // Start backup timer at the very beginning
        $start_time = microtime( true );
        
        // Check if there's an active async backup job to prevent duplicates
        if ( class_exists( 'Backup_Lite_Backup_Jobs' ) ) {
            $active_job = Backup_Lite_Backup_Jobs::get_active_job();
            if ( ! empty( $active_job ) ) {
                $duration = max( 1, (int) round( microtime( true ) - $start_time ) );
                backup_lite_log( 'warning', 'Backup skipped: another backup is already in progress.', [
                    'active_job_id' => $active_job['id'] ?? 'unknown',
                    'duration' => $duration,
                ] );
                return [
                    'success' => false,
                    'message' => __( 'Another backup is already in progress. Please wait for it to complete.', 'museder-restoreone' ),
                    'duration' => $duration,
                ];
            }
        }
        
        $backup_dir = trailingslashit( backup_lite_get_backup_dir() );

        self::optimize_runtime_environment();

        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            // Calculate duration even on failure (ensure at least 1 second)
            $duration = microtime( true ) - $start_time;
            $duration = max( 1, (int) round( $duration ) );
            
            $log = backup_lite_log( 'error', 'Backup directory is not writable.', [ 
                'dir' => $backup_dir,
                'duration' => $duration,
            ] );
            self::record_backup_event( 'failed', [
                'message' => __( 'Backup directory is not writable.', 'museder-restoreone' ),
                'duration' => $duration,
            ] );
            return [
                'success' => false,
                'message' => __( 'Backup directory is not writable.', 'museder-restoreone' ),
                'log'     => $log,
                'duration' => $duration,
            ];
        }

        // Generate backup filename: site_url + YYYYMMDD + HHMM + random_code
        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_url ) ) {
            $site_url = 'site';
        }
        // Sanitize domain name for filename
        $site_url = sanitize_file_name( $site_url );
        
        $date_time = backup_lite_local_time( 'YmdHis' );
        $random_code = wp_generate_password( 6, false, false );
        
        $label_suffix = '';
        // PRO: Backup label
        if ( ! empty( $options['label'] ) && function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }
        
        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;

        $temp_dir = backup_lite_create_temp_dir( 'build' );
        $sql_path = trailingslashit( $temp_dir ) . 'database.sql';
        $meta_path = trailingslashit( $temp_dir ) . 'meta.json';

        $log = backup_lite_log( 'info', 'Site backup started.', [
            'archive' => $archive_path,
            'method'  => backup_lite_can_use_ziparchive() ? 'ZipArchive' : 'PclZip',
        ] );

        if ( ! self::generate_database_dump( $sql_path ) ) {
            // Calculate duration even on failure (ensure at least 1 second)
            $duration = microtime( true ) - $start_time;
            $duration = max( 1, (int) round( $duration ) );
            
            backup_lite_log( 'error', 'Failed to generate database dump.', [ 
                'path' => $sql_path,
                'duration' => $duration,
            ] );
            backup_lite_delete_directory( $temp_dir );

            self::record_backup_event( 'failed', [
                'message' => __( 'Database export failed. Check logs for details.', 'museder-restoreone' ),
                'duration' => $duration,
            ] );

            return [
                'success' => false,
                'message' => __( 'Database export failed. Check logs for details.', 'museder-restoreone' ),
                'log'     => $log,
                'duration' => $duration,
            ];
        }

        if ( ! self::write_meta_file( $meta_path, $options ) ) {
            // Calculate duration even on failure (ensure at least 1 second)
            $duration = microtime( true ) - $start_time;
            $duration = max( 1, (int) round( $duration ) );
            
            backup_lite_log( 'error', 'Failed to write meta.json file.', [ 
                'path' => $meta_path,
                'duration' => $duration,
            ] );
            backup_lite_delete_directory( $temp_dir );

            self::record_backup_event( 'failed', [
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
                'duration' => $duration,
            ] );

            return [
                'success' => false,
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
                'log'     => $log,
                'duration' => $duration,
            ];
        }

        if ( file_exists( $archive_path ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $archive_path is from plugin-controlled backup directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $archive_path );
            } else {
                @unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled backup directory
            }
        }

        $directories = self::get_directory_map();

        $success = backup_lite_can_use_ziparchive()
            ? self::create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories )
            : self::create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories );

        backup_lite_delete_directory( $temp_dir );

        if ( ! $success || ! file_exists( $archive_path ) ) {
            // Calculate duration even on failure (ensure at least 1 second)
            $duration = microtime( true ) - $start_time;
            $duration = max( 1, (int) round( $duration ) );
            
            backup_lite_log( 'error', 'Site backup failed.', [ 
                'archive' => $archive_path,
                'duration' => $duration,
            ] );

            self::record_backup_event( 'failed', [
                'message' => __( 'Backup failed. See logs for more information.', 'museder-restoreone' ),
                'file'    => $archive_path,
                'duration' => $duration,
            ] );

            return [
                'success' => false,
                'message' => __( 'Backup failed. See logs for more information.', 'museder-restoreone' ),
                'log'     => $log,
                'duration' => $duration,
            ];
        }

        $size = filesize( $archive_path );
        
        // Calculate backup duration (ensure at least 1 second)
        $duration = microtime( true ) - $start_time;
        $duration = max( 1, (int) round( $duration ) ); // Convert to seconds, minimum 1 second
        
        backup_lite_log( 'info', 'Backup completed locally, preparing for S3 upload if enabled.', [
            'file'    => $archive_path,
            'size'    => $size,
            'duration' => $duration,
            'dest_s3' => isset( $options['dest_s3'] ) ? (bool) $options['dest_s3'] : null,
        ] );

        // S3 cloud storage: upload backup archive and record status
        $s3_result = array(
            'status' => 'none',
            'message' => '',
            'object_key' => '',
            'error' => '',
        );
        
        // Check if S3 upload is requested - use unified upload method
        if ( ! empty( $options['dest_s3'] ) && ! empty( $archive_path ) && file_exists( $archive_path ) ) {
            backup_lite_log(
                'info',
                'S3 upload requested for this backup. Starting unified upload handler.',
                array( 'file' => $archive_path )
            );
            
            // Use unified upload method (same as async backup and manual upload)
            $s3_upload_result = self::upload_backup_to_s3( $archive_path );
            
            if ( is_wp_error( $s3_upload_result ) ) {
                // Upload failed - log error but don't fail the backup
                $error_code = $s3_upload_result->get_error_code();
                $error_message = $s3_upload_result->get_error_message();
                
                backup_lite_log( 'error', 'S3 upload failed during backup completion.', [
                    'file' => $archive_path,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                ] );
                
                // Store error status (use unified status: 'failed')
                $s3_result = array(
                    'status' => 'failed',
                    'error' => $error_message, // Store sanitized error message
                );
            } else {
                // Upload succeeded (use unified status: 'stored')
                $s3_result = array(
                    'status' => 'stored',
                );
                if ( is_array( $s3_upload_result ) && isset( $s3_upload_result['object_key'] ) ) {
                    $s3_result['object_key'] = $s3_upload_result['object_key'];
                }
                
                backup_lite_log( 'info', 'S3 upload completed successfully during backup.', [
                    'file' => $archive_path,
                    'object_key' => $s3_result['object_key'] ?? '',
                ] );
            }
        } else {
            backup_lite_log(
                'info',
                'S3 upload skipped because dest_s3 option is not set.',
                array( 'file' => $archive_path )
            );
        }
        
        // Legacy: Cloud Storage upload (for backward compatibility)
        $should_upload_cloud = ! empty( $options['cloud_destination'] ) || ! empty( $options['upload_to_cloud'] );
        if ( $should_upload_cloud && ! $should_upload_s3 && function_exists( 'museder_restoreone_is_cloud_configured' ) && museder_restoreone_is_cloud_configured() && class_exists( 'Museder_Cloud_Service' ) ) {
            $cloud_context = [
                'type'       => isset( $options['schedule_id'] ) ? 'schedule' : 'manual',
                'site_url'   => home_url(),
                'created_at' => time(),
                'backup_id'  => basename( $archive_path ),
            ];
            
            $cloud_result = Museder_Cloud_Service::maybe_upload_backup( $archive_path, $cloud_context, $should_upload_cloud );
            
            // Log cloud upload result
            if ( $cloud_result['status'] === 'success' ) {
                backup_lite_log( 'info', sprintf( 'Cloud: uploaded backup to S3 bucket %s - file: %s', $cloud_result['remote_path'] ?? 'cloud storage', basename( $archive_path ) ) );
            } elseif ( $cloud_result['status'] === 'error' ) {
                backup_lite_log( 'error', sprintf( 'Cloud: failed to upload backup to S3 (%s)', $cloud_result['message'] ?? 'Unknown error' ) );
            }
            // 'skipped' status is not logged
        }

        // Store backup metadata (for labels, S3 status, duration, etc.)
        $metadata = array();
        if ( ! empty( $options['label'] ) ) {
            $metadata['label'] = sanitize_text_field( $options['label'] );
        }
        if ( ! empty( $options['encrypt'] ) ) {
            $metadata['encrypted'] = true;
        }
        if ( ! empty( $options['cloud_destinations'] ) ) {
            $metadata['cloud_destinations'] = $options['cloud_destinations'];
        }
        
        // Store backup duration (always store duration, even if metadata is empty)
        $metadata['duration'] = $duration;
        
        // Always store S3 status (even for Free tier, for future compatibility)
        if ( ! empty( $s3_result ) && $s3_result['status'] !== 'none' ) {
            $metadata['s3_status'] = $s3_result['status'];
            if ( ! empty( $s3_result['object_key'] ) ) {
                $metadata['s3_object_key'] = $s3_result['object_key'];
            }
            if ( ! empty( $s3_result['error'] ) ) {
                $metadata['s3_error'] = $s3_result['error'];
            }
        }
        
        // Always store metadata (at minimum, duration should be stored)
        self::store_backup_metadata( basename( $archive_path ), $metadata );
        
        // Display admin notice if S3 upload failed (only in admin context)
        if ( ! empty( $s3_result ) && $s3_result['status'] === 'failed' && is_admin() ) {
            add_action( 'admin_notices', function() use ( $s3_result ) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    esc_html( sprintf( __( 'Local backup succeeded, but S3 upload failed: %s', 'museder-restoreone' ), $s3_result['error'] ) )
                );
            } );
        }

        $response = [
            'success' => true,
            'message' => __( 'Backup completed successfully.', 'museder-restoreone' ),
            'file'    => $archive_path,
            'url'     => backup_lite_get_download_url( $archive_path ),
            'size'    => $size,
            'duration' => $duration,
        ];

        self::record_backup_event( 'success', [
            'file'       => $archive_path,
            'size_bytes' => $size,
            'size_human' => size_format( $size, 2 ),
            'label'      => $options['label'] ?? '',
            'duration'   => $duration,
        ] );

        // PRO: Upload to cloud storage if specified
        if ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() && ! empty( $options['cloud_destinations'] ) && is_array( $options['cloud_destinations'] ) ) {
            foreach ( $options['cloud_destinations'] as $destination ) {
                if ( 'local' !== $destination ) {
                    Backup_Lite_Cloud_Storage::upload_backup( $archive_path, $destination );
                }
            }
        }

        return $response;
    }

    /**
     * Generate database dump to destination path.
     */
    private static function generate_database_dump( $filepath ) {
        $method = backup_lite_can_use_mysqldump() ? 'mysqldump' : 'php';

        backup_lite_log( 'info', 'Database export initiated.', [ 'method' => $method, 'path' => $filepath ] );

        $success = backup_lite_can_use_mysqldump()
            ? self::export_database_with_mysqldump( $filepath )
            : self::export_database_with_php( $filepath );

        if ( $success && file_exists( $filepath ) ) {
            backup_lite_log( 'info', 'Database export finished.', [ 'path' => $filepath, 'size' => filesize( $filepath ) ] );
            return true;
        }

        return false;
    }

    private static function write_meta_file( $path, $options = [] ) {
        $meta = [
            'plugin_version'    => defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : 'unknown',
            'wordpress_version' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'unknown',
            'generated_at'      => backup_lite_local_time( 'c' ),
            // @plugin-check: allowed - GMT time for internal logs and metadata
            'generated_at_gmt'  => gmdate( 'c' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- GMT time for internal metadata, not user-facing
            'site_url'          => function_exists( 'home_url' ) ? home_url() : '',
            'php_version'       => PHP_VERSION,
        ];

        // PRO: Add label and encryption info
        if ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() ) {
            if ( ! empty( $options['label'] ) ) {
                $meta['label'] = sanitize_text_field( $options['label'] );
            }
            if ( ! empty( $options['encrypt'] ) ) {
                $meta['encrypted'] = true;
            }
            if ( ! empty( $options['cloud_destinations'] ) ) {
                $meta['cloud_destinations'] = $options['cloud_destinations'];
            }
        }

        $encoded = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        return false !== file_put_contents( $path, $encoded );
    }

    private static function ensure_writable_directory( $dir ) {
        if ( ! file_exists( $dir ) ) {
            backup_lite_ensure_directory( $dir );
        }

        return is_dir( $dir ) && wp_is_writable( $dir );
    }

    private static function create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories ) {
        self::optimize_runtime_environment();

        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            backup_lite_log( 'error', 'Unable to create zip archive with ZipArchive.', [ 'path' => $archive_path ] );
            return false;
        }

        backup_lite_log( 'info', 'ZipArchive bundle phase started.', [
            'archive' => $archive_path,
            'roots'   => count( $directories ),
        ] );

        $zip->addFile( $sql_path, 'database.sql' );
        $zip->addFile( $meta_path, 'meta.json' );

        foreach ( $directories as $target => $source ) {
            backup_lite_log( 'info', 'Adding directory to archive.', [
                'source' => $source,
                'target' => $target,
            ] );
            self::add_directory_to_zip( $zip, $source, $target );
        }

        return $zip->close();
    }

    private static function create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories ) {
        self::optimize_runtime_environment();

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $manifest = self::build_pclzip_manifest( $sql_path, $meta_path, $directories );
        backup_lite_log( 'info', 'PclZip bundle phase started.', [
            'archive' => $archive_path,
            'items'   => count( $manifest ),
        ] );

        $archive = new PclZip( $archive_path );
        $result  = $archive->create( $manifest );

        if ( 0 === $result ) {
            backup_lite_log( 'error', 'PclZip failed while creating archive.', [ 'error' => $archive->errorInfo( true ) ] );
            return false;
        }

        return true;
    }

    private static function build_pclzip_manifest( $sql_path, $meta_path, $directories ) {
        $manifest = [
            [
                PCLZIP_ATT_FILE_NAME          => $sql_path,
                PCLZIP_ATT_FILE_NEW_FULL_NAME => 'database.sql',
            ],
            [
                PCLZIP_ATT_FILE_NAME          => $meta_path,
                PCLZIP_ATT_FILE_NEW_FULL_NAME => 'meta.json',
            ],
        ];

        foreach ( $directories as $target => $source ) {
            if ( ! is_dir( $source ) ) {
                continue;
            }

            $source = rtrim( $source, '/\\' );

            if ( self::should_skip_path( $source ) ) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                /** @var SplFileInfo $file */
                if ( $file->isDir() ) {
                    continue;
                }

                $file_path = $file->getRealPath();
                if ( ! $file_path || self::should_skip_path( $file_path ) ) {
                    continue;
                }

                $relative = ltrim( substr( $file_path, strlen( $source ) ), '/\\' );
                if ( '' === $relative ) {
                    continue;
                }

                $manifest[] = [
                    PCLZIP_ATT_FILE_NAME          => $file_path,
                    PCLZIP_ATT_FILE_NEW_FULL_NAME => $target . '/' . str_replace( '\\', '/', $relative ),
                ];
            }
        }

        return $manifest;
    }

    private static function add_directory_to_zip( ZipArchive $zip, $source, $target ) {
        if ( ! is_dir( $source ) ) {
            return;
        }

        $source = rtrim( $source, '/\\' );

        if ( self::should_skip_path( $source ) ) {
            return;
        }

        $zip->addEmptyDir( $target );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            /** @var SplFileInfo $file */
            $file_path = $file->getRealPath();
            if ( ! $file_path ) {
                continue;
            }

            if ( self::should_skip_path( $file_path ) ) {
                if ( $file->isDir() && method_exists( $iterator, 'skipChildren' ) ) {
                    $iterator->skipChildren();
                }
                continue;
            }

            $relative = ltrim( substr( $file_path, strlen( $source ) ), '/\\' );
            $entry    = $target . '/' . str_replace( '\\', '/', $relative );

            if ( $file->isDir() ) {
                $zip->addEmptyDir( $entry );
            } else {
                $zip->addFile( $file_path, $entry );
            }
        }
    }

    private static function get_directory_map() {
        $map = [
            'wp-content' => WP_CONTENT_DIR,
        ];

        $maybe = [
            'themes'  => WP_CONTENT_DIR . '/themes',
            'plugins' => WP_CONTENT_DIR . '/plugins',
            'uploads' => WP_CONTENT_DIR . '/uploads',
            'mu-plugins' => WP_CONTENT_DIR . '/mu-plugins',
            'languages' => WP_CONTENT_DIR . '/languages',
        ];

        foreach ( $maybe as $target => $path ) {
            if ( is_dir( $path ) ) {
                $map[ $target ] = $path;
            }
        }

        return $map;
    }

    /**
     * Prepare an asynchronous backup job blueprint.
     *
     * @param string $job_id  Job identifier.
     * @param array  $options Backup options.
     * @return array
     */
    public static function prepare_async_job( $job_id, $options = [] ) {
        self::optimize_runtime_environment();

        $backup_dir = trailingslashit( backup_lite_get_backup_dir() );

        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            throw new RuntimeException( esc_html__( 'Backup directory is not writable.', 'museder-restoreone' ) );
        }

        // Generate backup filename: site_url + YYYYMMDD + HHMM + random_code
        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_url ) ) {
            $site_url = 'site';
        }
        // Sanitize domain name for filename
        $site_url = sanitize_file_name( $site_url );
        
        $date_time = backup_lite_local_time( 'YmdHis' );
        $random_code = wp_generate_password( 6, false, false );
        
        $label_suffix = '';
        if ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() && ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }

        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;
        
        // Start backup timer
        $start_time = microtime( true );
        
        $temp_dir     = backup_lite_create_temp_dir( 'build' );
        $sql_path     = trailingslashit( $temp_dir ) . 'database.sql';
        $meta_path    = trailingslashit( $temp_dir ) . 'meta.json';

        if ( ! self::generate_database_dump( $sql_path ) ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Database export failed. Check logs for details.', 'museder-restoreone' ) );
        }

        if ( ! self::write_meta_file( $meta_path, $options ) ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Unable to write meta information for backup.', 'museder-restoreone' ) );
        }

        if ( file_exists( $archive_path ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $archive_path is from plugin-controlled backup directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $archive_path );
            } else {
                @unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled backup directory
            }
        }

        self::initialize_archive_with_meta( $archive_path, $sql_path, $meta_path );

        $directories    = self::get_directory_map();
        $manifest_data  = self::build_manifest_from_directories( $directories );
        $manifest_file  = trailingslashit( backup_lite_get_jobs_dir() ) . $job_id . '-manifest.json';
        $manifest_bytes = wp_json_encode( $manifest_data['files'], JSON_UNESCAPED_SLASHES );

        if ( false === $manifest_bytes ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Failed to encode backup manifest.', 'museder-restoreone' ) );
        }

        if ( false === file_put_contents( $manifest_file, $manifest_bytes, LOCK_EX ) ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Unable to write backup manifest.', 'museder-restoreone' ) );
        }

        backup_lite_log( 'info', 'Backup job prepared.', [
            'job'   => $job_id,
            'files' => $manifest_data['count'],
            'bytes' => $manifest_data['bytes'],
        ] );

        return [
            'archive_path'   => $archive_path,
            'temp_dir'       => $temp_dir,
            'manifest_file'  => $manifest_file,
            'manifest_count' => $manifest_data['count'],
            'manifest_bytes' => $manifest_data['bytes'],
            'options'        => $options,
            'start_time'     => $start_time, // Backup timer start
        ];
    }

    /**
     * Process a chunk of files for the asynchronous backup job.
     *
     * @param array $job        Job state.
     * @param int   $max_files  Maximum files per batch.
     * @param int   $max_bytes  Maximum bytes per batch.
     * @return array
     */
    public static function process_job_batch( array $job, $max_files = 200, $max_bytes = 52428800 ) {
        // Check if job has been cancelled before processing
        if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
            backup_lite_log( 'info', 'Backup job cancelled by user, stopping batch processing.', [
                'job_id' => $job['id'] ?? '',
            ] );
            return $job;
        }

        self::optimize_runtime_environment();

        $manifest = self::load_manifest_for_job( $job );
        $total    = isset( $job['total_files'] ) ? (int) $job['total_files'] : count( $manifest );
        $pointer  = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
        $pointer  = max( 0, min( $pointer, $total ) );

        if ( $total <= 0 || $pointer >= $total ) {
            // Check again before finalizing
            if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
                return $job;
            }
            return self::finalize_async_job( $job );
        }

        $batch = [];
        $bytes = 0;
        $index = $pointer;

        while ( $index < $total && count( $batch ) < $max_files && $bytes < $max_bytes ) {
            $entry = $manifest[ $index ] ?? null;
            if ( empty( $entry['path'] ) || empty( $entry['target'] ) ) {
                $index++;
                continue;
            }

            $path = wp_normalize_path( $entry['path'] );
            if ( ! file_exists( $path ) ) {
                backup_lite_log( 'warning', 'Skipped missing file during backup job.', [ 'path' => $path ] );
                $index++;
                continue;
            }

            $entry['path'] = $path;
            $batch[]       = $entry;
            $bytes        += isset( $entry['size'] ) ? (int) $entry['size'] : filesize( $path );
            $index++;
        }

        // Check if cancelled before processing batch
        if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
            return $job;
        }

        if ( ! empty( $batch ) ) {
            self::append_files_to_zip( $job['archive_path'], $batch );
        }

        // Check again after processing batch
        if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
            return $job;
        }

        $job['pointer']         = $index;
        $job['processed_files'] = min( $index, $total );

        $total_bytes = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
        $current     = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
        $job['processed_bytes'] = min(
            max( $total_bytes, 1 ),
            max( $current, $current + (int) $bytes )
        );
        
        // Only update status to running if not cancelled
        if ( ! isset( $job['status'] ) || Backup_Lite_Backup_Jobs::STATUS_CANCELLED !== $job['status'] ) {
            $job['status']  = Backup_Lite_Backup_Jobs::STATUS_RUNNING;
        $job['stage']   = 'packing';
        $job['message'] = __( 'Backup running…', 'museder-restoreone' );
        }

        if ( $job['pointer'] >= $total ) {
            // Check again before finalizing
            if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
                return $job;
            }
            return self::finalize_async_job( $job );
        }

        return $job;
    }

    /**
     * Initialise archive with database + meta files.
     */
    private static function initialize_archive_with_meta( $archive_path, $sql_path, $meta_path ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            throw new RuntimeException( esc_html__( 'Unable to initialize archive.', 'museder-restoreone' ) );
        }

        $zip->addFile( $sql_path, 'database.sql' );
        $zip->addFile( $meta_path, 'meta.json' );
        $zip->close();
    }

    /**
     * Build manifest entries for all directories that should be included.
     *
     * @param array $directories Directory map.
     * @return array
     */
    private static function build_manifest_from_directories( $directories ) {
        $manifest = [];
        $bytes    = 0;

        foreach ( $directories as $target => $source ) {
            if ( ! is_dir( $source ) ) {
                continue;
            }

            $source = rtrim( $source, '/\\' );
            if ( self::should_skip_path( $source ) ) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                /** @var SplFileInfo $file */
                if ( $file->isDir() ) {
                    continue;
                }

                $file_path = $file->getRealPath();
                if ( ! $file_path || self::should_skip_path( $file_path ) ) {
                    continue;
                }

                $relative = ltrim( substr( $file_path, strlen( $source ) ), '/\\' );
                if ( '' === $relative ) {
                    continue;
                }

                $size = $file->getSize();
                $manifest[] = [
                    'path'   => wp_normalize_path( $file_path ),
                    'target' => $target . '/' . str_replace( '\\', '/', $relative ),
                    'size'   => $size !== false ? (int) $size : 0,
                ];

                if ( $size && $size > 0 ) {
                    $bytes += (int) $size;
                }
            }
        }

        return [
            'files' => $manifest,
            'count' => count( $manifest ),
            'bytes' => $bytes,
        ];
    }

    /**
     * Load manifest file for a given job.
     *
     * @param array $job Job data.
     * @return array
     */
    private static function load_manifest_for_job( $job ) {
        static $cache = [];

        $path = isset( $job['manifest_file'] ) ? $job['manifest_file'] : '';
        if ( empty( $path ) || ! file_exists( $path ) ) {
            return [];
        }

        if ( isset( $cache[ $path ] ) ) {
            return $cache[ $path ];
        }

        $contents = file_get_contents( $path );
        $decoded  = json_decode( $contents, true );

        if ( ! is_array( $decoded ) ) {
            $decoded = [];
        }

        $cache[ $path ] = $decoded;
        return $decoded;
    }

    /**
     * Append files to an existing archive.
     *
     * @param string $archive_path Archive path.
     * @param array  $files        Files to add.
     */
    private static function append_files_to_zip( $archive_path, array $files ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path, ZipArchive::CREATE ) ) {
            throw new RuntimeException( esc_html__( 'Unable to append files to archive.', 'museder-restoreone' ) );
        }

        $created_dirs = [];

        foreach ( $files as $file ) {
            $path   = $file['path'];
            $target = ltrim( $file['target'], '/' );

            if ( ! file_exists( $path ) ) {
                continue;
            }

            $dir = trim( dirname( $target ), '.' );
            if ( $dir && empty( $created_dirs[ $dir ] ) ) {
                $zip->addEmptyDir( $dir );
                $created_dirs[ $dir ] = true;
            }

            $zip->addFile( $path, $target );
        }

        $zip->close();
    }

    /**
     * Finalize job success state.
     *
     * @param array $job Job state.
     * @return array
     */
    private static function finalize_async_job( array $job ) {
        // Check if job was cancelled before finalizing
        if ( isset( $job['status'] ) && Backup_Lite_Backup_Jobs::STATUS_CANCELLED === $job['status'] ) {
            backup_lite_log( 'info', 'Backup job cancelled by user, skipping finalization.', [
                'job_id' => $job['id'] ?? '',
            ] );
            return $job;
        }

        try {
            $job['status']          = Backup_Lite_Backup_Jobs::STATUS_COMPLETED;
            $job['stage']           = 'completed';
            $job['message']         = esc_html__( 'Backup completed successfully.', 'museder-restoreone' );
        $job['processed_files'] = isset( $job['total_files'] ) ? (int) $job['total_files'] : $job['processed_files'];
        $job['processed_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : $job['processed_bytes'];

        $size = file_exists( $job['archive_path'] ) ? filesize( $job['archive_path'] ) : 0;

        // Update download_url with the final archive path
        if ( ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
            $job['download_url'] = backup_lite_get_download_url( $job['archive_path'] );
        }

        // Calculate backup duration from started_at timestamp (two-phase timing)
        $duration = 0;
        if ( ! empty( $job['started_at'] ) ) {
            // Use started_at from job meta (when job was queued)
            $duration = max( 1, time() - (int) $job['started_at'] );
        } elseif ( ! empty( $job['start_time'] ) ) {
            // Fallback to microtime if started_at is not available
            $duration = microtime( true ) - (float) $job['start_time'];
            $duration = max( 1, (int) round( $duration ) );
        } else {
            // If no timestamp recorded, set to 1 second as fallback
            $duration = 1;
        }

        // Log backup completion with unified format
        backup_lite_log( 'info', 'Backup completed locally, preparing for S3 upload if enabled.', array(
            'file'     => $job['archive_path'],
            'size'     => $size,
            'duration' => $duration,
            'dest_s3'  => ! empty( $job['options']['dest_s3'] ) ? 'true' : 'false',
        ) );

        // S3 cloud storage: upload backup archive and record status
        $s3_result = array(
            'status' => 'none',
            'message' => '',
            'object_key' => '',
            'error' => '',
        );
        
        // Check if S3 upload is requested (use dest_s3, fallback to legacy formats)
        $dest_s3 = ! empty( $job['options']['dest_s3'] );
        if ( ! $dest_s3 && ! empty( $job['options']['destinations']['s3'] ) ) {
            $dest_s3 = (bool) $job['options']['destinations']['s3'];
        } elseif ( ! $dest_s3 && ! empty( $job['options']['upload_to_s3'] ) ) {
            // Legacy format support
            $dest_s3 = (bool) $job['options']['upload_to_s3'];
        }
        
        // S3 upload: use unified upload method
        if ( $dest_s3 && ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
            backup_lite_log(
                'info',
                'S3 upload requested for this backup. Starting unified upload handler.',
                array( 'file' => $job['archive_path'] )
            );
            
            try {
                // Use unified upload method (same as manual upload)
                $s3_upload_result = self::upload_backup_to_s3( $job['archive_path'] );
                
                if ( is_wp_error( $s3_upload_result ) ) {
                    // Upload failed - log error but don't fail the backup
                    $error_code = $s3_upload_result->get_error_code();
                    $error_message = $s3_upload_result->get_error_message();
                    
                    backup_lite_log( 'error', 'S3 upload failed during backup completion.', [
                        'file' => $job['archive_path'],
                        'error_code' => $error_code,
                        'error_message' => $error_message,
                    ] );
                    
                    // Store error status in metadata (use unified status: 'failed')
                    $s3_result = array(
                        'status' => 'failed',
                        'error' => $error_message, // Store sanitized error message
                    );
                } else {
                    // Upload succeeded (use unified status: 'stored')
                    $s3_result = array(
                        'status' => 'stored',
                    );
                    if ( is_array( $s3_upload_result ) && isset( $s3_upload_result['object_key'] ) ) {
                        $s3_result['object_key'] = $s3_upload_result['object_key'];
                    }
                    
                    backup_lite_log( 'info', 'S3 upload completed successfully during backup.', [
                        'file' => $job['archive_path'],
                        'object_key' => $s3_result['object_key'] ?? '',
                    ] );
                }
            } catch ( Throwable $e ) {
                // Catch any unhandled exceptions during S3 upload
                $error_message = class_exists( 'Backup_Lite_S3_Service' ) && method_exists( 'Backup_Lite_S3_Service', 'sanitize_s3_error_message' ) 
                    ? Backup_Lite_S3_Service::sanitize_s3_error_message( $e->getMessage() )
                    : $e->getMessage();
                
                backup_lite_log( 'error', 'S3 upload exception during backup completion.', [
                    'file' => $job['archive_path'],
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ] );
                
                // Store error status in metadata (use unified status: 'failed')
                $s3_result = array(
                    'status' => 'failed',
                    'error' => $error_message,
                );
            }
        } else {
            backup_lite_log(
                'info',
                'S3 upload skipped because dest_s3 option is not set.',
                array( 'file' => $job['archive_path'] ?? '' )
            );
        }
        
        // Legacy: Cloud Storage upload (for backward compatibility)
        $should_upload_cloud = ! empty( $job['options']['cloud_destination'] ) || ! empty( $job['options']['upload_to_cloud'] );
        if ( $should_upload_cloud && ! $should_upload_s3 && ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) && function_exists( 'museder_restoreone_is_cloud_configured' ) && museder_restoreone_is_cloud_configured() && class_exists( 'Museder_Cloud_Service' ) ) {
            $cloud_context = [
                'type'       => isset( $job['options']['schedule_id'] ) ? 'schedule' : 'manual',
                'site_url'   => home_url(),
                'created_at' => time(),
                'backup_id'  => basename( $job['archive_path'] ),
            ];
            
            $cloud_result = Museder_Cloud_Service::maybe_upload_backup( $job['archive_path'], $cloud_context, $should_upload_cloud );
            
            // Log cloud upload result
            if ( $cloud_result['status'] === 'success' ) {
                backup_lite_log( 'info', sprintf( 'Cloud: uploaded backup to S3 bucket %s - file: %s', $cloud_result['remote_path'] ?? 'cloud storage', basename( $job['archive_path'] ) ) );
            } elseif ( $cloud_result['status'] === 'error' ) {
                backup_lite_log( 'error', sprintf( 'Cloud: failed to upload backup to S3 (%s)', $cloud_result['message'] ?? 'Unknown error' ) );
            }
            // 'skipped' status is not logged
        }

        self::record_backup_event( 'success', [
            'file'       => $job['archive_path'],
            'size_bytes' => $size,
            'size_human' => size_format( $size, 2 ),
            'label'      => $job['options']['label'] ?? '',
            'duration'   => $duration,
        ] );

        // Store backup metadata (label, encrypted, duration, etc.)
        $job_metadata = array();
        if ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() && ! empty( $job['options']['label'] ) ) {
            $job_metadata['label'] = sanitize_text_field( $job['options']['label'] );
        }
        if ( ! empty( $job['options']['encrypt'] ) ) {
            $job_metadata['encrypted'] = true;
        }
        if ( ! empty( $job['options']['cloud_destinations'] ) ) {
            $job_metadata['cloud_destinations'] = $job['options']['cloud_destinations'];
        }
        // Store backup duration (always store duration, even if metadata is empty)
        $job_metadata['duration'] = $duration;
        
        // Store options snapshot in metadata for future reference
        if ( ! empty( $job['options'] ) ) {
            $options_snapshot = array(
                'encrypt' => ! empty( $job['options']['encrypt'] ),
                'dual'    => ! empty( $job['options']['create_dual_version'] ) || ! empty( $job['options']['dual_version'] ) || ! empty( $job['options']['dual'] ),
                'dest_s3' => ! empty( $job['options']['dest_s3'] ),
            );
            $job_metadata['options'] = $options_snapshot;
        }
        
        // Store S3 status in metadata if upload was attempted
        if ( isset( $s3_result ) && $s3_result['status'] !== 'none' ) {
            $job_metadata['s3_status'] = $s3_result['status'];
            if ( ! empty( $s3_result['object_key'] ) ) {
                $job_metadata['s3_object_key'] = $s3_result['object_key'];
            }
            if ( ! empty( $s3_result['error'] ) ) {
                $job_metadata['s3_error'] = $s3_result['error'];
            }
        }
        
        // Always store metadata (at minimum, duration should be stored)
        // Merge all metadata before storing to ensure duration and options are both saved
        self::store_backup_metadata( basename( $job['archive_path'] ), $job_metadata );
        
        // Store duration in job for response
        $job['duration'] = $duration;

        // Clean up job resources
        if ( class_exists( 'Backup_Lite_Backup_Jobs' ) ) {
            Backup_Lite_Backup_Jobs::cleanup_job( $job );
            Backup_Lite_Backup_Jobs::clear_active_job( $job['id'] );
        }

        // Clear the current job transient to allow new backups
        if ( class_exists( 'Backup_Lite_UI' ) ) {
            Backup_Lite_UI::clear_job_running();
        }

        return $job;
        } catch ( Throwable $e ) {
            // Log error but don't fail silently - return job with error status
            backup_lite_log( 'error', 'Error in finalize_async_job.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            
            // Still try to clean up
            if ( class_exists( 'Backup_Lite_Backup_Jobs' ) ) {
                try {
                    Backup_Lite_Backup_Jobs::cleanup_job( $job );
                    Backup_Lite_Backup_Jobs::clear_active_job( $job['id'] ?? '' );
                } catch ( Throwable $cleanup_error ) {
                    backup_lite_log( 'error', 'Error during job cleanup.', [
                        'message' => $cleanup_error->getMessage(),
                    ] );
                }
            }
            
            if ( class_exists( 'Backup_Lite_UI' ) ) {
                try {
                    Backup_Lite_UI::clear_job_running();
                } catch ( Throwable $clear_error ) {
                    backup_lite_log( 'error', 'Error clearing job running state.', [
                        'message' => $clear_error->getMessage(),
                    ] );
                }
            }
            
            // Only mark as failed if not cancelled
            if ( ! isset( $job['status'] ) || Backup_Lite_Backup_Jobs::STATUS_CANCELLED !== $job['status'] ) {
                $job['status'] = Backup_Lite_Backup_Jobs::STATUS_FAILED;
                $job['message'] = __( 'Backup completed but encountered an error during finalization. Please check logs.', 'museder-restoreone' );
            }
            return $job;
        }
    }

    /**
     * Unified S3 upload method used by both automatic and manual uploads.
     * 
     * @param string $file_path Absolute path to backup archive file.
     * @return array|WP_Error On success, returns array with 'status' => 'success' and 'object_key'. On failure, returns WP_Error.
     */
    public static function upload_backup_to_s3( $file_path ) {
        $file_path = wp_normalize_path( $file_path );
        
        // Validate file exists and is readable
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'Backup file not found.', 'museder-restoreone' ) );
        }
        
        if ( ! is_readable( $file_path ) ) {
            return new WP_Error( 'file_not_readable', __( 'Backup file is not readable.', 'museder-restoreone' ) );
        }
        
        // Check if S3 service is available
        if ( ! class_exists( 'Backup_Lite_S3_Service' ) ) {
            return new WP_Error( 's3_service_unavailable', __( 'S3 service is not available.', 'museder-restoreone' ) );
        }
        
        // Check S3 configuration
        $s3_settings = backup_lite_get_s3_settings();
        if ( empty( $s3_settings['enabled'] ) || empty( $s3_settings['bucket'] ) ) {
            return new WP_Error( 's3_not_configured', __( 'S3 is not configured. Please configure S3 settings first.', 'museder-restoreone' ) );
        }
        
        // Use new Backup_Lite_S3_Uploader class (supports both single-part and multipart upload)
        // This provides better memory management for large files
        if ( class_exists( 'Backup_Lite_S3_Uploader' ) ) {
            try {
                $uploader = Backup_Lite_S3_Uploader::get_instance();
                $s3_result = $uploader->upload_backup_file( $file_path );
            } catch ( Throwable $e ) {
                // Catch any exceptions from upload_backup_file() itself
                backup_lite_log( 'error', 'S3 upload_backup_file() threw exception.', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ] );
                error_log( '[Backup Lite] S3 upload_backup_file() fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
                return new WP_Error( 's3_upload_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
            }
            
            // Handle result: Backup_Lite_S3_Uploader::upload_backup_file() returns:
            // - array with 'status' => 'success' and 'object_key' on success
            // - WP_Error on failure
            if ( is_wp_error( $s3_result ) ) {
                return $s3_result; // Return WP_Error directly
            } elseif ( is_array( $s3_result ) && ( isset( $s3_result['status'] ) && 'success' === $s3_result['status'] ) || ( isset( $s3_result['success'] ) && $s3_result['success'] ) ) {
                return $s3_result; // Return success array with object_key
            } else {
                // Unexpected result type - convert to WP_Error
                return new WP_Error( 's3_unexpected_result', __( 'S3 upload returned unexpected result.', 'museder-restoreone' ) );
            }
        }
        
        // Fallback to old method if Backup_Lite_S3_Uploader is not available
        // Call S3 service upload with error handling
        try {
            $s3_result = Backup_Lite_S3_Service::upload_backup( $file_path );
        } catch ( Throwable $e ) {
            // Catch any exceptions from upload_backup() itself
            backup_lite_log( 'error', 'S3 upload_backup() threw exception.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] );
            error_log( '[Backup Lite] S3 upload_backup() fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return new WP_Error( 's3_upload_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
        }
        
        // Handle result: Backup_Lite_S3_Service::upload_backup() returns:
        // - array with 'status' => 'success' and 'object_key' on success
        // - WP_Error on failure
        if ( is_wp_error( $s3_result ) ) {
            return $s3_result; // Return WP_Error directly
        } elseif ( is_array( $s3_result ) && ( ( isset( $s3_result['status'] ) && 'success' === $s3_result['status'] ) || ( isset( $s3_result['success'] ) && $s3_result['success'] ) ) ) {
            return $s3_result; // Return success array with object_key
        } else {
            // Unexpected result type - convert to WP_Error
            $error_code = is_string( $s3_result ) ? $s3_result : 'unknown_error';
            $error_message = self::get_s3_error_message( $error_code );
            return new WP_Error( $error_code, $error_message );
        }
    }

    /**
     * Get user-friendly error message for S3 error code.
     * 
     * @param string $error_code S3 error code.
     * @return string Error message.
     */
    private static function get_s3_error_message( $error_code ) {
        $messages = array(
            's3_disabled' => __( 'S3 is disabled in settings.', 'museder-restoreone' ),
            'missing_setting_access_key_id' => __( 'S3 access key ID is missing.', 'museder-restoreone' ),
            'missing_setting_secret_access_key' => __( 'S3 secret access key is missing.', 'museder-restoreone' ),
            'missing_setting_region' => __( 'S3 region is missing.', 'museder-restoreone' ),
            'missing_setting_bucket' => __( 'S3 bucket name is missing.', 'museder-restoreone' ),
            'file_not_found' => __( 'Backup file not found.', 'museder-restoreone' ),
            'file_not_readable' => __( 'Backup file is not readable.', 'museder-restoreone' ),
            'file_size_invalid' => __( 'Backup file size is invalid.', 'museder-restoreone' ),
            'read_file_error' => __( 'Could not read backup file for S3 upload.', 'museder-restoreone' ),
            'network_error' => __( 'Network error during S3 upload.', 'museder-restoreone' ),
            'http_error_403_forbidden' => __( 'S3 access denied. Please check your credentials and bucket permissions.', 'museder-restoreone' ),
            'http_error_404_not_found' => __( 'S3 bucket not found.', 'museder-restoreone' ),
            'http_error_500_server_error' => __( 'S3 server error. Please try again later.', 'museder-restoreone' ),
            'http_error_503_server_error' => __( 'S3 service unavailable. Please try again later.', 'museder-restoreone' ),
        );
        
        return isset( $messages[ $error_code ] ) ? $messages[ $error_code ] : sprintf( __( 'S3 upload failed: %s', 'museder-restoreone' ), $error_code );
    }

    private static function export_database_with_mysqldump( $filepath ) {
        $command = sprintf(
            'mysqldump --no-tablespaces -u%s -p%s %s > %s',
            escapeshellarg( DB_USER ),
            escapeshellarg( DB_PASSWORD ),
            escapeshellarg( DB_NAME ),
            escapeshellarg( $filepath )
        );

        $output  = '';
        $success = self::run_shell_command( $command, $output );

        if ( ! $success ) {
            backup_lite_log( 'error', 'mysqldump command failed.', [ 'output' => $output ] );
        }

        return $success;
    }

    private static function export_database_with_php( $filepath ) {
        global $wpdb;

        $handle = fopen( $filepath, 'w' );
        if ( ! $handle ) {
            backup_lite_log( 'error', 'Unable to open SQL file for writing.', [ 'path' => $filepath ] );
            return false;
        }

        $wpdb->hide_errors();
        // @plugin-check: okay - needed for long running backup/restore operations
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        fwrite( $handle, "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n" );
        fwrite( $handle, "SET time_zone = '+00:00';\n\n" );

        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            fclose( $handle );
            backup_lite_log( 'warning', 'No database tables found for export.' );
            return true;
        }

        foreach ( $tables as $table ) {
            // @plugin-check: safe table name from whitelist
            // $table comes from SHOW TABLES result (system query, not user input)
            // Sanitize table name to ensure only safe characters
            $safe_table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
            if ( empty( $safe_table ) ) {
                continue;
            }

            fwrite( $handle, sprintf( "-- Table structure for table `%s`\n\n", $safe_table ) );

            // @plugin-check: allowed - schema introspection for backup, table name from whitelist only
            // Cannot use prepare() because SHOW CREATE TABLE doesn't support placeholders
            $create = $wpdb->get_row( $wpdb->prepare( "SHOW CREATE TABLE `%s`", $safe_table ), ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: table name sanitized from SHOW TABLES result
            if ( isset( $create[1] ) ) {
                fwrite( $handle, "DROP TABLE IF EXISTS `{$safe_table}`;\n" );
                fwrite( $handle, $create[1] . ";\n\n" );
            }

            // @plugin-check: safe table name from whitelist
            $row_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `%s`", $safe_table ) );
            if ( $row_count === 0 ) {
                fwrite( $handle, "\n" );
                continue;
            }

            fwrite( $handle, sprintf( "-- Dumping data for table `%s`\n", $safe_table ) );

            $offset = 0;
            while ( $offset < $row_count ) {
                // @plugin-check: safe table name from whitelist
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `%s` LIMIT %d OFFSET %d",
                    $safe_table,
                    self::CHUNK_SIZE,
                    $offset
                ), ARRAY_A );

                if ( empty( $rows ) ) {
                    break;
                }

                $values = [];
                foreach ( $rows as $row ) {
                    $escaped = array_map( [ __CLASS__, 'escape_value' ], $row );
                    $values[] = '(' . implode( ',', $escaped ) . ')';
                }

                if ( ! empty( $values ) ) {
                    $columns = array_map( [ __CLASS__, 'escape_identifier' ], array_keys( $rows[0] ) );

                    // @plugin-check: safe table name from whitelist
                    $sql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES\n%s;\n",
                        $safe_table,
                        implode( ',', $columns ),
                        implode( ",\n", $values )
                    );

                    fwrite( $handle, $sql );
                }

                $offset += self::CHUNK_SIZE;
            }

            fwrite( $handle, "\n" );
        }

        fclose( $handle );

        return true;
    }

    public static function pclzip_filter_exclusions( $event, &$file ) {
        if ( 'check' === $event && self::should_skip_path( $file['filename'] ) ) {
            return 0;
        }
        return 1;
    }

    private static function should_skip_path( $path ) {
        $normalized = wp_normalize_path( $path );

        foreach ( self::get_internal_exclusions() as $excluded ) {
            if ( '' !== $excluded && 0 === strpos( $normalized, $excluded ) ) {
                return true;
            }
        }

        // Also exclude any museder-restoreone-* directories in uploads (handles versioned directories)
        if ( strpos( $normalized, '/uploads/museder-restoreone' ) !== false ) {
            return true;
        }

        // Exclude backup files (.zip) in uploads directory
        if ( strpos( $normalized, '/uploads/' ) !== false && preg_match( '/\.(zip|wpress)$/i', $normalized ) ) {
            // Only exclude if it's in a backup-related directory
            if ( strpos( $normalized, '/backups/' ) !== false || strpos( $normalized, '/museder-restoreone' ) !== false ) {
                return true;
            }
        }

        $basename = basename( $normalized );
        $skip     = [ '.DS_Store', 'desktop.ini', 'Thumbs.db' ];

        return in_array( $basename, $skip, true );
    }

    /**
     * Build the list of internal paths that must never be included in backups.
     *
     * @return array<string>
     */
    private static function get_internal_exclusions() {
        if ( null !== self::$internal_exclusions ) {
            return self::$internal_exclusions;
        }

        $paths = [];

        $normalize = static function( $path, $must_exist = false ) {
            if ( empty( $path ) ) {
                return '';
            }

            $normalized = wp_normalize_path( rtrim( $path, '/\\' ) );
            if ( '' === $normalized ) {
                return '';
            }

            if ( $must_exist && ! file_exists( $normalized ) ) {
                return '';
            }

            return trailingslashit( $normalized );
        };

        $storage = backup_lite_get_storage_root();

        if ( ! empty( $storage['path'] ) ) {
            $root = trailingslashit( $storage['path'] );
            $paths[] = $normalize( $root );
            $paths[] = $normalize( $root . 'backups' );
            $paths[] = $normalize( $root . 'logs' );
            $paths[] = $normalize( $root . 'jobs' );
            $paths[] = $normalize( $root . 'temp' );
            $paths[] = $normalize( $root . 'reports' );
            $paths[] = $normalize( $root . 'pro' );
            $paths[] = $normalize( $root . 'pro/jobs' );
            $paths[] = $normalize( $root . 'pro/reports' );
        }

        // Always exclude the active backup directory (even if customized) and its parent root.
        $active_backup_dir = backup_lite_get_backup_dir();
        $paths[] = $normalize( $active_backup_dir );
        $paths[] = $normalize( trailingslashit( dirname( $active_backup_dir ) ) );
        $paths[] = $normalize( backup_lite_get_temp_dir() );
        $paths[] = $normalize( backup_lite_get_jobs_dir() );
        $paths[] = $normalize( backup_lite_get_reports_dir() );

        // Legacy directories (only exclude when they exist to avoid false positives).
        $legacy = [
            WP_CONTENT_DIR . '/uploads/backup-lite',
            WP_CONTENT_DIR . '/uploads/backup-lite/backups',
            WP_CONTENT_DIR . '/uploads/backup-lite/temp',
            WP_CONTENT_DIR . '/uploads/backup-lite/jobs',
            WP_CONTENT_DIR . '/uploads/backup-lite/pro',
            WP_CONTENT_DIR . '/uploads/backup-lite/pro/jobs',
            WP_CONTENT_DIR . '/uploads/backup-lite/pro/reports',
            WP_CONTENT_DIR . '/uploads/backup-lite-logs',
        ];

        foreach ( $legacy as $legacy_path ) {
            $normalized = $normalize( $legacy_path, true );
            if ( $normalized ) {
                $paths[] = $normalized;
            }
        }

        // Exclude all museder-restoreone-* directories in uploads (handles versioned plugin directories)
        $uploads_dir = WP_CONTENT_DIR . '/uploads';
        if ( is_dir( $uploads_dir ) && is_readable( $uploads_dir ) ) {
            try {
                $iterator = new DirectoryIterator( $uploads_dir );
                foreach ( $iterator as $file ) {
                    if ( $file->isDir() && ! $file->isDot() ) {
                        $dir_name = $file->getFilename();
                        // Match museder-restoreone, museder-restoreone-1, museder-restoreone-2, etc.
                        if ( preg_match( '/^museder-restoreone(-\d+)?$/', $dir_name ) ) {
                            $normalized_path = $normalize( $file->getPathname() );
                            if ( $normalized_path ) {
                                $paths[] = $normalized_path;
                            }
                        }
                    }
                }
            } catch ( Exception $e ) {
                // Silently continue if directory iteration fails
                backup_lite_log( 'warning', 'Failed to scan uploads directory for exclusions.', [ 'error' => $e->getMessage() ] );
            }
        }

        // Deduplicate while preserving order.
        $paths = array_values( array_unique( array_filter( $paths ) ) );

        self::$internal_exclusions = $paths;

        return self::$internal_exclusions;
    }

    /**
     * Ensure PHP has enough resources (time + memory) to process large sites.
     */
    private static function optimize_runtime_environment() {
        static $optimized = false;

        if ( $optimized ) {
            return;
        }

        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            // @plugin-check: okay - needed for long running backup/restore operations
            @set_time_limit( 0 );
        }

        self::maybe_raise_memory_limit();
        $optimized = true;
    }

    /**
     * Attempt to raise the PHP memory limit for heavy backup jobs.
     */
    private static function maybe_raise_memory_limit() {
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            @wp_raise_memory_limit( 'admin' );
            @wp_raise_memory_limit( 'media' );
        }

        if ( function_exists( 'ini_get' ) && function_exists( 'ini_set' ) ) {
            $current = ini_get( 'memory_limit' );
            $current_bytes = self::memory_limit_to_bytes( $current );
            $target_bytes  = self::memory_limit_to_bytes( '1024M' );

            if ( $target_bytes > 0 && ( $current_bytes <= 0 || $current_bytes < $target_bytes ) ) {
                // @plugin-check: safe - increase memory limit for large backup operations
                // This is necessary to handle large file archives and database exports
                @ini_set( 'memory_limit', '1024M' );
            }
        }
    }

    /**
     * Convert shorthand memory strings (128M, 1G, etc.) to bytes.
     *
     * @param string|int $value Memory value.
     * @return int Bytes or -1 for unlimited.
     */
    private static function memory_limit_to_bytes( $value ) {
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        $value = trim( (string) $value );

        if ( '' === $value || '-1' === $value ) {
            return -1;
        }

        $unit  = strtolower( substr( $value, -1 ) );
        $bytes = (int) $value;

        switch ( $unit ) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }

    public static function run_shell_command( $command, &$output = null ) {
        if ( ! backup_lite_is_shell_available() ) {
            return false;
        }

        $output_lines = [];
        $exit_code    = null;

        if ( function_exists( 'exec' ) ) {
            @exec( $command . ' 2>&1', $output_lines, $exit_code );
            $output = implode( "\n", $output_lines );
        } elseif ( function_exists( 'system' ) ) {
            ob_start();
            @system( $command . ' 2>&1', $exit_code );
            $output = ob_get_clean();
        } elseif ( function_exists( 'shell_exec' ) ) {
            $output = shell_exec( $command . ' 2>&1' );
            if ( null === $output ) {
                return false;
            }
            $exit_code = null;
        } else {
            return false;
        }

        if ( null !== $exit_code && 0 !== (int) $exit_code ) {
            return false;
        }

        return true;
    }

    private static function get_tables() {
        global $wpdb;
        // @plugin-check: allowed - schema introspection for backup, system query not user input
        $tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- system query for backup, caching not applicable
        return is_array( $tables ) ? $tables : [];
    }

    private static function escape_value( $value ) {
        if ( is_null( $value ) ) {
            return 'NULL';
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_numeric( $value ) ) {
            return (string) $value;
        }

        $value = is_string( $value ) ? $value : maybe_serialize( $value );
        return "'" . addslashes( $value ) . "'";
    }

    private static function escape_identifier( $value ) {
        return '`' . str_replace( '`', '``', $value ) . '`';
    }

    private static function record_backup_event( $status, array $context = [] ) {
        $context['status'] = $status;
        $context['time']   = current_time( 'mysql' );
        Backup_Lite_Log_Handler::record_event( 'backup_result', $context, 'success' === $status ? 'info' : 'error' );
    }

    /**
     * Store backup metadata (labels, S3 status, etc.).
     * 
     * @param string $filename Backup filename.
     * @param array  $metadata Metadata to store (will be merged with existing).
     * @return bool
     */
    public static function store_backup_metadata( $filename, $metadata ) {
        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        $all_meta = [];

        if ( file_exists( $meta_file ) ) {
            $content = file_get_contents( $meta_file );
            $all_meta = json_decode( $content, true ) ?: [];
        }

        // Merge with existing metadata for this backup
        $existing = $all_meta[ $filename ] ?? array();
        $all_meta[ $filename ] = array_merge( $existing, $metadata );

        return false !== file_put_contents( $meta_file, wp_json_encode( $all_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
    }

    /**
     * Get backup metadata.
     * 
     * @param string $filename Backup filename.
     * @return array
     */
    public static function get_backup_metadata( $filename ) {
        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return [];
        }

        $content = file_get_contents( $meta_file );
        $all_meta = json_decode( $content, true ) ?: [];

        $metadata = $all_meta[ $filename ] ?? [];
        
        // Set default s3_status for older backups that don't have this field
        if ( ! isset( $metadata['s3_status'] ) ) {
            $metadata['s3_status'] = 'none';
        }
        
        return $metadata;
    }
}
