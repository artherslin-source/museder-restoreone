<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Restore Approach B: destination profile, preflight blocks, and wp-config merge.
 */
class Museder_Restoreone_Restore_Preflight {

    const PROFILE_POPULATED = 'populated_wp';
    const PROFILE_FRESH     = 'fresh_wp';
    const PROFILE_EMPTY     = 'empty_shell';

    const ORDER_DB_THEN_FILES    = 'db_then_files';
    const ORDER_FILES_THEN_DB    = 'files_then_db';

    const MODE_CONFIG_BACKUP = 'backup';
    const MODE_CONFIG_KEEP   = 'keep';
    const MODE_CONFIG_MERGE  = 'merge';

    const SCOPE_FULL     = 'full';
    const SCOPE_CONTENT  = 'content_only';
    const SCOPE_DB_ONLY  = 'db_only';

    /**
     * Normalize restore options from UI / legacy fields.
     *
     * @param array<string, mixed> $options Raw options.
     * @return array<string, mixed>
     */
    public static function normalize_options( array $options ) {
        $options = is_array( $options ) ? $options : [];

        $scope = isset( $options['restore_scope'] ) ? sanitize_key( (string) $options['restore_scope'] ) : '';
        if ( '' === $scope && ! empty( $options['files_only'] ) ) {
            $scope = self::SCOPE_CONTENT;
        }
        if ( '' === $scope ) {
            $scope = self::SCOPE_FULL;
        }
        if ( ! in_array( $scope, [ self::SCOPE_FULL, self::SCOPE_CONTENT, self::SCOPE_DB_ONLY ], true ) ) {
            $scope = self::SCOPE_FULL;
        }
        $options['restore_scope'] = $scope;

        if ( self::SCOPE_DB_ONLY === $scope ) {
            $options['files_only'] = false;
        } elseif ( self::SCOPE_CONTENT === $scope ) {
            $options['files_only'] = false;
        }

        $mode = isset( $options['wp_config_mode'] ) ? sanitize_key( (string) $options['wp_config_mode'] ) : '';
        if ( ! in_array( $mode, [ self::MODE_CONFIG_BACKUP, self::MODE_CONFIG_KEEP, self::MODE_CONFIG_MERGE ], true ) ) {
            if ( ! empty( $options['skip_config'] ) ) {
                $mode = self::MODE_CONFIG_KEEP;
            } else {
                $mode = self::MODE_CONFIG_BACKUP;
            }
        }
        $options['wp_config_mode'] = $mode;
        $options['skip_config']    = ( self::MODE_CONFIG_KEEP === $mode );

        if ( ! isset( $options['pause_other_plugins'] ) ) {
            $options['pause_other_plugins'] = true;
        } else {
            $options['pause_other_plugins'] = ! empty( $options['pause_other_plugins'] );
        }

        $profile = isset( $options['restore_profile'] ) ? sanitize_key( (string) $options['restore_profile'] ) : '';
        if ( '' === $profile ) {
            $profile = self::detect_restore_profile();
        }
        $options['restore_profile'] = $profile;

        $order = isset( $options['restore_order'] ) ? sanitize_key( (string) $options['restore_order'] ) : '';
        if ( ! in_array( $order, [ self::ORDER_DB_THEN_FILES, self::ORDER_FILES_THEN_DB ], true ) ) {
            if ( in_array( $profile, [ self::PROFILE_FRESH, self::PROFILE_EMPTY ], true ) ) {
                $order = self::ORDER_FILES_THEN_DB;
            } else {
                $order = self::ORDER_DB_THEN_FILES;
            }
        }
        $options['restore_order'] = $order;

        if ( self::PROFILE_POPULATED === $profile ) {
            $options['auto_backup'] = true;
        }

        return $options;
    }

