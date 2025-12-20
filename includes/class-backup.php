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
     * Runtime exclusions (job-scoped): absolute directory prefixes (normalized, with trailing slash).
     *
     * @var array<string>
     */
    private static $runtime_exclude_prefixes = [];

    /**
     * Runtime exclusions (job-scoped): substring patterns to match against normalized paths.
     *
     * @var array<string>
     */
    private static $runtime_exclude_patterns = [];

    /**
     * Runtime exclusions (job-scoped): basenames (directories/files) to skip.
     *
     * @var array<string>
     */
    private static $runtime_exclude_basenames = [];

    /**
     * Runtime backup mode (job-scoped): balanced|fast.
     *
     * @var string
     */
    private static $runtime_backup_mode = 'balanced';

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
        $backup_dir = trailingslashit( backup_lite_get_backup_dir() );

        self::optimize_runtime_environment();

        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            $log = backup_lite_log( 'error', 'Backup directory is not writable.', [ 'dir' => $backup_dir ] );
            self::record_backup_event( 'failed', [
                'message' => __( 'Backup directory is not writable.', 'museder-restoreone' ),
            ] );
            return [
                'success' => false,
                'message' => __( 'Backup directory is not writable.', 'museder-restoreone' ),
                'log'     => $log,
            ];
        }

        // Generate backup filename: 网址+西元年月日+时分+乱数编码
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
        if ( ! empty( $options['label'] ) && Backup_Lite_Pro::is_pro_active() ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }
        
        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;

        $temp_dir = backup_lite_create_temp_dir( 'build' );
        $sql_path = trailingslashit( $temp_dir ) . 'database.sql';
        $meta_path = trailingslashit( $temp_dir ) . 'meta.json';

        // Record backup start time (UTC timestamp)
        $backup_started_at = time();

        $log = backup_lite_log( 'info', 'Site backup started.', [
            'archive' => $archive_path,
            'method'  => backup_lite_can_use_ziparchive() ? 'ZipArchive' : 'PclZip',
        ] );

        if ( ! self::generate_database_dump( $sql_path ) ) {
            backup_lite_log( 'error', 'Failed to generate database dump.', [ 'path' => $sql_path ] );
            backup_lite_delete_directory( $temp_dir );

            self::record_backup_event( 'failed', [
                'message' => __( 'Database export failed. Check logs for details.', 'museder-restoreone' ),
            ] );

            return [
                'success' => false,
                'message' => __( 'Database export failed. Check logs for details.', 'museder-restoreone' ),
                'log'     => $log,
            ];
        }

        if ( ! self::write_meta_file( $meta_path, $options ) ) {
            backup_lite_log( 'error', 'Failed to write meta.json file.', [ 'path' => $meta_path ] );
            backup_lite_delete_directory( $temp_dir );

            self::record_backup_event( 'failed', [
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
            ] );

            return [
                'success' => false,
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
                'log'     => $log,
            ];
        }

        if ( file_exists( $archive_path ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $archive_path is from plugin-controlled backup directory
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $archive_path );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- required for cleanup, path from plugin-controlled backup directory
                @unlink( $archive_path );
            }
        }

        $directories = self::get_directory_map();

        $success = self::with_runtime_exclusions(
            $options,
            function () use ( $archive_path, $sql_path, $meta_path, $directories ) {
                return backup_lite_can_use_ziparchive()
            ? self::create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories )
            : self::create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories );
            }
        );

        backup_lite_delete_directory( $temp_dir );

        if ( ! $success || ! file_exists( $archive_path ) ) {
            backup_lite_log( 'error', 'Site backup failed.', [ 'archive' => $archive_path ] );

            self::record_backup_event( 'failed', [
                'message' => __( 'Backup failed. See logs for more information.', 'museder-restoreone' ),
                'file'    => $archive_path,
            ] );

            return [
                'success' => false,
                'message' => __( 'Backup failed. See logs for more information.', 'museder-restoreone' ),
                'log'     => $log,
            ];
        }

        $size = filesize( $archive_path );
        
        // Record backup completion time and calculate duration
        $backup_completed_at = time();
        $backup_duration_seconds = isset( $backup_started_at ) ? ( $backup_completed_at - $backup_started_at ) : 0;

        backup_lite_log( 'info', 'Site backup completed.', [
            'archive' => $archive_path,
            'size'    => $size,
            'duration_seconds' => $backup_duration_seconds,
        ] );

        // Store backup metadata (for labels, etc.)
        $backup_metadata = [
            'duration_seconds' => $backup_duration_seconds,
            'started_at' => isset( $backup_started_at ) ? $backup_started_at : null,
            'completed_at' => $backup_completed_at,
        ];
        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $options['label'] ) ) {
            $backup_metadata['label'] = sanitize_text_field( $options['label'] );
            $backup_metadata['encrypted'] = ! empty( $options['encrypt'] );
            $backup_metadata['cloud_destinations'] = $options['cloud_destinations'] ?? [];
        }
        self::store_backup_metadata( basename( $archive_path ), $backup_metadata );

        $response = [
            'success' => true,
            'message' => __( 'Backup completed successfully.', 'museder-restoreone' ),
            'file'    => $archive_path,
            'url'     => backup_lite_get_download_url( $archive_path ),
            'size'    => $size,
        ];

        self::record_backup_event( 'success', [
            'file'       => $archive_path,
            'size_bytes' => $size,
            'size_human' => size_format( $size, 2 ),
            'label'     => $options['label'] ?? '',
            'started_at' => isset( $backup_started_at ) ? $backup_started_at : null,
            'completed_at' => $backup_completed_at,
            'duration_seconds' => $backup_duration_seconds,
        ] );

        // PRO: Upload to cloud storage if specified
        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $options['cloud_destinations'] ) && is_array( $options['cloud_destinations'] ) ) {
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
        if ( Backup_Lite_Pro::is_pro_active() ) {
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

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
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
        // Keep DB/meta compressed even in Fast mode (single files; low overhead; big size win).
        $zip->setCompressionName( 'database.sql', ZipArchive::CM_DEFLATE );
        $zip->setCompressionName( 'meta.json', ZipArchive::CM_DEFLATE );

        foreach ( $directories as $target => $source ) {
            backup_lite_log( 'info', 'Adding directory to archive.', [
                'source' => $source,
                'target' => $target,
            ] );
            self::add_directory_to_zip( $zip, $source, $target, $directories );
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
            $normalized_source = wp_normalize_path( $source );
            $normalized_source = rtrim( $normalized_source, '/' );

            if ( self::should_skip_path( $source ) ) {
                continue;
            }

            // Use optimized iterator flags for better performance
            // CATCH_GET_CHILD requires PHP 5.6.0+, use version check for compatibility
            $iterator_flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
            if ( defined( 'FilesystemIterator::CATCH_GET_CHILD' ) ) {
                $iterator_flags |= FilesystemIterator::CATCH_GET_CHILD;
            }
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, $iterator_flags ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                /** @var SplFileInfo $file */
                if ( $file->isDir() ) {
                    continue;
                }

                // Use pathname (not realpath) to preserve symlinked paths on hosts with open_basedir constraints.
                // getRealPath() may resolve to an inaccessible physical path, causing later file_exists() to fail.
                $file_path = $file->getPathname();
                if ( empty( $file_path ) ) {
                    continue;
                }
                $file_path = wp_normalize_path( $file_path );

                // Safety: ensure the file stays within the scanned root.
                if ( 0 !== strpos( $file_path, trailingslashit( $normalized_source ) ) ) {
                    continue;
                }

                if ( self::should_skip_path( $file_path ) ) {
                    continue;
                }

                // Skip extremely large files (>2GB) to prevent issues
                $size = $file->getSize();
                $max_file_size = 2147483648; // 2GB
                if ( $size !== false && $size > $max_file_size ) {
                    backup_lite_log( 'warning', 'Skipping extremely large file in PclZip manifest.', [
                        'path' => $file_path,
                        'size' => $size,
                        'size_mb' => round( $size / 1048576, 2 ),
                    ] );
                    continue;
                }

                $relative = ltrim( substr( $file_path, strlen( $normalized_source ) ), '/' );
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

    private static function add_directory_to_zip( ZipArchive $zip, $source, $target, $directory_map = null ) {
        if ( ! is_dir( $source ) ) {
            return;
        }

        $source = rtrim( $source, '/\\' );
        $normalized_source = wp_normalize_path( $source );
        $normalized_source = rtrim( $normalized_source, '/' );

        if ( self::should_skip_path( $source ) ) {
            return;
        }

        $zip->addEmptyDir( $target );

        // Use optimized iterator flags for better performance
        // CATCH_GET_CHILD requires PHP 5.6.0+, use version check for compatibility
        $iterator_flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
        if ( defined( 'FilesystemIterator::CATCH_GET_CHILD' ) ) {
            $iterator_flags |= FilesystemIterator::CATCH_GET_CHILD;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, $iterator_flags ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $wp_content_skip_prefixes = [];
        if ( 'wp-content' === $target ) {
            // Prevent duplicate inclusion ONLY when these directories are actually included elsewhere.
            // Some hosts may not expose themes/plugins/uploads as separate roots, so skipping unconditionally
            // can lead to missing uploads in backups.
            $candidates = [
                'themes'     => WP_CONTENT_DIR . '/themes',
                'plugins'    => WP_CONTENT_DIR . '/plugins',
                'uploads'    => WP_CONTENT_DIR . '/uploads',
                'mu-plugins' => WP_CONTENT_DIR . '/mu-plugins',
                'languages'  => WP_CONTENT_DIR . '/languages',
            ];

            foreach ( $candidates as $key => $default_path ) {
                if ( is_array( $directory_map ) && isset( $directory_map[ $key ] ) && is_string( $directory_map[ $key ] ) && '' !== $directory_map[ $key ] ) {
                    $wp_content_skip_prefixes[] = wp_normalize_path( trailingslashit( $directory_map[ $key ] ) );
                }
            }
        }

        foreach ( $iterator as $file ) {
            /** @var SplFileInfo $file */
            // Use pathname (not realpath) to preserve symlinked paths on hosts with open_basedir constraints.
            $file_path = $file->getPathname();
            if ( empty( $file_path ) ) {
                continue;
            }
            $file_path = wp_normalize_path( $file_path );

            // Safety: ensure the path stays within the scanned root.
            if ( 0 !== strpos( $file_path, trailingslashit( $normalized_source ) ) ) {
                if ( $file->isDir() && method_exists( $iterator, 'skipChildren' ) ) {
                    $iterator->skipChildren();
                }
                continue;
            }

            if ( ! empty( $wp_content_skip_prefixes ) ) {
                foreach ( $wp_content_skip_prefixes as $skip_prefix ) {
                    if ( '' !== $skip_prefix && 0 === strpos( $file_path, $skip_prefix ) ) {
                        if ( $file->isDir() && method_exists( $iterator, 'skipChildren' ) ) {
                            $iterator->skipChildren();
                        }
                        continue 2;
                    }
                }
            }

            if ( self::should_skip_path( $file_path ) ) {
                if ( $file->isDir() && method_exists( $iterator, 'skipChildren' ) ) {
                    $iterator->skipChildren();
                }
                continue;
            }

            $relative = ltrim( substr( $file_path, strlen( $normalized_source ) ), '/' );
            $entry    = $target . '/' . $relative;

            if ( $file->isDir() ) {
                $zip->addEmptyDir( $entry );
            } else {
                // Skip extremely large files (>2GB) to prevent issues
                $size = $file->getSize();
                $max_file_size = 2147483648; // 2GB
                if ( $size !== false && $size > $max_file_size ) {
                    backup_lite_log( 'warning', 'Skipping extremely large file in ZipArchive.', [
                        'path' => $file_path,
                        'size' => $size,
                        'size_mb' => round( $size / 1048576, 2 ),
                    ] );
                    continue;
                }
                
                $zip->addFile( $file_path, $entry );
                
                // Compression strategy:
                // - Fast mode: store everything to reduce CPU on shared hosting (tons of small files).
                // - Balanced: store only large files (>10MB), deflate smaller files.
                if ( 'fast' === self::$runtime_backup_mode ) {
                    $zip->setCompressionName( $entry, ZipArchive::CM_STORE );
                } elseif ( $size !== false && $size > 10485760 ) { // > 10MB
                    $zip->setCompressionName( $entry, ZipArchive::CM_STORE );
                } else {
                    $zip->setCompressionName( $entry, ZipArchive::CM_DEFLATE );
                }
            }
        }
    }

    /**
     * Get directory map with optimized priority order.
     * Important directories (themes, plugins, uploads) are processed first.
     *
     * @return array<string, string> Map of target => source directory paths.
     */
    private static function get_directory_map() {
        // Priority order: most important directories first
        // This allows important content to be backed up first, improving perceived performance
        $priority_dirs = [
            'themes'     => WP_CONTENT_DIR . '/themes',
            'plugins'    => WP_CONTENT_DIR . '/plugins',
            'uploads'    => WP_CONTENT_DIR . '/uploads',
        ];

        $other_dirs = [
            'mu-plugins' => WP_CONTENT_DIR . '/mu-plugins',
            'languages'  => WP_CONTENT_DIR . '/languages',
        ];

        $map = [];

        // Add priority directories first
        foreach ( $priority_dirs as $target => $path ) {
            if ( is_dir( $path ) ) {
                $map[ $target ] = $path;
            }
        }

        // Add wp-content root if it exists and has content
        if ( is_dir( WP_CONTENT_DIR ) ) {
            $map['wp-content'] = WP_CONTENT_DIR;
        }

        // Add other directories
        foreach ( $other_dirs as $target => $path ) {
            if ( is_dir( $path ) ) {
                $map[ $target ] = $path;
            }
        }

        return $map;
    }

    /**
     * Create a lightweight async job context (no DB dump / manifest work).
     *
     * @param string $job_id  Job identifier.
     * @param array  $options Backup options.
     * @return array<string,mixed>
     */
    public static function create_async_job_stub_context( $job_id, $options = [] ) {
        self::optimize_runtime_environment();

        $backup_dir = trailingslashit( backup_lite_get_backup_dir() );
        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            throw new RuntimeException( esc_html__( 'Backup directory is not writable.', 'museder-restoreone' ) );
        }

        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_url ) ) {
            $site_url = 'site';
        }
        $site_url = sanitize_file_name( $site_url );

        $date_time    = backup_lite_local_time( 'YmdHis' );
        $random_code  = wp_generate_password( 6, false, false );
        $label_suffix = '';

        if ( class_exists( 'Backup_Lite_Pro' ) && Backup_Lite_Pro::is_pro_active() && ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }

        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;

        $temp_dir  = backup_lite_create_temp_dir( 'build' );
        $sql_path  = trailingslashit( $temp_dir ) . 'database.sql';
        $meta_path = trailingslashit( $temp_dir ) . 'meta.json';

        $manifest_file = trailingslashit( backup_lite_get_jobs_dir() ) . sanitize_file_name( $job_id ) . '-manifest.json';

        return [
            'archive_path'  => $archive_path,
            'archive_name'  => $archive_name,
            'temp_dir'      => $temp_dir,
            'sql_path'      => $sql_path,
            'meta_path'     => $meta_path,
            'manifest_file' => $manifest_file,
            'options'       => is_array( $options ) ? $options : [],
        ];
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

        // Generate backup filename: 网址+西元年月日+时分+乱数编码
        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_url ) ) {
            $site_url = 'site';
        }
        // Sanitize domain name for filename
        $site_url = sanitize_file_name( $site_url );
        
        $date_time = backup_lite_local_time( 'YmdHis' );
        $random_code = wp_generate_password( 6, false, false );
        
        $label_suffix = '';
        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }

        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;
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
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- required for cleanup, path from plugin-controlled backup directory
                @unlink( $archive_path );
            }
        }

        self::initialize_archive_with_meta( $archive_path, $sql_path, $meta_path );

        try {
            $directories = self::get_directory_map();

            // Resolve Auto mode (large site detection) before building the full manifest.
            $options = self::resolve_effective_backup_options_for_job( $options, $directories );

            $manifest_data = self::with_runtime_exclusions(
                $options,
                function () use ( $directories ) {
                    return self::get_cached_file_manifest( $directories );
                }
            );
        } catch ( Exception $e ) {
            backup_lite_delete_directory( $temp_dir );
            backup_lite_log( 'error', 'Failed to build file manifest during job preparation.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            throw new RuntimeException( esc_html__( 'Failed to build file manifest. Check logs for details.', 'museder-restoreone' ) );
        }

        if ( empty( $manifest_data ) || ! isset( $manifest_data['files'] ) ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'File manifest is empty or invalid.', 'museder-restoreone' ) );
        }

        // Root self-check: detect common hosting restrictions (open_basedir/symlink) before starting packing.
        // This prevents "fake success" archives that contain only a tiny subset of files.
        $selfcheck = self::selfcheck_backup_roots( $archive_path, $directories );
        if ( ! empty( $selfcheck['failed'] ) ) {
            backup_lite_log( 'error', 'Backup root self-check failed.', $selfcheck );
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException(
                esc_html__( 'Backup cannot access required WordPress directories on this host. Please check logs for details.', 'museder-restoreone' )
            );
        }

        $manifest_file  = trailingslashit( backup_lite_get_jobs_dir() ) . $job_id . '-manifest.json';
        $manifest_bytes = wp_json_encode( $manifest_data['files'], JSON_UNESCAPED_SLASHES );

        if ( false === $manifest_bytes ) {
            backup_lite_delete_directory( $temp_dir );
            $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown JSON error';
            backup_lite_log( 'error', 'Failed to encode backup manifest to JSON.', [
                'json_error' => $json_error,
                'file_count' => isset( $manifest_data['count'] ) ? $manifest_data['count'] : 0,
            ] );
            throw new RuntimeException( esc_html__( 'Failed to encode backup manifest. The site may have too many files.', 'museder-restoreone' ) );
        }

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        if ( false === file_put_contents( $manifest_file, $manifest_bytes, LOCK_EX ) ) {
            backup_lite_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Unable to write backup manifest.', 'museder-restoreone' ) );
        }

        backup_lite_log( 'info', 'Backup job prepared.', [
            'job'   => $job_id,
            'files' => $manifest_data['count'],
            'bytes' => $manifest_data['bytes'],
            'backup_mode' => $options['backup_mode_effective'] ?? ( $options['backup_mode'] ?? '' ),
            'smart_exclude' => $options['backup_smart_exclude_effective'] ?? ( $options['backup_smart_exclude'] ?? '' ),
        ] );

        return [
            'archive_path'   => $archive_path,
            'temp_dir'       => $temp_dir,
            'manifest_file'  => $manifest_file,
            'manifest_count' => $manifest_data['count'],
            'manifest_bytes' => $manifest_data['bytes'],
            'options'        => $options,
            'selfcheck'      => $selfcheck,
        ];
    }

    /**
     * Background preparing stage for async jobs: DB dump, meta, init archive, manifest build, self-check.
     *
     * @param array $job Job state.
     * @return array Updated job state.
     */
    public static function run_preparing_stage( array $job ) {
        $step = isset( $job['prep_step'] ) ? (string) $job['prep_step'] : 'db';

        $archive_path = isset( $job['archive_path'] ) ? (string) $job['archive_path'] : '';
        $temp_dir     = isset( $job['temp_dir'] ) ? (string) $job['temp_dir'] : '';
        $sql_path     = isset( $job['sql_path'] ) ? (string) $job['sql_path'] : '';
        $meta_path    = isset( $job['meta_path'] ) ? (string) $job['meta_path'] : '';
        $manifest_file = isset( $job['manifest_file'] ) ? (string) $job['manifest_file'] : '';
        $options      = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];

        if ( '' === $archive_path || '' === $temp_dir || '' === $sql_path || '' === $meta_path || '' === $manifest_file ) {
            throw new RuntimeException( esc_html__( 'Backup job is missing required paths. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $job['status'] = 'running';
        $job['stage']  = 'preparing';

        if ( 'db' === $step ) {
            $job['message'] = __( 'Preparing database export…', 'museder-restoreone' );
            if ( ! self::generate_database_dump( $sql_path ) ) {
                throw new RuntimeException( esc_html__( 'Database export failed. Check logs for details.', 'museder-restoreone' ) );
            }
            $job['prep_step'] = 'meta';
            return $job;
        }

        if ( 'meta' === $step ) {
            $job['message'] = __( 'Preparing backup metadata…', 'museder-restoreone' );
            if ( ! self::write_meta_file( $meta_path, $options ) ) {
                throw new RuntimeException( esc_html__( 'Unable to write meta information for backup.', 'museder-restoreone' ) );
            }
            $job['prep_step'] = 'archive';
            return $job;
        }

        if ( 'archive' === $step ) {
            $job['message'] = __( 'Preparing backup archive…', 'museder-restoreone' );
            if ( file_exists( $archive_path ) ) {
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $archive_path );
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup in plugin-controlled backup directory
                    @unlink( $archive_path );
                }
            }
            self::initialize_archive_with_meta( $archive_path, $sql_path, $meta_path );
            $job['prep_step'] = 'manifest';
            return $job;
        }

        if ( 'manifest' === $step ) {
            $job['message'] = __( 'Building file list…', 'museder-restoreone' );

            $directories = self::get_directory_map();
            $options     = self::resolve_effective_backup_options_for_job( $options, $directories );

            $manifest_data = self::with_runtime_exclusions(
                $options,
                function () use ( $directories ) {
                    return self::get_cached_file_manifest( $directories );
                }
            );

            if ( empty( $manifest_data ) || ! isset( $manifest_data['files'] ) ) {
                throw new RuntimeException( esc_html__( 'File manifest is empty or invalid.', 'museder-restoreone' ) );
            }

            $encoded = wp_json_encode( $manifest_data['files'], JSON_UNESCAPED_SLASHES );
            if ( false === $encoded ) {
                $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown JSON error';
                backup_lite_log( 'error', 'Failed to encode backup manifest to JSON.', [
                    'json_error' => $json_error,
                    'file_count' => isset( $manifest_data['count'] ) ? $manifest_data['count'] : 0,
                ] );
                throw new RuntimeException( esc_html__( 'Failed to encode backup manifest. The site may have too many files.', 'museder-restoreone' ) );
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            if ( false === file_put_contents( $manifest_file, $encoded, LOCK_EX ) ) {
                throw new RuntimeException( esc_html__( 'Unable to write backup manifest.', 'museder-restoreone' ) );
            }

            $job['options']     = $options;
            $job['total_files'] = isset( $manifest_data['count'] ) ? max( 1, (int) $manifest_data['count'] ) : 1;
            $job['total_bytes'] = isset( $manifest_data['bytes'] ) ? max( 1, (int) $manifest_data['bytes'] ) : 1;

            backup_lite_log( 'info', 'Backup job prepared (background preparing stage).', [
                'job'   => $job['id'] ?? '',
                'files' => $job['total_files'],
                'bytes' => $job['total_bytes'],
                'backup_mode' => $options['backup_mode_effective'] ?? ( $options['backup_mode'] ?? '' ),
                'smart_exclude' => $options['backup_smart_exclude_effective'] ?? ( $options['backup_smart_exclude'] ?? '' ),
            ] );

            $job['directories'] = $directories;
            $job['prep_step']   = 'selfcheck';
            return $job;
        }

        if ( 'selfcheck' === $step ) {
            $job['message'] = __( 'Checking file access…', 'museder-restoreone' );
            $directories = isset( $job['directories'] ) && is_array( $job['directories'] ) ? $job['directories'] : self::get_directory_map();
            $selfcheck   = self::selfcheck_backup_roots( $archive_path, $directories );
            $job['selfcheck'] = $selfcheck;

            if ( ! empty( $selfcheck['failed'] ) ) {
                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: required directories are not readable on this host. Please check logs for details.', 'museder-restoreone' );
                backup_lite_log( 'error', 'Backup root self-check failed.', $selfcheck );
                return $job;
            }

            // Preparing is complete; packing will start in job processor.
            $job['prep_step'] = 'done';
            $job['message']   = __( 'Preparing complete. Starting backup…', 'museder-restoreone' );
            return $job;
        }

        // done
        $job['prep_step'] = 'done';
        return $job;
    }

    /**
     * Self-check core WordPress content roots to detect access restrictions early.
     *
     * @param string               $archive_path Backup archive path.
     * @param array<string,string> $directories  Directory map.
     * @return array<string,mixed>
     */
    private static function selfcheck_backup_roots( $archive_path, array $directories ) {
        $roots = [
            'uploads'   => $directories['uploads'] ?? ( WP_CONTENT_DIR . '/uploads' ),
            'plugins'   => $directories['plugins'] ?? ( WP_CONTENT_DIR . '/plugins' ),
            'themes'    => $directories['themes'] ?? ( WP_CONTENT_DIR . '/themes' ),
            'wp-content'=> $directories['wp-content'] ?? WP_CONTENT_DIR,
        ];

        $results = [
            'failed'  => false,
            'roots'   => [],
            'version' => defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : '',
        ];

        foreach ( $roots as $key => $root ) {
            $root = wp_normalize_path( (string) $root );
            $root = rtrim( $root, '/' );

            $root_result = [
                'root'           => $root,
                'exists'         => is_dir( $root ),
                'readable'       => is_readable( $root ),
                'sampled'        => 0,
                'read_ok'        => 0,
                'zip_add_ok'     => 0,
                'zip_add_failed' => 0,
                'samples'        => [],
                'errors'         => [],
            ];

            if ( ! $root_result['exists'] ) {
                $results['roots'][ $key ] = $root_result;
                continue;
            }

            $samples = self::sample_files_from_root( $root, 8 );
            $root_result['sampled'] = count( $samples );

            foreach ( $samples as $sample_path ) {
                $sample = [
                    'path'      => $sample_path,
                    'readable'  => @is_readable( $sample_path ),
                    'error'     => '',
                    'zip_add'   => null,
                ];

                $read_error = '';
                if ( $sample['readable'] ) {
                    $read_error = self::probe_read_error( $sample_path );
                } else {
                    $read_error = self::probe_read_error( $sample_path );
                }

                if ( '' === $read_error ) {
                    $root_result['read_ok']++;
                } else {
                    $sample['error'] = $read_error;
                    $root_result['errors'][] = $read_error;
                }

                if ( backup_lite_can_use_ziparchive() && file_exists( $archive_path ) ) {
                    $zip = new ZipArchive();
                    if ( true === $zip->open( $archive_path, ZipArchive::CREATE ) ) {
                        $test_name = '__bl_selfcheck/' . $key . '/' . basename( $sample_path );
                        $ok = $zip->addFile( $sample_path, $test_name );
                        $sample['zip_add'] = (bool) $ok;
                        if ( $ok ) {
                            $root_result['zip_add_ok']++;
                            if ( method_exists( $zip, 'deleteName' ) ) {
                                $zip->deleteName( $test_name );
                            }
                        } else {
                            $root_result['zip_add_failed']++;
                            $status = method_exists( $zip, 'getStatusString' ) ? $zip->getStatusString() : '';
                            if ( '' !== $status ) {
                                $root_result['errors'][] = $status;
                            }
                        }
                        $zip->close();
                    }
                }

                $root_result['samples'][] = $sample;
                if ( count( $root_result['samples'] ) >= 5 ) {
                    break;
                }
            }

            // Mark as failed if we could not read ANY sampled file from a core root.
            if ( $root_result['sampled'] > 0 && 0 === $root_result['read_ok'] ) {
                $results['failed'] = true;
            }

            $results['roots'][ $key ] = $root_result;
        }

        return $results;
    }

    /**
     * Sample up to N files from a root directory.
     *
     * @param string $root Root directory.
     * @param int    $limit Max number of files.
     * @return array<int,string>
     */
    private static function sample_files_from_root( $root, $limit = 10 ) {
        $limit = max( 1, (int) $limit );
        $root  = wp_normalize_path( $root );
        $root  = rtrim( $root, '/' );

        if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
            return [];
        }

        $files = [];

        $iterator_flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
        if ( defined( 'FilesystemIterator::CATCH_GET_CHILD' ) ) {
            $iterator_flags |= FilesystemIterator::CATCH_GET_CHILD;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $root, $iterator_flags ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                /** @var SplFileInfo $file */
                if ( $file->isDir() ) {
                    continue;
                }
                $path = $file->getPathname();
                if ( empty( $path ) ) {
                    continue;
                }
                $path = wp_normalize_path( $path );
                if ( self::should_skip_path( $path ) ) {
                    continue;
                }
                $files[] = $path;
                if ( count( $files ) >= $limit ) {
                    break;
                }
            }
        } catch ( Exception $e ) {
            return $files;
        }

        return $files;
    }

    /**
     * Try to read a file and capture common PHP warnings (e.g. open_basedir restriction).
     *
     * @param string $path File path.
     * @return string Error message (empty when OK).
     */
    private static function probe_read_error( $path ) {
        $path = (string) $path;
        $path = wp_normalize_path( $path );

        $captured = '';
        $handler  = static function( $errno, $errstr ) use ( &$captured ) {
            $captured = (string) $errstr;
            return true;
        };

        set_error_handler( $handler );
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- probe only; no data is stored
            $h = @fopen( $path, 'rb' );
            if ( false === $h ) {
                restore_error_handler();
                return '' !== $captured ? $captured : 'Unable to open file for reading.';
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- probe only
            @fclose( $h );
        } finally {
            restore_error_handler();
        }

        return $captured;
    }

    /**
     * Resolve effective backup options for Auto mode, including Smart Exclude.
     *
     * - Auto mode switches to Fast + Smart Exclude when file count is above threshold.
     * - Auto mode stays Balanced and keeps Smart Exclude off for smaller sites.
     *
     * @param array<string,mixed> $options     Incoming options.
     * @param array<string,string> $directories Directory map.
     * @return array<string,mixed> Updated options with *_effective fields.
     */
    private static function resolve_effective_backup_options_for_job( array $options, array $directories ): array {
        $requested_mode = isset( $options['backup_mode'] ) ? (string) $options['backup_mode'] : '';
        $requested_smart = isset( $options['backup_smart_exclude'] ) ? (string) $options['backup_smart_exclude'] : '';
        $requested_mode_raw  = $requested_mode;
        $requested_smart_raw = $requested_smart;

        if ( class_exists( 'Backup_Lite_Settings' ) ) {
            $settings = Backup_Lite_Settings::get_settings();
            if ( '' === $requested_mode && isset( $settings['backup_mode_default'] ) ) {
                $requested_mode = (string) $settings['backup_mode_default'];
            }
            if ( '' === $requested_smart && isset( $settings['backup_smart_exclude_default'] ) ) {
                $requested_smart = (string) $settings['backup_smart_exclude_default'];
            }
        }

        if ( ! in_array( $requested_mode, [ 'auto', 'balanced', 'fast' ], true ) ) {
            $requested_mode = 'auto';
        }
        if ( ! in_array( $requested_smart, [ 'auto', 'on', 'off' ], true ) ) {
            $requested_smart = 'auto';
        }

        $options['backup_mode'] = $requested_mode;
        $options['backup_smart_exclude'] = $requested_smart;

        // If no auto behavior requested, keep as-is.
        if ( 'auto' !== $requested_mode && 'auto' !== $requested_smart ) {
            $options['backup_mode_effective'] = $requested_mode;
            $options['backup_smart_exclude_effective'] = $requested_smart;
            return $options;
        }

        $threshold = 50000;
        if ( class_exists( 'Backup_Lite_Settings' ) ) {
            $settings = Backup_Lite_Settings::get_settings();
            if ( isset( $settings['backup_smart_exclude_threshold'] ) ) {
                $threshold = (int) $settings['backup_smart_exclude_threshold'];
            }
        }

        /**
         * Filter the file-count threshold used by Auto mode.
         *
         * @param int $threshold File count threshold.
         */
        $threshold = (int) apply_filters( 'backup_lite_backup_auto_threshold_files', $threshold );
        $threshold = max( 1000, min( 500000, $threshold ) );

        // Decide large site based on a lightweight count-only scan (stops once threshold is reached).
        $decision_options = $options;
        $decision_options['backup_smart_exclude'] = 'off';

        $stats = self::with_runtime_exclusions(
            $decision_options,
            function () use ( $directories, $threshold ) {
                return self::scan_manifest_stats( $directories, $threshold );
            }
        );

        $is_large = ! empty( $stats['reached_threshold'] );

        $effective_mode = $requested_mode;
        if ( 'auto' === $requested_mode ) {
            $effective_mode = $is_large ? 'fast' : 'balanced';
        }

        $effective_smart = $requested_smart;
        if ( 'auto' === $requested_smart ) {
            $effective_smart = $is_large ? 'on' : 'off';
        }

        $options['backup_mode_effective'] = $effective_mode;
        $options['backup_smart_exclude_effective'] = $effective_smart;
        $options['backup_large_site_detected'] = $is_large;
        $options['backup_auto_threshold_files'] = $threshold;
        $options['backup_auto_applied'] = ( 'auto' === $requested_mode_raw || 'auto' === $requested_smart_raw );

        // Ensure downstream steps use the effective values.
        $options['backup_mode'] = $effective_mode;
        $options['backup_smart_exclude'] = $effective_smart;

        backup_lite_log( 'info', 'Backup Auto mode decision.', [
            'threshold_files' => $threshold,
            'reached_threshold' => $is_large,
            'scanned_files' => isset( $stats['count'] ) ? (int) $stats['count'] : 0,
            'scanned_bytes' => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
            'backup_mode_effective' => $effective_mode,
            'smart_exclude_effective' => $effective_smart,
        ] );

        return $options;
    }

    /**
     * Lightweight manifest scan (count/bytes only), with early stop when reaching threshold.
     *
     * @param array<string,string> $directories Directory map.
     * @param int                 $stop_after_files Stop after reaching this file count.
     * @return array{count:int,bytes:int,reached_threshold:bool}
     */
    private static function scan_manifest_stats( array $directories, int $stop_after_files ): array {
        $count = 0;
        $bytes = 0;
        $reached = false;

        $stop_after_files = max( 1, $stop_after_files );

        foreach ( $directories as $target => $source ) {
            if ( ! is_dir( $source ) ) {
                continue;
            }

            $source = rtrim( $source, '/\\' );
            $normalized_source = wp_normalize_path( $source );
            $normalized_source = rtrim( $normalized_source, '/' );
            if ( self::should_skip_path( $source ) ) {
                continue;
            }

            $wp_content_skip_prefixes = [];
            if ( 'wp-content' === $target ) {
                // Prevent duplicate inclusion ONLY when those directories are explicitly included elsewhere.
                $candidates = [ 'themes', 'plugins', 'uploads', 'mu-plugins', 'languages' ];
                foreach ( $candidates as $key ) {
                    if ( isset( $directories[ $key ] ) && is_string( $directories[ $key ] ) && '' !== $directories[ $key ] ) {
                        $wp_content_skip_prefixes[] = wp_normalize_path( trailingslashit( $directories[ $key ] ) );
                    }
                }
            }

            // Use optimized iterator flags for better performance.
            $iterator_flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
            if ( defined( 'FilesystemIterator::CATCH_GET_CHILD' ) ) {
                $iterator_flags |= FilesystemIterator::CATCH_GET_CHILD;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $source, $iterator_flags ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ( $iterator as $file ) {
                    /** @var SplFileInfo $file */
                    if ( $file->isDir() ) {
                        continue;
                    }

                    // Use pathname (not realpath) to preserve symlinked paths on hosts with open_basedir constraints.
                    $file_path = $file->getPathname();
                    if ( empty( $file_path ) ) {
                        continue;
                    }
                    $file_path = wp_normalize_path( $file_path );

                    // Safety: ensure the file stays within the scanned root.
                    if ( 0 !== strpos( $file_path, trailingslashit( $normalized_source ) ) ) {
                        continue;
                    }

                    if ( self::should_skip_path( $file_path ) ) {
                        continue;
                    }

                    if ( ! empty( $wp_content_skip_prefixes ) ) {
                        foreach ( $wp_content_skip_prefixes as $skip_prefix ) {
                            if ( '' !== $skip_prefix && 0 === strpos( $file_path, $skip_prefix ) ) {
                                continue 2;
                            }
                        }
                    }

                    $size = $file->getSize();
                    if ( $size !== false && $size > 0 ) {
                        $bytes += (int) $size;
                    }

                    $count++;
                    if ( $count >= $stop_after_files ) {
                        $reached = true;
                        break 2;
                    }
                }
            } catch ( Exception $e ) {
                // Ignore scan errors for auto decision; continue other directories.
                continue;
            }
        }

        return [
            'count' => $count,
            'bytes' => $bytes,
            'reached_threshold' => $reached,
        ];
    }

    /**
     * Get optimal batch size based on available system resources.
     *
     * @return array{max_files: int, max_bytes: int}
     */
    private static function get_optimal_batch_size() {
        // Use WordPress memory management functions
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage( true );
        $available_memory = $memory_limit > 0 ? ( $memory_limit - $memory_usage ) : 0;

        // Dynamically adjust batch size based on available memory
        // Increased batch sizes to reduce AJAX request overhead
        if ( $available_memory > 512 * 1024 * 1024 ) {
            // > 512MB available: larger batches
            return [
                'max_files' => 800,
                'max_bytes' => 157286400, // 150MB
            ];
        } elseif ( $available_memory > 256 * 1024 * 1024 ) {
            // > 256MB available: medium batches
            return [
                'max_files' => 500,
                'max_bytes' => 104857600, // 100MB
            ];
        }

        // Default: smaller batches for limited memory environments
        return [
            'max_files' => 300,
            'max_bytes' => 73400320, // 70MB
        ];
    }

    /**
     * Process a chunk of files for the asynchronous backup job.
     *
     * @param array      $job        Job state.
     * @param int        $max_files  Maximum files per batch (optional, will be auto-calculated if not provided).
     * @param int        $max_bytes  Maximum bytes per batch (optional, will be auto-calculated if not provided).
     * @param ZipArchive $zip        Optional ZipArchive instance to reuse (for performance optimization).
     * @return array
     */
    public static function process_job_batch( array $job, $max_files = null, $max_bytes = null, $zip = null ) {
        self::optimize_runtime_environment();

        // Auto-calculate optimal batch size if not provided
        if ( null === $max_files || null === $max_bytes ) {
            $optimal = self::get_optimal_batch_size();
            $max_files = $max_files ?? $optimal['max_files'];
            $max_bytes = $max_bytes ?? $optimal['max_bytes'];
        }

        $manifest = self::load_manifest_for_job( $job );
        $total    = isset( $job['total_files'] ) ? (int) $job['total_files'] : count( $manifest );
        $pointer  = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
        $pointer  = max( 0, min( $pointer, $total ) );

        // Initialize diagnostic counters (persisted in job state).
        if ( ! isset( $job['attempted_files'] ) ) {
            $job['attempted_files'] = 0;
        }
        if ( ! isset( $job['added_files'] ) ) {
            $job['added_files'] = 0;
        }
        if ( ! isset( $job['skipped_files'] ) ) {
            $job['skipped_files'] = 0;
        }
        if ( ! isset( $job['added_bytes'] ) ) {
            $job['added_bytes'] = 0;
        }
        if ( ! isset( $job['skipped_bytes'] ) ) {
            $job['skipped_bytes'] = 0;
        }
        if ( ! isset( $job['skip_reasons'] ) || ! is_array( $job['skip_reasons'] ) ) {
            $job['skip_reasons'] = [];
        }
        if ( ! isset( $job['diagnostic_samples'] ) || ! is_array( $job['diagnostic_samples'] ) ) {
            $job['diagnostic_samples'] = [];
        }

        // Guard: if total_files indicates there should be work, but manifest is empty/missing,
        // do NOT fast-forward to "completed" (would create a partial archive with only meta+DB).
        if ( $total > 0 && empty( $manifest ) ) {
            throw new RuntimeException(
                esc_html__( 'Backup manifest is missing or empty. Please restart the backup job.', 'museder-restoreone' )
            );
        }

        // If there is nothing to pack (or we've already reached the end), defer finalize until the archive is closed.
        // This prevents reporting "completed" (and computing filesize/metadata) before ZipArchive::close() flushes data.
        if ( $total <= 0 || $pointer >= $total ) {
            $job['processed_files'] = isset( $job['total_files'] ) ? (int) $job['total_files'] : ( $job['processed_files'] ?? 0 );
            $job['processed_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : ( $job['processed_bytes'] ?? 0 );
            $job['status']          = 'running';
            $job['stage']           = 'finalizing';
            $job['message']         = __( 'Finalising backup archive…', 'museder-restoreone' );
            $job['needs_finalize']  = true;
            return $job;
        }

        $batch = [];
        $bytes = 0;
        $index = $pointer;

        while ( $index < $total && count( $batch ) < $max_files && $bytes < $max_bytes ) {
            $entry = $manifest[ $index ] ?? null;
            if ( empty( $entry['path'] ) || empty( $entry['target'] ) ) {
                $job['skipped_files']++;
                $job['skip_reasons']['invalid_entry'] = isset( $job['skip_reasons']['invalid_entry'] ) ? ( (int) $job['skip_reasons']['invalid_entry'] + 1 ) : 1;
                $index++;
                continue;
            }

            $path = wp_normalize_path( $entry['path'] );
            $job['attempted_files']++;

            if ( ! @file_exists( $path ) ) {
                $job['skipped_files']++;
                $job['skip_reasons']['missing_or_blocked'] = isset( $job['skip_reasons']['missing_or_blocked'] ) ? ( (int) $job['skip_reasons']['missing_or_blocked'] + 1 ) : 1;

                // Capture a small number of samples with error message for debugging.
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'missing_or_blocked',
                        'path' => $path,
                        'error' => self::probe_read_error( $path ),
                    ];
                }
                $index++;
                continue;
            }

            if ( ! @is_readable( $path ) ) {
                $job['skipped_files']++;
                $job['skip_reasons']['unreadable'] = isset( $job['skip_reasons']['unreadable'] ) ? ( (int) $job['skip_reasons']['unreadable'] + 1 ) : 1;
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'unreadable',
                        'path' => $path,
                        'error' => self::probe_read_error( $path ),
                    ];
                }
                $index++;
                continue;
            }

            // Use manifest size if available (already calculated during scan) - avoid unnecessary filesize() call
            $manifest_size = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
            
            // Skip extremely large files (>2GB) even if in manifest
            // Skip silently to reduce I/O overhead from logging
            $max_file_size = 2147483648; // 2GB
            if ( $manifest_size > $max_file_size ) {
                $job['skipped_files']++;
                $job['skip_reasons']['too_large'] = isset( $job['skip_reasons']['too_large'] ) ? ( (int) $job['skip_reasons']['too_large'] + 1 ) : 1;
                $index++;
                continue;
            }

            // Only verify file size if manifest size is missing or zero
            // This reduces I/O overhead for most files
            $file_size = $manifest_size;
            if ( $file_size <= 0 ) {
                // Fallback: get actual size only if manifest size is missing
                $actual_size = filesize( $path );
                if ( $actual_size > $max_file_size ) {
                    $job['skipped_files']++;
                    $job['skip_reasons']['too_large'] = isset( $job['skip_reasons']['too_large'] ) ? ( (int) $job['skip_reasons']['too_large'] + 1 ) : 1;
                    $index++;
                    continue;
                }
                $file_size = $actual_size > 0 ? $actual_size : 0;
            }
            
            $entry['path'] = $path;
            $entry['size'] = $file_size;
            $batch[]       = $entry;
            $bytes        += $file_size;
            $index++;
        }

        $append_results = [
            'attempted'      => 0,
            'added'          => 0,
            'failed'         => 0,
            'added_bytes'    => 0,
            'failed_samples' => [],
            'failed_entries' => [],
        ];

        if ( ! empty( $batch ) ) {
            $job_options = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];
            $pack_method = isset( $job['pack_method'] ) ? (string) $job['pack_method'] : '';
            if ( '' === $pack_method ) {
                $pack_method = backup_lite_can_use_ziparchive() ? 'ziparchive' : 'pclzip';
            }

            $append_results = self::with_runtime_exclusions(
                $job_options,
                function () use ( $job, $batch, $zip, $job_options, $pack_method ) {
                    if ( 'pclzip' === $pack_method ) {
                        return self::append_files_to_pclzip( $job['archive_path'], $batch );
                    }

                    $result = self::append_files_to_zip( $job['archive_path'], $batch, $zip, $job_options );

                    // If ZipArchive failed for some files, try fallback for those failed entries only.
                    if ( ! empty( $result['failed_entries'] ) ) {
                        $fallback = self::append_files_to_pclzip( $job['archive_path'], $result['failed_entries'] );
                        $result['fallback'] = $fallback;
                    }

                    return $result;
                }
            );
        }

        // Apply results to job diagnostics.
        if ( isset( $append_results['added'] ) ) {
            $job['added_files'] += (int) $append_results['added'];
        }
        if ( isset( $append_results['added_bytes'] ) ) {
            $job['added_bytes'] += (int) $append_results['added_bytes'];
        }
        if ( isset( $append_results['failed'] ) && (int) $append_results['failed'] > 0 ) {
            $job['skip_reasons']['zip_add_failed'] = isset( $job['skip_reasons']['zip_add_failed'] ) ? ( (int) $job['skip_reasons']['zip_add_failed'] + (int) $append_results['failed'] ) : (int) $append_results['failed'];
            if ( ! empty( $append_results['failed_samples'] ) && count( $job['diagnostic_samples'] ) < 20 ) {
                foreach ( $append_results['failed_samples'] as $sample ) {
                    if ( count( $job['diagnostic_samples'] ) >= 20 ) {
                        break;
                    }
                    $job['diagnostic_samples'][] = [
                        'type'   => 'zip_add_failed',
                        'path'   => $sample['path'] ?? '',
                        'target' => $sample['target'] ?? '',
                        'error'  => $sample['status'] ?? '',
                    ];
                }
            }
        }

        // If fallback ran, persist pack_method and include diagnostic info.
        if ( isset( $append_results['fallback'] ) && is_array( $append_results['fallback'] ) ) {
            $job['pack_method'] = 'pclzip';
            $job['skip_reasons']['fallback_to_pclzip'] = isset( $job['skip_reasons']['fallback_to_pclzip'] ) ? ( (int) $job['skip_reasons']['fallback_to_pclzip'] + 1 ) : 1;
            backup_lite_log( 'warning', 'Fallback to PclZip executed for failed ZipArchive entries.', [
                'job_id' => $job['id'] ?? '',
                'failed' => $append_results['failed'] ?? 0,
                'fallback' => $append_results['fallback'],
            ] );
        }

        $job['pointer']         = $index;
        $job['processed_files'] = min( (int) $job['added_files'], $total );

        $total_bytes = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
        $current     = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
        $delta_bytes = isset( $append_results['added_bytes'] ) ? (int) $append_results['added_bytes'] : 0;
        $job['processed_bytes'] = min(
            max( $total_bytes, 1 ),
            max( $current, $current + $delta_bytes )
        );
        $job['status']  = 'running';
        $job['stage']   = 'packing';
        $job['message'] = __( 'Backup running…', 'museder-restoreone' );

        if ( $job['pointer'] >= $total ) {
            // Completion guard: if we reached the end pointer but did not actually pack most files,
            // fail the job to prevent a "fake success" archive.
            $added_files   = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
            $skipped_files = isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0;
            $min_ratio     = 0.80;

            if ( $total >= 1000 && $added_files < (int) round( $total * $min_ratio ) ) {
                backup_lite_log( 'error', 'Backup packing finished with too many skipped/blocked files. Marking job failed to avoid incomplete archive.', [
                    'job_id'        => $job['id'] ?? '',
                    'total_files'   => $total,
                    'added_files'   => $added_files,
                    'skipped_files' => $skipped_files,
                    'skip_reasons'  => $job['skip_reasons'] ?? [],
                    'samples'       => $job['diagnostic_samples'] ?? [],
                    'pack_method'   => $job['pack_method'] ?? '',
                ] );

                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: too many files could not be read or added to the archive on this host. Please check logs for details.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                return $job;
            }

            // Defer finalize until after ZipArchive::close() in the job processor.
            $job['processed_files'] = isset( $job['total_files'] ) ? (int) $job['total_files'] : $job['processed_files'];
            $job['processed_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : $job['processed_bytes'];
            $job['status']          = 'running';
            $job['stage']           = 'finalizing';
            $job['message']         = __( 'Finalising backup archive…', 'museder-restoreone' );
            $job['needs_finalize']  = true;
            return $job;
        }

        return $job;
    }

    /**
     * Finalize a prepared async job AFTER the ZIP archive has been closed.
     *
     * @param array $job Job state.
     * @return array Finalized job state.
     */
    public static function finalize_async_job_after_close( array $job ) {
        return self::finalize_async_job( $job );
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
        // Default: keep DB/meta compressed.
        $zip->setCompressionName( 'database.sql', ZipArchive::CM_DEFLATE );
        $zip->setCompressionName( 'meta.json', ZipArchive::CM_DEFLATE );
        $zip->close();
    }

    /**
     * Get cached file manifest or build new one.
     * Uses WordPress Transients API to cache file lists for 5 minutes.
     * Note: Large manifests may not be cached due to WordPress transient size limits.
     * For small sites (< 1000 files), skip caching to reduce overhead.
     *
     * @param array $directories Directory map.
     * @return array
     */
    private static function get_cached_file_manifest( $directories ) {
        // For small sites, skip caching to reduce overhead
        // Build manifest directly without cache checks
        try {
            $manifest = self::build_manifest_from_directories( $directories );
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Failed to build file manifest.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            throw $e;
        }
        
        $file_count = isset( $manifest['count'] ) ? (int) $manifest['count'] : 0;
        
        // Only use cache for larger sites (> 1000 files) where cache benefits outweigh overhead
        if ( $file_count < 1000 ) {
            // Small site: skip caching to reduce overhead
            backup_lite_log( 'info', 'Built file manifest (cache skipped for small site).', [
                'files' => $file_count,
                'bytes' => $manifest['bytes'] ?? 0,
            ] );
            return $manifest;
        }
        
        // For larger sites, try to use cache
        $directories_json = wp_json_encode( $directories );
        if ( false === $directories_json ) {
            // If encoding fails, skip cache and return manifest
            return $manifest;
        }
        
        $cache_key = 'backup_lite_manifest_' . md5( $directories_json );
        
        // Try to get cached manifest
        $cached = get_transient( $cache_key );
        
        if ( false !== $cached && isset( $cached['timestamp'] ) && isset( $cached['manifest'] ) ) {
            $age = time() - $cached['timestamp'];
            // Use cache if it's less than 5 minutes old
            if ( $age < 300 && is_array( $cached['manifest'] ) ) {
                backup_lite_log( 'info', 'Using cached file manifest.', [
                    'age_seconds' => $age,
                    'files' => $cached['manifest']['count'] ?? 0,
                ] );
                return $cached['manifest'];
            }
        }
        
        // Try to cache, but don't fail if caching fails (transient may be too large)
        $cache_data = [
            'manifest' => $manifest,
            'timestamp' => time(),
        ];
        
        // Estimate cache size (rough estimate: ~200 bytes per file entry)
        $estimated_size = $file_count * 200;
        $max_cache_size = 900 * 1024; // 900KB (WordPress transient limit is usually 1MB)
        
        if ( $estimated_size < $max_cache_size ) {
            $cached_result = set_transient( $cache_key, $cache_data, 600 );
            // Don't log cache failures to reduce I/O overhead
        }
        
        backup_lite_log( 'info', 'Built file manifest.', [
            'files' => $file_count,
            'bytes' => $manifest['bytes'] ?? 0,
        ] );
        
        return $manifest;
    }

    /**
     * Build manifest entries for all directories that should be included.
     *
     * @param array $directories Directory map.
     * @return array
     * @throws RuntimeException If manifest building fails.
     */
    private static function build_manifest_from_directories( $directories ) {
        $manifest = [];
        $bytes    = 0;
        $file_count = 0;
        $max_files = 100000; // Safety limit: prevent memory exhaustion

        foreach ( $directories as $target => $source ) {
            if ( ! is_dir( $source ) ) {
                continue;
            }

            $source = rtrim( $source, '/\\' );
            $normalized_source = wp_normalize_path( $source );
            $normalized_source = rtrim( $normalized_source, '/' );
            if ( self::should_skip_path( $source ) ) {
                continue;
            }

            try {
            $wp_content_skip_prefixes = [];
            if ( 'wp-content' === $target ) {
                // Prevent duplicate inclusion ONLY when those directories are explicitly included elsewhere.
                $candidates = [ 'themes', 'plugins', 'uploads', 'mu-plugins', 'languages' ];
                foreach ( $candidates as $key ) {
                    if ( isset( $directories[ $key ] ) && is_string( $directories[ $key ] ) && '' !== $directories[ $key ] ) {
                        $wp_content_skip_prefixes[] = wp_normalize_path( trailingslashit( $directories[ $key ] ) );
                    }
                }
            }

            // Use optimized iterator flags for better performance
            // CATCH_GET_CHILD requires PHP 5.6.0+, use version check for compatibility
            $iterator_flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
            if ( defined( 'FilesystemIterator::CATCH_GET_CHILD' ) ) {
                $iterator_flags |= FilesystemIterator::CATCH_GET_CHILD;
            }
            
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, $iterator_flags ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

                foreach ( $iterator as $file ) {
                    // Safety check: prevent memory exhaustion
                    if ( $file_count >= $max_files ) {
                        backup_lite_log( 'warning', 'File manifest limit reached, stopping scan.', [
                            'max_files' => $max_files,
                            'scanned' => $file_count,
                        ] );
                        break 2; // Break out of both loops
                    }

                    /** @var SplFileInfo $file */
                    if ( $file->isDir() ) {
                        continue;
                    }

                    // Use pathname (not realpath) to preserve symlinked paths on hosts with open_basedir constraints.
                    $file_path = $file->getPathname();
                    if ( empty( $file_path ) ) {
                        continue;
                    }
                    $file_path = wp_normalize_path( $file_path );

                    // Safety: ensure the file stays within the scanned root.
                    if ( 0 !== strpos( $file_path, trailingslashit( $normalized_source ) ) ) {
                        continue;
                    }

                    if ( self::should_skip_path( $file_path ) ) {
                        continue;
                    }

                    if ( ! empty( $wp_content_skip_prefixes ) ) {
                        foreach ( $wp_content_skip_prefixes as $skip_prefix ) {
                            if ( '' !== $skip_prefix && 0 === strpos( $file_path, $skip_prefix ) ) {
                                continue 2;
                            }
                        }
                    }

                    $relative = ltrim( substr( $file_path, strlen( $normalized_source ) ), '/' );
                    if ( '' === $relative ) {
                        continue;
                    }

                    $size = $file->getSize();
                    
                    // Skip extremely large files (>2GB) to prevent issues
                    // These are typically log files, database dumps, or other non-essential files
                    // Skip silently to reduce I/O overhead from logging
                    $max_file_size = 2147483648; // 2GB
                    if ( $size !== false && $size > $max_file_size ) {
                        // Skip without logging to improve performance
                        continue;
                    }

                    $file_size = $size !== false ? (int) $size : 0;
                    $manifest[] = [
                        'path'   => $file_path,
                        'target' => $target . '/' . $relative,
                        'size'   => $file_size,
                    ];

                    if ( $file_size > 0 ) {
                        $bytes += $file_size;
                    }

                    $file_count++;
                }
            } catch ( Exception $e ) {
                backup_lite_log( 'error', 'Error scanning directory for manifest.', [
                    'source' => $source,
                    'target' => $target,
                    'error' => $e->getMessage(),
                ] );
                // Continue with other directories instead of failing completely
                continue;
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

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
    /**
     * Append files to ZIP archive with optimized compression.
     * Large files (>10MB) use no compression for better performance.
     *
     * @param string     $archive_path Path to ZIP archive.
     * @param array      $files        Array of file entries with 'path', 'target', and 'size' keys.
     * @param ZipArchive $zip          Optional ZipArchive instance to reuse (for performance optimization).
     *                                 If not provided, a new instance will be created and closed.
     */
    /**
     * Append files to ZIP archive and return detailed results.
     *
     * @param string     $archive_path Path to ZIP archive.
     * @param array      $files        Array of file entries with 'path', 'target', and 'size' keys.
     * @param ZipArchive $zip          Optional ZipArchive instance to reuse.
     * @param array      $options      Backup options.
     * @return array{attempted:int,added:int,failed:int,added_bytes:int,failed_samples:array<int,array<string,string>>,failed_entries:array<int,array<string,mixed>>}
     */
    private static function append_files_to_zip( $archive_path, array $files, $zip = null, array $options = [] ) {
        $should_close = false;
        
        // If no ZipArchive instance provided, create and open a new one
        if ( null === $zip ) {
            $zip = new ZipArchive();
            if ( true !== $zip->open( $archive_path, ZipArchive::CREATE ) ) {
                throw new RuntimeException( esc_html__( 'Unable to append files to archive.', 'museder-restoreone' ) );
            }
            $should_close = true;
        }

        $created_dirs = [];
        $large_file_threshold = 10485760; // 10MB
        $results = [
            'attempted'       => 0,
            'added'           => 0,
            'failed'          => 0,
            'added_bytes'     => 0,
            'failed_samples'  => [],
            'failed_entries'  => [],
        ];

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

            // Get file size from manifest (already calculated during scan) - avoid unnecessary filesize() call
            $file_size = isset( $file['size'] ) ? (int) $file['size'] : 0;
            
            // Skip extremely large files (>2GB) that should have been filtered during manifest building
            if ( $file_size > 2147483648 ) {
                continue;
            }

            $results['attempted']++;

            // Add file to archive (do NOT throw here; caller may fallback to PclZip).
            $added = $zip->addFile( $path, $target );
            if ( false === $added ) {
                $results['failed']++;
                $status = method_exists( $zip, 'getStatusString' ) ? $zip->getStatusString() : '';
                $results['failed_entries'][] = $file;
                if ( count( $results['failed_samples'] ) < 10 ) {
                    $results['failed_samples'][] = [
                        'path'   => (string) $path,
                        'target' => (string) $target,
                        'status' => (string) $status,
                    ];
                }
                continue;
            }

            $results['added']++;
            if ( $file_size > 0 ) {
                $results['added_bytes'] += $file_size;
            }

            // Compression strategy mirrors add_directory_to_zip().
            // Fast mode: store everything to reduce CPU on shared hosting.
            // Balanced: store only large files (>10MB), deflate smaller files.
            $mode = self::$runtime_backup_mode;
            if ( isset( $options['backup_mode'] ) && in_array( $options['backup_mode'], [ 'balanced', 'fast' ], true ) ) {
                $mode = (string) $options['backup_mode'];
            }

            if ( 'fast' === $mode ) {
                $zip->setCompressionName( $target, ZipArchive::CM_STORE );
            } elseif ( $file_size > 0 ) {
                if ( $file_size > $large_file_threshold ) {
                    $zip->setCompressionName( $target, ZipArchive::CM_STORE );
                } else {
                    $zip->setCompressionName( $target, ZipArchive::CM_DEFLATE );
                }
            }
        }

        // Only close if we created the ZipArchive instance
        if ( $should_close ) {
            $zip->close();
        }

        return $results;
    }

    /**
     * Append files to ZIP using PclZip (fallback for hosts where ZipArchive fails).
     *
     * @param string $archive_path Archive path.
     * @param array  $files        File entries with path/target/size.
     * @return array{attempted:int,added:int,failed:int,added_bytes:int,failed_samples:array<int,array<string,string>>}
     */
    private static function append_files_to_pclzip( $archive_path, array $files ) {
        self::optimize_runtime_environment();

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $results = [
            'attempted'      => 0,
            'added'          => 0,
            'failed'         => 0,
            'added_bytes'    => 0,
            'failed_samples' => [],
        ];

        $manifest = [];
        foreach ( $files as $file ) {
            $path = isset( $file['path'] ) ? (string) $file['path'] : '';
            $target = isset( $file['target'] ) ? (string) $file['target'] : '';
            if ( '' === $path || '' === $target ) {
                continue;
            }
            if ( ! @file_exists( $path ) || ! @is_readable( $path ) ) {
                $results['failed']++;
                if ( count( $results['failed_samples'] ) < 10 ) {
                    $results['failed_samples'][] = [
                        'path'   => $path,
                        'target' => $target,
                        'status' => 'missing_or_unreadable',
                    ];
                }
                continue;
            }
            $results['attempted']++;
            $manifest[] = [
                PCLZIP_ATT_FILE_NAME          => $path,
                PCLZIP_ATT_FILE_NEW_FULL_NAME => ltrim( $target, '/' ),
            ];
        }

        if ( empty( $manifest ) ) {
            return $results;
        }

        $archive = new PclZip( $archive_path );
        $result  = $archive->add( $manifest );
        if ( 0 === $result ) {
            $results['failed'] += count( $manifest );
            $results['failed_samples'][] = [
                'path'   => '',
                'target' => '',
                'status' => $archive->errorInfo( true ),
            ];
            return $results;
        }

        // PclZip does not provide per-file success info; assume all added if add() returns >0.
        $results['added'] = count( $manifest );
        foreach ( $files as $file ) {
            if ( isset( $file['size'] ) && (int) $file['size'] > 0 ) {
                $results['added_bytes'] += (int) $file['size'];
            }
        }

        return $results;
    }

    /**
     * Finalize job success state.
     *
     * @param array $job Job state.
     * @return array
     */
    private static function finalize_async_job( array $job ) {
        // Safety: never mark a job completed if it hasn't actually packed the majority of its manifest.
        $total_files     = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
        $processed_files = isset( $job['processed_files'] ) ? (int) $job['processed_files'] : 0;

        if ( $total_files > 0 && $processed_files < $total_files ) {
            backup_lite_log( 'warning', 'Finalize requested before job finished packing. Continuing backup instead of completing.', [
                'total_files'     => $total_files,
                'processed_files' => $processed_files,
            ] );

            $job['status']  = 'running';
            $job['stage']   = 'packing';
            $job['message'] = __( 'Backup running…', 'museder-restoreone' );
            if ( isset( $job['needs_finalize'] ) ) {
                unset( $job['needs_finalize'] );
            }

            return $job;
        }

        $job['status']          = 'completed';
        $job['stage']           = 'completed';
        $job['message']         = esc_html__( 'Backup completed successfully.', 'museder-restoreone' );
        $job['processed_files'] = isset( $job['total_files'] ) ? (int) $job['total_files'] : $job['processed_files'];
        $job['processed_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : $job['processed_bytes'];

        $size = file_exists( $job['archive_path'] ) ? filesize( $job['archive_path'] ) : 0;

        // Update download_url with the final archive path
        if ( ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
            $job['download_url'] = backup_lite_get_download_url( $job['archive_path'] );
        }

        // Calculate duration if started_at exists
        $backup_completed_at = time();
        $backup_duration_seconds = 0;
        if ( isset( $job['started_at'] ) && is_numeric( $job['started_at'] ) ) {
            $backup_duration_seconds = $backup_completed_at - (int) $job['started_at'];
        }
        $job['completed_at'] = $backup_completed_at;
        $job['duration_seconds'] = $backup_duration_seconds;

        backup_lite_log( 'info', 'Backup job completed.', [
            'archive' => $job['archive_path'],
            'size'    => $size,
            'duration_seconds' => $backup_duration_seconds,
        ] );

        self::record_backup_event( 'success', [
            'file'       => $job['archive_path'],
            'size_bytes' => $size,
            'size_human' => size_format( $size, 2 ),
            'label'      => $job['options']['label'] ?? '',
            'started_at' => isset( $job['started_at'] ) ? (int) $job['started_at'] : null,
            'completed_at' => $backup_completed_at,
            'duration_seconds' => $backup_duration_seconds,
        ] );

        // Store backup metadata (including duration)
        $backup_metadata = [
            'duration_seconds' => $backup_duration_seconds,
            'started_at' => isset( $job['started_at'] ) ? (int) $job['started_at'] : null,
            'completed_at' => $backup_completed_at,
        ];
        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $job['options']['label'] ) ) {
            $backup_metadata['label'] = sanitize_text_field( $job['options']['label'] );
            $backup_metadata['encrypted'] = ! empty( $job['options']['encrypt'] );
            $backup_metadata['cloud_destinations'] = $job['options']['cloud_destinations'] ?? [];
        }
        self::store_backup_metadata( basename( $job['archive_path'] ), $backup_metadata );

        if ( class_exists( 'Backup_Lite_Backup_Jobs' ) ) {
            Backup_Lite_Backup_Jobs::cleanup_job( $job );
        }

        return $job;
    }

    /**
     * Export database using mysqldump with optimized parameters.
     * Uses --single-transaction for consistency and --quick for better performance.
     *
     * @param string $filepath Path to output SQL file.
     * @return bool
     */
    private static function export_database_with_mysqldump( $filepath ) {
        // Build optimized mysqldump command
        // --single-transaction: Ensures consistency without locking tables
        // --quick: Processes rows one at a time, reducing memory usage
        // --lock-tables=false: Don't lock tables (works with --single-transaction)
        // --skip-comments: Skip comments to reduce file size
        // --no-tablespaces: Avoid tablespace issues
        $db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
        $db_user = escapeshellarg( DB_USER );
        $db_pass = escapeshellarg( DB_PASSWORD );
        $db_name = escapeshellarg( DB_NAME );
        $filepath_escaped = escapeshellarg( $filepath );

        // Handle DB_HOST with port or socket
        $host_parts = explode( ':', $db_host );
        $host = escapeshellarg( $host_parts[0] );
        $port = isset( $host_parts[1] ) ? ' -P' . escapeshellarg( $host_parts[1] ) : '';

        $command = sprintf(
            'mysqldump --single-transaction --quick --lock-tables=false --skip-comments --no-tablespaces -h%s%s -u%s -p%s %s > %s 2>&1',
            $host,
            $port,
            $db_user,
            $db_pass,
            $db_name,
            $filepath_escaped
        );

        $output  = '';
        $success = self::run_shell_command( $command, $output );

        if ( ! $success ) {
            backup_lite_log( 'error', 'mysqldump command failed.', [ 'output' => $output ] );
        } else {
            backup_lite_log( 'info', 'Database exported with optimized mysqldump parameters.', [
                'file' => basename( $filepath ),
                'size' => file_exists( $filepath ) ? filesize( $filepath ) : 0,
            ] );
        }

        return $success;
    }

    private static function export_database_with_php( $filepath ) {
        global $wpdb;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for writing SQL dump file, path validated and sanitized
        $handle = fopen( $filepath, 'wb' ); // Use binary mode for better performance
        if ( ! $handle ) {
            backup_lite_log( 'error', 'Unable to open SQL file for writing.', [ 'path' => $filepath ] );
            return false;
        }

        // Set write buffer for better I/O performance (64KB buffer)
        if ( function_exists( 'stream_set_write_buffer' ) ) {
            stream_set_write_buffer( $handle, 65536 );
        }

        $wpdb->hide_errors();
        // Allow longer execution time for large backup/restore jobs when possible.
        // phpcs:ignore WordPress.PHP.NoSetTimeLimit
        if ( function_exists( 'set_time_limit' ) ) {
            // Long-running backup/restore job: attempt to raise time limit for CLI/cron.
            // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
            }
            // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
        fwrite( $handle, "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n" );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
        fwrite( $handle, "SET time_zone = '+00:00';\n\n" );

        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
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

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
            fwrite( $handle, sprintf( "-- Table structure for table `%s`\n\n", $safe_table ) );

            // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
            // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
            // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $create = $wpdb->get_row( $wpdb->prepare( "SHOW CREATE TABLE `%s`", $safe_table ), ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: table name sanitized from SHOW TABLES result
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( isset( $create[1] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
                fwrite( $handle, "DROP TABLE IF EXISTS `{$safe_table}`;\n" );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
                fwrite( $handle, $create[1] . ";\n\n" );
            }

            // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
            // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
            // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `%s`", $safe_table ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $row_count === 0 ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
                fwrite( $handle, "\n" );
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
            fwrite( $handle, sprintf( "-- Dumping data for table `%s`\n", $safe_table ) );

            $offset = 0;
            while ( $offset < $row_count ) {
                // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
                // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
                // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `%s` LIMIT %d OFFSET %d",
                    $safe_table,
                    self::CHUNK_SIZE,
                    $offset
                ), ARRAY_A );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
                    fwrite( $handle, $sql );
                }

                $offset += self::CHUNK_SIZE;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing SQL dump file
            fwrite( $handle, "\n" );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $handle );

        return true;
    }

    public static function pclzip_filter_exclusions( $event, &$file ) {
        if ( 'check' === $event && self::should_skip_path( $file['filename'] ) ) {
            return 0;
        }
        return 1;
    }

    /**
     * Check if a path should be skipped during backup.
     * Optimized with early returns and cached exclusions.
     *
     * @param string $path File or directory path.
     * @return bool True if should skip, false otherwise.
     */
    private static function should_skip_path( $path ) {
        static $skip_basenames = null;
        static $exclusion_cache = [];

        // Cache skip basenames
        if ( null === $skip_basenames ) {
            $skip_basenames = [
                '.DS_Store',
                'desktop.ini',
                'Thumbs.db',
                '.git',
                '.svn',
                '.hg',
                'node_modules',
                '.npm',
                '.yarn',
                'vendor',
                '.composer',
                '.cache',
                '.tmp',
                '.temp',
            ];
        }

        $normalized = wp_normalize_path( $path );

        // Quick check: cache lookup
        if ( isset( $exclusion_cache[ $normalized ] ) ) {
            return $exclusion_cache[ $normalized ];
        }

        // Quick check: basename (fastest)
        $basename = basename( $normalized );
        if ( in_array( $basename, $skip_basenames, true ) ) {
            $exclusion_cache[ $normalized ] = true;
            return true;
        }

        if ( ! empty( self::$runtime_exclude_basenames ) && in_array( $basename, self::$runtime_exclude_basenames, true ) ) {
            $exclusion_cache[ $normalized ] = true;
            return true;
        }

        // Quick check: common exclusion patterns (before expensive operations)
        if ( strpos( $normalized, '/uploads/museder-restoreone' ) !== false ) {
            $exclusion_cache[ $normalized ] = true;
            return true;
        }

        // Check backup files in uploads directory
        if ( strpos( $normalized, '/uploads/' ) !== false && preg_match( '/\.(zip|wpress)$/i', $normalized ) ) {
            if ( strpos( $normalized, '/backups/' ) !== false || strpos( $normalized, '/museder-restoreone' ) !== false ) {
                $exclusion_cache[ $normalized ] = true;
                return true;
            }
        }

        // Check against internal exclusions (most expensive, do last)
        $exclusions = self::get_internal_exclusions();
        foreach ( $exclusions as $excluded ) {
            if ( '' !== $excluded && 0 === strpos( $normalized, $excluded ) ) {
                $exclusion_cache[ $normalized ] = true;
                return true;
            }
        }

        if ( ! empty( self::$runtime_exclude_prefixes ) ) {
            foreach ( self::$runtime_exclude_prefixes as $excluded ) {
                if ( '' !== $excluded && 0 === strpos( $normalized, $excluded ) ) {
                    $exclusion_cache[ $normalized ] = true;
                    return true;
                }
            }
        }

        if ( ! empty( self::$runtime_exclude_patterns ) ) {
            foreach ( self::$runtime_exclude_patterns as $pattern ) {
                if ( '' !== $pattern && strpos( $normalized, $pattern ) !== false ) {
                    $exclusion_cache[ $normalized ] = true;
                    return true;
                }
            }
        }

        // Cache negative result (limit cache size to prevent memory issues)
        if ( count( $exclusion_cache ) < 1000 ) {
            $exclusion_cache[ $normalized ] = false;
        }

        return false;
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

        // Use shared helper function for consistency
        $paths = backup_lite_get_excluded_paths();
        
        // Add additional exclusions specific to backup process
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
     * Run a callback with runtime exclusions applied for this request (job-scoped).
     *
     * @param array    $options  Backup options.
     * @param callable $callback Callback to execute.
     * @return mixed
     */
    private static function with_runtime_exclusions( array $options, callable $callback ) {
        $prev_prefixes  = self::$runtime_exclude_prefixes;
        $prev_patterns  = self::$runtime_exclude_patterns;
        $prev_basenames = self::$runtime_exclude_basenames;
        $prev_mode      = self::$runtime_backup_mode;

        $resolved = self::resolve_runtime_exclusions( $options );
        self::$runtime_exclude_prefixes  = $resolved['prefixes'];
        self::$runtime_exclude_patterns  = $resolved['patterns'];
        self::$runtime_exclude_basenames = $resolved['basenames'];
        self::$runtime_backup_mode       = self::resolve_runtime_backup_mode( $options );

        try {
            return call_user_func( $callback );
        } finally {
            self::$runtime_exclude_prefixes  = $prev_prefixes;
            self::$runtime_exclude_patterns  = $prev_patterns;
            self::$runtime_exclude_basenames = $prev_basenames;
            self::$runtime_backup_mode       = $prev_mode;
        }
    }

    /**
     * Resolve runtime backup mode for the current request.
     *
     * @param array $options Backup options.
     * @return string balanced|fast
     */
    private static function resolve_runtime_backup_mode( array $options ) {
        $mode = isset( $options['backup_mode'] ) ? (string) $options['backup_mode'] : '';
        if ( '' === $mode && class_exists( 'Backup_Lite_Settings' ) ) {
            $settings = Backup_Lite_Settings::get_settings();
            $mode = isset( $settings['backup_mode_default'] ) ? (string) $settings['backup_mode_default'] : '';
        }

        if ( ! in_array( $mode, [ 'auto', 'balanced', 'fast' ], true ) ) {
            $mode = 'balanced';
        }

        // Auto should have been resolved during async job preparation; treat remaining 'auto' as balanced.
        if ( 'auto' === $mode ) {
            $mode = 'balanced';
        }

        return $mode;
    }

    /**
     * Resolve runtime exclusions based on options + settings.
     *
     * Note: Auto/on/off behavior will be finalized during async job preparation; this function
     * resolves only explicit exclusions (smart exclude on/off + custom excludes) for the current request.
     *
     * @param array $options Backup options.
     * @return array{prefixes:array<string>,patterns:array<string>,basenames:array<string>}
     */
    private static function resolve_runtime_exclusions( array $options ) {
        $prefixes  = [];
        $patterns  = [];
        $basenames = [];

        $smart = isset( $options['backup_smart_exclude'] ) ? (string) $options['backup_smart_exclude'] : '';
        if ( '' === $smart && class_exists( 'Backup_Lite_Settings' ) ) {
            $settings = Backup_Lite_Settings::get_settings();
            $smart = isset( $settings['backup_smart_exclude_default'] ) ? (string) $settings['backup_smart_exclude_default'] : '';
        }
        if ( ! in_array( $smart, [ 'auto', 'on', 'off' ], true ) ) {
            $smart = 'auto';
        }

        // Only apply smart excludes when explicitly enabled.
        if ( 'on' === $smart ) {
            $smart_prefixes = self::get_smart_exclude_prefixes();
            $prefixes = array_merge( $prefixes, $smart_prefixes );
        }

        $custom = isset( $options['backup_custom_excludes'] ) ? (string) $options['backup_custom_excludes'] : '';
        if ( '' === $custom && class_exists( 'Backup_Lite_Settings' ) ) {
            $settings = Backup_Lite_Settings::get_settings();
            $custom = isset( $settings['backup_custom_excludes'] ) ? (string) $settings['backup_custom_excludes'] : '';
        }
        if ( '' !== trim( $custom ) ) {
            $parsed = self::parse_custom_excludes( $custom );
            $prefixes  = array_merge( $prefixes, $parsed['prefixes'] );
            $patterns  = array_merge( $patterns, $parsed['patterns'] );
            $basenames = array_merge( $basenames, $parsed['basenames'] );
        }

        $prefixes  = array_values( array_unique( array_filter( $prefixes ) ) );
        $patterns  = array_values( array_unique( array_filter( $patterns ) ) );
        $basenames = array_values( array_unique( array_filter( $basenames ) ) );

        return [
            'prefixes'  => $prefixes,
            'patterns'  => $patterns,
            'basenames' => $basenames,
        ];
    }

    /**
     * Return smart exclude absolute directory prefixes.
     *
     * @return array<string>
     */
    private static function get_smart_exclude_prefixes() {
        $prefixes = [
            trailingslashit( WP_CONTENT_DIR . '/cache' ),
            trailingslashit( WP_CONTENT_DIR . '/litespeed' ),
            trailingslashit( WP_CONTENT_DIR . '/w3tc-cache' ),
            trailingslashit( WP_CONTENT_DIR . '/wp-rocket-cache' ),
            trailingslashit( WP_CONTENT_DIR . '/uploads/cache' ),
        ];

        $prefixes = array_map(
            static function ( $p ) {
                return wp_normalize_path( $p );
            },
            $prefixes
        );

        /**
         * Filter smart exclude prefixes.
         *
         * @param array<string> $prefixes Normalized directory prefixes with trailing slashes.
         */
        $prefixes = apply_filters( 'backup_lite_smart_exclude_prefixes', $prefixes );

        if ( ! is_array( $prefixes ) ) {
            $prefixes = [];
        }

        return array_values( array_unique( array_filter( $prefixes ) ) );
    }

    /**
     * Parse custom excludes (one per line).
     *
     * Supports:\n
     * - relative paths like wp-content/cache/\n
     * - absolute paths\n
     * - basenames like node_modules\n
     * - simple substring patterns (contains asterisk '*' or starts/ends with '/')
     *
     * @param string $raw Raw textarea value.
     * @return array{prefixes:array<string>,patterns:array<string>,basenames:array<string>}
     */
    private static function parse_custom_excludes( $raw ) {
        $prefixes  = [];
        $patterns  = [];
        $basenames = [];

        $lines = preg_split( '/\R/u', (string) $raw );
        if ( empty( $lines ) || ! is_array( $lines ) ) {
            return [
                'prefixes' => [],
                'patterns' => [],
                'basenames'=> [],
            ];
        }

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }

            // Strip leading/trailing quotes.
            $line = trim( $line, " \t\n\r\0\x0B\"'" );
            if ( '' === $line ) {
                continue;
            }

            $line = wp_normalize_path( $line );

            // If no slash, treat as basename to skip (e.g., node_modules).
            if ( false === strpos( $line, '/' ) ) {
                $basenames[] = $line;
                continue;
            }

            // Substring patterns.
            if ( strpos( $line, '*' ) !== false ) {
                $patterns[] = str_replace( '*', '', $line );
                continue;
            }

            // Normalize relative roots.
            if ( 0 === strpos( $line, 'wp-content/' ) ) {
                $line = wp_normalize_path( WP_CONTENT_DIR . '/' . substr( $line, strlen( 'wp-content/' ) ) );
            } elseif ( 0 === strpos( $line, 'uploads/' ) ) {
                $line = wp_normalize_path( WP_CONTENT_DIR . '/uploads/' . substr( $line, strlen( 'uploads/' ) ) );
            } elseif ( 0 === strpos( $line, './' ) ) {
                $line = ltrim( $line, './' );
                $line = wp_normalize_path( ABSPATH . $line );
            } elseif ( 0 !== strpos( $line, '/' ) && false === preg_match( '#^([a-zA-Z]:/|\\\\\\\\)#', $line ) ) {
                // Treat as relative to ABSPATH.
                $line = wp_normalize_path( ABSPATH . ltrim( $line, '/' ) );
            }

            $prefixes[] = trailingslashit( $line );
        }

        return [
            'prefixes'  => array_values( array_unique( array_filter( $prefixes ) ) ),
            'patterns'  => array_values( array_unique( array_filter( $patterns ) ) ),
            'basenames' => array_values( array_unique( array_filter( $basenames ) ) ),
        ];
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
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
            // Long-running backup/restore job: attempt to raise time limit for CLI/cron.
            // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
            }
            // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
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
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for large backup operations
                // Adjusting PHP settings locally for backup/restore process.
                // phpcs:ignore WordPress.PHP.IniSet
                // Adjust memory limit for large backup/restore operations.
                // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
                if ( function_exists( 'ini_set' ) ) {
                    @ini_set( 'memory_limit', '1024M' );
                }
                // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
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
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
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
     * Store backup metadata (labels, etc.).
     * 
     * @param string $filename Backup filename.
     * @param array  $metadata Metadata to store.
     * @return bool
     */
    private static function store_backup_metadata( $filename, $metadata ) {
        // Always store metadata (duration, timestamps) even without PRO
        // PRO features (label, encrypted, cloud_destinations) are only stored if PRO is active
        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        $all_meta = [];

        if ( file_exists( $meta_file ) ) {
            // Using native file APIs on local backup directory; paths are sanitized and constrained.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents( $meta_file );
            $all_meta = json_decode( $content, true ) ?: [];
        }

        // Merge with existing metadata to preserve PRO features
        $existing = $all_meta[ $filename ] ?? [];
        $merged = array_merge( $existing, $metadata );
        $all_meta[ $filename ] = $merged;

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        return false !== file_put_contents( $meta_file, wp_json_encode( $all_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
    }

    /**
     * Get backup metadata.
     * 
     * @param string $filename Backup filename.
     * @return array
     */
    public static function get_backup_metadata( $filename ) {
        // Always return metadata (duration, timestamps) even without PRO
        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return [];
        }

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $meta_file );
        $all_meta = json_decode( $content, true ) ?: [];

        return $all_meta[ $filename ] ?? [];
    }
}
