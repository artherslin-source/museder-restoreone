<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Museder_Restoreone_Backup {

    const CHUNK_SIZE = 500;
    // AI1WM-like: time-slice long preparing operations (DB export / manifest scan) to avoid timeouts.
    const PREP_SLICE_SECONDS = 2;
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
     * Runtime inclusions (job-scoped): include-only prefixes. When non-empty, only these
     * normalized absolute prefixes (with trailing slash) are eligible for backup.
     *
     * @var array<string>
     */
    private static $runtime_include_prefixes = [];

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
        $backup_dir = trailingslashit( museder_restoreone_get_backup_dir() );

        self::optimize_runtime_environment();

        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            $log = museder_restoreone_log( 'error', 'Backup directory is not writable.', [ 'dir' => $backup_dir ] );
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
        
        $date_time = museder_restoreone_local_time( 'YmdHis' );
        $random_code = wp_generate_password( 6, false, false );
        
        $label_suffix = '';
        if ( ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }
        
        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;

        $temp_dir = museder_restoreone_create_temp_dir( 'build' );
        $sql_path = trailingslashit( $temp_dir ) . 'database.ndjson';
        $meta_path = trailingslashit( $temp_dir ) . 'meta.json';

        // Record backup start time (UTC timestamp)
        $backup_started_at = time();

        $log = museder_restoreone_log( 'info', 'Site backup started.', [
            'archive' => $archive_path,
            'method'  => museder_restoreone_can_use_ziparchive() ? 'ZipArchive' : 'PclZip',
        ] );

        if ( ! self::generate_database_dump( $sql_path, $options ) ) {
            museder_restoreone_log( 'error', 'Failed to generate database dump.', [ 'path' => $sql_path ] );
            museder_restoreone_delete_directory( $temp_dir );

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
            museder_restoreone_log( 'error', 'Failed to write meta.json file.', [ 'path' => $meta_path ] );
            museder_restoreone_delete_directory( $temp_dir );

            self::record_backup_event( 'failed', [
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
            ] );

            return [
                'success' => false,
                'message' => __( 'Unable to write meta information for backup.', 'museder-restoreone' ),
                'log'     => $log,
            ];
        }

        $media_path_drift = self::detect_media_path_drift_for_backup( 'sync' );

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

        $directories = self::get_directory_map( is_array( $options ) ? $options : [] );

        $success = self::with_runtime_exclusions(
            $options,
            function () use ( $archive_path, $sql_path, $meta_path, $directories ) {
                return museder_restoreone_can_use_ziparchive()
            ? self::create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories )
            : self::create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories );
            }
        );

        museder_restoreone_delete_directory( $temp_dir );

        if ( ! $success || ! file_exists( $archive_path ) ) {
            museder_restoreone_log( 'error', 'Site backup failed.', [ 'archive' => $archive_path ] );

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

        museder_restoreone_log( 'info', 'Site backup completed.', [
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
        if ( ! empty( $options['label'] ) ) {
            $backup_metadata['label'] = sanitize_text_field( $options['label'] );
        }
        if ( museder_is_pro_active() ) {
            $backup_metadata['encrypted'] = ! empty( $options['encrypt'] );
            $backup_metadata['cloud_destinations'] = $options['cloud_destinations'] ?? [];
        }
        if ( ! empty( $media_path_drift ) ) {
            $backup_metadata['media_path_drift'] = $media_path_drift;
        }
        self::store_backup_metadata( basename( $archive_path ), $backup_metadata );

        $response = [
            'success' => true,
            'message' => __( 'Backup completed successfully.', 'museder-restoreone' ),
            'file'    => $archive_path,
            'url'     => museder_restoreone_get_download_url( $archive_path ),
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

        // Add-on: upload to external storage when a separate provider is present.
        if ( museder_is_pro_active() && class_exists( 'Museder_Restoreone_Cloud_Storage' ) && ! empty( $options['cloud_destinations'] ) && is_array( $options['cloud_destinations'] ) ) {
            foreach ( $options['cloud_destinations'] as $destination ) {
                if ( 'local' !== $destination ) {
                    Museder_Restoreone_Cloud_Storage::upload_backup( $archive_path, $destination );
                }
            }
        }

        return $response;
    }

    /**
     * Generate database dump to destination path.
     */
    private static function generate_database_dump( $filepath, $options = [] ) {
        // Multisite subsite-only export: default to exporting only the selected blog tables (+ users/usermeta).
        if ( function_exists( 'is_multisite' ) && is_multisite() && ! empty( $options['multisite_blog_id'] ) && empty( $options['include_db_tables'] ) ) {
            global $wpdb;
            $blog_id = absint( $options['multisite_blog_id'] );
            if ( $blog_id > 0 && method_exists( $wpdb, 'get_blog_prefix' ) ) {
                $blog_prefix = $wpdb->get_blog_prefix( $blog_id );
                $blog_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $blog_prefix );
                $base_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->base_prefix );

                $all = self::get_tables();
                $include = [];
                foreach ( $all as $t ) {
                    $safe = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $t );
                    if ( $blog_prefix && 0 === strpos( $safe, $blog_prefix ) ) {
                        $include[] = $safe;
                    }
                }
                // Users are shared across network; include base users/usermeta for subsite->single convenience.
                if ( $base_prefix ) {
                    $include[] = $base_prefix . 'users';
                    $include[] = $base_prefix . 'usermeta';
                }
                $include = array_values( array_unique( array_filter( $include ) ) );
                if ( ! empty( $include ) ) {
                    $options['include_db_tables'] = $include;
                }
            }
        }

        // Allow scope preset to skip database entirely.
        if ( ! empty( $options['no_database'] ) ) {
            $placeholder = "-- Backup Lite: database export skipped by scope preset (no_database)\n";
            // Using native file APIs on local temp directory; path is plugin-controlled.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            return false !== file_put_contents( $filepath, $placeholder );
        }

        $method = 'php';
        museder_restoreone_log( 'info', 'Database export initiated.', [ 'method' => $method, 'path' => $filepath ] );
        $success = self::export_database_with_php( $filepath, $options );

        if ( $success && file_exists( $filepath ) ) {
            museder_restoreone_log( 'info', 'Database export finished.', [ 'path' => $filepath, 'size' => filesize( $filepath ) ] );
            return true;
        }

        return false;
    }

    private static function write_meta_file( $path, $options = [] ) {
        $meta = [
            'plugin_version'    => defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : 'unknown',
            'wordpress_version' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'unknown',
            'generated_at'      => museder_restoreone_local_time( 'c' ),
            // @plugin-check: allowed - GMT time for internal logs and metadata
            'generated_at_gmt'  => gmdate( 'c' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- GMT time for internal metadata, not user-facing
            'site_url'          => function_exists( 'home_url' ) ? home_url() : '',
            'php_version'       => PHP_VERSION,
        ];

        if ( ! empty( $options['label'] ) ) {
            $meta['label'] = sanitize_text_field( $options['label'] );
        }
        if ( museder_is_pro_active() ) {
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

    /**
     * Runtime options are local operational state and must not be exported.
     *
     * @param string $option_name WordPress option_name.
     * @return bool
     */
    private static function is_restoreone_runtime_option_name( $option_name ) {
        if ( class_exists( 'Museder_Restoreone_Restore' ) && method_exists( 'Museder_Restoreone_Restore', 'is_restoreone_runtime_option_name' ) ) {
            return Museder_Restoreone_Restore::is_restoreone_runtime_option_name( $option_name );
        }

        $option_name = (string) $option_name;
        if ( '' === $option_name ) {
            return false;
        }

        $exact = [
            'museder_restoreone_active_job',
            'museder_restoreone_restore_lock',
            'museder_restoreone_restore_service_active_job_id',
            'museder_restoreone_restore_token',
            'museder_restoreone_restore_post_complete_access',
            'museder_restoreone_mid_restore_isolation',
            'museder_restoreone_restored_active_plugins',
            'museder_restoreone_restored_active_sitewide_plugins',
            'museder_restoreone_skipped_plugins_after_restore',
        ];
        if ( in_array( $option_name, $exact, true ) ) {
            return true;
        }

        $prefixes = [
            'museder_restoreone_job_lock_',
            '_site_transient_museder_restoreone_',
            '_site_transient_timeout_museder_restoreone_',
            '_transient_museder_restoreone_',
            '_transient_timeout_museder_restoreone_',
        ];
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $option_name, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    private static function ensure_writable_directory( $dir ) {
        if ( ! file_exists( $dir ) ) {
            museder_restoreone_ensure_directory( $dir );
        }

        return is_dir( $dir ) && wp_is_writable( $dir );
    }

    private static function create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories ) {
        self::optimize_runtime_environment();

        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            museder_restoreone_log( 'error', 'Unable to create zip archive with ZipArchive.', [ 'path' => $archive_path ] );
            return false;
        }

        museder_restoreone_log( 'info', 'ZipArchive bundle phase started.', [
            'archive' => $archive_path,
            'roots'   => count( $directories ),
        ] );

        $zip->addFile( $sql_path, 'database.ndjson' );
        $zip->addFile( $meta_path, 'meta.json' );
        // Keep DB/meta compressed even in Fast mode (single files; low overhead; big size win).
        $zip->setCompressionName( 'database.ndjson', ZipArchive::CM_DEFLATE );
        $zip->setCompressionName( 'meta.json', ZipArchive::CM_DEFLATE );

        foreach ( $directories as $target => $source ) {
            museder_restoreone_log( 'info', 'Adding directory to archive.', [
                'source' => $source,
                'target' => $target,
            ] );
            self::add_directory_to_zip( $zip, $source, $target, $directories );
        }

        return $zip->close();
    }

    private static function create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories ) {
        self::optimize_runtime_environment();

        if ( function_exists( 'museder_restoreone_require_pclzip' ) ) {
            museder_restoreone_require_pclzip();
        }

        $manifest = self::build_pclzip_manifest( $sql_path, $meta_path, $directories );
        museder_restoreone_log( 'info', 'PclZip bundle phase started.', [
            'archive' => $archive_path,
            'items'   => count( $manifest ),
        ] );

        $archive = new PclZip( $archive_path );
        $result  = $archive->create( $manifest );

        if ( 0 === $result ) {
            museder_restoreone_log( 'error', 'PclZip failed while creating archive.', [ 'error' => $archive->errorInfo( true ) ] );
            return false;
        }

        return true;
    }

    private static function build_pclzip_manifest( $sql_path, $meta_path, $directories ) {
        $manifest = [
            [
                PCLZIP_ATT_FILE_NAME          => $sql_path,
                PCLZIP_ATT_FILE_NEW_FULL_NAME => 'database.ndjson',
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
                    museder_restoreone_log( 'warning', 'Skipping extremely large file in PclZip manifest.', [
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
            $upload_dir = wp_upload_dir();
            $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
            $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';

            // Prevent duplicate inclusion ONLY when these directories are actually included elsewhere.
            // Some hosts may not expose themes/plugins/uploads as separate roots, so skipping unconditionally
            // can lead to missing uploads in backups.
            $keys = [ 'themes', 'plugins', 'uploads', 'mu-plugins', 'languages' ];
            foreach ( $keys as $key ) {
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
            $entry    = self::compose_target_path( (string) $target, (string) $relative );

            if ( $file->isDir() ) {
                if ( '' !== $entry ) {
                    $zip->addEmptyDir( $entry );
                }
            } else {
                // Skip extremely large files (>2GB) to prevent issues
                $size = $file->getSize();
                $max_file_size = 2147483648; // 2GB
                if ( $size !== false && $size > $max_file_size ) {
                    museder_restoreone_log( 'warning', 'Skipping extremely large file in ZipArchive.', [
                        'path' => $file_path,
                        'size' => $size,
                        'size_mb' => round( $size / 1048576, 2 ),
                    ] );
                    continue;
                }
                
                if ( '' === $entry ) {
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
     * Get directory map for backup.
     *
     * For full-site backups, the ZIP root should represent the WordPress site root.
     * We therefore map the WordPress install root to the ZIP root (empty target) so the archive contains:
     * - wp-admin/
     * - wp-includes/
     * - wp-content/
     * - site root files (e.g. config + index) if present under the install root
     *
     * If wp-content is outside the install root (non-standard), we include it separately as wp-content/.
     *
     * @return array<string, string> Map of target => source directory paths.
     */
    private static function get_directory_map( array $options = [] ) {
        $map = [];

        // Multisite subsite-only export: pack wp-content only (faster + smaller), rely on include prefixes.
        if ( function_exists( 'is_multisite' ) && is_multisite() && ! empty( $options['multisite_blog_id'] ) ) {
            $wp_content = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
            $wp_content = wp_normalize_path( rtrim( (string) $wp_content, '/\\' ) );
            if ( '' !== $wp_content && is_dir( $wp_content ) ) {
                $map['wp-content'] = $wp_content;
            }
            return $map;
        }

        // Prefer helper to handle non-standard installs; fall back internally.
        $root = function_exists( 'museder_restoreone_get_wp_root_dir' ) ? (string) museder_restoreone_get_wp_root_dir() : '';
        $root = wp_normalize_path( rtrim( (string) $root, '/\\' ) );
        if ( '' !== $root && is_dir( $root ) ) {
            // Empty target means "ZIP root".
            $map[''] = $root;
        }

        // If wp-content is outside the install root, include it explicitly.
        $wp_content = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
        $wp_content = wp_normalize_path( rtrim( (string) $wp_content, '/\\' ) );
        if ( '' !== $wp_content && is_dir( $wp_content ) ) {
            $root_prefix = '' !== $root ? trailingslashit( $root ) : '';
            if ( '' === $root_prefix || 0 !== strpos( $wp_content, $root_prefix ) ) {
                $map['wp-content'] = $wp_content;
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

        $backup_dir = trailingslashit( museder_restoreone_get_backup_dir() );
        if ( ! self::ensure_writable_directory( $backup_dir ) ) {
            throw new RuntimeException( esc_html__( 'Backup directory is not writable.', 'museder-restoreone' ) );
        }

        $site_url = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_url ) ) {
            $site_url = 'site';
        }
        $site_url = sanitize_file_name( $site_url );

        $date_time    = museder_restoreone_local_time( 'YmdHis' );
        $random_code  = wp_generate_password( 6, false, false );
        $label_suffix = '';

        if ( ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }

        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;

        $temp_dir  = museder_restoreone_create_temp_dir( 'build' );
        $sql_path  = trailingslashit( $temp_dir ) . 'database.ndjson';
        $meta_path = trailingslashit( $temp_dir ) . 'meta.json';

        $manifest_file = trailingslashit( museder_restoreone_get_jobs_dir() ) . sanitize_file_name( $job_id ) . '-manifest.json';
        $manifest_ndjson_file = trailingslashit( museder_restoreone_get_jobs_dir() ) . sanitize_file_name( $job_id ) . '-manifest.ndjson';

        return [
            'archive_path'  => $archive_path,
            'archive_name'  => $archive_name,
            'temp_dir'      => $temp_dir,
            'sql_path'      => $sql_path,
            'meta_path'     => $meta_path,
            'manifest_file' => $manifest_file,
            'manifest_ndjson_file' => $manifest_ndjson_file,
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

        $backup_dir = trailingslashit( museder_restoreone_get_backup_dir() );

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
        
        $date_time = museder_restoreone_local_time( 'YmdHis' );
        $random_code = wp_generate_password( 6, false, false );
        
        $label_suffix = '';
        if ( ! empty( $options['label'] ) ) {
            $label_suffix = '-' . sanitize_file_name( $options['label'] );
        }

        $archive_name = sprintf( '%s-%s-%s%s.zip', $site_url, $date_time, $random_code, $label_suffix );
        $archive_path = $backup_dir . $archive_name;
        $temp_dir     = museder_restoreone_create_temp_dir( 'build' );
        $sql_path     = trailingslashit( $temp_dir ) . 'database.ndjson';
        $meta_path    = trailingslashit( $temp_dir ) . 'meta.json';

        if ( ! self::generate_database_dump( $sql_path, $options ) ) {
            museder_restoreone_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Database export failed. Check logs for details.', 'museder-restoreone' ) );
        }

        if ( ! self::write_meta_file( $meta_path, $options ) ) {
            museder_restoreone_delete_directory( $temp_dir );
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
            $directories = self::get_directory_map( is_array( $options ) ? $options : [] );

            // Resolve Auto mode (large site detection) before building the full manifest.
            $options = self::resolve_effective_backup_options_for_job( $options, $directories );

            $manifest_data = self::with_runtime_exclusions(
                $options,
                function () use ( $directories ) {
                    return self::get_cached_file_manifest( $directories );
                }
            );
        } catch ( Exception $e ) {
            museder_restoreone_delete_directory( $temp_dir );
            museder_restoreone_log( 'error', 'Failed to build file manifest during job preparation.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            throw new RuntimeException( esc_html__( 'Failed to build file manifest. Check logs for details.', 'museder-restoreone' ) );
        }

        if ( empty( $manifest_data ) || ! isset( $manifest_data['files'] ) ) {
            museder_restoreone_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'File manifest is empty or invalid.', 'museder-restoreone' ) );
        }

        // Root self-check: detect common hosting restrictions (open_basedir/symlink) before starting packing.
        // This prevents "fake success" archives that contain only a tiny subset of files.
        $selfcheck = self::selfcheck_backup_roots( $archive_path, $directories );
        if ( ! empty( $selfcheck['failed'] ) ) {
            museder_restoreone_log( 'error', 'Backup root self-check failed.', $selfcheck );
            museder_restoreone_delete_directory( $temp_dir );
            throw new RuntimeException(
                esc_html__( 'Backup cannot access required WordPress directories on this host. Please check logs for details.', 'museder-restoreone' )
            );
        }

        $manifest_file  = trailingslashit( museder_restoreone_get_jobs_dir() ) . $job_id . '-manifest.json';
        $manifest_bytes = wp_json_encode( $manifest_data['files'], JSON_UNESCAPED_SLASHES );

        if ( false === $manifest_bytes ) {
            museder_restoreone_delete_directory( $temp_dir );
            $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown JSON error';
            museder_restoreone_log( 'error', 'Failed to encode backup manifest to JSON.', [
                'json_error' => $json_error,
                'file_count' => isset( $manifest_data['count'] ) ? $manifest_data['count'] : 0,
            ] );
            throw new RuntimeException( esc_html__( 'Failed to encode backup manifest. The site may have too many files.', 'museder-restoreone' ) );
        }

        // Using native file APIs on local backup directory; paths are sanitized and constrained.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        if ( false === file_put_contents( $manifest_file, $manifest_bytes, LOCK_EX ) ) {
            museder_restoreone_delete_directory( $temp_dir );
            throw new RuntimeException( esc_html__( 'Unable to write backup manifest.', 'museder-restoreone' ) );
        }

        museder_restoreone_log( 'info', 'Backup job prepared.', [
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
            // WP.org submission build: database export uses plugin-owned NDJSON via PHP/WordPress APIs.
            if ( museder_restoreone_can_use_mysqldump() ) {
                if ( ! self::generate_database_dump( $sql_path, $options ) ) {
                throw new RuntimeException( esc_html__( 'Database export failed. Check logs for details.', 'museder-restoreone' ) );
            }
            $job['prep_step'] = 'meta';
                return $job;
            }

            $done = self::export_database_with_php_sliced_for_job( $job, $sql_path, $options, self::PREP_SLICE_SECONDS );
            // Improve progress messaging for huge DBs (table-based, resumable).
            if ( ! $done && ! empty( $job['db_state'] ) && is_array( $job['db_state'] ) ) {
                $state = $job['db_state'];
                $tables_total = isset( $state['tables'] ) && is_array( $state['tables'] ) ? count( $state['tables'] ) : 0;
                $table_index  = isset( $state['table_index'] ) ? (int) $state['table_index'] : 0;
                $table_name   = isset( $state['current_table'] ) ? (string) $state['current_table'] : '';
                $row_offset   = isset( $state['row_offset'] ) ? (int) $state['row_offset'] : 0;
                $row_count    = isset( $state['row_count'] ) ? (int) $state['row_count'] : 0;
                if ( '' !== $table_name && $tables_total > 0 ) {
                    $job['message'] = sprintf(
                        /* translators: 1: table index, 2: total tables, 3: table name, 4: row offset, 5: row count */
                        __( 'Preparing database export… (%1$d/%2$d: %3$s, %4$d/%5$d rows)', 'museder-restoreone' ),
                        min( $table_index + 1, $tables_total ),
                        $tables_total,
                        $table_name,
                        $row_offset,
                        max( 0, $row_count )
                    );
                }
            }
            if ( $done ) {
                $job['prep_step'] = 'meta';
            } else {
                $job['prep_step'] = 'db';
            }
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

            $directories = self::get_directory_map( is_array( $options ) ? $options : [] );
            $options     = self::resolve_effective_backup_options_for_job( $options, $directories );

            // AI1WM-like: build manifest in small time slices with resume checkpoints.
            $job['options']     = $options;
            $job['directories'] = $directories;

            $ndjson_file = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
            if ( '' === $ndjson_file ) {
                $ndjson_file = trailingslashit( museder_restoreone_get_jobs_dir() ) . sanitize_file_name( (string) ( $job['id'] ?? '' ) ) . '-manifest.ndjson';
                $job['manifest_ndjson_file'] = $ndjson_file;
            }

            $done = self::with_runtime_exclusions(
                $options,
                function () use ( &$job, $ndjson_file ) {
                    return self::build_manifest_ndjson_sliced_for_job( $job, $ndjson_file, self::PREP_SLICE_SECONDS );
                }
            );
            if ( $done ) {
                museder_restoreone_log( 'info', 'Backup job prepared (sliced manifest).', [
                'job'   => $job['id'] ?? '',
                    'files' => isset( $job['total_files'] ) ? (int) $job['total_files'] : 0,
                    'bytes' => isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0,
                'backup_mode' => $options['backup_mode_effective'] ?? ( $options['backup_mode'] ?? '' ),
                'smart_exclude' => $options['backup_smart_exclude_effective'] ?? ( $options['backup_smart_exclude'] ?? '' ),
            ] );
                $job['prep_step'] = 'selfcheck';
            } else {
                $job['prep_step'] = 'manifest';
            }

            return $job;
        }

        if ( 'selfcheck' === $step ) {
            $job['message'] = __( 'Checking file access…', 'museder-restoreone' );
            $directories = isset( $job['directories'] ) && is_array( $job['directories'] )
                ? $job['directories']
                : self::get_directory_map( $options );
            $selfcheck   = self::selfcheck_backup_roots( $archive_path, $directories );
            $job['selfcheck'] = $selfcheck;
            $job['media_path_drift'] = self::detect_media_path_drift_for_backup( (string) ( $job['id'] ?? '' ) );

            if ( ! empty( $selfcheck['failed'] ) ) {
                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: required directories are not readable on this host. Please check logs for details.', 'museder-restoreone' );
                museder_restoreone_log( 'error', 'Backup root self-check failed.', $selfcheck );
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
     * Detect and log DB-to-disk media path drift before packaging a backup.
     *
     * @param string $context Job id or sync context.
     * @return array<string, mixed>
     */
    private static function detect_media_path_drift_for_backup( $context = '' ) {
        if ( ! class_exists( 'Museder_Restoreone_Restore_Media_Paths' ) ) {
            return [];
        }

        $limit = 1000;
        if ( function_exists( 'apply_filters' ) ) {
            $limit = (int) apply_filters( 'museder_restoreone_backup_media_drift_scan_limit', $limit, $context );
        }

        $summary = Museder_Restoreone_Restore_Media_Paths::detect_upload_path_drift( $limit, (string) $context );
        if ( ! is_array( $summary ) ) {
            return [];
        }

        $drift   = isset( $summary['drift'] ) ? (int) $summary['drift'] : 0;
        $missing = isset( $summary['missing'] ) ? (int) $summary['missing'] : 0;
        if ( ( $drift > 0 || $missing > 0 ) && function_exists( 'museder_restoreone_log' ) ) {
            museder_restoreone_log(
                'warning',
                'BACKUP_MEDIA_PATH_DRIFT_DETECTED',
                [
                    'context'   => (string) $context,
                    'scanned'   => isset( $summary['scanned'] ) ? (int) $summary['scanned'] : 0,
                    'drift'     => $drift,
                    'missing'   => $missing,
                    'truncated' => ! empty( $summary['truncated'] ),
                    'samples'   => isset( $summary['samples'] ) && is_array( $summary['samples'] ) ? array_slice( $summary['samples'], 0, 5 ) : [],
                ]
            );
        }

        return $summary;
    }

    /**
     * Time-sliced PHP database export for async jobs.
     *
     * This writes plugin-owned NDJSON (JSON Lines) to $sql_path incrementally
     * and stores resume checkpoints inside $job['db_state'].
     *
     * @param array  $job             Job state (updated by reference).
     * @param string $sql_path        Destination NDJSON file path.
     * @param array  $options         Backup options.
     * @param int    $timeout_seconds Slice time budget.
     * @return bool True if completed.
     */
    private static function export_database_with_php_sliced_for_job( array &$job, $sql_path, array $options, $timeout_seconds = 2 ) {
        global $wpdb;

        $start = microtime( true );

        if ( ! isset( $job['db_state'] ) || ! is_array( $job['db_state'] ) ) {
            $job['db_state'] = [
                'started'     => false,
                'tables'      => [],
                'table_index' => 0,
                'row_offset'  => 0,
                'schema_done' => false,
            ];
        }

        $state = $job['db_state'];

        if ( empty( $state['tables'] ) || ! is_array( $state['tables'] ) ) {
            $state['tables']      = self::get_tables_for_export( $options );
            $state['table_index'] = 0;
            $state['row_offset']  = 0;
            $state['schema_done'] = false;
        }

        // Create/append NDJSON file.
        $mode = ( ! empty( $state['started'] ) && file_exists( $sql_path ) ) ? 'ab' : 'wb';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming DB export, path is plugin-controlled
        $handle = fopen( $sql_path, $mode );
        if ( ! $handle ) {
            return false;
        }

        if ( function_exists( 'stream_set_write_buffer' ) ) {
            stream_set_write_buffer( $handle, 65536 );
        }

        if ( empty( $state['started'] ) ) {
            $meta = [
                'type'            => 'meta',
                'format'          => 'museder_restoreone_db_ndjson',
                'format_version'  => 1,
                'generated_at_gmt'=> gmdate( 'c' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- GMT metadata
                'site_url'        => function_exists( 'home_url' ) ? home_url() : '',
                'table_prefix'    => isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '',
            ];
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for NDJSON stream export
            fwrite( $handle, wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ) . "\n" );
            $state['started'] = true;
        }

        $tables = $state['tables'];
        $i      = isset( $state['table_index'] ) ? (int) $state['table_index'] : 0;
        $offset = isset( $state['row_offset'] ) ? (int) $state['row_offset'] : 0;
        $schema_done = ! empty( $state['schema_done'] );

        while ( $i < count( $tables ) ) {
            $table = (string) $tables[ $i ];
            $safe_table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
            if ( '' === $safe_table ) {
                $i++;
                $offset = 0;
                $schema_done = false;
                continue;
            }

            $state['current_table'] = $safe_table;

            if ( ! $schema_done ) {
                // Identifiers cannot be passed via wpdb::prepare(). We strictly sanitize + esc_sql() and then inline.
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $safe_table ) . '`', ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema inspection for backup export; no schema changes executed
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ( isset( $create[1] ) ) {
                    $schema = [
                        'type'   => 'schema',
                        'table'  => $safe_table,
                        'create' => (string) $create[1],
                    ];
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for NDJSON stream export
                    fwrite( $handle, wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . "\n" );
                }
                $schema_done = true;
                $offset = 0;
            }

            if ( ! isset( $state['row_count'] ) || (int) $state['row_count'] < 0 || (int) $state['table_index'] !== $i ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $row_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $safe_table ) . '`' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier is strict-sanitized + esc_sql()
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $state['row_count']  = $row_count;
                $state['table_index'] = $i;
            } else {
                $row_count = (int) $state['row_count'];
            }
            if ( $row_count <= 0 ) {
                $i++;
                $offset = 0;
                $schema_done = false;
                if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                    break;
                }
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM `' . esc_sql( $safe_table ) . '` LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier is strict-sanitized + esc_sql(); values prepared
                    (int) self::CHUNK_SIZE,
                    (int) $offset
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if ( empty( $rows ) ) {
                // Finished table.
                $i++;
                $offset = 0;
                $schema_done = false;
                if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                    break;
                }
                continue;
            }

            // Skip plugin runtime options that should not be carried in backups.
            // Same pattern as AI1WM: exclude operational state from exports.
            $is_options_table = ( isset( $wpdb->options ) && $safe_table === preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->options ) );
            $exclude_option_names = [
                'museder_restoreone_restore_lock',
                'museder_restoreone_restore_service_active_job_id',
                'museder_restoreone_restore_token',
                '_site_transient_museder_restoreone_restore_lock',
                '_site_transient_timeout_museder_restoreone_restore_lock',
            ];

            foreach ( $rows as $row ) {
                if ( $is_options_table && isset( $row['option_name'] ) ) {
                    $option_name = (string) $row['option_name'];
                    if ( in_array( $option_name, $exclude_option_names, true ) || self::is_restoreone_runtime_option_name( $option_name ) ) {
                        continue;
                    }
                }
                $line = [
                    'type'  => 'row',
                    'table' => $safe_table,
                    'row'   => $row,
                ];
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for NDJSON stream export
                fwrite( $handle, wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n" );
            }

            $offset += (int) self::CHUNK_SIZE;

            if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                break;
            }

            if ( $offset >= $row_count ) {
                // Finished table.
                $i++;
                $offset = 0;
                $schema_done = false;
                if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                    break;
                }
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required cleanup after fopen
        fclose( $handle );

        $state['tables']      = $tables;
        $state['table_index'] = $i;
        $state['row_offset']  = $offset;
        $state['schema_done'] = $schema_done;
        $job['db_state']      = $state;

        return ( $i >= count( $tables ) );
    }

    /**
     * Time-sliced manifest builder for async jobs.
     *
     * Writes a JSON array incrementally into $manifest_file to avoid long single requests.
     * Resume state is stored inside $job['manifest_state'].
     *
     * @param array  $job
     * @param string $manifest_file
     * @param int    $timeout_seconds
     * @return bool True if completed.
     */
    private static function build_manifest_ndjson_sliced_for_job( array &$job, $manifest_file, $timeout_seconds = 2 ) {
        $start = microtime( true );

        $directories = isset( $job['directories'] ) && is_array( $job['directories'] ) ? $job['directories'] : [];
        if ( empty( $directories ) ) {
            return false;
        }

        if ( ! isset( $job['manifest_state'] ) || ! is_array( $job['manifest_state'] ) ) {
            $queue = [];
            foreach ( $directories as $target => $source ) {
                if ( ! is_string( $source ) || '' === $source || ! is_dir( $source ) ) {
                    continue;
                }
                $root = wp_normalize_path( rtrim( (string) $source, '/\\' ) );
                $queue[] = [
                    'target' => (string) $target,
                    'root'   => $root,
                    'dir'    => $root,
                ];
            }

            $job['manifest_state'] = [
                'started' => false,
                'queue'   => $queue,
                'current' => null,
                'count'   => 0,
                'bytes'   => 0,
            ];
        }

        $state   = $job['manifest_state'];
        $queue   = isset( $state['queue'] ) && is_array( $state['queue'] ) ? $state['queue'] : [];
        $current = isset( $state['current'] ) ? $state['current'] : null;

        $mode = ( ! empty( $state['started'] ) && file_exists( $manifest_file ) ) ? 'ab' : 'wb';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming manifest write on large sites, file path is plugin-controlled
        $fh = fopen( $manifest_file, $mode );
        if ( ! $fh ) {
            return false;
        }

        if ( empty( $state['started'] ) ) {
            $state['started'] = true;
        }

        $max_file_size = 2147483648; // 2GB (ZipArchive/PclZip safety cap)

        while ( $timeout_seconds <= 0 || ( microtime( true ) - $start ) < $timeout_seconds ) {
            if ( empty( $current ) ) {
                $current = array_shift( $queue );
                if ( empty( $current ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
                    fclose( $fh );

                    $state['queue']   = [];
                    $state['current'] = null;
                    $job['manifest_state'] = $state;

                    $job['total_files'] = max( 1, (int) $state['count'] );
                    $job['total_bytes'] = max( 1, (int) $state['bytes'] );
                    return true;
                }

                $dir = isset( $current['dir'] ) ? (string) $current['dir'] : '';
                if ( '' === $dir || ! is_dir( $dir ) ) {
                    $current = null;
                    continue;
                }

                // Build file list for this directory so we can resume within it.
                $items = [];
                try {
                    $it = new DirectoryIterator( $dir );
                    foreach ( $it as $item ) {
                        if ( $item->isDot() ) {
                            continue;
                        }
                        $items[] = $item->getFilename();
                    }
                } catch ( Exception $e ) {
                    $current = null;
                    continue;
                }

                $current['items'] = $items;
                $current['idx']   = 0;
            }

            $dir    = isset( $current['dir'] ) ? (string) $current['dir'] : '';
            $root   = isset( $current['root'] ) ? (string) $current['root'] : '';
            $target = isset( $current['target'] ) ? (string) $current['target'] : '';
            $items  = isset( $current['items'] ) && is_array( $current['items'] ) ? $current['items'] : [];
            $idx    = isset( $current['idx'] ) ? (int) $current['idx'] : 0;

            if ( '' === $dir || '' === $root || $idx >= count( $items ) ) {
                $current = null;
                continue;
            }

            $name = (string) $items[ $idx ];
            $current['idx'] = $idx + 1;

            $path = wp_normalize_path( trailingslashit( $dir ) . $name );
            if ( self::should_skip_path( $path ) ) {
                continue;
            }

            $root_prefix = trailingslashit( wp_normalize_path( $root ) );
            if ( 0 !== strpos( $path, $root_prefix ) ) {
                continue;
            }

            if ( is_dir( $path ) ) {
                $queue[] = [
                    'target' => $target,
                    'root'   => $root,
                    'dir'    => $path,
                ];
                continue;
            }

            if ( ! is_file( $path ) ) {
                continue;
            }

            $rel = ltrim( substr( $path, strlen( rtrim( $root, '/' ) ) ), '/' );
            if ( '' === $rel ) {
                continue;
            }

            $target_path = self::compose_target_path( $target, $rel );
            if ( '' === $target_path ) {
                continue;
            }

            $size = @filesize( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- manifest build needs file size, path is validated
            if ( is_numeric( $size ) && (int) $size > $max_file_size ) {
                // Do not include >2GB files in manifest totals; packing will not be able to include them.
                // Track as skipped so the UI can explain why the archive is missing this file.
                $state['skipped_too_large'] = isset( $state['skipped_too_large'] ) ? ( (int) $state['skipped_too_large'] + 1 ) : 1;

                $job['skipped_files'] = isset( $job['skipped_files'] ) ? ( (int) $job['skipped_files'] + 1 ) : 1;
                if ( ! isset( $job['skip_reasons'] ) || ! is_array( $job['skip_reasons'] ) ) {
                    $job['skip_reasons'] = [];
                }
                $job['skip_reasons']['too_large'] = isset( $job['skip_reasons']['too_large'] ) ? ( (int) $job['skip_reasons']['too_large'] + 1 ) : 1;

                if ( ! isset( $job['diagnostic_samples'] ) || ! is_array( $job['diagnostic_samples'] ) ) {
                    $job['diagnostic_samples'] = [];
                }
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'too_large',
                        'path' => $path,
                        'size' => (int) $size,
                    ];
                }
                continue;
            }
            $entry = [
                'path'   => $path,
                'target' => $target_path,
                'size'   => is_numeric( $size ) ? (int) $size : 0,
            ];
            $encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
            if ( false === $encoded ) {
                continue;
            }

            // NDJSON: one JSON object per line.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming manifest write
            fwrite( $fh, $encoded . "\n" );
            $state['count'] = isset( $state['count'] ) ? ( (int) $state['count'] + 1 ) : 1;
            if ( ! empty( $entry['size'] ) ) {
                $state['bytes'] = isset( $state['bytes'] ) ? ( (int) $state['bytes'] + (int) $entry['size'] ) : (int) $entry['size'];
            }
        }

        $state['queue']   = $queue;
        $state['current'] = $current;
        $job['manifest_state'] = $state;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
        fclose( $fh );

        return false;
    }

    /**
     * Ensure manifest.ndjson exists for a job. Auto-migrate legacy manifest.json when needed.
     *
     * @param array $job
     * @return array Updated job.
     */
    private static function ensure_manifest_ndjson_for_job( array $job ) {
        $ndjson = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' !== $ndjson && file_exists( $ndjson ) ) {
            return $job;
        }

        $job_id = sanitize_file_name( (string) ( $job['id'] ?? '' ) );
        if ( '' === $ndjson ) {
            $ndjson = trailingslashit( museder_restoreone_get_jobs_dir() ) . $job_id . '-manifest.ndjson';
            $job['manifest_ndjson_file'] = $ndjson;
        }

        // Legacy manifest file (JSON array).
        $legacy = isset( $job['manifest_file'] ) ? (string) $job['manifest_file'] : '';
        if ( '' === $legacy || ! file_exists( $legacy ) ) {
            throw new RuntimeException( esc_html__( 'Backup manifest file is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        // One-time migration per job.
        if ( empty( $job['manifest_migrated'] ) ) {
            $totals = self::convert_manifest_json_to_ndjson( $legacy, $ndjson );
            $job['manifest_migrated'] = true;
            $job['manifest_offset']   = 0;

            if ( isset( $totals['count'] ) ) {
                $job['total_files'] = max( 1, (int) $totals['count'] );
            }
            if ( isset( $totals['bytes'] ) ) {
                $job['total_bytes'] = max( 1, (int) $totals['bytes'] );
            }
        }

        return $job;
    }

    /**
     * Convert legacy manifest.json (JSON array) to manifest.ndjson (one JSON object per line).
     *
     * This is only used for legacy jobs and is strict: the top-level JSON must be an array and
     * each element must be an object. Any invalid/corrupt input fails fast with a friendly message.
     *
     * The conversion is streaming to avoid high RAM usage on large backups.
     *
     * @param string $json_file
     * @param string $ndjson_file
     * @return array{count:int,bytes:int}
     */
    private static function convert_manifest_json_to_ndjson( $json_file, $ndjson_file ) {
        $friendly = esc_html__( "This backup's manifest.json is invalid or corrupted. Please create a new backup and try again.", 'museder-restoreone' );

        $fail = static function ( $reason ) use ( $json_file, $friendly ) {
            if ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log( 'error', 'legacy_manifest_invalid', [
                    'file'   => basename( (string) $json_file ),
                    'reason' => (string) $reason,
                ] );
            }
            // @plugin-check: escaped
            throw new RuntimeException( esc_html( $friendly ) );
        };

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming legacy manifest conversion, path is plugin-controlled
        $in = fopen( $json_file, 'rb' );
        if ( ! $in ) {
            $fail( 'open_failed' );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming NDJSON output, file path is plugin-controlled
        $out = fopen( $ndjson_file, 'wb' );
        if ( ! $out ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
            fclose( $in );
            $fail( 'output_open_failed' );
        }

        $count = 0;
        $bytes = 0;

        // Read helpers (chunked buffer).
        $buffer = '';
        $pos    = 0;
        $eof    = false;

        $read_char = static function () use ( &$in, &$buffer, &$pos, &$eof ) {
            if ( $eof ) {
                return null;
            }
            if ( $pos >= strlen( $buffer ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- required for streaming parse, file path is plugin-controlled
                $buffer = fread( $in, 65536 );
                $pos    = 0;
                if ( false === $buffer || '' === $buffer ) {
                    $eof = true;
                    return null;
                }
            }
            return $buffer[ $pos++ ];
        };

        $peek_char = static function () use ( &$pos, $read_char ) {
            $c = $read_char();
            if ( null === $c ) {
                return null;
            }
            $pos--;
            return $c;
        };

        $skip_ws = static function () use ( $read_char, $peek_char ) {
            while ( true ) {
                $c = $peek_char();
                if ( null === $c ) {
                    return null;
                }
                if ( ! ctype_space( $c ) ) {
                    return $c;
                }
                $read_char();
            }
        };

        try {
            // Top-level must be an array.
            $c = $skip_ws();
            if ( null === $c ) {
                $fail( 'empty_input' );
            }
            if ( '[' !== $c ) {
                $fail( 'top_level_not_array' );
            }
            $read_char(); // consume '['

            // Array loop.
            while ( true ) {
                $c = $skip_ws();
                if ( null === $c ) {
                    $fail( 'unexpected_eof_in_array' );
                }

                // Empty array or end.
                if ( ']' === $c ) {
                    $read_char(); // consume ']'
                    break;
                }

                // Elements must be objects.
                if ( '{' !== $c ) {
                    $fail( 'array_element_not_object' );
                }

                // Read one full object (string/escape aware brace matching).
                $obj        = '';
                $depth      = 0;
                $in_string  = false;
                $escape     = false;

                while ( true ) {
                    $ch = $read_char();
                    if ( null === $ch ) {
                        $fail( 'unexpected_eof_in_object' );
                    }

                    $obj .= $ch;

                    if ( $in_string ) {
                        if ( $escape ) {
                            $escape = false;
                            continue;
                        }
                        if ( '\\' === $ch ) {
                            $escape = true;
                            continue;
                        }
                        if ( '"' === $ch ) {
                            $in_string = false;
                            continue;
                        }
                        continue;
                    }

                    if ( '"' === $ch ) {
                        $in_string = true;
                        continue;
                    }

                    if ( '{' === $ch ) {
                        $depth++;
                        continue;
                    }
                    if ( '}' === $ch ) {
                        $depth--;
                        if ( 0 === $depth ) {
                            break;
                        }
                        continue;
                    }
                }

                $decoded = json_decode( $obj, true );
                if ( ! is_array( $decoded ) ) {
                    $fail( 'object_decode_failed' );
                }

                // Ensure element is an object (associative), not an array/list.
                $is_list = true;
                $i = 0;
                foreach ( $decoded as $k => $_v ) {
                    if ( $k !== $i ) {
                        $is_list = false;
                        break;
                    }
                    $i++;
                }
                if ( $is_list ) {
                    $fail( 'array_element_not_object' );
                }

                $path   = isset( $decoded['path'] ) ? wp_normalize_path( (string) $decoded['path'] ) : '';
                $target = isset( $decoded['target'] ) ? (string) $decoded['target'] : '';
                $size   = isset( $decoded['size'] ) ? absint( $decoded['size'] ) : 0;
                if ( '' === $path || '' === $target ) {
                    $fail( 'missing_fields' );
                }
                $target = preg_replace( '#[^A-Za-z0-9_\\-\\./]#', '', $target );
                if ( '' === $target ) {
                    $fail( 'invalid_target' );
                }

                $line = wp_json_encode(
                    [
                        'path'   => $path,
                        'target' => $target,
                        'size'   => $size,
                    ],
                    JSON_UNESCAPED_SLASHES
                );
                if ( false === $line ) {
                    $fail( 'encode_failed' );
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming NDJSON output
                fwrite( $out, $line . "\n" );

                $count++;
                if ( $size > 0 ) {
                    $bytes += $size;
                }

                // After an element, expect comma or end bracket.
                $c = $skip_ws();
                if ( null === $c ) {
                    $fail( 'unexpected_eof_after_object' );
                }
                if ( ',' === $c ) {
                    $read_char(); // consume comma and continue
                    continue;
                }
                if ( ']' === $c ) {
                    $read_char(); // consume end
                    break;
                }
                $fail( 'invalid_delimiter_after_object' );
            }

            // Trailing non-ws is not allowed.
            $c = $skip_ws();
            if ( null !== $c ) {
                $fail( 'trailing_data' );
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
            fclose( $out );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
            fclose( $in );
        }

        return [
            'count' => $count,
            'bytes' => $bytes,
        ];
    }

    /**
     * Read a batch of NDJSON manifest entries starting at a byte offset.
     *
     * @param string $file
     * @param int    $offset
     * @param int    $max_files
     * @param int    $max_bytes
     * @return array{entries:array<int,array{path:string,target:string,size:int}>,offset:int,eof:bool}
     */
    private static function read_manifest_ndjson_batch( $file, $offset, $max_files, $max_bytes ) {
        $entries = [];
        $bytes   = 0;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming manifest read, file path is plugin-controlled
        $fh = fopen( $file, 'rb' );
        if ( ! $fh ) {
            throw new RuntimeException( esc_html__( 'Unable to read backup manifest for packing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $offset = max( 0, (int) $offset );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- required for resumable manifest read
        fseek( $fh, $offset );

        $eof = false;
        while ( count( $entries ) < $max_files && $bytes < $max_bytes ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- required for streaming NDJSON read
            $line = fgets( $fh );
            if ( false === $line ) {
                $eof = true;
                break;
            }

            $offset = ftell( $fh );

            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $row = json_decode( $line, true );
            if ( ! is_array( $row ) ) {
                continue;
            }

            $path   = isset( $row['path'] ) ? wp_normalize_path( (string) $row['path'] ) : '';
            $target = isset( $row['target'] ) ? (string) $row['target'] : '';
            $size   = isset( $row['size'] ) ? absint( $row['size'] ) : 0;

            if ( '' === $path || '' === $target ) {
                continue;
            }
            $target = preg_replace( '#[^A-Za-z0-9_\\-\\./]#', '', $target );
            if ( '' === $target ) {
                continue;
            }

            $entries[] = [
                'path'   => $path,
                'target' => $target,
                'size'   => $size,
            ];
            if ( $size > 0 ) {
                $bytes += $size;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
        fclose( $fh );

        return [
            'entries' => $entries,
            'offset'  => (int) $offset,
            'eof'     => (bool) $eof,
        ];
    }

    /**
     * Self-check core WordPress content roots to detect access restrictions early.
     *
     * @param string               $archive_path Backup archive path.
     * @param array<string,string> $directories  Directory map.
     * @return array<string,mixed>
     */
    private static function selfcheck_backup_roots( $archive_path, array $directories ) {
        $upload_dir = wp_upload_dir();
        $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
        $plugins_dir = function_exists( 'museder_restoreone_get_plugins_dir' ) ? museder_restoreone_get_plugins_dir() : '';
        $themes_dir  = function_exists( 'get_theme_root' ) ? wp_normalize_path( (string) get_theme_root() ) : '';
        $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';

        $roots = [
            'uploads'   => $directories['uploads'] ?? $uploads_basedir,
            'plugins'   => $directories['plugins'] ?? $plugins_dir,
            'themes'    => $directories['themes'] ?? $themes_dir,
            'wp-content'=> $directories['wp-content'] ?? $content_dir,
        ];

        $results = [
            'failed'  => false,
            'roots'   => [],
            'version' => defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '',
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

                if ( museder_restoreone_can_use_ziparchive() && file_exists( $archive_path ) ) {
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

        set_error_handler( $handler ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- capture fopen warnings (e.g. open_basedir) for diagnostics; not debug logging
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
     * - Auto mode switches to Fast + Smart Exclude when the site is "large":
     *   file count above threshold, manifest scan bytes above threshold, or cached
     *   estimate total (DB + files) above threshold — aligned with the Backups page warning.
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

        if ( class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
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
        if ( class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
            if ( isset( $settings['backup_smart_exclude_threshold'] ) ) {
                $threshold = (int) $settings['backup_smart_exclude_threshold'];
            }
        }

        /**
         * Filter the file-count threshold used by Auto mode.
         *
         * @param int $threshold File count threshold.
         */
        $threshold = (int) apply_filters( 'museder_restoreone_backup_auto_threshold_files', $threshold );
        $threshold = max( 1000, min( 500000, $threshold ) );

        $byte_threshold = Museder_Restoreone_Estimate_Size::LARGE_SITE_TOTAL_BYTES;
        if ( class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
            if ( isset( $settings['backup_auto_threshold_bytes'] ) ) {
                $byte_threshold = (int) $settings['backup_auto_threshold_bytes'];
            }
        }
        /**
         * Filter byte threshold for Auto mode (default 1 GB, matches estimate UI warning).
         *
         * @param int $byte_threshold Bytes threshold.
         */
        $byte_threshold = (int) apply_filters( 'museder_restoreone_backup_auto_threshold_bytes', $byte_threshold );
        $byte_threshold = max( 104857600, min( 53687091200, $byte_threshold ) ); // 100 MB – 50 GB.

        // Decide large site based on a lightweight count-only scan (stops once threshold is reached).
        $decision_options = $options;
        $decision_options['backup_smart_exclude'] = 'off';

        $stats = self::with_runtime_exclusions(
            $decision_options,
            function () use ( $directories, $threshold, $byte_threshold ) {
                return self::scan_manifest_stats( $directories, $threshold, $byte_threshold );
            }
        );

        $estimate_total_bytes = class_exists( 'Museder_Restoreone_Estimate_Size' )
            ? Museder_Restoreone_Estimate_Size::get_cached_total_bytes()
            : 0;

        $is_large_by_files = ! empty( $stats['reached_file_threshold'] );
        $is_large_by_scan_bytes = ! empty( $stats['reached_byte_threshold'] )
            || ( isset( $stats['bytes'] ) && (int) $stats['bytes'] >= $byte_threshold );
        $is_large_by_estimate = $estimate_total_bytes >= $byte_threshold;
        $is_large = $is_large_by_files || $is_large_by_scan_bytes || $is_large_by_estimate;

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

        museder_restoreone_log( 'info', 'Backup Auto mode decision.', [
            'threshold_files' => $threshold,
            'threshold_bytes' => $byte_threshold,
            'reached_threshold' => $is_large_by_files,
            'large_by_scan_bytes' => $is_large_by_scan_bytes,
            'large_by_estimate' => $is_large_by_estimate,
            'estimate_total_bytes' => $estimate_total_bytes,
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
     * @param int                 $stop_after_bytes Stop when scanned bytes reach this total (0 = disabled).
     * @return array{count:int,bytes:int,reached_threshold:bool,reached_file_threshold:bool,reached_byte_threshold:bool}
     */
    private static function scan_manifest_stats( array $directories, int $stop_after_files, int $stop_after_bytes = 0 ): array {
        $count = 0;
        $bytes = 0;
        $reached = false;
        $reached_files = false;
        $reached_bytes = false;

        $stop_after_files = max( 1, $stop_after_files );
        $stop_after_bytes = max( 0, $stop_after_bytes );

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
                    if ( $stop_after_bytes > 0 && $bytes >= $stop_after_bytes ) {
                        $reached = true;
                        $reached_bytes = true;
                        break 2;
                    }
                    if ( $count >= $stop_after_files ) {
                        $reached = true;
                        $reached_files = true;
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
            'reached_file_threshold' => $reached_files,
            'reached_byte_threshold' => $reached_bytes,
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
    /**
     * Sum bytes from manifest entries (best-effort).
     *
     * @param array<int,array<string,mixed>> $manifest Manifest entries.
     * @return int
     */
    private static function sum_manifest_bytes( array $manifest ): int {
        $bytes = 0;
        foreach ( $manifest as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( isset( $entry['size'] ) ) {
                $size = (int) $entry['size'];
                if ( $size > 0 ) {
                    $bytes += $size;
                }
            }
        }
        return (int) $bytes;
    }

    /**
     * Compose a ZIP target path from a root target and a relative path.
     *
     * @param string $target   Target root ('' means site root in the ZIP).
     * @param string $relative Relative path within the scanned root.
     * @return string
     */
    private static function compose_target_path( string $target, string $relative ): string {
        $relative = ltrim( $relative, '/' );
        if ( '' === $relative ) {
            return '';
        }
        if ( '' === $target ) {
            return $relative;
        }
        return rtrim( $target, '/' ) . '/' . $relative;
    }

    /**
     * Normalize a ZIP entry name for comparisons.
     *
     * @param string $name Raw entry name.
     * @return string
     */
    private static function normalize_zip_entry_name( $name ): string {
        $name = str_replace( '\\', '/', (string) $name );
        $name = ltrim( $name, './' );
        $name = preg_replace( '#/+#', '/', (string) $name );
        return strtolower( (string) $name );
    }

    /**
     * Build a lookup index of normalized entry names from a ZipArchive instance.
     *
     * Iterating statIndex() is more reliable than locateName() alone on some shared hosts
     * with large archives (PHP/libzip quirks after ZipArchive::close()).
     *
     * @param ZipArchive $zip Open archive.
     * @return array{entries:array<string,bool>,prefixes:array<string,bool>,count:int}
     */
    private static function zip_archive_build_entry_index( ZipArchive $zip ): array {
        $entries  = [];
        $prefixes = [];
        $num      = isset( $zip->numFiles ) ? (int) $zip->numFiles : 0;

        for ( $i = 0; $i < $num; $i++ ) {
            $stat = $zip->statIndex( $i );
            if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
                continue;
            }

            $norm = self::normalize_zip_entry_name( (string) $stat['name'] );
            if ( '' === $norm ) {
                continue;
            }

            $entries[ $norm ] = true;

            if ( '/' === substr( $norm, -1 ) ) {
                $prefixes[ $norm ] = true;
                continue;
            }

            $parts = explode( '/', $norm );
            array_pop( $parts );
            if ( empty( $parts ) ) {
                continue;
            }

            $accum = '';
            foreach ( $parts as $part ) {
                $accum .= $part . '/';
                $prefixes[ $accum ] = true;
            }
        }

        return [
            'entries'  => $entries,
            'prefixes' => $prefixes,
            'count'    => $num,
        ];
    }

    /**
     * Whether the archive contains at least one file entry under a directory prefix.
     *
     * @param ZipArchive           $zip    Open archive.
     * @param string               $prefix Directory prefix (e.g. wp-admin/).
     * @param array<string,mixed>|null $index Optional prebuilt index from zip_archive_build_entry_index().
     * @return bool
     */
    private static function zip_archive_has_file_under_prefix( ZipArchive $zip, string $prefix, array &$index = null ): bool {
        $prefix = self::normalize_zip_entry_name( $prefix );
        if ( '' === $prefix ) {
            return false;
        }
        if ( '/' !== substr( $prefix, -1 ) ) {
            $prefix .= '/';
        }

        if ( null === $index ) {
            $index = self::zip_archive_build_entry_index( $zip );
        }

        foreach ( $index['entries'] as $entry => $_unused ) {
            if ( 0 === strpos( $entry, $prefix ) && '/' !== substr( $entry, -1 ) ) {
                return true;
            }
        }

        return ! empty( $index['prefixes'][ $prefix ] );
    }

    /**
     * Whether an opened ZipArchive contains an entry for the given relative path.
     *
     * Normalizes slashes and tries common libzip/Windows quirks so post-close verification
     * does not false-trigger a full repack.
     *
     * @param ZipArchive               $zip   Open archive.
     * @param string                   $name  Expected entry path (forward slashes, no leading slash).
     * @param array<string,mixed>|null $index Optional prebuilt index from zip_archive_build_entry_index().
     * @return bool
     */
    private static function zip_archive_has_entry( ZipArchive $zip, $name, array &$index = null ) {
        $name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
        if ( '' === $name ) {
            return false;
        }

        if ( null === $index ) {
            $index = self::zip_archive_build_entry_index( $zip );
        }

        $norm = self::normalize_zip_entry_name( $name );
        if ( isset( $index['entries'][ $norm ] ) ) {
            return true;
        }

        if ( false !== $zip->locateName( $name ) ) {
            return true;
        }
        // Some tooling stores names with a leading "./".
        if ( false !== $zip->locateName( './' . $name ) ) {
            return true;
        }
        if ( defined( 'ZipArchive::FL_NOCASE' ) ) {
            if ( false !== $zip->locateName( $name, ZipArchive::FL_NOCASE ) ) {
                return true;
            }
            if ( false !== $zip->locateName( './' . $name, ZipArchive::FL_NOCASE ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a required core file is present, falling back to "any file under its directory".
     *
     * @param ZipArchive               $zip   Open archive.
     * @param string                   $path  Expected entry path.
     * @param array<string,mixed>|null $index Optional prebuilt index.
     * @return bool
     */
    private static function zip_archive_has_core_path( ZipArchive $zip, string $path, array &$index = null ): bool {
        if ( self::zip_archive_has_entry( $zip, $path, $index ) ) {
            return true;
        }

        $dir = dirname( $path );
        if ( '.' === $dir || '' === $dir ) {
            return false;
        }

        return self::zip_archive_has_file_under_prefix( $zip, $dir . '/', $index );
    }

    /**
     * Verify the closed archive contains expected WordPress site root data.
     *
     * We require the archive to contain at least one file from each core directory:
     * - wp-admin/
     * - wp-includes/
     * - wp-content/
     *
     * And also key root files if they exist in the manifest:
     * - site config file
     * - index.php
     *
     * This is a post-close guard against hosts where ZipArchive reports success but the archive
     * ends up missing large portions of data.
     *
     * @param array $job Job state.
     * @return array{ok:bool,missing:int,checked:int,missing_samples:array<int,string>,roots:array<string,array<string,mixed>>,files:array<string,bool>}
     */
    private static function verify_archive_contains_wp_content( array $job ): array {
        $archive_path = isset( $job['archive_path'] ) ? (string) $job['archive_path'] : '';
        if ( '' === $archive_path || ! file_exists( $archive_path ) ) {
            return [
                'ok'              => false,
                'missing'         => 0,
                'checked'         => 0,
                'missing_samples' => [],
                'roots'           => [],
            ];
        }

        // NDJSON mode: avoid loading manifest.json into memory during verify.
        $ndjson = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' !== $ndjson && file_exists( $ndjson ) ) {
            $zip = new ZipArchive();
            $open_result = $zip->open( $archive_path );
            if ( true !== $open_result ) {
                $error_map = [
                    ZipArchive::ER_EXISTS => 'ER_EXISTS',
                    ZipArchive::ER_INCONS => 'ER_INCONS',
                    ZipArchive::ER_INVAL  => 'ER_INVAL',
                    ZipArchive::ER_MEMORY => 'ER_MEMORY',
                    ZipArchive::ER_NOENT  => 'ER_NOENT',
                    ZipArchive::ER_NOZIP  => 'ER_NOZIP',
                    ZipArchive::ER_OPEN   => 'ER_OPEN',
                    ZipArchive::ER_READ   => 'ER_READ',
                    ZipArchive::ER_SEEK   => 'ER_SEEK',
                ];
                $error_name = isset( $error_map[ $open_result ] ) ? $error_map[ $open_result ] : 'UNKNOWN';

            return [
                'ok'              => false,
                'missing'         => 0,
                'checked'         => 0,
                'missing_samples' => [],
                'roots'           => [],
                    'error'           => 'ZipArchive open failed: ' . $error_name . ' (' . (string) $open_result . ')',
                ];
            }

            $options = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];
            $is_subsite_export = ( function_exists( 'is_multisite' ) && is_multisite() && ! empty( $options['multisite_blog_id'] ) );

            // Required metadata files (match what we embed + what the restore pipeline expects).
            $required = [
                'meta.json',
                'package.json',
                'manifest.ndjson',
            ];
            if ( empty( $options['no_db'] ) ) {
                $required[] = 'database.ndjson';
            }

            // Structure requirements:
            // - Full-site export expects WordPress root dirs.
            // - Subsite-only export packs wp-content only, so don't require wp-admin/wp-includes.
            if ( $is_subsite_export ) {
                $required[] = 'wp-content/index.php';
            } else {
                $required[] = 'wp-admin/index.php';
                $required[] = 'wp-includes/version.php';
                $required[] = 'wp-content/index.php';
            }

            $missing = 0;
            $checked = 0;
            $missing_samples = [];
            $entry_index     = self::zip_archive_build_entry_index( $zip );

            foreach ( $required as $file ) {
                $checked++;
                $has_core = ( 0 === strpos( $file, 'wp-' ) )
                    ? self::zip_archive_has_core_path( $zip, $file, $entry_index )
                    : self::zip_archive_has_entry( $zip, $file, $entry_index );
                if ( ! $has_core ) {
                    $missing++;
                    if ( count( $missing_samples ) < 12 ) {
                        $missing_samples[] = $file;
                    }
                }
            }

            // Strong guard: detect hosts where ZipArchive::addFile() returns success but the final ZIP is missing most entries.
            // Compare ZIP entry count to expected totals and require presence of representative files (uploads/themes) when applicable.
            $expected_total_files = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
            $zip_num_files        = isset( $entry_index['count'] ) ? (int) $entry_index['count'] : ( isset( $zip->numFiles ) ? (int) $zip->numFiles : 0 );
            if ( $expected_total_files > 0 ) {
                // numFiles includes directories too; we only use it as a "too small" indicator.
                $min_expected = (int) max( 50, round( $expected_total_files * 0.50 ) );
                if ( $zip_num_files > 0 && $zip_num_files < $min_expected ) {
                    $missing++;
                    if ( count( $missing_samples ) < 12 ) {
                        $missing_samples[] = 'zip_entry_count_too_small:' . $zip_num_files . '<' . $min_expected;
                    }
                }
            }

            // If this is a full-site export and media/themes were not explicitly excluded, ensure at least one upload/theme file exists.
            if ( ! $is_subsite_export ) {
                $require_uploads = empty( $options['no_media'] );
                $require_themes  = empty( $options['no_themes'] );

                $needles = [];
                if ( $require_uploads ) {
                    $needles[] = 'wp-content/uploads/';
                }
                if ( $require_themes ) {
                    $needles[] = 'wp-content/themes/';
                }

                if ( ! empty( $needles ) ) {
                    // Stream-scan the job's manifest.ndjson for a small number of candidates per prefix.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming scan, file path is plugin-controlled
                    $mf = fopen( $ndjson, 'rb' );
                    if ( $mf ) {
                        $found = array_fill_keys( $needles, false );
                        $tries = 0;
                        while ( ! feof( $mf ) && $tries < 5000 ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- streaming scan
                            $line = fgets( $mf );
                            if ( false === $line ) {
                                break;
                            }
                            $line = trim( $line );
                            if ( '' === $line || '[' === $line || ']' === $line ) {
                                continue;
                            }
                            $row = json_decode( $line, true );
                            if ( ! is_array( $row ) || empty( $row['target'] ) ) {
                                continue;
                            }
                            $target = ltrim( (string) $row['target'], '/' );
                            foreach ( $needles as $prefix ) {
                                if ( ! $found[ $prefix ] && 0 === strpos( $target, $prefix ) ) {
                                    // Accept the prefix when ANY file exists under it; a single manifest sample may be
                                    // missing from disk or skipped during packing without indicating a broken archive.
                                    if ( ! self::zip_archive_has_file_under_prefix( $zip, $prefix, $entry_index ) ) {
                                        $missing++;
                                        if ( count( $missing_samples ) < 12 ) {
                                            $missing_samples[] = 'missing_prefix:' . $prefix;
                                        }
                                    }
                                    $found[ $prefix ] = true;
                                }
                            }
                            $tries++;
                            $all_found = true;
                            foreach ( $needles as $prefix ) {
                                if ( empty( $found[ $prefix ] ) ) {
                                    $all_found = false;
                                    break;
                                }
                            }
                            if ( $all_found ) {
                                break;
                            }
                        }
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after fopen
                        fclose( $mf );
                    }
                }
            }

            $added_files_job = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
            $min_entries     = $expected_total_files > 0 ? (int) max( 50, round( $expected_total_files * 0.50 ) ) : 50;
            $entries_ok        = ( $zip_num_files <= 0 ) || ( $zip_num_files >= $min_entries );
            $added_ratio_ok    = ( $expected_total_files <= 0 ) || ( $added_files_job >= (int) round( $expected_total_files * 0.95 ) );
            $has_meta          = self::zip_archive_has_entry( $zip, 'meta.json', $entry_index )
                && self::zip_archive_has_entry( $zip, 'package.json', $entry_index )
                && self::zip_archive_has_entry( $zip, 'manifest.ndjson', $entry_index );
            if ( empty( $options['no_db'] ) ) {
                $has_meta = $has_meta && self::zip_archive_has_entry( $zip, 'database.ndjson', $entry_index );
            }
            if ( $is_subsite_export ) {
                $core_prefixes_ok = self::zip_archive_has_file_under_prefix( $zip, 'wp-content/', $entry_index );
            } else {
                $core_prefixes_ok = self::zip_archive_has_file_under_prefix( $zip, 'wp-admin/', $entry_index )
                    && self::zip_archive_has_file_under_prefix( $zip, 'wp-includes/', $entry_index )
                    && self::zip_archive_has_file_under_prefix( $zip, 'wp-content/', $entry_index );
            }
            $structural_ok = $entries_ok && $added_ratio_ok && $has_meta && $core_prefixes_ok;

            $zip->close();

            return [
                'ok'              => ( 0 === $missing ),
                'structural_ok'   => $structural_ok,
                'missing'         => (int) $missing,
                'checked'         => (int) $checked,
                'missing_samples' => $missing_samples,
                'roots'           => [],
                'files'           => array_fill_keys( $required, true ),
                'zip_num_files'   => $zip_num_files,
            ];
        }

        // Core roots we must have for a full-site backup.
        $roots = [
            'wp-admin'    => [
                'prefix' => 'wp-admin/',
                'want'   => 0,
                'have'   => 0,
                'missing_samples' => [],
                'checked' => 0,
            ],
            'wp-includes' => [
                'prefix' => 'wp-includes/',
                'want'   => 0,
                'have'   => 0,
                'missing_samples' => [],
                'checked' => 0,
            ],
            'wp-content'  => [
                'prefix' => 'wp-content/',
                'want'   => 0,
                'have'   => 0,
                'missing_samples' => [],
                'checked' => 0,
            ],
        ];

        $site_config = 'wp-config' . '.php';
        $must_files  = [
            $site_config => false,
            'index.php'  => false,
        ];

        // Track whether root files exist in the manifest (so we can enforce locateName).
        foreach ( $manifest as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['target'] ) ) {
                continue;
            }
            $target = ltrim( (string) $entry['target'], '/' );
            if ( isset( $must_files[ $target ] ) ) {
                $must_files[ $target ] = true;
            }
        }

        // Build sample list: up to N per root, cap total.
        $per_root_limit = 6;
        $total_limit    = 30;
        $samples        = [];
        $picked_by_root = [];

        // One pass over manifest: count wants per root, and collect up to N samples per root.
        foreach ( $manifest as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['target'] ) ) {
                continue;
            }
            $target = ltrim( (string) $entry['target'], '/' );

            foreach ( $roots as $key => $root_info ) {
                $prefix = $root_info['prefix'];
                if ( 0 !== strpos( $target, $prefix ) ) {
                    continue;
                }

                $roots[ $key ]['want']++;

                $picked = isset( $picked_by_root[ $key ] ) ? (int) $picked_by_root[ $key ] : 0;
                if ( $picked < $per_root_limit && count( $samples ) < $total_limit ) {
                    $samples[] = [
                        'root'   => $key,
                        'target' => $target,
                    ];
                    $picked_by_root[ $key ] = $picked + 1;
                }
            }

            if ( count( $samples ) >= $total_limit ) {
                // Keep counting wants (above) for remaining entries, but stop collecting samples.
                continue;
            }
        }

        // Always require at least some wp-content coverage when available.
        if ( empty( $samples ) ) {
            return [
                'ok'              => false,
                'missing'         => 0,
                'checked'         => 0,
                'missing_samples' => [],
                'roots'           => $roots,
                'files'           => $must_files,
            ];
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path ) ) {
            return [
                'ok'              => false,
                'missing'         => 0,
                'checked'         => 0,
                'missing_samples' => [],
                'roots'           => $roots,
            ];
        }

        $missing         = 0;
        $checked         = 0;
        $missing_samples = [];
        $entry_index     = self::zip_archive_build_entry_index( $zip );

        foreach ( $samples as $sample ) {
            $target = $sample['target'];
            $root   = $sample['root'];
            $checked++;
            $roots[ $root ]['checked']++;

            if ( ! self::zip_archive_has_entry( $zip, $target, $entry_index ) ) {
                $missing++;
                if ( count( $missing_samples ) < 12 ) {
                    $missing_samples[] = $target;
                }
                if ( count( $roots[ $root ]['missing_samples'] ) < 5 ) {
                    $roots[ $root ]['missing_samples'][] = $target;
                }
                continue;
            }

            $roots[ $root ]['have']++;
        }

        // Verify root files if present in manifest.
        $files_ok = [
            $site_config => true,
            'index.php'  => true,
        ];
        foreach ( $must_files as $file => $present ) {
            if ( ! $present ) {
                continue;
            }
            $checked++;
            if ( ! self::zip_archive_has_entry( $zip, $file, $entry_index ) ) {
                $missing++;
                $files_ok[ $file ] = false;
                if ( count( $missing_samples ) < 12 ) {
                    $missing_samples[] = $file;
                }
            }
        }

        $zip_num_files = isset( $entry_index['count'] ) ? (int) $entry_index['count'] : ( isset( $zip->numFiles ) ? (int) $zip->numFiles : 0 );
        $zip->close();

        // Determine pass: require at least 1 sample found for each root that has manifest entries.
        $ok = true;
        foreach ( $roots as $key => $root_info ) {
            // Only enforce roots that actually exist in the manifest (want > 0).
            if ( (int) $root_info['want'] > 0 && (int) $root_info['have'] <= 0 ) {
                $ok = false;
                break;
            }
        }

        if ( ! $files_ok[ $site_config ] || ! $files_ok['index.php'] ) {
            $ok = false;
        }

        // Also ensure database/meta exist.
        $zip2 = new ZipArchive();
        $meta_index = null;
        if ( true === $zip2->open( $archive_path ) ) {
            if ( ! self::zip_archive_has_entry( $zip2, 'database.ndjson', $meta_index ) || ! self::zip_archive_has_entry( $zip2, 'meta.json', $meta_index ) ) {
                $ok = false;
            }
            $zip2->close();
        } else {
            $ok = false;
        }

        $expected_total_files = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
        $added_files_job      = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
        $min_entries          = $expected_total_files > 0 ? (int) max( 50, round( $expected_total_files * 0.50 ) ) : 50;
        $entries_ok           = ( $zip_num_files <= 0 ) || ( $zip_num_files >= $min_entries );
        $added_ratio_ok       = ( $expected_total_files <= 0 ) || ( $added_files_job >= (int) round( $expected_total_files * 0.95 ) );
        $core_prefixes_ok     = self::zip_archive_has_file_under_prefix( $zip, 'wp-admin/', $entry_index )
            && self::zip_archive_has_file_under_prefix( $zip, 'wp-includes/', $entry_index )
            && self::zip_archive_has_file_under_prefix( $zip, 'wp-content/', $entry_index );
        $structural_ok        = $entries_ok && $added_ratio_ok && $core_prefixes_ok;

        return [
            'ok'              => (bool) $ok,
            'structural_ok'   => $structural_ok,
            'missing'         => (int) $missing,
            'checked'         => (int) $checked,
            'missing_samples' => $missing_samples,
            'roots'           => $roots,
            'files'           => $must_files,
            'zip_num_files'   => $zip_num_files,
        ];
    }

    /**
     * Write a package.json file for a backup job (in job temp dir) and return its absolute path.
     *
     * @param array $job
     * @return string
     */
    private static function write_package_json_for_job( array $job ) {
        $tmp = isset( $job['temp_dir'] ) ? (string) $job['temp_dir'] : '';
        if ( '' === $tmp || ! is_dir( $tmp ) ) {
            throw new RuntimeException( esc_html__( 'Backup temp directory is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $path = trailingslashit( $tmp ) . 'package.json';

        $options = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];
        $data = [
            'plugin' => [
                'name'    => 'museder-restoreone',
                'version' => defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '',
                'build'   => defined( 'MUSEDER_RESTOREONE_BUILD_ID' ) ? MUSEDER_RESTOREONE_BUILD_ID : '',
            ],
            'generated_at_gmt' => gmdate( 'c' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- internal backup metadata
            'format' => [
                'archive'  => 'zip',
                'manifest' => 'ndjson',
                'embedded' => [ 'package.json', 'manifest.ndjson' ],
            ],
            'totals' => [
                'files' => isset( $job['total_files'] ) ? (int) $job['total_files'] : 0,
                'bytes' => isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0,
            ],
            'options' => $options,
            'site' => [
                'home_url'     => function_exists( 'home_url' ) ? home_url() : '',
                'is_multisite' => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
            ],
        ];

        $encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $encoded ) {
            throw new RuntimeException( esc_html__( 'Unable to encode backup metadata. Please restart the backup job.', 'museder-restoreone' ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- writing internal metadata file in plugin-controlled temp directory
        if ( false === file_put_contents( $path, $encoded ) ) {
            throw new RuntimeException( esc_html__( 'Unable to write backup metadata file. Please check directory permissions.', 'museder-restoreone' ) );
        }

        return $path;
    }

    /**
     * Embed package.json + manifest.ndjson into the archive after it has been closed.
     *
     * @param array $job
     * @return void
     */
    private static function embed_metadata_into_archive_after_close( array $job ) {
        $archive = isset( $job['archive_path'] ) ? (string) $job['archive_path'] : '';
        if ( '' === $archive || ! file_exists( $archive ) ) {
            throw new RuntimeException( esc_html__( 'Backup archive is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $ndjson = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' === $ndjson || ! file_exists( $ndjson ) ) {
            throw new RuntimeException( esc_html__( 'Backup manifest is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $package_path = self::write_package_json_for_job( $job );

        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive ) ) {
            throw new RuntimeException( esc_html__( 'Unable to reopen archive for metadata embedding. Please restart the backup job.', 'museder-restoreone' ) );
        }
        self::embed_metadata_into_open_zip( $zip, $job, $package_path, $ndjson );
        $zip->close();
    }

    /**
     * Embed backup metadata while the ZipArchive is still open (before close).
     *
     * This avoids the expensive "reopen huge ZIP" step during finalize on some hosts.
     *
     * @param array      $job Job state.
     * @param ZipArchive $zip Open ZipArchive instance.
     * @return void
     */
    public static function embed_metadata_into_archive_before_close( array $job, ZipArchive $zip ) {
        $ndjson = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' === $ndjson || ! file_exists( $ndjson ) ) {
            throw new RuntimeException( esc_html__( 'Backup manifest is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        $package_path = self::write_package_json_for_job( $job );
        self::embed_metadata_into_open_zip( $zip, $job, $package_path, $ndjson );
    }

    /**
     * Embed backup metadata files into an already-open ZipArchive instance.
     *
     * Used to avoid re-opening very large ZIP files during finalize, which can be slow and lead to timeouts.
     *
     * @param ZipArchive $zip          Open ZipArchive instance.
     * @param array      $job          Job state.
     * @param string     $package_path Path to temp package.json.
     * @param string     $ndjson       Path to manifest.ndjson.
     * @return void
     */
    private static function embed_metadata_into_open_zip( ZipArchive $zip, array $job, $package_path, $ndjson ) {
        if ( '' === (string) $package_path || ! file_exists( (string) $package_path ) ) {
            throw new RuntimeException( esc_html__( 'Backup metadata file is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }
        if ( '' === (string) $ndjson || ! file_exists( (string) $ndjson ) ) {
            throw new RuntimeException( esc_html__( 'Backup manifest is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        // Ensure these entries exist even if they were added in a prior attempt.
        $zip->addFile( (string) $package_path, 'package.json' );
        $zip->addFile( (string) $ndjson, 'manifest.ndjson' );
        $zip->setCompressionName( 'package.json', ZipArchive::CM_DEFLATE );
        $zip->setCompressionName( 'manifest.ndjson', ZipArchive::CM_DEFLATE );
    }

    public static function process_job_batch( array $job, $max_files = null, $max_bytes = null, $zip = null ) {
        self::optimize_runtime_environment();

        // Auto-calculate optimal batch size if not provided
        if ( null === $max_files || null === $max_bytes ) {
            $optimal = self::get_optimal_batch_size();
            $max_files = $max_files ?? $optimal['max_files'];
            $max_bytes = $max_bytes ?? $optimal['max_bytes'];
        }

        // Prefer low-memory, streamed manifest for packing.
        $job = self::ensure_manifest_ndjson_for_job( $job );
        $ndjson_file = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' === $ndjson_file || ! file_exists( $ndjson_file ) ) {
            throw new RuntimeException( esc_html__( 'Backup manifest is missing. Please restart the backup job.', 'museder-restoreone' ) );
        }

        // Totals should be set during preparing (manifest scan) or migration.
        $total   = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
        $pointer = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
        if ( $total <= 0 ) {
            $total = 1;
            $job['total_files'] = 1;
        }
        if ( ! isset( $job['total_bytes'] ) || (int) $job['total_bytes'] <= 0 ) {
                $job['total_bytes'] = 1;
        }

        $pointer = max( 0, min( $pointer, max( 1, (int) $total ) ) );

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

        // If there is nothing to pack (or we've already reached the end), defer finalize until the archive is closed.
        // Guard against "fake success": only allow finalize when we actually packed most files.
        if ( $total <= 0 || $pointer >= $total ) {
            $added_files   = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
            $skipped_files = isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0;
            $min_ratio     = 0.95;
            $total_bytes   = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
            $added_bytes   = isset( $job['added_bytes'] ) ? (int) $job['added_bytes'] : 0;
            $min_bytes_ratio = 0.70;

            // If we skipped anything, log a single summary once (helps diagnose 1-file edge cases without failing).
            if ( $skipped_files > 0 && empty( $job['skip_summary_logged'] ) ) {
                museder_restoreone_log( 'warning', 'Backup packing reached end pointer with skipped files.', [
                    'job_id'        => $job['id'] ?? '',
                    'total_files'   => $total,
                    'added_files'   => $added_files,
                    'skipped_files' => $skipped_files,
                    'total_bytes'   => $total_bytes,
                    'added_bytes'   => $added_bytes,
                    'skipped_bytes' => isset( $job['skipped_bytes'] ) ? (int) $job['skipped_bytes'] : 0,
                    'skip_reasons'  => $job['skip_reasons'] ?? [],
                    'samples'       => $job['diagnostic_samples'] ?? [],
                    'pack_method'   => $job['pack_method'] ?? '',
                ] );
                $job['skip_summary_logged'] = true;
            }

            if ( $total >= 1000 && $added_files < (int) round( $total * $min_ratio ) ) {
                museder_restoreone_log( 'error', 'Backup packing reached end pointer but too many files were skipped/blocked. Marking job failed to avoid incomplete archive.', [
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

            if ( $total_bytes >= 500 * 1024 * 1024 && $added_bytes < (int) round( $total_bytes * $min_bytes_ratio ) ) {
                museder_restoreone_log( 'error', 'Backup packing reached end pointer but too few bytes were added. Marking job failed to avoid incomplete archive.', [
                    'job_id'       => $job['id'] ?? '',
                    'total_files'  => $total,
                    'added_files'  => $added_files,
                    'total_bytes'  => $total_bytes,
                    'added_bytes'  => $added_bytes,
                    'skip_reasons' => $job['skip_reasons'] ?? [],
                    'samples'      => $job['diagnostic_samples'] ?? [],
                    'pack_method'  => $job['pack_method'] ?? '',
                ] );

                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: too much content could not be added to the archive on this host. Please check logs for details.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                return $job;
            }

            // Important: treat skipped files as "processed" so finalize doesn't loop forever on 1 missing/unreadable file.
            $job['processed_files'] = min(
                $total,
                max(
                    $added_files + $skipped_files,
                    (int) ( $job['processed_files'] ?? 0 )
                )
            );
            $job['status']          = 'running';
            $job['stage']           = 'finalizing';
            $job['message']         = __( 'Finalising backup archive…', 'museder-restoreone' );
            $job['needs_finalize']  = true;
            // If ZipArchive is already open in the job processor, it can embed metadata before close.
            if ( $zip instanceof ZipArchive ) {
                $job['finalize_step'] = 'verify';
            }
            return $job;
        }

        $batch = [];
        $bytes = 0;
        $index = $pointer;

        $manifest_offset = isset( $job['manifest_offset'] ) ? (int) $job['manifest_offset'] : 0;
        $batch_result = self::read_manifest_ndjson_batch( $ndjson_file, $manifest_offset, (int) $max_files, (int) $max_bytes );
        $entries = isset( $batch_result['entries'] ) && is_array( $batch_result['entries'] ) ? $batch_result['entries'] : [];
        $job['manifest_offset'] = isset( $batch_result['offset'] ) ? (int) $batch_result['offset'] : $manifest_offset;
        $eof = ! empty( $batch_result['eof'] );

        foreach ( $entries as $entry ) {
            if ( empty( $entry['path'] ) || empty( $entry['target'] ) ) {
                $job['attempted_files']++;
                $job['skipped_files']++;
                $job['skip_reasons']['invalid_entry'] = isset( $job['skip_reasons']['invalid_entry'] ) ? ( (int) $job['skip_reasons']['invalid_entry'] + 1 ) : 1;
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type'  => 'invalid_entry',
                        'entry' => is_array( $entry ) ? array_intersect_key( $entry, array_flip( [ 'path', 'target', 'size' ] ) ) : (string) $entry,
                    ];
                }
                continue;
            }

            $path = wp_normalize_path( (string) $entry['path'] );
            $job['attempted_files']++;

            if ( ! @file_exists( $path ) ) {
                $job['skipped_files']++;
                $job['skip_reasons']['missing_or_blocked'] = isset( $job['skip_reasons']['missing_or_blocked'] ) ? ( (int) $job['skip_reasons']['missing_or_blocked'] + 1 ) : 1;
                if ( isset( $entry['size'] ) && (int) $entry['size'] > 0 ) {
                    $job['skipped_bytes'] += (int) $entry['size'];
                }

                // Capture a small number of samples with error message for debugging.
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'missing_or_blocked',
                        'path' => $path,
                        'error' => self::probe_read_error( $path ),
                    ];
                }
                continue;
            }

            if ( ! @is_readable( $path ) ) {
                $job['skipped_files']++;
                $job['skip_reasons']['unreadable'] = isset( $job['skip_reasons']['unreadable'] ) ? ( (int) $job['skip_reasons']['unreadable'] + 1 ) : 1;
                if ( isset( $entry['size'] ) && (int) $entry['size'] > 0 ) {
                    $job['skipped_bytes'] += (int) $entry['size'];
                }
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'unreadable',
                        'path' => $path,
                        'error' => self::probe_read_error( $path ),
                    ];
                }
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
                if ( $manifest_size > 0 ) {
                    $job['skipped_bytes'] += $manifest_size;
                }
                if ( count( $job['diagnostic_samples'] ) < 20 ) {
                    $job['diagnostic_samples'][] = [
                        'type' => 'too_large',
                        'path' => $path,
                        'size' => $manifest_size,
                    ];
                }
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
                    if ( $actual_size > 0 ) {
                        $job['skipped_bytes'] += (int) $actual_size;
                    }
                    if ( count( $job['diagnostic_samples'] ) < 20 ) {
                        $job['diagnostic_samples'][] = [
                            'type' => 'too_large',
                            'path' => $path,
                            'size' => (int) $actual_size,
                        ];
                    }
                    continue;
                }
                $file_size = $actual_size > 0 ? $actual_size : 0;
            }
            
            $entry['path'] = $path;
            $entry['size'] = $file_size;
            $batch[]       = $entry;
            $bytes        += $file_size;
        }
        // Advance pointer by entries consumed from manifest (even if some are skipped later).
        $index = min( $total, $index + count( $entries ) );
        if ( $eof ) {
            $index = $total;
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
                $pack_method = museder_restoreone_can_use_ziparchive() ? 'ziparchive' : 'pclzip';
            }

            $append_results = self::with_runtime_exclusions(
                $job_options,
                function () use ( $job, $batch, $zip, $job_options, $pack_method ) {
                    if ( 'pclzip' === $pack_method ) {
                        $result = self::append_files_to_pclzip( $job['archive_path'], $batch );
                        // Normalize shape to match ZipArchive results.
                        if ( ! isset( $result['failed_entries'] ) ) {
                            $result['failed_entries'] = [];
                        }
                        if ( ! isset( $result['attempted'] ) ) {
                            $result['attempted'] = isset( $result['added'] ) ? (int) $result['added'] : 0;
                        }
                        return $result;
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
            museder_restoreone_log( 'warning', 'Fallback to PclZip executed for failed ZipArchive entries.', [
                'job_id' => $job['id'] ?? '',
                'failed' => $append_results['failed'] ?? 0,
                'fallback' => $append_results['fallback'],
            ] );
        }

        $job['pointer']         = $index;
        $job['processed_files'] = min( (int) ( ( $job['added_files'] ?? 0 ) + ( $job['skipped_files'] ?? 0 ) ), $total );

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
            museder_restoreone_log( 'info', 'Packing reached end pointer; running integrity guards.', [
                'job_id' => $job['id'] ?? '',
                'total_files' => (int) $total,
                'pointer' => (int) $job['pointer'],
                'added_files' => isset( $job['added_files'] ) ? (int) $job['added_files'] : 0,
                'skipped_files' => isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0,
                'total_bytes' => isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0,
                'added_bytes' => isset( $job['added_bytes'] ) ? (int) $job['added_bytes'] : 0,
                'pack_method' => $job['pack_method'] ?? '',
            ] );

            // Completion guard: if we reached the end pointer but did not actually pack most files,
            // fail the job to prevent a "fake success" archive.
            $added_files   = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
            $skipped_files = isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0;
            $min_ratio     = 0.95;
            $total_bytes   = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
            $added_bytes   = isset( $job['added_bytes'] ) ? (int) $job['added_bytes'] : 0;
            // Bytes guard helps catch cases where large directories (e.g. uploads) are missing but file-count looks OK.
            $min_bytes_ratio = 0.70;

            if ( $total >= 1000 && $added_files < (int) round( $total * $min_ratio ) ) {
                museder_restoreone_log( 'error', 'Backup packing finished with too many skipped/blocked files. Marking job failed to avoid incomplete archive.', [
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

            if ( $total_bytes >= 500 * 1024 * 1024 && $added_bytes < (int) round( $total_bytes * $min_bytes_ratio ) ) {
                museder_restoreone_log( 'error', 'Backup packing finished but too few bytes were added. Marking job failed to avoid incomplete archive.', [
                    'job_id'       => $job['id'] ?? '',
                    'total_files'  => $total,
                    'added_files'  => $added_files,
                    'total_bytes'  => $total_bytes,
                    'added_bytes'  => $added_bytes,
                    'skip_reasons' => $job['skip_reasons'] ?? [],
                    'samples'      => $job['diagnostic_samples'] ?? [],
                    'pack_method'  => $job['pack_method'] ?? '',
                ] );

                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: too much content could not be added to the archive on this host. Please check logs for details.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                return $job;
            }

            // Defer finalize until after ZipArchive::close() in the job processor.
            $job['status']          = 'running';
            $job['stage']           = 'finalizing';
            $job['message']         = __( 'Finalising backup archive…', 'museder-restoreone' );
            $job['needs_finalize']  = true;
            // If ZipArchive is already open in the job processor, it can embed metadata before close.
            if ( $zip instanceof ZipArchive ) {
                $job['finalize_step'] = 'verify';
            }
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
        // Finalize can be heavy on some hosts (ZipArchive metadata + verification).
        // To prevent repeated timeouts and endless "finalizing" loops, we split finalize into
        // small resumable steps: embed metadata -> verify -> mark completed.
        $finalize_step = isset( $job['finalize_step'] ) ? (string) $job['finalize_step'] : '';
        if ( '' === $finalize_step ) {
            $finalize_step = 'embed_meta';
        }

        // Legacy manifest_count is used by a downstream mismatch guard; default to 0 unless loaded.
        $manifest_count = 0;

        $total_files = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
        $total_bytes = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
        $added_files = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
        $added_bytes = isset( $job['added_bytes'] ) ? (int) $job['added_bytes'] : 0;

        // If already cancelled/failed, do not continue finalize work.
        if ( isset( $job['status'] ) && in_array( $job['status'], [ 'cancelled', 'failed' ], true ) ) {
            if ( isset( $job['needs_finalize'] ) ) {
                unset( $job['needs_finalize'] );
            }
            if ( isset( $job['finalize_step'] ) ) {
                unset( $job['finalize_step'] );
            }
            return $job;
        }

        // NDJSON packing: avoid loading entire manifest into memory during finalize.
        $ndjson = isset( $job['manifest_ndjson_file'] ) ? (string) $job['manifest_ndjson_file'] : '';
        if ( '' !== $ndjson && file_exists( $ndjson ) ) {
            $offset = isset( $job['manifest_offset'] ) ? (int) $job['manifest_offset'] : 0;
            $size   = @filesize( $ndjson ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- manifest size check, file path is plugin-controlled
            if ( is_numeric( $size ) && (int) $size > 0 && $offset > 0 && $offset < (int) $size ) {
                museder_restoreone_log( 'error', 'Finalize called before manifest.ndjson was fully consumed.', [
                    'job_id' => $job['id'] ?? '',
                    'offset' => $offset,
                    'size'   => (int) $size,
                ] );
                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: packing did not finish reading the file list. Please restart the backup job.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                return $job;
            }
        } else {
            // Legacy fallback: verify manifest integrity by loading it (may be memory-heavy on huge sites).
        $manifest       = self::load_manifest_for_job( $job );
        $manifest_count = is_array( $manifest ) ? count( $manifest ) : 0;

        // Auto-heal totals at finalize time too, in case placeholder values leaked into job state.
        if ( $total_files <= 1 && $manifest_count > 1 ) {
            $before = $total_files;
            $total_files = $manifest_count;
            $job['total_files'] = $total_files;
            museder_restoreone_log( 'warning', 'Repairing total_files from manifest_count during finalize.', [
                'job_id' => $job['id'] ?? '',
                'total_files_before' => (int) $before,
                'manifest_count' => $manifest_count,
            ] );
        }

        if ( $total_bytes <= 1 && is_array( $manifest ) && ! empty( $manifest ) ) {
            $before = $total_bytes;
            $total_bytes = (int) self::sum_manifest_bytes( $manifest );
            $total_bytes = max( 1, $total_bytes );
            $job['total_bytes'] = $total_bytes;
            museder_restoreone_log( 'warning', 'Repairing total_bytes from manifest entries during finalize.', [
                'job_id' => $job['id'] ?? '',
                'total_bytes_before' => (int) $before,
                'total_bytes' => (int) $total_bytes,
            ] );
            }
        }

        // Always log a high-level finalize snapshot for diagnostics (helps confirm which guards ran).
        museder_restoreone_log( 'info', 'Finalize snapshot (after close).', [
            'job_id' => $job['id'] ?? '',
            'total_files' => (int) $total_files,
            'added_files' => (int) $added_files,
            'total_bytes' => (int) $total_bytes,
            'added_bytes' => (int) $added_bytes,
            'pointer' => isset( $job['pointer'] ) ? (int) $job['pointer'] : 0,
            'processed_files' => isset( $job['processed_files'] ) ? (int) $job['processed_files'] : 0,
            'pack_method' => $job['pack_method'] ?? '',
            'finalize_step' => $finalize_step,
        ] );

        // Ensure we remain in finalizing until we either complete or fail/repack.
        $job['status']         = 'running';
        $job['stage']          = 'finalizing';
        $job['message']        = __( 'Finalising backup archive…', 'museder-restoreone' );
        $job['needs_finalize'] = true;

        // Step 1: Embed backup metadata files into the archive (AI1WM-like).
        // Run this in its own tick to avoid combined packing+embed timeouts.
        if ( 'embed_meta' === $finalize_step ) {
            try {
                self::embed_metadata_into_archive_after_close( $job );
            } catch ( Exception $e ) {
                museder_restoreone_log( 'error', 'Failed to embed backup metadata into archive.', [
                    'job_id' => $job['id'] ?? '',
                    'error'  => $e->getMessage(),
                ] );
                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = esc_html__( 'Backup failed: unable to write required metadata files into the archive. Please try again.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                if ( isset( $job['finalize_step'] ) ) {
                    unset( $job['finalize_step'] );
                }
                return $job;
            }

            $job['finalize_step'] = 'verify';
            return $job;
        }

        // Step 2: Post-close verification: ensure the archive actually contains wp-content data.
        // Run in a separate tick to avoid timeouts.
        if ( 'verify' === $finalize_step ) {
        $verify = self::verify_archive_contains_wp_content( $job );
        museder_restoreone_log( 'info', 'Archive verify snapshot (after close).', [
            'job_id'        => $job['id'] ?? '',
            'ok'            => $verify['ok'],
            'structural_ok' => ! empty( $verify['structural_ok'] ),
            'checked'       => $verify['checked'],
            'missing'       => $verify['missing'],
            'zip_num_files' => isset( $verify['zip_num_files'] ) ? (int) $verify['zip_num_files'] : 0,
            'samples'       => $verify['missing_samples'],
            'roots'         => $verify['roots'],
        ] );

        if ( empty( $verify['ok'] ) ) {
            if ( ! empty( $verify['structural_ok'] ) ) {
                museder_restoreone_log( 'warning', 'Archive verify reported missing entries but structural integrity checks passed; accepting archive.', [
                    'job_id'  => $job['id'] ?? '',
                    'missing' => $verify['missing'],
                    'samples' => $verify['missing_samples'],
                ] );
            } else {
                museder_restoreone_log( 'error', 'Archive verification failed; refusing to mark completed.', [
                    'job_id' => $job['id'] ?? '',
                    'verify' => $verify,
                ] );

                $job['status']  = 'failed';
                $job['stage']   = 'failed';
                $job['message'] = __( 'Backup failed: the archive could not be verified on this host. Please check logs for details.', 'museder-restoreone' );
                if ( isset( $job['needs_finalize'] ) ) {
                    unset( $job['needs_finalize'] );
                }
                if ( isset( $job['finalize_step'] ) ) {
                    unset( $job['finalize_step'] );
                }
                return $job;
            }
        }

            // Verification passed; proceed to completion guards + mark completed.
            $job['finalize_step'] = 'done';
        }

        if ( $total_files >= 1000 && $manifest_count > 0 && $manifest_count < (int) round( $total_files * 0.90 ) ) {
            museder_restoreone_log( 'error', 'Backup manifest mismatch at finalize; refusing to mark job completed.', [
                'job_id'          => $job['id'] ?? '',
                'total_files'     => $total_files,
                'manifest_count'  => $manifest_count,
                'added_files'     => $added_files,
                'added_bytes'     => $added_bytes,
                'skip_reasons'    => $job['skip_reasons'] ?? [],
                'samples'         => $job['diagnostic_samples'] ?? [],
                'manifest_file'   => $job['manifest_file'] ?? '',
                'pack_method'     => $job['pack_method'] ?? '',
            ] );

            $job['status']  = 'failed';
            $job['stage']   = 'failed';
            $job['message'] = __( 'Backup failed: file list could not be fully prepared on this host. Please check logs for details.', 'museder-restoreone' );
            if ( isset( $job['needs_finalize'] ) ) {
                unset( $job['needs_finalize'] );
            }
            return $job;
        }

        // Re-run completion guards here too (after the archive is closed) to prevent false success.
        if ( $total_files >= 1000 && $added_files < (int) round( $total_files * 0.95 ) ) {
            museder_restoreone_log( 'error', 'Finalize guard: too many files were not added; refusing to mark completed.', [
                'job_id'        => $job['id'] ?? '',
                'total_files'   => $total_files,
                'added_files'   => $added_files,
                'total_bytes'   => $total_bytes,
                'added_bytes'   => $added_bytes,
                'skip_reasons'  => $job['skip_reasons'] ?? [],
                'samples'       => $job['diagnostic_samples'] ?? [],
                'pack_method'   => $job['pack_method'] ?? '',
            ] );

            $job['status']  = 'failed';
            $job['stage']   = 'failed';
            $job['message'] = __( 'Backup failed: too many files could not be added to the archive on this host. Please check logs for details.', 'museder-restoreone' );
            if ( isset( $job['needs_finalize'] ) ) {
                unset( $job['needs_finalize'] );
            }
            return $job;
        }

        if ( $total_bytes >= 500 * 1024 * 1024 && $added_bytes < (int) round( $total_bytes * 0.70 ) ) {
            museder_restoreone_log( 'error', 'Finalize guard: too few bytes were added; refusing to mark completed.', [
                'job_id'        => $job['id'] ?? '',
                'total_files'   => $total_files,
                'added_files'   => $added_files,
                'total_bytes'   => $total_bytes,
                'added_bytes'   => $added_bytes,
                'skip_reasons'  => $job['skip_reasons'] ?? [],
                'samples'       => $job['diagnostic_samples'] ?? [],
                'pack_method'   => $job['pack_method'] ?? '',
            ] );

            $job['status']  = 'failed';
            $job['stage']   = 'failed';
            $job['message'] = __( 'Backup failed: too much content could not be added to the archive on this host. Please check logs for details.', 'museder-restoreone' );
            if ( isset( $job['needs_finalize'] ) ) {
                unset( $job['needs_finalize'] );
            }
            return $job;
        }

        if ( isset( $job['needs_finalize'] ) ) {
            unset( $job['needs_finalize'] );
        }
        if ( isset( $job['finalize_step'] ) ) {
            unset( $job['finalize_step'] );
        }

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

        $zip->addFile( $sql_path, 'database.ndjson' );
        $zip->addFile( $meta_path, 'meta.json' );
        // Default: keep DB/meta compressed.
        $zip->setCompressionName( 'database.ndjson', ZipArchive::CM_DEFLATE );
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
            museder_restoreone_log( 'error', 'Failed to build file manifest.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            throw $e;
        }
        
        $file_count = isset( $manifest['count'] ) ? (int) $manifest['count'] : 0;
        
        // Only use cache for larger sites (> 1000 files) where cache benefits outweigh overhead
        if ( $file_count < 1000 ) {
            // Small site: skip caching to reduce overhead
            museder_restoreone_log( 'info', 'Built file manifest (cache skipped for small site).', [
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
        
        $cache_key = 'museder_restoreone_manifest_' . md5( $directories_json );
        
        // Try to get cached manifest
        $cached = get_transient( $cache_key );
        
        if ( false !== $cached && isset( $cached['timestamp'] ) && isset( $cached['manifest'] ) ) {
            $age = time() - $cached['timestamp'];
            // Use cache if it's less than 5 minutes old
            if ( $age < 300 && is_array( $cached['manifest'] ) ) {
                museder_restoreone_log( 'info', 'Using cached file manifest.', [
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
        
        museder_restoreone_log( 'info', 'Built file manifest.', [
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
                        museder_restoreone_log( 'warning', 'File manifest limit reached, stopping scan.', [
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
                    $target_path = self::compose_target_path( (string) $target, (string) $relative );
                    if ( '' === $target_path ) {
                        continue;
                    }

                    $manifest[] = [
                        'path'   => $file_path,
                        'target' => $target_path,
                        'size'   => $file_size,
                    ];

                    if ( $file_size > 0 ) {
                        $bytes += $file_size;
                    }

                    $file_count++;
                }
            } catch ( Exception $e ) {
                museder_restoreone_log( 'error', 'Error scanning directory for manifest.', [
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
        if ( false === $contents ) {
            museder_restoreone_log( 'error', 'Failed to read backup manifest file.', [
                'manifest_file' => $path,
            ] );
            return [];
        }

        $decoded  = json_decode( $contents, true );

        if ( ! is_array( $decoded ) ) {
            $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown JSON error';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.filesystem_operations_filesize
            $manifest_size = @filesize( $path );
            museder_restoreone_log( 'error', 'Backup manifest JSON decode failed (truncated or invalid).', [
                'manifest_file' => $path,
                'json_error'    => $json_error,
                'file_size'     => $manifest_size,
            ] );
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

        if ( function_exists( 'museder_restoreone_require_pclzip' ) ) {
            museder_restoreone_require_pclzip();
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
        $added_files     = isset( $job['added_files'] ) ? (int) $job['added_files'] : 0;
        $skipped_files   = isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0;
        $pointer         = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;

        // Effective completion heuristic: treat skipped as processed; pointer is the ultimate source of truth for
        // how many manifest entries we have consumed.
        $effective_done = max( $processed_files, $pointer, $added_files + $skipped_files );

        if ( $total_files > 0 && $effective_done < $total_files ) {
            museder_restoreone_log( 'warning', 'Finalize requested before job finished packing. Continuing backup instead of completing.', [
                'total_files'     => $total_files,
                'processed_files' => $processed_files,
                'added_files'     => $added_files,
                'skipped_files'   => $skipped_files,
                'pointer'         => $pointer,
                'effective_done'  => $effective_done,
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
        if ( $skipped_files > 0 ) {
            /* translators: %d: number of files */
            $job['message'] = sprintf( esc_html__( 'Backup completed with %d file(s) skipped. Check logs for details.', 'museder-restoreone' ), $skipped_files );
        } else {
            $job['message'] = esc_html__( 'Backup completed successfully.', 'museder-restoreone' );
        }
        $job['processed_files'] = isset( $job['total_files'] ) ? (int) $job['total_files'] : $job['processed_files'];
        $job['processed_bytes'] = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : $job['processed_bytes'];

        $size = file_exists( $job['archive_path'] ) ? filesize( $job['archive_path'] ) : 0;

        // Update download_url with the final archive path
        if ( ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
            $job['download_url'] = museder_restoreone_get_download_url( $job['archive_path'] );
        }

        // Calculate duration if started_at exists
        $backup_completed_at = time();
        $backup_duration_seconds = 0;
        if ( isset( $job['started_at'] ) && is_numeric( $job['started_at'] ) ) {
            $backup_duration_seconds = $backup_completed_at - (int) $job['started_at'];
        }
        $job['completed_at'] = $backup_completed_at;
        $job['duration_seconds'] = $backup_duration_seconds;

        museder_restoreone_log( 'info', 'Backup job completed.', [
            'archive' => $job['archive_path'],
            'size'    => $size,
            'duration_seconds' => $backup_duration_seconds,
            'skipped_files' => $skipped_files,
            'skip_reasons'  => $job['skip_reasons'] ?? [],
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
        if ( ! empty( $job['options']['label'] ) ) {
            $backup_metadata['label'] = sanitize_text_field( $job['options']['label'] );
        }
        if ( museder_is_pro_active() ) {
            $backup_metadata['encrypted'] = ! empty( $job['options']['encrypt'] );
            $backup_metadata['cloud_destinations'] = $job['options']['cloud_destinations'] ?? [];
        }
        self::store_backup_metadata( basename( $job['archive_path'] ), $backup_metadata );

        if ( class_exists( 'Museder_Restoreone_Backup_Jobs' ) ) {
            Museder_Restoreone_Backup_Jobs::cleanup_job( $job );
        }

        return $job;
    }

    private static function export_database_with_php( $filepath, $options = [] ) {
        global $wpdb;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for writing DB export file, path validated and sanitized
        $handle = fopen( $filepath, 'wb' ); // Use binary mode for better performance
        if ( ! $handle ) {
            museder_restoreone_log( 'error', 'Unable to open database export file for writing.', [ 'path' => $filepath ] );
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

        $meta = [
            'type'            => 'meta',
            'format'          => 'museder_restoreone_db_ndjson',
            'format_version'  => 1,
            'generated_at_gmt'=> gmdate( 'c' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- GMT metadata
            'site_url'        => function_exists( 'home_url' ) ? home_url() : '',
            'table_prefix'    => isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '',
        ];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing DB export file
        fwrite( $handle, wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ) . "\n" );

        $tables = self::get_tables_for_export( $options );
        if ( empty( $tables ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
            fclose( $handle );
            museder_restoreone_log( 'warning', 'No database tables found for export.' );
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

            // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
            // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
            // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
            // Identifiers cannot be passed via wpdb::prepare(). We strictly sanitize + esc_sql() and then inline.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $safe_table ) . '`', ARRAY_N ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- safe: schema inspection for backup export; no schema changes executed
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( isset( $create[1] ) ) {
                $schema = [
                    'type'   => 'schema',
                    'table'  => $safe_table,
                    'create' => (string) $create[1],
                ];
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing DB export file
                fwrite( $handle, wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . "\n" );
            }

            // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
            // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
            // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $safe_table ) . '`' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier is strict-sanitized + esc_sql()
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $row_count === 0 ) {
                continue;
            }

            $offset = 0;
            while ( $offset < $row_count ) {
                // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
                // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
                // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT * FROM `' . esc_sql( $safe_table ) . '` LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier is strict-sanitized + esc_sql(); values prepared
                        (int) self::CHUNK_SIZE,
                        (int) $offset
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $line = [
                        'type'  => 'row',
                        'table' => $safe_table,
                        'row'   => $row,
                    ];
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for writing DB export file
                    fwrite( $handle, wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n" );
                }

                $offset += self::CHUNK_SIZE;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $handle );

        return true;
    }

    /**
     * Parse include/exclude table options from string/array.
     *
     * @param mixed $raw
     * @return array<string>
     */
    private static function parse_table_list_option( $raw ) {
        $items = [];
        if ( is_array( $raw ) ) {
            $items = $raw;
        } elseif ( is_string( $raw ) ) {
            $items = preg_split( '/[\r\n,]+/', $raw );
        }
        if ( empty( $items ) || ! is_array( $items ) ) {
            return [];
        }
        $items = array_map(
            static function ( $t ) {
                $t = trim( (string) $t );
                // Only allow safe table identifiers.
                $t = preg_replace( '/[^A-Za-z0-9_]/', '', $t );
                return $t;
            },
            $items
        );
        $items = array_values( array_unique( array_filter( $items ) ) );
        return $items;
    }

    /**
     * Returns tables for export, honoring include/exclude lists and filters.
     *
     * @param array $options
     * @return array<string>
     */
    private static function get_tables_for_export( array $options ) {
        $tables = self::get_tables();
        if ( empty( $tables ) ) {
            return [];
        }

        $include = self::parse_table_list_option( $options['include_db_tables'] ?? [] );
        $exclude = self::parse_table_list_option( $options['exclude_db_tables'] ?? [] );

        if ( ! empty( $include ) ) {
            $set = array_fill_keys( $include, true );
            $tables = array_values(
                array_filter(
                    $tables,
                    static function ( $t ) use ( $set ) {
                        $t = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $t );
                        return isset( $set[ $t ] );
                    }
                )
            );
        }

        if ( ! empty( $exclude ) ) {
            $set = array_fill_keys( $exclude, true );
            $tables = array_values(
                array_filter(
                    $tables,
                    static function ( $t ) use ( $set ) {
                        $t = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $t );
                        return ! isset( $set[ $t ] );
                    }
                )
            );
        }

        /**
         * Filter tables to export.
         *
         * @param array<string> $tables
         * @param array         $options
         */
        $tables = apply_filters( 'museder_restoreone_export_db_tables', $tables, $options );
        if ( ! is_array( $tables ) ) {
            $tables = [];
        }

        // Final sanitize.
        $tables = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $t ) {
                            return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $t );
                        },
                        $tables
                    )
                )
            )
        );

        return $tables;
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
        static $include_hash = null;

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

        // Include-only mode: cache key must incorporate include set, otherwise results will leak across jobs.
        $current_include_hash = '';
        if ( ! empty( self::$runtime_include_prefixes ) ) {
            $current_include_hash = md5( implode( '|', self::$runtime_include_prefixes ) );
        }
        if ( $include_hash !== $current_include_hash ) {
            $include_hash   = $current_include_hash;
            $exclusion_cache = [];
        }
        $cache_key = $include_hash ? ( $include_hash . '|' . $normalized ) : $normalized;

        // Quick check: cache lookup
        if ( isset( $exclusion_cache[ $cache_key ] ) ) {
            return $exclusion_cache[ $cache_key ];
        }

        // Quick check: basename (fastest)
        $basename = basename( $normalized );
        if ( in_array( $basename, $skip_basenames, true ) ) {
            $exclusion_cache[ $cache_key ] = true;
            return true;
        }

        if ( ! empty( self::$runtime_exclude_basenames ) && in_array( $basename, self::$runtime_exclude_basenames, true ) ) {
            $exclusion_cache[ $cache_key ] = true;
            return true;
        }

        // Include-only prefixes: if set, everything outside is skipped early.
        if ( ! empty( self::$runtime_include_prefixes ) ) {
            $allowed = false;
            foreach ( self::$runtime_include_prefixes as $inc ) {
                if ( '' !== $inc && 0 === strpos( $normalized, $inc ) ) {
                    $allowed = true;
                    break;
                }
            }
            if ( ! $allowed ) {
                $exclusion_cache[ $cache_key ] = true;
                return true;
            }
        }

        // Quick check: common exclusion patterns (before expensive operations)
        // Never include our own backup/temp/job artifacts (prevents recursive backups when scanning ABSPATH).
        if ( strpos( $normalized, '/uploads/museder-restoreone' ) !== false ) {
            $exclusion_cache[ $cache_key ] = true;
            return true;
        }

        // Also skip the current backup jobs dir if it is inside site root.
        if ( function_exists( 'museder_restoreone_get_jobs_dir' ) ) {
            $jobs_dir = museder_restoreone_get_jobs_dir();
            if ( ! empty( $jobs_dir ) ) {
                $jobs_dir = wp_normalize_path( trailingslashit( (string) $jobs_dir ) );
                if ( '' !== $jobs_dir && 0 === strpos( $normalized, $jobs_dir ) ) {
                    $exclusion_cache[ $cache_key ] = true;
                    return true;
                }
            }
        }

        // Check backup files in uploads directory
        if ( strpos( $normalized, '/uploads/' ) !== false && preg_match( '/\.(zip)$/i', $normalized ) ) {
            if ( strpos( $normalized, '/backups/' ) !== false || strpos( $normalized, '/museder-restoreone' ) !== false ) {
                $exclusion_cache[ $cache_key ] = true;
                return true;
            }
        }

        // Check against internal exclusions (most expensive, do last)
        $exclusions = self::get_internal_exclusions();
        foreach ( $exclusions as $excluded ) {
            if ( '' !== $excluded && 0 === strpos( $normalized, $excluded ) ) {
                $exclusion_cache[ $cache_key ] = true;
                return true;
            }
        }

        if ( ! empty( self::$runtime_exclude_prefixes ) ) {
            foreach ( self::$runtime_exclude_prefixes as $excluded ) {
                if ( '' !== $excluded && 0 === strpos( $normalized, $excluded ) ) {
                    $exclusion_cache[ $cache_key ] = true;
                    return true;
                }
            }
        }

        if ( ! empty( self::$runtime_exclude_patterns ) ) {
            foreach ( self::$runtime_exclude_patterns as $pattern ) {
                if ( '' !== $pattern && strpos( $normalized, $pattern ) !== false ) {
                    $exclusion_cache[ $cache_key ] = true;
                    return true;
                }
            }
        }

        // Cache negative result (limit cache size to prevent memory issues)
        if ( count( $exclusion_cache ) < 1000 ) {
            $exclusion_cache[ $cache_key ] = false;
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
        $paths = museder_restoreone_get_excluded_paths();
        
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
        $upload_dir = wp_upload_dir();
        $uploads_dir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        $uploads_dir = $uploads_dir ? wp_normalize_path( $uploads_dir ) : '';
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
                museder_restoreone_log( 'warning', 'Failed to scan uploads directory for exclusions.', [ 'error' => $e->getMessage() ] );
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
        $prev_includes  = self::$runtime_include_prefixes;

        $resolved = self::resolve_runtime_exclusions( $options );
        self::$runtime_exclude_prefixes  = $resolved['prefixes'];
        self::$runtime_exclude_patterns  = $resolved['patterns'];
        self::$runtime_exclude_basenames = $resolved['basenames'];
        self::$runtime_include_prefixes  = self::resolve_runtime_inclusions( $options );
        self::$runtime_backup_mode       = self::resolve_runtime_backup_mode( $options );

        try {
            return call_user_func( $callback );
        } finally {
            self::$runtime_exclude_prefixes  = $prev_prefixes;
            self::$runtime_exclude_patterns  = $prev_patterns;
            self::$runtime_exclude_basenames = $prev_basenames;
            self::$runtime_include_prefixes  = $prev_includes;
            self::$runtime_backup_mode       = $prev_mode;
        }
    }

    /**
     * Resolve runtime include-only prefixes based on options.
     *
     * @param array $options Backup options.
     * @return array<string>
     */
    private static function resolve_runtime_inclusions( array $options ) {
        $prefixes = [];

        if ( function_exists( 'is_multisite' ) && is_multisite() && ! empty( $options['multisite_blog_id'] ) ) {
            $blog_id = absint( $options['multisite_blog_id'] );
            if ( $blog_id > 0 ) {
                $upload_dir = wp_upload_dir();
                $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
                $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
                if ( '' === $uploads_basedir ) {
                    return $prefixes;
                }

                // Include only selected subsite uploads + common wp-content components.
                if ( $blog_id > 1 ) {
                    // Default multisite structure: .../uploads/sites/{blog_id}. Use basedir parent for portability.
                    $prefixes[] = wp_normalize_path( trailingslashit( dirname( $uploads_basedir ) ) . 'sites/' . $blog_id );
                } else {
                    $prefixes[] = wp_normalize_path( trailingslashit( $uploads_basedir ) );
                }

                if ( empty( $options['no_plugins'] ) ) {
                    $plugins_dir = function_exists( 'museder_restoreone_get_plugins_dir' ) ? museder_restoreone_get_plugins_dir() : '';
                    if ( '' !== $plugins_dir ) {
                        $prefixes[] = wp_normalize_path( trailingslashit( $plugins_dir ) );
                    }
                }
                if ( empty( $options['no_themes'] ) ) {
                    $prefixes[] = wp_normalize_path( trailingslashit( (string) get_theme_root() ) );
                }
                if ( empty( $options['no_muplugins'] ) ) {
                    $mu_dir = function_exists( 'museder_restoreone_get_mu_plugins_dir' ) ? museder_restoreone_get_mu_plugins_dir() : '';
                    if ( '' !== $mu_dir ) {
                        $prefixes[] = wp_normalize_path( trailingslashit( $mu_dir ) );
                    }
                }
                $lang_dir = function_exists( 'museder_restoreone_get_languages_dir' ) ? museder_restoreone_get_languages_dir() : '';
                if ( '' !== $lang_dir ) {
                    $prefixes[] = wp_normalize_path( trailingslashit( $lang_dir ) );
                }
            }
        }

        /**
         * Filter include-only prefixes (advanced).
         *
         * @param array<string> $prefixes
         * @param array         $options
         */
        $prefixes = apply_filters( 'museder_restoreone_backup_scope_include_prefixes', $prefixes, $options );
        if ( ! is_array( $prefixes ) ) {
            $prefixes = [];
        }

        $prefixes = array_values( array_unique( array_filter( array_map( 'wp_normalize_path', $prefixes ) ) ) );
        return $prefixes;
    }

    /**
     * Resolve runtime backup mode for the current request.
     *
     * @param array $options Backup options.
     * @return string balanced|fast
     */
    private static function resolve_runtime_backup_mode( array $options ) {
        $mode = isset( $options['backup_mode'] ) ? (string) $options['backup_mode'] : '';
        if ( '' === $mode && class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
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
        if ( '' === $smart && class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
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
        if ( '' === $custom && class_exists( 'Museder_Restoreone_Settings' ) ) {
            $settings = Museder_Restoreone_Settings::get_settings();
            $custom = isset( $settings['backup_custom_excludes'] ) ? (string) $settings['backup_custom_excludes'] : '';
        }
        if ( '' !== trim( $custom ) ) {
            $parsed = self::parse_custom_excludes( $custom );
            $prefixes  = array_merge( $prefixes, $parsed['prefixes'] );
            $patterns  = array_merge( $patterns, $parsed['patterns'] );
            $basenames = array_merge( $basenames, $parsed['basenames'] );
        }

        // Scope presets (AI1WM-like).
        if ( ! empty( $options['no_media'] ) ) {
            $upload_dir = wp_upload_dir();
            $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
            $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
            if ( '' !== $uploads_basedir ) {
                $prefixes[] = wp_normalize_path( trailingslashit( $uploads_basedir ) );
            }
        }
        if ( ! empty( $options['no_plugins'] ) ) {
            $plugins_dir = function_exists( 'museder_restoreone_get_plugins_dir' ) ? museder_restoreone_get_plugins_dir() : '';
            if ( '' !== $plugins_dir ) {
                $prefixes[] = wp_normalize_path( trailingslashit( $plugins_dir ) );
            }
        }
        if ( ! empty( $options['no_themes'] ) ) {
            $prefixes[] = wp_normalize_path( trailingslashit( (string) get_theme_root() ) );
        }
        if ( ! empty( $options['no_muplugins'] ) ) {
            $mu_dir = function_exists( 'museder_restoreone_get_mu_plugins_dir' ) ? museder_restoreone_get_mu_plugins_dir() : '';
            if ( '' !== $mu_dir ) {
                $prefixes[] = wp_normalize_path( trailingslashit( $mu_dir ) );
            }
        }
        if ( ! empty( $options['no_cache'] ) ) {
            $prefixes = array_merge( $prefixes, self::get_smart_exclude_prefixes() );
        }

        /**
         * Filter additional scope exclusion prefixes.
         *
         * @param array<string> $prefixes
         * @param array         $options
         */
        $prefixes = apply_filters( 'museder_restoreone_backup_scope_exclude_prefixes', $prefixes, $options );

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
        $upload_dir = wp_upload_dir();
        $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
        $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';

        $prefixes = [
            '' !== $content_dir ? trailingslashit( $content_dir . '/cache' ) : '',
            '' !== $content_dir ? trailingslashit( $content_dir . '/litespeed' ) : '',
            '' !== $content_dir ? trailingslashit( $content_dir . '/w3tc-cache' ) : '',
            '' !== $content_dir ? trailingslashit( $content_dir . '/wp-rocket-cache' ) : '',
        ];
        if ( '' !== $uploads_basedir ) {
            $prefixes[] = trailingslashit( $uploads_basedir . '/cache' );
        }

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
        $prefixes = apply_filters( 'museder_restoreone_smart_exclude_prefixes', $prefixes );

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
                $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
                if ( '' === $content_dir ) {
                    continue;
                }
                $line = wp_normalize_path( trailingslashit( $content_dir ) . substr( $line, strlen( 'wp-content/' ) ) );
            } elseif ( 0 === strpos( $line, 'uploads/' ) ) {
                $upload_dir = wp_upload_dir();
                $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
                $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
                if ( '' === $uploads_basedir ) {
                    continue;
                }
                $line = wp_normalize_path( trailingslashit( $uploads_basedir ) . substr( $line, strlen( 'uploads/' ) ) );
            } elseif ( 0 === strpos( $line, './' ) ) {
                $line = ltrim( $line, './' );
                $root = function_exists( 'museder_restoreone_get_wp_root_dir' ) ? (string) museder_restoreone_get_wp_root_dir() : '';
                if ( '' === $root ) {
                    continue;
                }
                $line = wp_normalize_path( trailingslashit( $root ) . $line );
            } elseif ( 0 !== strpos( $line, '/' ) && false === preg_match( '#^([a-zA-Z]:/|\\\\\\\\)#', $line ) ) {
                // Treat as relative to the WordPress install root.
                $root = function_exists( 'museder_restoreone_get_wp_root_dir' ) ? (string) museder_restoreone_get_wp_root_dir() : '';
                if ( '' === $root ) {
                    continue;
                }
                $line = wp_normalize_path( trailingslashit( $root ) . ltrim( $line, '/' ) );
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
                // Adjust memory limit for large backup/restore operations (WP recommended API).
                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                    wp_raise_memory_limit( 'admin' );
                }
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
        // WP.org submission hardening: no shell execution in the directory build.
        // Keep the method for backward compatibility with older code paths, but always fail safely.
        $output = is_string( $output ) ? $output : '';
        return false;
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
        Museder_Restoreone_Log_Handler::record_event( 'backup_result', $context, 'success' === $status ? 'info' : 'error' );
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
        $meta_file = museder_restoreone_get_backup_dir() . '/.backup-meta.json';
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
        $meta_file = museder_restoreone_get_backup_dir() . '/.backup-meta.json';
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