    /**
     * Detect destination site profile.
     *
     * @return string One of populated_wp, fresh_wp, empty_shell.
     */
    public static function detect_restore_profile() {
        $root = function_exists( 'museder_restoreone_get_wp_root_dir' ) ? wp_normalize_path( (string) museder_restoreone_get_wp_root_dir() ) : '';
        $has_core = ( '' !== $root && is_readable( $root . '/wp-includes/version.php' ) );

        if ( ! $has_core ) {
            return self::PROFILE_EMPTY;
        }

        global $wpdb;
        if ( isset( $wpdb->posts ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $post_count = (int) $wpdb->get_var(
                "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future','pending') AND post_type NOT IN ('revision','auto-draft','nav_menu_item')"
            );
            if ( $post_count > 0 ) {
                return self::PROFILE_POPULATED;
            }
        }

        $plugins = get_option( 'active_plugins', [] );
        $plugin_count = is_array( $plugins ) ? count( $plugins ) : 0;
        if ( $plugin_count > 2 ) {
            return self::PROFILE_POPULATED;
        }

        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [];
        $base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        if ( '' !== $base && is_dir( $base ) ) {
            $years = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );
            if ( is_array( $years ) ) {
                foreach ( $years as $dir ) {
                    $leaf = basename( $dir );
                    if ( is_numeric( $leaf ) && (int) $leaf > 2000 ) {
                        $files = glob( trailingslashit( $dir ) . '*/*' );
                        if ( is_array( $files ) && count( $files ) > 2 ) {
                            return self::PROFILE_POPULATED;
                        }
                    }
                }
            }
        }

        return self::PROFILE_FRESH;
    }

    /**
     * Run preflight checks before restore starts.
     *
     * @param string               $archive_path Absolute path to backup archive.
     * @param array<string, mixed> $options      Restore options (normalized in-place).
     * @return array{blocked:bool,message:string,profile:string,warnings:array<int,string>,options:array<string,mixed>,archive_has_core:bool}
     */
    public static function preflight( $archive_path, array $options ) {
        $options = self::normalize_options( $options );
        $profile = (string) $options['restore_profile'];
        $warnings = [];
        $blocked  = false;
        $message  = '';

        $has_core = false;
        if ( '' !== $archive_path && file_exists( $archive_path ) && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            $has_core = Museder_Restoreone_Restore_Service::zip_archive_has_wp_core( $archive_path );
        }

        if ( self::PROFILE_EMPTY === $profile && ! $has_core && self::SCOPE_CONTENT !== $options['restore_scope'] && self::SCOPE_FULL === $options['restore_scope'] ) {
            $blocked = true;
            $message = __( 'This backup contains only wp-content (no WordPress core). Install WordPress on this path first, or use a full-site backup archive.', 'museder-restoreone' );
        }

        if ( self::SCOPE_CONTENT === $options['restore_scope'] && self::PROFILE_EMPTY === $profile ) {
            $blocked = true;
            $message = __( 'Files-only (wp-content) restore requires an existing WordPress installation at the destination.', 'museder-restoreone' );
        }

        if ( self::PROFILE_POPULATED === $profile ) {
            $warnings[] = __( 'This site already has content. A pre-restore snapshot of the current site is required.', 'museder-restoreone' );
            if ( empty( $options['overwrite'] ) && self::SCOPE_FULL === $options['restore_scope'] ) {
                $blocked = true;
                $message = __( 'Full-site restore on a site with existing content requires enabling “Overwrite existing data”.', 'museder-restoreone' );
            }
        }

        if ( self::PROFILE_FRESH === $profile && self::SCOPE_FULL === $options['restore_scope'] ) {
            $warnings[] = __( 'After WordPress core files are restored, the backup database will replace the database created by the WordPress installer. This is expected for a fresh install.', 'museder-restoreone' );
        }

        if ( self::PROFILE_EMPTY === $profile && $has_core && self::SCOPE_FULL === $options['restore_scope'] ) {
            $warnings[] = __( 'No WordPress core detected yet. Restore will extract core from the archive first. Keep this page open or use the document-root bootstrap URL until completion.', 'museder-restoreone' );
        }

        $backup_wp = self::read_backup_wp_version_from_archive( $archive_path );
        $current_wp = get_bloginfo( 'version' );
        if ( '' !== $backup_wp && '' !== $current_wp && self::wp_versions_differ_significantly( $backup_wp, $current_wp ) ) {
            $warnings[] = sprintf(
                /* translators: 1: backup WP version, 2: current WP version */
                __( 'WordPress version mismatch: backup %1$s, this site %2$s. Core files will still be restored if included in the archive.', 'museder-restoreone' ),
                $backup_wp,
                $current_wp
            );
        }

        return [
            'blocked'           => $blocked,
            'message'           => $message,
            'profile'           => $profile,
            'warnings'          => $warnings,
            'options'           => $options,
            'archive_has_core'  => $has_core,
        ];
    }

