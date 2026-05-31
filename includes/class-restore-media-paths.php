<?php
/**
 * Reconcile wp-content/uploads paths after restore when DB references UTF-8 paths
 * but the backup archive contains ASCII-only filenames on disk.
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Post-restore media path reconciliation (DB ↔ disk).
 */
class Museder_Restoreone_Restore_Media_Paths {

    /**
     * @var array<string, true>|null
     */
    private static $rel_path_index = null;

    /**
     * @var array<string, array<int, string>>|null dir fingerprint => list of rel paths
     */
    private static $dir_fingerprint_index = null;

    /**
     * Time-sliced cleanup step for stage_cleanup_and_finish().
     *
     * @param array<string, mixed> $cleanup         Cleanup checkpoint (mutated).
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      microtime( true ) at slice start.
     * @param string               $job_id          Restore job id (logging).
     * @return array{done:bool,message:string}
     */
    public static function reconcile_sliced( array &$cleanup, $timeout_seconds, $start_time, $job_id ) {
        $timeout_seconds = max( 1, (int) $timeout_seconds );
        $phase           = isset( $cleanup['media_paths_phase'] ) ? (string) $cleanup['media_paths_phase'] : 'build_index';

        if ( ! apply_filters( 'museder_restoreone_reconcile_upload_paths', true, $job_id, $cleanup ) ) {
            $cleanup['media_paths_phase'] = 'done';
            self::log_reconcile_done( $job_id, $cleanup, 'skipped_filter' );
            return self::progress_result( true );
        }

        $uploads = wp_upload_dir();
        $basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        if ( '' === $basedir || ! is_dir( $basedir ) ) {
            $cleanup['media_paths_phase'] = 'done';
            self::log_reconcile_done( $job_id, $cleanup, 'uploads_missing' );
            return self::progress_result( true );
        }

        if ( 'build_index' === $phase ) {
            self::$rel_path_index          = [];
            self::$dir_fingerprint_index   = [];
            self::build_uploads_index( $basedir );
            $cleanup['media_paths_phase']                 = 'scan_meta';
            $cleanup['media_paths_last_meta_id']          = 0;
            $cleanup['media_paths_fixed']                 = 0;
            $cleanup['media_paths_unresolved']            = 0;
            $cleanup['media_paths_scanned']               = 0;
            $cleanup['media_paths_pairs']                 = [];
            $cleanup['media_paths_unresolved_samples']    = [];
            $cleanup['media_paths_verify_last_meta_id']   = 0;
            $cleanup['media_paths_verify_missing']        = 0;
            $cleanup['media_paths_verify_checked']        = 0;
            return self::progress_result( false );
        }

        if ( 'scan_meta' === $phase ) {
            $result = self::scan_attached_file_meta_sliced( $basedir, $cleanup, $timeout_seconds, $start_time, $job_id );
            if ( ! empty( $result['done'] ) ) {
                $cleanup['media_paths_phase']               = 'expand_pairs';
                $cleanup['media_paths_content_sub']         = 'postmeta';
                $cleanup['media_paths_content_last_meta_id'] = 0;
                $cleanup['media_paths_content_last_post_id'] = 0;
                $cleanup['media_paths_content_scanned']     = 0;
                $cleanup['media_paths_content_pairs']       = 0;
            }
            return self::progress_result( false );
        }

        if ( 'expand_pairs' === $phase ) {
            $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];
            $cleanup['media_paths_pairs'] = self::expand_upload_path_pairs( $pairs, $basedir );
            $cleanup['media_paths_phase'] = 'scan_content_refs';
            return self::progress_result( false );
        }

        if ( 'scan_content_refs' === $phase ) {
            $result = self::scan_content_refs_sliced( $basedir, $cleanup, $timeout_seconds, $start_time, $job_id );
            if ( ! empty( $result['done'] ) ) {
                $cleanup['media_paths_phase'] = 'apply_pairs';
            }
            return self::progress_result( false );
        }

