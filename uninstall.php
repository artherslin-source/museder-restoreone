<?php
/**
 * Uninstall handler — removes plugin-owned database state only.
 *
 * Does **not** delete backup archives, logs, reports, or temp files under uploads;
 * remove those manually if needed (see readme.txt).
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Deletes plugin options, transients, dynamic job-lock options, and scheduled hooks
 * for the current site (blog).
 *
 * @return void
 */
function museder_restoreone_uninstall_for_site() {
    global $wpdb;

    $option_names = [
        // Bootstrap / versioning.
        'museder_restoreone_needs_init',
        'museder_restoreone_plugin_version',
        'museder_restoreone_plugin_build_id',
        'museder_restoreone_build_log_ts',
        'museder_restoreone_prefix_migration_done',

        // Settings / schedules / jobs.
        'museder_restoreone_options',
        'museder_restoreone_settings',
        'museder_restoreone_schedules',
        'museder_restoreone_active_job',
        'museder_restoreone_restore_jobs',
        'museder_restoreone_restore_lock',
        'museder_restoreone_restore_service_active_job_id',
        'museder_restoreone_restore_state',

        // Restore safe mode markers.
        'museder_restoreone_safe_mode',
        'museder_restoreone_prev_active_plugins',
        'museder_restoreone_restored_active_plugins',
        'museder_restoreone_restored_active_plugins_last',
        'museder_restoreone_restored_active_sitewide_plugins',
        'museder_restoreone_mid_restore_isolation',

        // Misc.
        'museder_restoreone_legacy_storage_migrated',
        'museder_restoreone_scan_filesize_job',
        'museder_restoreone_last_file_scan_size',
        'museder_restoreone_last_file_scan_time',
        'museder_restoreone_pro_active',
        'museder_restoreone_ai_anomalies',
        'museder_restoreone_ai_reports',
    ];

    foreach ( $option_names as $name ) {
        delete_option( $name );
    }

    // Dynamic backup job lock rows (add_option with prefixed keys).
    $like = $wpdb->esc_like( 'museder_restoreone_job_lock_' ) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

    // Transients and timeouts owned by this plugin.
    $t_like = $wpdb->esc_like( '_transient_museder_restoreone_' ) . '%';
    $tt_like = $wpdb->esc_like( '_transient_timeout_museder_restoreone_' ) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $t_like, $tt_like ) );

    // Clear any cron events whose hook starts with museder_restoreone_.
    if ( function_exists( '_get_cron_array' ) ) {
        $crons = _get_cron_array();
        if ( is_array( $crons ) ) {
            foreach ( $crons as $timestamp => $hooks ) {
                if ( ! is_array( $hooks ) ) {
                    continue;
                }
                foreach ( $hooks as $hook => $dings ) {
                    if ( ! is_string( $hook ) || 0 !== strpos( $hook, 'museder_restoreone_' ) ) {
                        continue;
                    }
                    if ( ! is_array( $dings ) ) {
                        continue;
                    }
                    foreach ( $dings as $data ) {
                        $args = ( is_array( $data ) && isset( $data['args'] ) ) ? $data['args'] : [];
                        if ( function_exists( 'wp_unschedule_event' ) ) {
                            wp_unschedule_event( (int) $timestamp, $hook, $args );
                        }
                    }
                }
            }
        }
    }
}

/**
 * Runs uninstall cleanup for single site or batched Multisite (avoid file-scope vars for static analysis).
 *
 * @return void
 */
function museder_restoreone_run_uninstall_all_sites() {
    if ( ! is_multisite() ) {
        museder_restoreone_uninstall_for_site();
        return;
    }

    // Batch sites to reduce peak memory / lock time on very large networks (readme: maintenance window for huge networks).
    $mro_batch  = 100;
    $mro_offset = 0;
    while ( true ) {
        $mro_site_ids = get_sites(
            [
                'fields'   => 'ids',
                'number'   => $mro_batch,
                'offset'   => $mro_offset,
                'orderby'  => 'id',
                'order'    => 'ASC',
            ]
        );
        if ( ! is_array( $mro_site_ids ) || empty( $mro_site_ids ) ) {
            break;
        }
        foreach ( $mro_site_ids as $mro_blog_id ) {
            switch_to_blog( (int) $mro_blog_id );
            museder_restoreone_uninstall_for_site();
            restore_current_blog();
        }
        if ( count( $mro_site_ids ) < $mro_batch ) {
            break;
        }
        $mro_offset += $mro_batch;
    }
}

museder_restoreone_run_uninstall_all_sites();