    /**
     * Summary hints for Step 1 / Step 2 UI.
     *
     * @param string               $archive_path Archive path.
     * @param array<string, mixed> $options      Options (optional).
     * @return array<string, mixed>
     */
    public static function hints_for_summary( $archive_path, array $options = [] ) {
        $preflight = self::preflight( $archive_path, $options );
        $profile = $preflight['profile'];
        $suggest_files_first = in_array( $profile, [ self::PROFILE_FRESH, self::PROFILE_EMPTY ], true );

        return [
            'restore_profile'       => $profile,
            'restore_profile_label' => self::profile_label( $profile ),
            'default_restore_order' => $preflight['options']['restore_order'],
            'archive_has_core'      => $preflight['archive_has_core'],
            'preflight_warnings'    => $preflight['warnings'],
            'preflight_blocked'     => $preflight['blocked'],
            'preflight_message'     => $preflight['message'],
            'force_auto_backup'     => ( self::PROFILE_POPULATED === $profile ),
            'suggest_files_first'   => $suggest_files_first,
            'fresh_db_overwrite_notice' => ( self::PROFILE_FRESH === $profile )
                ? __( 'Recommended: restore files before the database. The backup database will overwrite tables created by the WordPress installer.', 'museder-restoreone' )
                : ( self::PROFILE_EMPTY === $profile
                    ? __( 'Recommended: restore files before the database. Use the bootstrap script in the site root if wp-admin is not available yet.', 'museder-restoreone' )
                    : '' ),
            'bootstrap_recommended' => ( self::PROFILE_EMPTY === $profile && ! empty( $preflight['archive_has_core'] ) ),
        ];
    }

    /**
     * @param string $profile Profile slug.
     * @return string
     */
    public static function profile_label( $profile ) {
        switch ( (string) $profile ) {
            case self::PROFILE_POPULATED:
                return __( 'Existing site (has content)', 'museder-restoreone' );
            case self::PROFILE_FRESH:
                return __( 'Fresh WordPress install', 'museder-restoreone' );
            case self::PROFILE_EMPTY:
                return __( 'No WordPress core detected', 'museder-restoreone' );
            default:
                return (string) $profile;
        }
    }

    /**
     * Initial pipeline stage after execute().
     *
     * @param array<string, mixed> $options Normalized options.
     * @return string Stage slug.
     */
    public static function initial_stage( array $options ) {
        if ( self::SCOPE_DB_ONLY === $options['restore_scope'] ) {
            return 'restore-extract-db';
        }
        if ( ! empty( $options['files_only'] ) || self::SCOPE_CONTENT === $options['restore_scope'] ) {
            return 'restore-files';
        }
        if ( self::ORDER_FILES_THEN_DB === $options['restore_order'] ) {
            return 'restore-files';
        }
        return 'restore-extract-db';
    }

    /**
     * Whether wp-config.php should be skipped during ZIP site-root extract.
     *
     * @param array<string, mixed> $options Job options.
     * @return bool
     */
    public static function should_skip_wp_config_in_zip( array $options ) {
        $mode = isset( $options['wp_config_mode'] ) ? (string) $options['wp_config_mode'] : self::MODE_CONFIG_BACKUP;
        return self::MODE_CONFIG_KEEP === $mode;
    }

