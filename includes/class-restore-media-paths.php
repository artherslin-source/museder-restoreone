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
        }

        if ( 'scan_meta' === $phase ) {
            $result = self::scan_attached_file_meta_sliced( $basedir, $cleanup, $timeout_seconds, $start_time, $job_id );
            if ( ! empty( $result['done'] ) ) {
                $cleanup['media_paths_phase'] = 'apply_pairs';
            }
            return self::progress_result( false );
        }

        if ( 'apply_pairs' === $phase ) {
            $pairs = isset( $cleanup['media_paths_pairs'] ) && is_array( $cleanup['media_paths_pairs'] ) ? $cleanup['media_paths_pairs'] : [];
            if ( ! empty( $pairs ) && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                Museder_Restoreone_Restore_Service::apply_path_replacements_for_restore( $pairs );
            }
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

            self::$rel_path_index[ $match ] = true;
            $pairs[] = [
                'search'  => $rel,
                'replace' => $match,
            ];
            $uploads_fragment_old = 'wp-content/uploads/' . $rel;
            $uploads_fragment_new = 'wp-content/uploads/' . $match;
            if ( $uploads_fragment_old !== $uploads_fragment_new ) {
                $pairs[] = [
                    'search'  => $uploads_fragment_old,
                    'replace' => $uploads_fragment_new,
                ];
            }

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
            'samples_unresolved'   => $samples,
        ];
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

        $fp_key = ( '' !== $dir ? $dir . '/' : '' ) . self::ascii_fold_filename( basename( $rel_path ) );
        if ( isset( self::$dir_fingerprint_index[ $fp_key ] ) ) {
            $candidates = self::$dir_fingerprint_index[ $fp_key ];
            if ( 1 === count( $candidates ) ) {
                return (string) $candidates[0];
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
        return strtolower( $stem . strtolower( $ext ) );
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
            $seen[ $search ] = true;
            $out[]           = [
                'search'  => $search,
                'replace' => $replace,
            ];
        }
        return $out;
    }
}
