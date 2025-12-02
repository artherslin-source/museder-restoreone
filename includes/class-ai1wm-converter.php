<?php
/**
 * All-in-One WP Migration Backup Converter
 * 
 * Converts All-in-One WP Migration backup files to Museder RestoreOne format
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_AI1WM_Converter {

    /**
     * Detect if a backup file is from All-in-One WP Migration
     *
     * @param string $file_path Path to the backup file
     * @return bool
     */
    public static function is_ai1wm_backup( $file_path ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return false;
        }

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        
        // Check for .wpress extension
        if ( 'wpress' === $ext ) {
            return true;
        }

        // Check ZIP files for All-in-One structure
        if ( 'zip' === $ext ) {
            return self::check_ai1wm_zip_structure( $file_path );
        }

        return false;
    }

    /**
     * Check if ZIP file has All-in-One WP Migration structure
     *
     * @param string $file_path Path to ZIP file
     * @return bool
     */
    protected static function check_ai1wm_zip_structure( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return false;
        }

        // All-in-One typically has these patterns:
        // - database.sql or database.sql.gz
        // - plugins/, themes/, uploads/ directories
        // - Or a restore-package structure
        
        $has_database = false;
        $has_content = false;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( false === $name ) {
                continue;
            }

            // Check for database file
            if ( preg_match( '#^.*database\.sql(\.gz)?$#i', $name ) ) {
                $has_database = true;
            }

            // Check for typical All-in-One structure
            if ( preg_match( '#^(plugins|themes|uploads|mu-plugins)/#i', $name ) ) {
                $has_content = true;
            }

            // Check for restore-package structure
            if ( preg_match( '#^restore-package/#i', $name ) ) {
                $has_content = true;
                $has_database = true; // Assume database exists in restore-package
            }

            // If we found both indicators, it's likely All-in-One
            if ( $has_database && $has_content ) {
                $zip->close();
                return true;
            }
        }

        $zip->close();
        return false;
    }

    /**
     * Convert All-in-One WP Migration backup to Museder RestoreOne format
     *
     * @param string $source_file Path to All-in-One backup file
     * @param string $output_file Optional output file path. If not provided, creates one automatically.
     * @return array{success:bool, message:string, file?:string, error?:string}
     */
    public static function convert( $source_file, $output_file = null ) {
        if ( ! file_exists( $source_file ) || ! is_readable( $source_file ) ) {
            return [
                'success' => false,
                'message' => __( 'Source backup file not found or unreadable.', 'museder-restoreone' ),
                'error'   => 'file_not_found',
            ];
        }

        if ( ! self::is_ai1wm_backup( $source_file ) ) {
            return [
                'success' => false,
                'message' => __( 'File does not appear to be an All-in-One WP Migration backup.', 'museder-restoreone' ),
                'error'   => 'not_ai1wm_format',
            ];
        }

        $ext = strtolower( pathinfo( $source_file, PATHINFO_EXTENSION ) );
        
        if ( 'wpress' === $ext ) {
            return self::convert_wpress( $source_file, $output_file );
        } elseif ( 'zip' === $ext ) {
            return self::convert_zip( $source_file, $output_file );
        }

        return [
            'success' => false,
            'message' => __( 'Unsupported All-in-One backup format.', 'museder-restoreone' ),
            'error'   => 'unsupported_format',
        ];
    }

    /**
     * Convert .wpress file to Museder RestoreOne format
     *
     * @param string $source_file Path to .wpress file
     * @param string $output_file Optional output file path
     * @return array{success:bool, message:string, file?:string, error?:string}
     */
    protected static function convert_wpress( $source_file, $output_file = null ) {
        // .wpress files are gzip-compressed tar archives
        // They can be extracted directly using tar command, no conversion needed
        // This method returns false to indicate no conversion is performed,
        // but the restore process will handle .wpress extraction directly
        
        backup_lite_log( 'info', 'WPRESS format detected. Direct extraction will be attempted during restore (no conversion needed).', [ 'file' => basename( $source_file ) ] );

        return [
            'success' => false,
            'message' => __( '.wpress files can be restored directly without conversion. The restore process will extract the file using tar command.', 'museder-restoreone' ),
            'error'   => 'wpress_no_conversion_needed',
        ];
    }

    /**
     * Convert All-in-One ZIP backup to Museder RestoreOne format
     *
     * @param string $source_file Path to All-in-One ZIP file
     * @param string $output_file Optional output file path
     * @return array{success:bool, message:string, file?:string, error?:string}
     */
    protected static function convert_zip( $source_file, $output_file = null ) {
        // Optimize runtime environment for large file conversion
        self::optimize_runtime_environment();
        
        // Check file size - warn if very large
        $file_size = file_exists( $source_file ) ? filesize( $source_file ) : 0;
        $large_file_threshold = 500 * 1024 * 1024; // 500MB
        
        if ( $file_size > $large_file_threshold ) {
            backup_lite_log( 'info', 'Large file conversion started, this may take some time.', [
                'file' => basename( $source_file ),
                'size' => size_format( $file_size, 2 ),
            ] );
        }
        
        $temp_dir = backup_lite_create_temp_dir( 'ai1wm_convert' );
        if ( ! $temp_dir || ! is_dir( $temp_dir ) ) {
            return [
                'success' => false,
                'message' => __( 'Unable to create temporary directory for conversion.', 'museder-restoreone' ),
                'error'   => 'temp_dir_failed',
            ];
        }

        try {
            // Step 1: Extract source ZIP
            backup_lite_log( 'info', 'Extracting All-in-One backup for conversion.', [ 'file' => basename( $source_file ) ] );
            
            $extract_dir = trailingslashit( $temp_dir ) . 'source';
            backup_lite_ensure_directory( $extract_dir );
            
            $extract_result = self::extract_archive( $source_file, $extract_dir );
            if ( ! $extract_result['success'] ) {
                backup_lite_delete_directory( $temp_dir );
                return [
                    'success' => false,
                    'message' => __( 'Failed to extract All-in-One backup archive.', 'museder-restoreone' ),
                    'error'   => 'extract_failed',
                ];
            }

            // Step 2: Analyze structure and reorganize
            $reorganized_dir = trailingslashit( $temp_dir ) . 'reorganized';
            backup_lite_ensure_directory( $reorganized_dir );
            
            $reorganize_result = self::reorganize_structure( $extract_dir, $reorganized_dir );
            if ( ! $reorganize_result['success'] ) {
                backup_lite_delete_directory( $temp_dir );
                return [
                    'success' => false,
                    'message' => $reorganize_result['message'],
                    'error'   => $reorganize_result['error'],
                ];
            }

            // Step 3: Create metadata file
            $meta_result = self::create_metadata( $reorganized_dir, $source_file );
            if ( ! $meta_result['success'] ) {
                backup_lite_delete_directory( $temp_dir );
                return [
                    'success' => false,
                    'message' => __( 'Failed to create metadata file.', 'museder-restoreone' ),
                    'error'   => 'metadata_failed',
                ];
            }

            // Step 4: Create output ZIP
            if ( empty( $output_file ) ) {
                $backup_dir = backup_lite_get_backup_dir();
                $base_name = pathinfo( $source_file, PATHINFO_FILENAME );
                $output_file = trailingslashit( $backup_dir ) . sanitize_file_name( $base_name . '-converted.zip' );
                $output_file = wp_unique_filename( $backup_dir, basename( $output_file ) );
            }

            $zip_result = self::create_output_zip( $reorganized_dir, $output_file );
            
            // Cleanup
            backup_lite_delete_directory( $temp_dir );

            if ( ! $zip_result['success'] ) {
                return [
                    'success' => false,
                    'message' => __( 'Failed to create output backup file.', 'museder-restoreone' ),
                    'error'   => 'zip_creation_failed',
                ];
            }

            backup_lite_log( 'info', 'Successfully converted All-in-One backup to Museder RestoreOne format.', [
                'source' => basename( $source_file ),
                'output' => basename( $output_file ),
            ] );

            return [
                'success' => true,
                'message' => __( 'Backup converted successfully.', 'museder-restoreone' ),
                'file'    => $output_file,
            ];

        } catch ( Exception $e ) {
            backup_lite_delete_directory( $temp_dir );
            backup_lite_log( 'error', 'Exception during All-in-One conversion.', [ 'error' => $e->getMessage() ] );
            
            return [
                'success' => false,
                /* translators: %s: Error message from exception. */
                'message' => sprintf( __( 'Conversion failed: %s', 'museder-restoreone' ), $e->getMessage() ),
                'error'   => 'conversion_exception',
            ];
        }
    }

    /**
     * Reorganize All-in-One structure to Museder RestoreOne format
     *
     * @param string $source_dir Extracted All-in-One backup directory
     * @param string $target_dir Target directory for reorganized structure
     * @return array{success:bool, message?:string, error?:string}
     */
    protected static function reorganize_structure( $source_dir, $target_dir ) {
        // Look for database.sql file
        $database_file = self::locate_database_file( $source_dir );
        if ( ! $database_file ) {
            return [
                'success' => false,
                'message' => __( 'Database file not found in All-in-One backup.', 'museder-restoreone' ),
                'error'   => 'database_not_found',
            ];
        }

        // Copy database.sql to target root
        $target_db = trailingslashit( $target_dir ) . 'database.sql';
        if ( ! copy( $database_file, $target_db ) ) {
            return [
                'success' => false,
                'message' => __( 'Failed to copy database file.', 'museder-restoreone' ),
                'error'   => 'database_copy_failed',
            ];
        }

        // Reorganize wp-content structure
        $wp_content_target = trailingslashit( $target_dir ) . 'wp-content';
        backup_lite_ensure_directory( $wp_content_target );

        // All-in-One structure variations:
        // 1. Direct plugins/, themes/, uploads/ at root
        // 2. restore-package/plugins/, restore-package/themes/, etc.
        // 3. wp-content/ already exists
        
        $content_source = null;
        
        // Check for existing wp-content directory
        if ( is_dir( trailingslashit( $source_dir ) . 'wp-content' ) ) {
            $content_source = trailingslashit( $source_dir ) . 'wp-content';
        }
        // Check for restore-package structure
        elseif ( is_dir( trailingslashit( $source_dir ) . 'restore-package' ) ) {
            $restore_package = trailingslashit( $source_dir ) . 'restore-package';
            
            // Check if restore-package has wp-content
            if ( is_dir( trailingslashit( $restore_package ) . 'wp-content' ) ) {
                $content_source = trailingslashit( $restore_package ) . 'wp-content';
            } else {
                // Reorganize individual directories from restore-package
                $directories_to_merge = [ 'plugins', 'themes', 'uploads', 'mu-plugins' ];
                
                foreach ( $directories_to_merge as $dir ) {
                    $source_path = trailingslashit( $restore_package ) . $dir;
                    $target_path = trailingslashit( $wp_content_target ) . $dir;
                    
                    if ( is_dir( $source_path ) ) {
                        self::recursive_copy( $source_path, $target_path );
                    }
                }
                
                // Copy other files/directories from restore-package that might be needed
                $iterator = new DirectoryIterator( $restore_package );
                foreach ( $iterator as $item ) {
                    if ( $item->isDot() || $item->isFile() ) {
                        continue;
                    }
                    
                    $name = $item->getFilename();
                    if ( ! in_array( $name, $directories_to_merge, true ) && 'wp-content' !== $name ) {
                        $source_path = $item->getPathname();
                        $target_path = trailingslashit( $wp_content_target ) . $name;
                        
                        if ( is_dir( $source_path ) ) {
                            self::recursive_copy( $source_path, $target_path );
                        }
                    }
                }
            }
        }
        // Check for direct plugins/, themes/, uploads/ at root
        else {
            $directories_to_merge = [ 'plugins', 'themes', 'uploads', 'mu-plugins' ];
            $has_any = false;
            
            foreach ( $directories_to_merge as $dir ) {
                $source_path = trailingslashit( $source_dir ) . $dir;
                if ( is_dir( $source_path ) ) {
                    $target_path = trailingslashit( $wp_content_target ) . $dir;
                    self::recursive_copy( $source_path, $target_path );
                    $has_any = true;
                }
            }
            
            if ( ! $has_any ) {
                return [
                    'success' => false,
                    'message' => __( 'Could not locate wp-content files in All-in-One backup.', 'museder-restoreone' ),
                    'error'   => 'content_not_found',
                ];
            }
        }

        // If we found a wp-content directory, copy it directly
        if ( $content_source && is_dir( $content_source ) ) {
            self::recursive_copy( $content_source, $wp_content_target );
        }

        return [
            'success' => true,
        ];
    }

    /**
     * Locate database file in extracted backup
     *
     * @param string $dir Directory to search
     * @return string|null Path to database file or null if not found
     */
    protected static function locate_database_file( $dir ) {
        // Common locations for database file
        $possible_locations = [
            trailingslashit( $dir ) . 'database.sql',
            trailingslashit( $dir ) . 'database.sql.gz',
            trailingslashit( $dir ) . 'restore-package/database.sql',
            trailingslashit( $dir ) . 'restore-package/database.sql.gz',
        ];

        foreach ( $possible_locations as $path ) {
            if ( file_exists( $path ) && is_readable( $path ) ) {
                // If it's a .gz file, we'd need to decompress it
                // For now, return the path and handle .gz in import
                return $path;
            }
        }

        // Search recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $name = $file->getFilename();
                if ( preg_match( '#^database\.sql(\.gz)?$#i', $name ) ) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Create metadata file for converted backup
     *
     * @param string $dir Directory containing reorganized backup
     * @param string $source_file Original source file path
     * @return array{success:bool}
     */
    protected static function create_metadata( $dir, $source_file ) {
        $meta_path = trailingslashit( $dir ) . 'backup-lite-meta.json';
        
        // Try to extract metadata from original backup
        $meta = [
            'plugin_version'    => defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : 'unknown',
            'wordpress_version' => 'unknown', // Will be determined from database if possible
            'generated_at'      => backup_lite_local_time( 'c' ),
            'generated_at_gmt'  => gmdate( 'c' ),
            'site_url'          => function_exists( 'home_url' ) ? home_url() : '',
            'php_version'       => PHP_VERSION,
            'source_format'     => 'ai1wm',
            'original_file'     => basename( $source_file ),
            'converted_at'      => backup_lite_local_time( 'c' ),
            'converted_at_gmt'  => gmdate( 'c' ),
        ];

        // Try to extract site URL from database file
        $db_file = trailingslashit( $dir ) . 'database.sql';
        if ( file_exists( $db_file ) ) {
            $site_url = self::extract_site_url_from_sql( $db_file );
            if ( $site_url ) {
                $meta['original_site_url'] = $site_url;
            }
        }

        $encoded = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        
        return [
            'success' => false !== file_put_contents( $meta_path, $encoded ),
        ];
    }

    /**
     * Extract site URL from SQL file
     *
     * @param string $sql_file Path to SQL file
     * @return string|null Site URL or null if not found
     */
    protected static function extract_site_url_from_sql( $sql_file ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for reading SQL file, path validated and sanitized
        $handle = fopen( $sql_file, 'rb' );
        if ( ! $handle ) {
            return null;
        }

        // Read first 1MB to find site URL
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- required for reading SQL file sample
        $sample = fread( $handle, 1048576 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $handle );

        // Look for siteurl option
        if ( preg_match( "#INSERT\s+INTO\s+[^`]+`wp_options`[^;]+siteurl[^,]+,\s*'([^']+)'#i", $sample, $matches ) ) {
            return $matches[1];
        }

        // Alternative pattern
        if ( preg_match( "#siteurl.*?'([^']+)'#i", $sample, $matches ) ) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Create output ZIP file in Museder RestoreOne format
     *
     * @param string $source_dir Directory with reorganized backup
     * @param string $output_file Output ZIP file path
     * @return array{success:bool}
     */
    protected static function create_output_zip( $source_dir, $output_file ) {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $output_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                self::add_directory_to_zip( $zip, $source_dir, '' );
                $zip->close();
                return [ 'success' => true ];
            }
        }

        // Fallback to PclZip
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $pcl = new PclZip( $output_file );
        $result = $pcl->create( $source_dir, PCLZIP_OPT_REMOVE_PATH, $source_dir );
        
        return [
            'success' => ( false !== $result && 0 !== $result ),
        ];
    }

    /**
     * Add directory recursively to ZIP archive
     *
     * @param ZipArchive $zip ZIP archive handle
     * @param string $dir Directory to add
     * @param string $zip_path Path within ZIP
     */
    protected static function add_directory_to_zip( $zip, $dir, $zip_path ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $file_count = 0;
        $max_files_before_check = 100; // Check execution time every 100 files

        foreach ( $iterator as $item ) {
            $file_path = $item->getPathname();
            $relative_path = str_replace( trailingslashit( $dir ), '', $file_path );
            
            if ( ! empty( $zip_path ) ) {
                $relative_path = trailingslashit( $zip_path ) . $relative_path;
            }

            if ( $item->isDir() ) {
                $zip->addEmptyDir( $relative_path );
            } else {
                // Use addFile for all files to avoid loading large files into memory
                // ZipArchive::addFile() is more memory efficient than addFromString()
                $zip->addFile( $file_path, $relative_path );
            }
            
            // Periodically check execution time and reset if needed
            $file_count++;
            if ( $file_count % $max_files_before_check === 0 ) {
                // @plugin-check: okay - needed for long running backup/restore operations
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
                if ( function_exists( 'set_time_limit' ) ) {
                    @set_time_limit( 600 ); // Reset to 10 minutes
                }
            }
        }
    }

    /**
     * Extract archive (ZIP or other format)
     *
     * @param string $archive_path Path to archive
     * @param string $destination Destination directory
     * @return array{success:bool}
     */
    protected static function extract_archive( $archive_path, $destination ) {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $archive_path ) ) {
                $zip->extractTo( $destination );
                $zip->close();
                return [ 'success' => true ];
            }
        }

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $pcl = new PclZip( $archive_path );
        $options = [
            PCLZIP_OPT_PATH => $destination,
            PCLZIP_OPT_REPLACE_NEWER => true,
        ];
        $result = $pcl->extract( $options );
        
        return [
            'success' => ( false !== $result && 0 !== $result ),
        ];
    }

    /**
     * Optimize runtime environment for conversion operations
     */
    protected static function optimize_runtime_environment() {
        static $optimized = false;

        if ( $optimized ) {
            return;
        }

        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        // @plugin-check: okay - needed for long running backup/restore operations
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 ); // 10 minutes for conversion
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            @wp_raise_memory_limit( 'admin' );
        }

        $optimized = true;
    }

    /**
     * Recursively copy directory
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     */
    protected static function recursive_copy( $source, $destination ) {
        if ( ! is_dir( $source ) ) {
            return;
        }

        if ( ! is_dir( $destination ) ) {
            wp_mkdir_p( $destination );
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ( $item->isDir() ) {
                if ( ! is_dir( $target ) ) {
                    wp_mkdir_p( $target );
                }
            } else {
                wp_mkdir_p( dirname( $target ) );
                copy( $item->getPathname(), $target );
            }
        }
    }
}