    /**
     * Apply wp-config policy after site-root files are restored.
     *
     * @param string               $job_id  Job ID.
     * @param array<string, mixed> $options Job options.
     * @param string               $zip_path Archive path.
     * @return bool True on success.
     */
    public static function apply_wp_config_policy( $job_id, array $options, $zip_path ) {
        $mode = isset( $options['wp_config_mode'] ) ? (string) $options['wp_config_mode'] : self::MODE_CONFIG_BACKUP;
        if ( self::MODE_CONFIG_KEEP === $mode ) {
            return true;
        }

        $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
        $site_root   = '' !== $content_dir ? wp_normalize_path( (string) dirname( $content_dir ) ) : '';
        if ( '' === $site_root ) {
            return false;
        }

        $dest_config = trailingslashit( $site_root ) . 'wp-config.php';
        $job_dir     = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::ensure_job_tmp_directory( $job_id ) : '';
        $backup_tmp  = trailingslashit( $job_dir ) . 'wp-config-from-archive.php';

        if ( self::MODE_CONFIG_MERGE === $mode ) {
            if ( ! file_exists( $backup_tmp ) && '' !== $zip_path && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                Museder_Restoreone_Restore_Service::extract_zip_entry_to_path( $zip_path, 'wp-config.php', $backup_tmp );
            }
            if ( ! file_exists( $backup_tmp ) ) {
                return false;
            }
            $merged = self::merge_wp_config_files( $dest_config, $backup_tmp );
            if ( ! $merged ) {
                return false;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return false !== file_put_contents( $dest_config, $merged );
        }

        return file_exists( $dest_config );
    }

    /**
     * Merge: DB_* from destination, remainder from backup file.
     *
     * @param string $dest_config Path to destination wp-config.php (may exist).
     * @param string $backup_config Path to backup wp-config.php.
     * @return string|false Merged file contents or false.
     */
    public static function merge_wp_config_files( $dest_config, $backup_config ) {
        if ( ! is_readable( $backup_config ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $merged = file_get_contents( $backup_config );
        if ( false === $merged ) {
            return false;
        }

        $db_keys = [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE' ];
        if ( is_readable( $dest_config ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $dest_body = file_get_contents( $dest_config );
            if ( is_string( $dest_body ) ) {
                foreach ( $db_keys as $key ) {
                    if ( preg_match( "/define\\s*\\(\\s*['\"]" . preg_quote( $key, '/' ) . "['\"]\\s*,\\s*([^)]+)\\)/i", $dest_body, $m ) ) {
                        $replacement = "define( '" . $key . "', " . trim( $m[1] ) . " )";
                        if ( preg_match( "/define\\s*\\(\\s*['\"]" . preg_quote( $key, '/' ) . "['\"]/i", $merged ) ) {
                            $merged = preg_replace( "/define\\s*\\(\\s*['\"]" . preg_quote( $key, '/' ) . "['\"]\\s*,\\s*[^)]+\\)/i", $replacement, $merged, 1 );
                        } else {
                            $merged = preg_replace( '/(<\?php)/i', "<?php\n" . $replacement . ';', $merged, 1 );
                        }
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * @param string $archive_path ZIP path.
     * @return string WP version from meta.json or ''.
     */
    protected static function read_backup_wp_version_from_archive( $archive_path ) {
        if ( '' === $archive_path || ! file_exists( $archive_path ) || ! class_exists( 'ZipArchive' ) ) {
            return '';
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $archive_path ) ) {
            return '';
        }
        $meta_raw = $zip->getFromName( 'meta.json' );
        if ( false === $meta_raw ) {
            for ( $i = 0; $i < min( 200, $zip->numFiles ); $i++ ) {
                $name = $zip->getNameIndex( $i );
                if ( is_string( $name ) && false !== stripos( $name, 'meta.json' ) && substr( $name, -9 ) === 'meta.json' ) {
                    $meta_raw = $zip->getFromIndex( $i );
                    break;
                }
            }
        }
        $zip->close();
        if ( ! is_string( $meta_raw ) || '' === $meta_raw ) {
            return '';
        }
        $meta = json_decode( $meta_raw, true );
        return is_array( $meta ) && ! empty( $meta['wp_version'] ) ? (string) $meta['wp_version'] : '';
    }

    /**
     * @param string $backup_ver Backup version.
     * @param string $current_ver Current version.
     * @return bool
     */
    public static function wp_versions_differ_significantly( $backup_ver, $current_ver ) {
        $b = explode( '.', preg_replace( '/[^0-9.].*$/', '', $backup_ver ) );
        $c = explode( '.', preg_replace( '/[^0-9.].*$/', '', $current_ver ) );
        return ( isset( $b[0], $c[0] ) && (int) $b[0] !== (int) $c[0] ) || ( isset( $b[1], $c[1] ) && abs( (int) $b[1] - (int) $c[1] ) >= 2 );
    }
}
