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
                @unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled backup directory
            }
        }

        $directories = self::get_directory_map();

        $success = backup_lite_can_use_ziparchive()
            ? self::create_zip_bundle( $archive_path, $sql_path, $meta_path, $directories )
            : self::create_pclzip_bundle( $archive_path, $sql_path, $meta_path, $directories );

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
        backup_lite_log( 'info', 'Site backup completed.', [
            'archive' => $archive_path,
            'size'    => $size,
        ] );

        // Store backup metadata (for labels, etc.)
        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $options['label'] ) ) {
            self::store_backup_metadata( basename( $archive_path ), [
                'label' => sanitize_text_field( $options['label'] ),
                'encrypted' => ! empty( $options['encrypt'] ),
                'cloud_destinations' => $options['cloud_destinations'] ?? [],
            ] );
        }

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
        self::optimize_runtime_environment();

        $manifest = self::load_manifest_for_job( $job );
        $total    = isset( $job['total_files'] ) ? (int) $job['total_files'] : count( $manifest );
        $pointer  = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
        $pointer  = max( 0, min( $pointer, $total ) );

        if ( $total <= 0 || $pointer >= $total ) {
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

        if ( ! empty( $batch ) ) {
            self::append_files_to_zip( $job['archive_path'], $batch );
        }

        $job['pointer']         = $index;
        $job['processed_files'] = min( $index, $total );

        $total_bytes = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
        $current     = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
        $job['processed_bytes'] = min(
            max( $total_bytes, 1 ),
            max( $current, $current + (int) $bytes )
        );
        $job['status']  = 'running';
        $job['stage']   = 'packing';
        $job['message'] = __( 'Backup running…', 'museder-restoreone' );

        if ( $job['pointer'] >= $total ) {
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

        backup_lite_log( 'info', 'Backup job completed.', [
            'archive' => $job['archive_path'],
            'size'    => $size,
        ] );

        self::record_backup_event( 'success', [
            'file'       => $job['archive_path'],
            'size_bytes' => $size,
            'size_human' => size_format( $size, 2 ),
            'label'      => $job['options']['label'] ?? '',
        ] );

        if ( Backup_Lite_Pro::is_pro_active() && ! empty( $job['options']['label'] ) ) {
            self::store_backup_metadata( basename( $job['archive_path'] ), [
                'label'               => sanitize_text_field( $job['options']['label'] ),
                'encrypted'           => ! empty( $job['options']['encrypt'] ),
                'cloud_destinations'  => $job['options']['cloud_destinations'] ?? [],
            ] );
        }

        if ( class_exists( 'Backup_Lite_Backup_Jobs' ) ) {
            Backup_Lite_Backup_Jobs::cleanup_job( $job );
        }

        return $job;
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
     * Store backup metadata (labels, etc.).
     * 
     * @param string $filename Backup filename.
     * @param array  $metadata Metadata to store.
     * @return bool
     */
    private static function store_backup_metadata( $filename, $metadata ) {
        if ( ! Backup_Lite_Pro::is_pro_active() ) {
            return false;
        }

        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        $all_meta = [];

        if ( file_exists( $meta_file ) ) {
            $content = file_get_contents( $meta_file );
            $all_meta = json_decode( $content, true ) ?: [];
        }

        $all_meta[ $filename ] = $metadata;

        return false !== file_put_contents( $meta_file, wp_json_encode( $all_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
    }

    /**
     * Get backup metadata.
     * 
     * @param string $filename Backup filename.
     * @return array
     */
    public static function get_backup_metadata( $filename ) {
        if ( ! Backup_Lite_Pro::is_pro_active() ) {
            return [];
        }

        $meta_file = backup_lite_get_backup_dir() . '/.backup-meta.json';
        if ( ! file_exists( $meta_file ) ) {
            return [];
        }

        $content = file_get_contents( $meta_file );
        $all_meta = json_decode( $content, true ) ?: [];

        return $all_meta[ $filename ] ?? [];
    }
}