        if ( 'apply_pairs' === $phase ) {
            $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];
            $pairs = self::sort_pairs_longest_first( self::filter_valid_path_pairs( $pairs ) );
            $cleanup['media_paths_pairs'] = $pairs;
            if ( ! empty( $pairs ) && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                $apply_result = Museder_Restoreone_Restore_Service::apply_path_replacements_for_restore_sliced(
                    $pairs,
                    $cleanup,
                    $timeout_seconds,
                    $start_time
                );
                if ( empty( $apply_result['done'] ) ) {
                    return self::progress_result( false );
                }
            }
            $cleanup['media_paths_widget_urls_fixed'] = self::sync_widget_media_image_urls( $basedir );
            self::log_reconcile_done( $job_id, $cleanup, 'apply_pairs' );
            $cleanup['media_paths_phase'] = 'verify';
            return self::progress_result( false );
        }

        if ( 'verify' === $phase ) {
            $result = self::verify_attached_files_sliced( $basedir, $cleanup, $timeout_seconds, $start_time );
            if ( empty( $result['done'] ) ) {
                return self::progress_result( false );
            }
            $verify_missing = isset( $cleanup['media_paths_verify_missing'] ) ? (int) $cleanup['media_paths_verify_missing'] : 0;
            if ( $verify_missing > 0 && function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log(
                    'warning',
                    'MEDIA_PATHS_VERIFY_WARN',
                    [
                        'job_id'  => $job_id,
                        'missing' => $verify_missing,
                        'checked' => isset( $cleanup['media_paths_verify_checked'] ) ? (int) $cleanup['media_paths_verify_checked'] : 0,
                    ]
                );
            }
            self::log_reconcile_done( $job_id, $cleanup, 'verify_complete' );
            $cleanup['media_paths_phase'] = 'done';
            return self::progress_result( true );
        }

        $cleanup['media_paths_phase'] = 'done';
        self::log_reconcile_done( $job_id, $cleanup, 'noop' );
        return self::progress_result( true );
    }

    /**
     * @param string               $job_id  Restore job id.
     * @param array<string, mixed> $cleanup Cleanup checkpoint (mutated).
     * @param string               $reason  Log reason slug.
     * @return void
     */
    private static function log_reconcile_done( $job_id, array &$cleanup, $reason ) {
        if ( ! empty( $cleanup['media_paths_logged'] ) ) {
            return;
        }
        $cleanup['media_paths_logged'] = 1;

        if ( ! function_exists( 'museder_restoreone_log' ) ) {
            return;
        }

        $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];

        museder_restoreone_log(
            'info',
            'MEDIA_PATHS_RECONCILE_DONE',
            [
                'job_id'     => $job_id,
                'reason'     => (string) $reason,
                'fixed'      => isset( $cleanup['media_paths_fixed'] ) ? (int) $cleanup['media_paths_fixed'] : 0,
                'unresolved' => isset( $cleanup['media_paths_unresolved'] ) ? (int) $cleanup['media_paths_unresolved'] : 0,
                'scanned'    => isset( $cleanup['media_paths_scanned'] ) ? (int) $cleanup['media_paths_scanned'] : 0,
                'pairs'      => count( $pairs ),
                'content_scanned' => isset( $cleanup['media_paths_content_scanned'] ) ? (int) $cleanup['media_paths_content_scanned'] : 0,
                'content_pairs'   => isset( $cleanup['media_paths_content_pairs'] ) ? (int) $cleanup['media_paths_content_pairs'] : 0,
                'widget_urls_fixed' => isset( $cleanup['media_paths_widget_urls_fixed'] ) ? (int) $cleanup['media_paths_widget_urls_fixed'] : 0,
            ]
        );
    }

    /**
     * Generic cleanup progress text (counts go to logs / job meta only).
     *
     * @param bool $done Slice complete for this step.
     * @return array{done:bool,message:string}
     */
    private static function progress_result( $done ) {
        return [
            'done'    => (bool) $done,
            'message' => __( 'Finalising restore…', 'museder-restoreone' ),
        ];
    }

    /**
     * @param string $basedir Uploads basedir.
     * @return void
     */
    public static function build_uploads_index( $basedir ) {
        $basedir = wp_normalize_path( (string) $basedir );
        $rel_index = [];
        $fp_index  = [];

        if ( ! is_dir( $basedir ) ) {
            self::$rel_path_index          = [];
            self::$dir_fingerprint_index   = [];
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $abs = wp_normalize_path( $file->getPathname() );
            if ( 0 !== strpos( $abs, $basedir ) ) {
                continue;
            }
            $rel = ltrim( substr( $abs, strlen( $basedir ) ), '/' );
            if ( '' === $rel ) {
                continue;
            }
            $rel_index[ $rel ] = true;
            $dir = dirname( $rel );
            if ( '.' === $dir ) {
                $dir = '';
            }
            $fp = self::ascii_fold_filename( basename( $rel ) );
            if ( '' === $fp ) {
                continue;
            }
            $key = ( '' !== $dir ? $dir . '/' : '' ) . $fp;
            if ( ! isset( $fp_index[ $key ] ) ) {
                $fp_index[ $key ] = [];
            }
            $fp_index[ $key ][] = $rel;
        }

        self::$rel_path_index        = $rel_index;
        self::$dir_fingerprint_index = $fp_index;
    }

    /**
     * @param string               $basedir Uploads basedir.
     * @param array<string, mixed> $cleanup Checkpoint.
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      Start time.
     * @return array{done:bool,message:string}
     */
    private static function scan_attached_file_meta_sliced( $basedir, array &$cleanup, $timeout_seconds, $start_time, $job_id = '' ) {
        global $wpdb;

        if ( null === self::$rel_path_index ) {
            self::build_uploads_index( $basedir );
        }

        $last_id = isset( $cleanup['media_paths_last_meta_id'] ) ? (int) $cleanup['media_paths_last_meta_id'] : 0;
        $batch   = 80;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                '_wp_attached_file',
                $last_id,
                $batch
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $rows ) ) {
            return self::progress_result( true );
        }

        $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];

        foreach ( $rows as $row ) {
            if ( ( microtime( true ) - $start_time ) >= $timeout_seconds ) {
                break;
            }

            $meta_id   = isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0;
            $post_id   = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $rel       = isset( $row['meta_value'] ) ? wp_normalize_path( (string) $row['meta_value'] ) : '';
            $last_id   = max( $last_id, $meta_id );
            $cleanup['media_paths_scanned'] = isset( $cleanup['media_paths_scanned'] ) ? (int) $cleanup['media_paths_scanned'] + 1 : 1;

            if ( '' === $rel || isset( self::$rel_path_index[ $rel ] ) ) {
                continue;
            }

            $abs = museder_restoreone_safe_path_join( $basedir, $rel );
            if ( '' !== $abs && file_exists( $abs ) ) {
                self::$rel_path_index[ $rel ] = true;
                continue;
            }

            $match = self::resolve_disk_relative_path( $rel, $post_id, $basedir, $job_id );
            if ( '' === $match || $match === $rel ) {
                $match = self::resolve_menu_icon_disk_path( $rel, $post_id, $basedir );
            }
            if ( '' === $match || $match === $rel ) {
                $cleanup['media_paths_unresolved'] = isset( $cleanup['media_paths_unresolved'] ) ? (int) $cleanup['media_paths_unresolved'] + 1 : 1;
                self::record_unresolved_sample( $cleanup, $rel );
                continue;
            }
            if ( ! self::is_valid_upload_path_pair( $rel, $match ) ) {
                $cleanup['media_paths_unresolved'] = isset( $cleanup['media_paths_unresolved'] ) ? (int) $cleanup['media_paths_unresolved'] + 1 : 1;
                self::record_unresolved_sample( $cleanup, $rel );
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_value' => $match ],
                [ 'meta_id' => $meta_id ],
                [ '%s' ],
                [ '%d' ]
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            self::rewrite_attachment_metadata_paths( $post_id, $rel, $match, $basedir, $job_id );

            self::$rel_path_index[ $match ] = true;
            self::append_upload_path_pair( $pairs, $rel, $match );

            $cleanup['media_paths_fixed'] = isset( $cleanup['media_paths_fixed'] ) ? (int) $cleanup['media_paths_fixed'] + 1 : 1;
        }

        $cleanup['media_paths_last_meta_id'] = $last_id;
        $cleanup['media_paths_pairs']       = self::dedupe_pairs( $pairs );

        return self::progress_result( false );
    }

    /**
     * Build summary for restore job meta after cleanup.
     *
     * @param array<string, mixed> $cleanup Cleanup checkpoint.
     * @return array<string, mixed>
     */
    public static function build_report_from_cleanup( array $cleanup ) {
        $samples = isset( $cleanup['media_paths_unresolved_samples'] ) && is_array( $cleanup['media_paths_unresolved_samples'] )
            ? $cleanup['media_paths_unresolved_samples']
            : [];

        return [
            'fixed'                => isset( $cleanup['media_paths_fixed'] ) ? (int) $cleanup['media_paths_fixed'] : 0,
            'unresolved'           => isset( $cleanup['media_paths_unresolved'] ) ? (int) $cleanup['media_paths_unresolved'] : 0,
            'scanned'              => isset( $cleanup['media_paths_scanned'] ) ? (int) $cleanup['media_paths_scanned'] : 0,
            'verify_checked'       => isset( $cleanup['media_paths_verify_checked'] ) ? (int) $cleanup['media_paths_verify_checked'] : 0,
            'verify_missing'       => isset( $cleanup['media_paths_verify_missing'] ) ? (int) $cleanup['media_paths_verify_missing'] : 0,
            'widget_urls_fixed'    => isset( $cleanup['media_paths_widget_urls_fixed'] ) ? (int) $cleanup['media_paths_widget_urls_fixed'] : 0,
            'samples_unresolved'   => $samples,
        ];
    }

    /**
     * Detect attachment DB paths that do not exist on disk but have a likely ASCII-folded match.
     *
     * @param int    $limit  Maximum attachment rows to scan.
     * @param string $job_id Optional job id for filters.
     * @return array<string, mixed>
     */
    public static function detect_upload_path_drift( $limit = 1000, $job_id = '' ) {
        global $wpdb;

        $limit = max( 1, min( 10000, (int) $limit ) );
        if ( ! isset( $wpdb->postmeta ) ) {
            return [
                'scanned'    => 0,
                'drift'      => 0,
                'missing'    => 0,
                'samples'    => [],
                'reason'     => 'wpdb_unavailable',
                'truncated'  => false,
            ];
        }

        $uploads = wp_upload_dir();
        $basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        if ( '' === $basedir || ! is_dir( $basedir ) ) {
            return [
                'scanned'    => 0,
                'drift'      => 0,
                'missing'    => 0,
                'samples'    => [],
                'reason'     => 'uploads_missing',
                'truncated'  => false,
            ];
        }

        self::build_uploads_index( $basedir );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d",
                '_wp_attached_file',
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $summary = [
            'scanned'    => 0,
            'drift'      => 0,
            'missing'    => 0,
            'samples'    => [],
            'reason'     => 'ok',
            'truncated'  => is_array( $rows ) && count( $rows ) >= $limit,
        ];

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return $summary;
        }

        foreach ( $rows as $row ) {
            $post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            $rel     = isset( $row['meta_value'] ) ? wp_normalize_path( (string) $row['meta_value'] ) : '';
            if ( '' === $rel ) {
                continue;
            }

            $summary['scanned']++;
            if ( isset( self::$rel_path_index[ $rel ] ) ) {
                continue;
            }

            $abs = museder_restoreone_safe_path_join( $basedir, $rel );
            if ( '' !== $abs && is_file( $abs ) ) {
                self::$rel_path_index[ $rel ] = true;
                continue;
            }

            $match = self::resolve_disk_relative_path( $rel, $post_id, $basedir, $job_id );
            if ( '' !== $match && $match !== $rel ) {
                $summary['drift']++;
                if ( count( $summary['samples'] ) < 10 ) {
                    $summary['samples'][] = [
                        'db'   => $rel,
                        'disk' => $match,
                    ];
                }
                continue;
            }

            $summary['missing']++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $cleanup Cleanup checkpoint (mutated).
     * @param string               $rel     Unresolved DB relative path.
     * @return void
     */
    private static function record_unresolved_sample( array &$cleanup, $rel ) {
        if ( ! isset( $cleanup['media_paths_unresolved_samples'] ) || ! is_array( $cleanup['media_paths_unresolved_samples'] ) ) {
            $cleanup['media_paths_unresolved_samples'] = [];
        }
        if ( count( $cleanup['media_paths_unresolved_samples'] ) >= 10 ) {
            return;
        }
        $rel = (string) $rel;
        foreach ( $cleanup['media_paths_unresolved_samples'] as $sample ) {
            if ( is_array( $sample ) && isset( $sample['db'] ) && (string) $sample['db'] === $rel ) {
                return;
            }
        }
        $cleanup['media_paths_unresolved_samples'][] = [ 'db' => $rel ];
    }

    /**
     * @param string               $basedir         Uploads basedir.
     * @param array<string, mixed> $cleanup         Cleanup checkpoint (mutated).
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      microtime( true ).
     * @return array{done:bool,message:string}
     */
    private static function verify_attached_files_sliced( $basedir, array &$cleanup, $timeout_seconds, $start_time ) {
        global $wpdb;

        $batch   = 100;
        $last_id = isset( $cleanup['media_paths_verify_last_meta_id'] ) ? (int) $cleanup['media_paths_verify_last_meta_id'] : 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                '_wp_attached_file',
                $last_id,
                $batch
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $rows ) ) {
            return [ 'done' => true ];
        }

        foreach ( $rows as $row ) {
            if ( ( microtime( true ) - $start_time ) >= $timeout_seconds ) {
                break;
            }

            $meta_id = isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0;
            $rel     = isset( $row['meta_value'] ) ? wp_normalize_path( (string) $row['meta_value'] ) : '';
            $last_id = max( $last_id, $meta_id );
            $cleanup['media_paths_verify_checked'] = isset( $cleanup['media_paths_verify_checked'] ) ? (int) $cleanup['media_paths_verify_checked'] + 1 : 1;

            if ( '' === $rel ) {
                continue;
            }

            $abs = museder_restoreone_safe_path_join( $basedir, $rel );
            if ( '' === $abs || ! is_file( $abs ) ) {
                $cleanup['media_paths_verify_missing'] = isset( $cleanup['media_paths_verify_missing'] ) ? (int) $cleanup['media_paths_verify_missing'] + 1 : 1;
            }
        }

        $cleanup['media_paths_verify_last_meta_id'] = $last_id;

        return [ 'done' => false ];
    }

    /**
     * @param string $rel_path  DB _wp_attached_file value.
     * @param int    $post_id   Attachment post ID.
     * @param string $basedir   Uploads basedir.
     * @param string $job_id    Restore job id (filters).
     * @return string Matched relative path or empty.
     */
    private static function resolve_disk_relative_path( $rel_path, $post_id, $basedir, $job_id = '' ) {
        $rel_path = wp_normalize_path( (string) $rel_path );
        $dir      = dirname( $rel_path );
        if ( '.' === $dir ) {
            $dir = '';
        }
        $ext = strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) );

        $fold = self::ascii_fold_filename( basename( $rel_path ) );
        if ( '' !== $fold ) {
            $fp_key = ( '' !== $dir ? $dir . '/' : '' ) . $fold;
            if ( isset( self::$dir_fingerprint_index[ $fp_key ] ) ) {
                $candidates = self::$dir_fingerprint_index[ $fp_key ];
                if ( 1 === count( $candidates ) ) {
                    return (string) $candidates[0];
                }
            }
        }

        $size_hint = self::get_attachment_filesize_hint( (int) $post_id );
        if ( $size_hint > 0 && '' !== $dir && isset( self::$dir_fingerprint_index ) ) {
            foreach ( self::$dir_fingerprint_index as $key => $paths ) {
                if ( 0 !== strpos( (string) $key, $dir . '/' ) ) {
                    continue;
                }
                foreach ( $paths as $candidate ) {
                    if ( '' !== $ext && strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) ) !== $ext ) {
                        continue;
                    }
                    $abs = museder_restoreone_safe_path_join( $basedir, $candidate );
                    if ( '' === $abs || ! is_file( $abs ) ) {
                        continue;
                    }
                    $size = (int) filesize( $abs );
                    if ( $size > 0 && abs( $size - $size_hint ) <= max( 512, (int) ( $size_hint * 0.02 ) ) ) {
                        return (string) $candidate;
                    }
                }
            }
        }

        if ( '' !== $dir ) {
            $dir_candidates = [];
            foreach ( self::$rel_path_index as $candidate => $_true ) {
                if ( 0 === strpos( (string) $candidate, $dir . '/' ) ) {
                    if ( '' === $ext || strtolower( pathinfo( (string) $candidate, PATHINFO_EXTENSION ) ) === $ext ) {
                        $dir_candidates[] = (string) $candidate;
                    }
                }
            }
            if ( 1 === count( $dir_candidates )
                && apply_filters( 'museder_restoreone_media_paths_allow_single_dir_candidate', true, $job_id ) ) {
                return $dir_candidates[0];
            }
        }

        return '';
    }

    /**
     * Match menu-icon attachments when ASCII-fold heuristics cannot pick a unique file.
     *
     * @param string $rel_path DB _wp_attached_file value.
     * @param int    $post_id  Attachment post ID.
     * @param string $basedir  Uploads basedir.
     * @return string Matched relative path or empty.
     */
    private static function resolve_menu_icon_disk_path( $rel_path, $post_id, $basedir ) {
        $rel_path = wp_normalize_path( (string) $rel_path );
        $basename = basename( $rel_path );
        if ( false === strpos( $basename, '主選單圖示' ) && false === strpos( $basename, '圖示_' ) ) {
            return '';
        }

        $dir = dirname( $rel_path );
        if ( '.' === $dir ) {
            $dir = '';
        }
        $prefix = ( '' !== $dir ? $dir . '/' : '' );

        $candidates = [];
        foreach ( array_keys( self::$rel_path_index ?? [] ) as $candidate ) {
            $candidate = (string) $candidate;
            if ( '' !== $dir && 0 !== strpos( $candidate, $prefix ) ) {
                continue;
            }
            $file = basename( $candidate );
            if ( preg_match( '/ICON/i', $file ) ) {
                $candidates[] = $candidate;
            }
        }

        if ( empty( $candidates ) ) {
            return '';
        }

        usort(
            $candidates,
            static function ( $a, $b ) {
                return strnatcasecmp( (string) $a, (string) $b );
            }
        );

        $index = self::get_menu_icon_attachment_index( $dir, (int) $post_id );
        if ( $index < 0 || ! isset( $candidates[ $index ] ) ) {
            return '';
        }

        $match = (string) $candidates[ $index ];
        $abs   = museder_restoreone_safe_path_join( $basedir, $match );
        if ( '' === $abs || ! is_file( $abs ) ) {
            return '';
        }

        return $match;
    }

    /**
     * @param string $dir     Uploads-relative directory (YYYY/MM).
     * @param int    $post_id Attachment post ID.
     * @return int Zero-based index among menu-icon attachments in the directory, or -1.
     */
    private static function get_menu_icon_attachment_index( $dir, $post_id ) {
        if ( $post_id <= 0 ) {
            return -1;
        }

        static $cache = [];
        $dir = wp_normalize_path( (string) $dir );
        if ( ! isset( $cache[ $dir ] ) ) {
            global $wpdb;
            $cache[ $dir ] = [];
            if ( isset( $wpdb->postmeta ) ) {
                $like = ( '' !== $dir ? $dir . '/%' : '%' ) . '主選單圖示%';
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY post_id ASC",
                        '_wp_attached_file',
                        $like
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ( is_array( $rows ) ) {
                    foreach ( $rows as $row ) {
                        $cache[ $dir ][] = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
                    }
                }
            }
        }

        $index = array_search( (int) $post_id, $cache[ $dir ], true );
        return false === $index ? -1 : (int) $index;
    }

    /**
     * @param int $post_id Attachment post ID.
     * @return int Filesize bytes or 0.
     */
    private static function get_attachment_filesize_hint( $post_id ) {
        if ( $post_id <= 0 ) {
            return 0;
        }
        $meta = get_post_meta( $post_id, '_wp_attachment_metadata', true );
        if ( ! is_array( $meta ) || empty( $meta['filesize'] ) ) {
            return 0;
        }
        return (int) $meta['filesize'];
    }

    /**
     * Fold filename for cross-matching DB UTF-8 paths to ASCII on-disk names.
     *
     * @param string $filename Basename.
     * @return string
     */
    public static function ascii_fold_filename( $filename ) {
        $filename = (string) $filename;
        $ext      = '';
        $dot      = strrpos( $filename, '.' );
        if ( false !== $dot ) {
            $ext      = substr( $filename, $dot );
            $filename = substr( $filename, 0, $dot );
        }
        $stem = preg_replace( '/[^a-zA-Z0-9._\-]+/', '', $filename );
        if ( ! is_string( $stem ) ) {
            $stem = '';
        }
        $stem = preg_replace( '/_+/', '_', $stem );
        $stem = trim( (string) $stem, '_.' );
        if ( '' === $stem ) {
            return '';
        }
        return strtolower( $stem . strtolower( $ext ) );
    }

    /**
     * @param array<int, array{search:string,replace:string}> $pairs Pairs (mutated).
     * @param string                                           $old_rel Old uploads-relative path.
     * @param string                                           $new_rel New uploads-relative path.
     * @return void
     */
    private static function append_upload_path_pair( array &$pairs, $old_rel, $new_rel ) {
        $old_rel = wp_normalize_path( (string) $old_rel );
        $new_rel = wp_normalize_path( (string) $new_rel );
        if ( '' === $old_rel || '' === $new_rel || $old_rel === $new_rel ) {
            return;
        }
        if ( ! self::is_valid_upload_path_pair( $old_rel, $new_rel ) ) {
            return;
        }

        $pairs[] = [
            'search'  => $old_rel,
            'replace' => $new_rel,
        ];

        $uploads_fragment_old = 'wp-content/uploads/' . $old_rel;
        $uploads_fragment_new = 'wp-content/uploads/' . $new_rel;
        if ( $uploads_fragment_old !== $uploads_fragment_new ) {
            $pairs[] = [
                'search'  => $uploads_fragment_old,
                'replace' => $uploads_fragment_new,
            ];
        }
    }

    /**
     * Expand base attachment pairs to include on-disk size variants (-WxH) and metadata paths.
     *
     * @param array<int, array{search:string,replace:string}> $pairs   Base pairs.
     * @param string                                          $basedir Uploads basedir.
     * @return array<int, array{search:string,replace:string}>
     */
    public static function expand_upload_path_pairs( array $pairs, $basedir ) {
        if ( null === self::$rel_path_index ) {
            self::build_uploads_index( $basedir );
        }

        $out = $pairs;
        foreach ( $pairs as $pair ) {
            if ( ! is_array( $pair ) || empty( $pair['search'] ) || empty( $pair['replace'] ) ) {
                continue;
            }
            $search  = wp_normalize_path( (string) $pair['search'] );
            $replace = wp_normalize_path( (string) $pair['replace'] );
            if ( 0 === strpos( $search, 'wp-content/uploads/' ) ) {
                $search = ltrim( substr( $search, strlen( 'wp-content/uploads/' ) ), '/' );
            }
            if ( 0 === strpos( $replace, 'wp-content/uploads/' ) ) {
                $replace = ltrim( substr( $replace, strlen( 'wp-content/uploads/' ) ), '/' );
            }
            if ( '' === $search || '' === $replace || $search === $replace ) {
                continue;
            }

            $variant_pairs = self::build_size_variant_pairs( $search, $replace );
            foreach ( $variant_pairs as $variant_pair ) {
                self::append_upload_path_pair( $out, $variant_pair['search'], $variant_pair['replace'] );
            }
        }

        return self::dedupe_pairs( $out );
    }

    /**
     * @param string $old_rel Old uploads-relative path (original file).
     * @param string $new_rel Matched uploads-relative path on disk.
     * @return array<int, array{search:string,replace:string}>
     */
    private static function build_size_variant_pairs( $old_rel, $new_rel ) {
        $old_rel = wp_normalize_path( (string) $old_rel );
        $new_rel = wp_normalize_path( (string) $new_rel );
        $pairs   = [];

        $old_dir  = dirname( $old_rel );
        $new_dir  = dirname( $new_rel );
        if ( '.' === $old_dir ) {
            $old_dir = '';
        }
        if ( '.' === $new_dir ) {
            $new_dir = '';
        }

        $old_base = basename( $old_rel );
        $new_base = basename( $new_rel );
        $old_stem = pathinfo( $old_base, PATHINFO_FILENAME );
        $new_stem = pathinfo( $new_base, PATHINFO_FILENAME );
        $ext      = pathinfo( $old_base, PATHINFO_EXTENSION );
        if ( '' === $new_stem || '' === $ext ) {
            return $pairs;
        }

        $prefix = ( '' !== $new_dir ? $new_dir . '/' : '' );
        $pattern = '/^' . preg_quote( $new_stem, '/' ) . '-(\d+x\d+)\.' . preg_quote( $ext, '/' ) . '$/i';

        foreach ( array_keys( self::$rel_path_index ?? [] ) as $candidate ) {
            $candidate = (string) $candidate;
            if ( '' !== $new_dir && 0 !== strpos( $candidate, $prefix ) ) {
                continue;
            }
            $file = basename( $candidate );
            if ( ! preg_match( $pattern, $file, $match ) ) {
                continue;
            }
            $dims        = $match[1];
            $old_variant = ( '' !== $old_dir ? $old_dir . '/' : '' ) . $old_stem . '-' . $dims . '.' . $ext;
            if ( $old_variant === $candidate ) {
                continue;
            }
            $pairs[] = [
                'search'  => $old_variant,
                'replace' => $candidate,
            ];
        }

        return $pairs;
    }

    /**
     * Rewrite _wp_attachment_metadata size paths after fixing the primary attached file.
     *
     * @param int    $post_id Attachment post ID.
     * @param string $old_rel Old uploads-relative path.
     * @param string $new_rel New uploads-relative path.
     * @param string $basedir Uploads basedir.
     * @param string $job_id  Restore job id.
     * @return void
     */
    private static function rewrite_attachment_metadata_paths( $post_id, $old_rel, $new_rel, $basedir, $job_id = '' ) {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
            return;
        }

        $meta = get_post_meta( $post_id, '_wp_attachment_metadata', true );
        if ( ! is_array( $meta ) ) {
            return;
        }

        $old_rel = wp_normalize_path( (string) $old_rel );
        $new_rel = wp_normalize_path( (string) $new_rel );
        $old_dir = dirname( $old_rel );
        if ( '.' === $old_dir ) {
            $old_dir = '';
        }
        $old_base = basename( $old_rel );
        $new_base = basename( $new_rel );
        $old_stem = pathinfo( $old_base, PATHINFO_FILENAME );
        $new_stem = pathinfo( $new_base, PATHINFO_FILENAME );

        if ( ! empty( $meta['file'] ) ) {
            $meta['file'] = str_replace( $old_rel, $new_rel, wp_normalize_path( (string) $meta['file'] ) );
            $meta['file'] = str_replace( $old_base, $new_base, (string) $meta['file'] );
        }

        if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as &$size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }
                $size_file = (string) $size_data['file'];
                if ( false !== strpos( $size_file, $old_stem ) ) {
                    $size_data['file'] = str_replace( $old_stem, $new_stem, $size_file );
                    continue;
                }
                $old_size_rel = ( '' !== $old_dir && false === strpos( $size_file, '/' ) )
                    ? ( '' !== $old_dir ? $old_dir . '/' : '' ) . $size_file
                    : wp_normalize_path( $size_file );
                $match = self::resolve_disk_relative_path( $old_size_rel, $post_id, $basedir, $job_id );
                if ( '' !== $match ) {
                    $size_data['file'] = basename( $match );
                }
            }
            unset( $size_data );
        }

        update_post_meta( $post_id, '_wp_attachment_metadata', $meta );
    }

    /**
     * Scan post_content and postmeta for uploads URLs that still reference missing paths.
     *
     * @param string               $basedir         Uploads basedir.
     * @param array<string, mixed> $cleanup         Cleanup checkpoint (mutated).
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      microtime( true ).
     * @param string               $job_id          Restore job id.
     * @return array{done:bool}
     */
    private static function scan_content_refs_sliced( $basedir, array &$cleanup, $timeout_seconds, $start_time, $job_id = '' ) {
        global $wpdb;

        if ( null === self::$rel_path_index ) {
            self::build_uploads_index( $basedir );
        }

        $sub = isset( $cleanup['media_paths_content_sub'] ) ? (string) $cleanup['media_paths_content_sub'] : 'postmeta';
        if ( 'postmeta' === $sub ) {
            $result = self::scan_content_refs_postmeta_sliced( $basedir, $cleanup, $timeout_seconds, $start_time, $job_id );
            if ( empty( $result['done'] ) ) {
                return $result;
            }
            $cleanup['media_paths_content_sub']          = 'posts';
            $cleanup['media_paths_content_last_post_id'] = 0;
        }

        return self::scan_content_refs_posts_sliced( $basedir, $cleanup, $timeout_seconds, $start_time, $job_id );
    }

    /**
     * @param string               $basedir         Uploads basedir.
     * @param array<string, mixed> $cleanup         Cleanup checkpoint (mutated).
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      microtime( true ).
     * @param string               $job_id          Restore job id.
     * @return array{done:bool}
     */
    private static function scan_content_refs_postmeta_sliced( $basedir, array &$cleanup, $timeout_seconds, $start_time, $job_id = '' ) {
        global $wpdb;

        $last_id = isset( $cleanup['media_paths_content_last_meta_id'] ) ? (int) $cleanup['media_paths_content_last_meta_id'] : 0;
        $batch   = 40;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key <> %s AND meta_value LIKE %s ORDER BY meta_id ASC LIMIT %d",
                $last_id,
                '_wp_attached_file',
                '%uploads/%',
                $batch
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $rows ) ) {
            return [ 'done' => true ];
        }

        $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];
        $seen  = isset( $cleanup['media_paths_content_seen'] ) && is_array( $cleanup['media_paths_content_seen'] ) ? $cleanup['media_paths_content_seen'] : [];

        foreach ( $rows as $row ) {
            if ( ( microtime( true ) - $start_time ) >= $timeout_seconds ) {
                break;
            }

            $meta_id = isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0;
            $text    = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
            $last_id = max( $last_id, $meta_id );

            self::collect_content_ref_pairs( $text, $basedir, $pairs, $seen, $cleanup, $job_id );
        }

        $cleanup['media_paths_content_last_meta_id'] = $last_id;
        $cleanup['media_paths_pairs']                = self::dedupe_pairs( $pairs );
        $cleanup['media_paths_content_seen']         = $seen;

        return [ 'done' => false ];
    }

    /**
     * @param string               $basedir         Uploads basedir.
     * @param array<string, mixed> $cleanup         Cleanup checkpoint (mutated).
     * @param int                  $timeout_seconds Slice budget.
     * @param float                $start_time      microtime( true ).
     * @param string               $job_id          Restore job id.
     * @return array{done:bool}
     */
    private static function scan_content_refs_posts_sliced( $basedir, array &$cleanup, $timeout_seconds, $start_time, $job_id = '' ) {
        global $wpdb;

        $last_id = isset( $cleanup['media_paths_content_last_post_id'] ) ? (int) $cleanup['media_paths_content_last_post_id'] : 0;
        $batch   = 40;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
                $last_id,
                '%uploads/%',
                $batch
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $rows ) ) {
            return [ 'done' => true ];
        }

        $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];
        $seen  = isset( $cleanup['media_paths_content_seen'] ) && is_array( $cleanup['media_paths_content_seen'] ) ? $cleanup['media_paths_content_seen'] : [];

        foreach ( $rows as $row ) {
            if ( ( microtime( true ) - $start_time ) >= $timeout_seconds ) {
                break;
            }

            $post_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
            $text    = isset( $row['post_content'] ) ? (string) $row['post_content'] : '';
            $last_id = max( $last_id, $post_id );

            self::collect_content_ref_pairs( $text, $basedir, $pairs, $seen, $cleanup, $job_id );
        }

        $cleanup['media_paths_content_last_post_id'] = $last_id;
        $cleanup['media_paths_pairs']              = self::dedupe_pairs( $pairs );
        $cleanup['media_paths_content_seen']       = $seen;

        return [ 'done' => false ];
    }

    /**
     * @param string               $text    Row text to scan.
     * @param string               $basedir Uploads basedir.
     * @param array<int, array{search:string,replace:string}> $pairs   Pairs (mutated).
     * @param array<string, true>  $seen    Seen old paths (mutated).
     * @param array<string, mixed> $cleanup Cleanup checkpoint (mutated).
     * @param string               $job_id  Restore job id.
     * @return void
     */
    private static function collect_content_ref_pairs( $text, $basedir, array &$pairs, array &$seen, array &$cleanup, $job_id = '' ) {
        $paths = self::extract_upload_relative_paths( $text );
        foreach ( $paths as $rel ) {
            $cleanup['media_paths_content_scanned'] = isset( $cleanup['media_paths_content_scanned'] ) ? (int) $cleanup['media_paths_content_scanned'] + 1 : 1;
            if ( isset( $seen[ $rel ] ) ) {
                continue;
            }
            $seen[ $rel ] = true;

            if ( self::upload_relative_path_exists( $basedir, $rel ) ) {
                continue;
            }

            $match = self::resolve_disk_relative_path( $rel, 0, $basedir, $job_id );
            if ( '' === $match || $match === $rel ) {
                continue;
            }
            if ( ! self::is_valid_upload_path_pair( $rel, $match ) ) {
                continue;
            }

            self::append_upload_path_pair( $pairs, $rel, $match );
            $cleanup['media_paths_content_pairs'] = isset( $cleanup['media_paths_content_pairs'] ) ? (int) $cleanup['media_paths_content_pairs'] + 1 : 1;
        }
    }

    /**
     * @param string $text Content or meta value.
     * @return array<int, string> Uploads-relative paths.
     */
    public static function extract_upload_relative_paths( $text ) {
        $text = self::decode_json_unicode_escapes( (string) $text );
        $paths = [];

        if ( preg_match_all( '#(?:wp-content/uploads/|uploads/)([0-9]{4}/[0-9]{2}/[^"\'\s<>\\\\]+\\.(?:jpe?g|png|gif|webp|svg|pdf|ico))#iu', $text, $matches ) ) {
            foreach ( $matches[1] as $rel ) {
                $rel = wp_normalize_path( rawurldecode( (string) $rel ) );
                if ( '' !== $rel ) {
                    $paths[] = $rel;
                }
            }
        }

        return array_values( array_unique( $paths ) );
    }

    /**
     * @param string $text Text that may contain JSON-style \uXXXX escapes.
     * @return string
     */
    private static function decode_json_unicode_escapes( $text ) {
        if ( false === strpos( $text, '\\u' ) ) {
            return (string) $text;
        }

        return (string) preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static function ( $match ) {
                if ( function_exists( 'mb_convert_encoding' ) ) {
                    $packed = pack( 'H*', $match[1] );
                    if ( is_string( $packed ) ) {
                        $decoded = mb_convert_encoding( $packed, 'UTF-8', 'UCS-2BE' );
                        if ( is_string( $decoded ) && '' !== $decoded ) {
                            return $decoded;
                        }
                    }
                }
                $entity = html_entity_decode( '&#x' . $match[1] . ';', ENT_QUOTES, 'UTF-8' );
                return is_string( $entity ) ? $entity : $match[0];
            },
            (string) $text
        );
    }

    /**
     * @param string $basedir Uploads basedir.
     * @param string $rel     Uploads-relative path.
     * @return bool
     */
    private static function upload_relative_path_exists( $basedir, $rel ) {
        $rel = wp_normalize_path( (string) $rel );
        if ( '' === $rel ) {
            return false;
        }
        if ( isset( self::$rel_path_index[ $rel ] ) ) {
            return true;
        }
        $abs = museder_restoreone_safe_path_join( $basedir, $rel );
        return '' !== $abs && is_file( $abs );
    }

    /**
     * Sync media widget raw URLs from their attachment IDs after attachment paths were reconciled.
     *
     * Max Mega Menu commonly stores Image Widget instances in widget_media_image with both
     * attachment_id and a raw URL; the raw URL must follow the fixed attachment path.
     *
     * @param string $basedir Uploads basedir.
     * @return int Updated widget instance count.
     */
    public static function sync_widget_media_image_urls( $basedir ) {
        if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! function_exists( 'get_post_meta' ) ) {
            return 0;
        }

        $widgets = get_option( 'widget_media_image', [] );
        if ( ! is_array( $widgets ) ) {
            return 0;
        }

        $uploads = wp_upload_dir();
        $baseurl = isset( $uploads['baseurl'] ) ? rtrim( (string) $uploads['baseurl'], '/' ) : '';
        if ( '' === $baseurl ) {
            return 0;
        }

        $changed = 0;
        foreach ( $widgets as $key => $widget ) {
            if ( '_multiwidget' === $key || ! is_array( $widget ) ) {
                continue;
            }

            $attachment_id = isset( $widget['attachment_id'] ) ? (int) $widget['attachment_id'] : 0;
            if ( $attachment_id <= 0 ) {
                continue;
            }

            $rel = self::get_attachment_file_from_db( $attachment_id );
            if ( '' === $rel ) {
                $rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
            }
            $rel = wp_normalize_path( (string) $rel );
            if ( '' === $rel || ! self::upload_relative_path_exists( $basedir, $rel ) ) {
                continue;
            }

            $new_url = $baseurl . '/' . ltrim( $rel, '/' );
            if ( isset( $widget['url'] ) && (string) $widget['url'] === $new_url ) {
                continue;
            }

            $widgets[ $key ]['url'] = $new_url;
            ++$changed;
        }

        if ( $changed > 0 ) {
            update_option( 'widget_media_image', $widgets, false );
        }

        return $changed;
    }

    /**
     * Read the latest _wp_attached_file value directly because reconcile updates it with $wpdb.
     *
     * @param int $attachment_id Attachment post ID.
     * @return string
     */
    private static function get_attachment_file_from_db( $attachment_id ) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 || ! isset( $wpdb->postmeta ) ) {
            return '';
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $attachment_id,
                '_wp_attached_file'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return is_string( $value ) ? $value : '';
    }

    /**
     * @param array<int, array{search:string,replace:string}> $pairs Pairs.
     * @return array<int, array{search:string,replace:string}>
     */
    private static function sort_pairs_longest_first( array $pairs ) {
        usort(
            $pairs,
            static function ( $a, $b ) {
                $len_a = isset( $a['search'] ) ? strlen( (string) $a['search'] ) : 0;
                $len_b = isset( $b['search'] ) ? strlen( (string) $b['search'] ) : 0;
                return $len_b <=> $len_a;
            }
        );
        return $pairs;
    }

    /**
     * @param string $search  Search path.
     * @param string $replace Replace path.
     * @return bool
     */
    public static function is_valid_upload_path_pair( $search, $replace ) {
        $search  = wp_normalize_path( (string) $search );
        $replace = wp_normalize_path( (string) $replace );
        if ( '' === $search || '' === $replace || $search === $replace ) {
            return false;
        }

        foreach ( [ $search, $replace ] as $path ) {
            $rel = $path;
            if ( 0 === strpos( $rel, 'wp-content/uploads/' ) ) {
                $rel = ltrim( substr( $rel, strlen( 'wp-content/uploads/' ) ), '/' );
            }
            $base = basename( $rel );
            $stem = pathinfo( $base, PATHINFO_FILENAME );
            if ( ! is_string( $stem ) || '' === $stem || '.' === $stem ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{search:string,replace:string}> $pairs Pairs.
     * @return array<int, array{search:string,replace:string}>
     */
    public static function filter_valid_path_pairs( array $pairs ) {
        $out = [];
        foreach ( $pairs as $pair ) {
            if ( ! is_array( $pair ) || empty( $pair['search'] ) || ! isset( $pair['replace'] ) ) {
                continue;
            }
            if ( ! self::is_valid_upload_path_pair( $pair['search'], $pair['replace'] ) ) {
                continue;
            }
            $out[] = [
                'search'  => (string) $pair['search'],
                'replace' => (string) $pair['replace'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{search:string,replace:string}> $pairs Pairs.
     * @return array<int, array{search:string,replace:string}>
     */
    private static function dedupe_pairs( array $pairs ) {
        $out  = [];
        $seen = [];
        foreach ( $pairs as $pair ) {
            if ( ! is_array( $pair ) || empty( $pair['search'] ) || ! isset( $pair['replace'] ) ) {
                continue;
            }
            $search  = (string) $pair['search'];
            $replace = (string) $pair['replace'];
            if ( $search === $replace || isset( $seen[ $search ] ) ) {
                continue;
            }
            if ( ! self::is_valid_upload_path_pair( $search, $replace ) ) {
                continue;
            }
            $seen[ $search ] = true;
            $out[]           = [
                'search'  => $search,
                'replace' => $replace,
            ];
        }
        return $out;
    }
}
