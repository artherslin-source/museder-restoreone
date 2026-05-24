<?php
/*
Plugin Name: Museder RestoreOne – WP Backup & Restore
Plugin URI: https://musederlabs.com/restoreone-plugin/
Description: Large-site WordPress backup & restore—asynchronous jobs, chunked uploads, and local migration.
Version: 2.7.265
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Author: Adrian Lin
Author URI: https://profiles.wordpress.org/artherslin/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: museder-restoreone
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MUSEDER_RESTOREONE_VERSION', '2.7.265' );
// Build identifier for debugging host-side opcode caching issues.
define( 'MUSEDER_RESTOREONE_BUILD_ID', '2.7.265-1' );
define( 'MUSEDER_RESTOREONE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSEDER_RESTOREONE_URL', plugin_dir_url( __FILE__ ) );

require_once MUSEDER_RESTOREONE_PATH . 'includes/helpers.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-backup.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-backup-jobs.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-ui.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-handler.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-service.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-lock.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-token.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-report.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-restore-controller.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-schedule-handler.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-log-handler.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-dashboard.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-email-handler.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-settings.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-chunk-handler.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-chunk-handler-v2.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/class-estimate-size.php';

// WPRESS engine (AI1WM-compatible archive reader; local-only, no external services).
require_once MUSEDER_RESTOREONE_PATH . 'includes/wpress/class-wpress-exception.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/wpress/class-wpress-crypto.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/wpress/class-wpress-archiver.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/wpress/class-wpress-extractor.php';

// AI Free scaffolding (no external network calls; Pro provider is not loaded here).
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/interface-ai-provider.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-sanitizer.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-rate-limiter.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-report-repository.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-provider-free.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-factory.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-rest-controller.php';
require_once MUSEDER_RESTOREONE_PATH . 'includes/ai/class-ai-admin-page.php';

// PRO Features will be loaded in museder_restoreone_bootstrap() after WordPress is fully loaded
// This prevents errors during activation when get_option() may not be available

register_activation_hook( __FILE__, 'museder_restoreone_activate' );

function museder_restoreone_activate() {
    // Minimal activation - defer most operations to plugins_loaded hook
    // This prevents errors during activation when WordPress functions may not be fully available
    
    // Schedule cleanup cron if not already scheduled
    if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'museder_restoreone_cleanup_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'museder_restoreone_cleanup_cron' );
    }
    
    // Set a flag to run full initialization on next page load
    // This ensures all directories and settings are created when WordPress is fully loaded
    update_option( 'museder_restoreone_needs_init', true );
}

/**
 * One-time migration for legacy option/transient keys and cron hooks.
 *
 * Renames the historical "backup_lite_*" identifiers to the plugin-specific
 * "museder_restoreone_*" prefix to avoid conflicts with other plugins.
 *
 * @return void
 */
function museder_restoreone_migrate_legacy_keys_once() {
    $marker = 'museder_restoreone_prefix_migration_done';
    if ( get_option( $marker, false ) ) {
        return;
    }

    $option_map = [
        // Core bootstrap/versioning.
        'backup_lite_needs_init'        => 'museder_restoreone_needs_init',
        'backup_lite_plugin_version'    => 'museder_restoreone_plugin_version',
        'backup_lite_plugin_build_id'   => 'museder_restoreone_plugin_build_id',
        'backup_lite_build_log_ts'      => 'museder_restoreone_build_log_ts',

        // Settings / schedules / jobs.
        'backup_lite_options'           => 'museder_restoreone_options',
        'backup_lite_settings'          => 'museder_restoreone_settings',
        'backup_lite_schedules'         => 'museder_restoreone_schedules',
        'backup_lite_active_job'        => 'museder_restoreone_active_job',
        'backup_lite_restore_jobs'      => 'museder_restoreone_restore_jobs',
        'backup_lite_restore_lock'      => 'museder_restoreone_restore_lock',
        'backup_lite_restore_service_active_job_id' => 'museder_restoreone_restore_service_active_job_id',

        // Restore safe mode.
        'backup_lite_safe_mode'         => 'museder_restoreone_safe_mode',
        'backup_lite_prev_active_plugins' => 'museder_restoreone_prev_active_plugins',
        'backup_lite_restored_active_plugins' => 'museder_restoreone_restored_active_plugins',
        'backup_lite_restored_active_plugins_last' => 'museder_restoreone_restored_active_plugins_last',

        // Misc.
        'backup_lite_legacy_storage_migrated' => 'museder_restoreone_legacy_storage_migrated',
        'backup_lite_scan_filesize_job'       => 'museder_restoreone_scan_filesize_job',
        'backup_lite_pro_active'              => 'museder_restoreone_pro_active',
        // Pro/A.I. option(s) referenced by Pro code.
        'backup_lite_ai_anomalies'            => 'museder_restoreone_ai_anomalies',
    ];

    foreach ( $option_map as $old => $new ) {
        $old_val = get_option( $old, null );
        if ( null === $old_val ) {
            continue;
        }

        $new_val = get_option( $new, null );
        if ( null === $new_val ) {
            update_option( $new, $old_val, false );
        }

        delete_option( $old );
    }

    // Transients are best-effort; loss is acceptable because they are caches/short-lived.
    $transient_map = [
        'backup_lite_wp_cron_nudge_ts' => 'museder_restoreone_wp_cron_nudge_ts',
    ];
    foreach ( $transient_map as $old => $new ) {
        $old_val = get_transient( $old );
        if ( false === $old_val ) {
            continue;
        }
        set_transient( $new, $old_val, 30 );
        delete_transient( $old );
    }

    // Prevent orphaned cron hooks from the legacy prefix.
    if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
        wp_clear_scheduled_hook( 'backup_lite_cleanup_cron' );
        wp_clear_scheduled_hook( 'backup_lite_run_restore_job' );
        wp_clear_scheduled_hook( 'backup_lite_process_job' );
        wp_clear_scheduled_hook( 'backup_lite_restore_service_process_job' );
        wp_clear_scheduled_hook( 'backup_lite_restore_service_background_cleanup' );
    }

    update_option( $marker, 1, false );
}

// Text domain loading removed: WordPress.org automatically loads .mo files for this text domain.
// If manual loading is needed for development, uncomment the function and action below:
/*
add_action( 'plugins_loaded', 'museder_restoreone_load_textdomain' );

function museder_restoreone_load_textdomain() {
    load_plugin_textdomain(
        'museder-restoreone',
        false,
        basename( __DIR__ ) . '/languages/'
    );
}
*/

add_action( 'plugins_loaded', 'museder_restoreone_bootstrap' );

function museder_restoreone_bootstrap() {
    museder_restoreone_migrate_legacy_keys_once();

    // Detect upgrades early so we can invalidate opcode cache for included files (shared hosting often caches includes/*).
    $stored_version = get_option( 'museder_restoreone_plugin_version', '' );
    if ( MUSEDER_RESTOREONE_VERSION !== $stored_version ) {
        museder_restoreone_maybe_invalidate_opcache_for_plugin();

        update_option( 'museder_restoreone_plugin_version', MUSEDER_RESTOREONE_VERSION );
        update_option( 'museder_restoreone_plugin_build_id', defined( 'MUSEDER_RESTOREONE_BUILD_ID' ) ? MUSEDER_RESTOREONE_BUILD_ID : '' );

        if ( function_exists( 'museder_restoreone_log' ) ) {
            museder_restoreone_log( 'info', 'Museder RestoreOne version updated.', [
                'version'   => MUSEDER_RESTOREONE_VERSION,
                'build_id'  => defined( 'MUSEDER_RESTOREONE_BUILD_ID' ) ? MUSEDER_RESTOREONE_BUILD_ID : '',
                'opcache'   => function_exists( 'opcache_get_status' ) ? (bool) ( opcache_get_status( false )['opcache_enabled'] ?? false ) : null,
                'php'       => PHP_VERSION,
            ] );
        }
    } elseif ( function_exists( 'museder_restoreone_log' ) && defined( 'MUSEDER_RESTOREONE_BUILD_ID' ) ) {
        // Lightweight heartbeat for support: helps confirm which build is executing on the server.
        // Log at most once per hour.
        $last = (int) get_option( 'museder_restoreone_build_log_ts', 0 );
        if ( time() - $last > 3600 ) {
            update_option( 'museder_restoreone_build_log_ts', time() );
            museder_restoreone_log( 'info', 'Museder RestoreOne build active.', [
                'version'  => MUSEDER_RESTOREONE_VERSION,
                'build_id' => MUSEDER_RESTOREONE_BUILD_ID,
                'opcache'  => function_exists( 'opcache_get_status' ) ? (bool) ( opcache_get_status( false )['opcache_enabled'] ?? false ) : null,
            ] );
        }
    }

    // Check if we need to run post-activation initialization
    if ( get_option( 'museder_restoreone_needs_init', false ) ) {
        // Run initialization tasks that were deferred from activation hook
        if ( function_exists( 'museder_restoreone_get_backup_dir' ) ) {
            try {
                // Migrate any legacy storage locations into wp_upload_dir()/museder-restoreone (best-effort).
                if ( function_exists( 'museder_restoreone_migrate_legacy_storage' ) ) {
                    museder_restoreone_migrate_legacy_storage();
                }

                museder_restoreone_get_backup_dir();
                museder_restoreone_get_log_dir();
                museder_restoreone_get_temp_dir();
                museder_restoreone_ensure_access_controls();
                
                // Only synchronise cron events if class is available and method exists
                if ( class_exists( 'Museder_Restoreone_Schedule_Handler' ) && method_exists( 'Museder_Restoreone_Schedule_Handler', 'synchronise_cron_events' ) ) {
                    Museder_Restoreone_Schedule_Handler::synchronise_cron_events();
                }

                // Create optional add-on directories if a separate add-on is active.
                if ( function_exists( 'museder_is_pro_active' ) && museder_is_pro_active() ) {
                    if ( function_exists( 'museder_restoreone_get_pro_jobs_dir' ) ) {
                        museder_restoreone_get_pro_jobs_dir();
                    }
                    if ( function_exists( 'museder_restoreone_get_pro_reports_dir' ) ) {
                        museder_restoreone_get_pro_reports_dir();
                    }
                    museder_restoreone_ensure_access_controls();
                }
            } catch ( Exception $e ) {
                // Log error but continue
                if ( function_exists( 'museder_restoreone_log' ) ) {
                    museder_restoreone_log( 'error', 'Post-activation initialization error: ' . $e->getMessage() );
                }
            }
        }
        
        // Clear the flag
        delete_option( 'museder_restoreone_needs_init' );
    }
    
    museder_restoreone_ensure_access_controls();
    Museder_Restoreone_UI::init();
    Museder_Restoreone_Backup_Jobs::init();
    Museder_Restoreone_Restore_Handler::init();
    Museder_Restoreone_Restore_Controller::init();
    Museder_Restoreone_Restore_Service::init();
    Museder_Restoreone_Schedule_Handler::init();
    Museder_Restoreone_Log_Handler::init();
    Museder_Restoreone_Dashboard::init();
    Museder_Restoreone_Email_Handler::init();
    Museder_Restoreone_Settings::init();
    Museder_Restoreone_Chunk_Handler::init();
    Museder_Restoreone_Chunk_V2::init();
    Museder_Restoreone_Estimate_Size::init();

    // AI Free scaffolding (Dashboard Preview).
    if ( class_exists( 'Museder_AI_REST_Controller' ) ) {
        Museder_AI_REST_Controller::init();
    }
    if ( class_exists( 'Museder_AI_Admin_Page' ) ) {
        Museder_AI_Admin_Page::init();
    }

    if ( ! wp_next_scheduled( 'museder_restoreone_cleanup_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'museder_restoreone_cleanup_cron' );
    }

    // Cleanup: legacy restore-jobs cron hook (deprecated, Restore_Service is the only restore engine now).
    if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
        wp_clear_scheduled_hook( 'backup_lite_run_restore_job' );
        wp_clear_scheduled_hook( 'backup_lite_cleanup_cron' );
    }
}

add_action( 'admin_menu', 'museder_restoreone_register_menu' );

function museder_restoreone_register_menu() {
    add_menu_page(
        __( 'Museder RestoreOne', 'museder-restoreone' ),
        __( 'Museder RestoreOne', 'museder-restoreone' ),
        'manage_options',
        'museder-restoreone-dashboard',
        'museder_restoreone_render_dashboard',
        'dashicons-database',
        56
    );

    add_submenu_page( 'museder-restoreone-dashboard', __( 'Dashboard', 'museder-restoreone' ), __( 'Dashboard', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-dashboard', 'museder_restoreone_render_dashboard' );
    add_submenu_page( 'museder-restoreone-dashboard', __( 'Backups', 'museder-restoreone' ), __( 'Backups', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-backups', 'museder_restoreone_render_backups' );
    add_submenu_page( 'museder-restoreone-dashboard', __( 'Restore', 'museder-restoreone' ), __( 'Restore', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-restore', 'museder_restoreone_render_restore_page' );
    add_submenu_page( 'museder-restoreone-dashboard', __( 'Schedules', 'museder-restoreone' ), __( 'Schedules', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-schedules', 'museder_restoreone_render_schedules' );
    add_submenu_page( 'museder-restoreone-dashboard', __( 'Logs', 'museder-restoreone' ), __( 'Logs', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-logs', 'museder_restoreone_render_logs' );
    add_submenu_page( 'museder-restoreone-dashboard', __( 'Settings', 'museder-restoreone' ), __( 'Settings', 'museder-restoreone' ), 'manage_options', 'museder-restoreone-settings', 'museder_restoreone_render_settings' );

    // Keep Lite review-facing navigation focused on shipped features only.
}

function museder_restoreone_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $status                   = Museder_Restoreone_UI::get_environment_status();
    $dashboard_recent_backups = Museder_Restoreone_Dashboard::get_recent_backups( 3 );
    $schedule_overview        = Museder_Restoreone_Dashboard::get_schedule_overview();
    $activity_stats           = Museder_Restoreone_Dashboard::get_activity_stats();
    $recent_logs              = Museder_Restoreone_Log_Handler::get_logs( 3 );

    wp_enqueue_script(
        'chartjs',
        MUSEDER_RESTOREONE_URL . 'assets/vendor/chart.4.5.1.min.js',
        [],
        '4.5.1',
        true
    );

    wp_enqueue_script(
        'museder-restoreone-dashboard',
        plugins_url( 'assets/js/dashboard.js', __FILE__ ),
        [ 'chartjs' ],
        MUSEDER_RESTOREONE_VERSION,
        true
    );

    $chart_success = isset( $activity_stats['success'] ) ? (int) $activity_stats['success'] : 0;
    $chart_failed  = isset( $activity_stats['failed'] ) ? (int) $activity_stats['failed'] : 0;
    $next_run      = isset( $schedule_overview['next_run'] ) ? (int) $schedule_overview['next_run'] : 0;

    wp_localize_script(
        'museder-restoreone-dashboard',
        'MusederRestoreOneDashboard',
        [
            'chart' => [
                'success' => $chart_success,
                'failed'  => $chart_failed,
            ],
            'nextRunTimestamp' => $next_run,
            'ai'               => [
                'restUrl'    => esc_url_raw( rest_url( 'museder-restoreone/v1/ai/scan' ) ),
                'reportsUrl' => esc_url_raw( rest_url( 'museder-restoreone/v1/ai/reports' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'strings'    => [
                    'run'      => __( 'Run offline readiness scan', 'museder-restoreone' ),
                    'running'  => __( 'Scanning (local rules)…', 'museder-restoreone' ),
                    'done'     => __( 'Local scan finished.', 'museder-restoreone' ),
                    'failed'   => __( 'Local scan could not finish.', 'museder-restoreone' ),
                ],
            ],
            'strings'          => [
                'dueNow'       => __( 'Due now', 'museder-restoreone' ),
                'noData'       => __( 'No activity recorded in the last 7 days.', 'museder-restoreone' ),
                'successLabel' => __( 'Success', 'museder-restoreone' ),
                'failedLabel'  => __( 'Failed', 'museder-restoreone' ),
            ],
        ]
    );

    include MUSEDER_RESTOREONE_PATH . 'templates/page-dashboard.php';
}

function museder_restoreone_render_backups() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $status  = Museder_Restoreone_UI::get_environment_status();
    $backups = Museder_Restoreone_UI::get_backups_list();

    include MUSEDER_RESTOREONE_PATH . 'templates/page-backups.php';
}

function museder_restoreone_render_restore_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_summary = Museder_Restoreone_Restore_Handler::current_summary();
    $museder_restoreone_progress = Museder_Restoreone_Restore_Handler::current_progress();
    $museder_restoreone_history  = Museder_Restoreone_Restore_Handler::history_for_js( 10 );
    $museder_restoreone_active_job_id = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::get_active_job_id() : '';
    $museder_restoreone_active_job = null;
    if ( ! empty( $museder_restoreone_active_job_id ) ) {
        try {
            $museder_restoreone_active_job = Museder_Restoreone_Restore_Service::status( $museder_restoreone_active_job_id );
        } catch ( Exception $e ) {
            $museder_restoreone_active_job = null;
        }
    }

    $museder_restoreone_backups = array_map(
        function ( $item ) {
            $museder_restoreone_size = isset( $item['size'] ) ? (int) $item['size'] : 0;
            return [
                'name'    => $item['name'],
                'size'    => $museder_restoreone_size,
                'size_human' => size_format( $museder_restoreone_size, 2 ),
                'created' => $item['created'],
            ];
        },
        Museder_Restoreone_UI::get_backups_list()
    );

    wp_enqueue_style(
        'museder-restoreone-restore',
        MUSEDER_RESTOREONE_URL . 'assets/css/restore.css',
        [ 'museder-restoreone-theme' ],
        MUSEDER_RESTOREONE_VERSION
    );

    wp_enqueue_script(
        'museder-restoreone-restore',
        MUSEDER_RESTOREONE_URL . 'assets/js/restore.js',
        [ 'jquery', 'toastify' ],
        MUSEDER_RESTOREONE_VERSION,
        true
    );

    wp_localize_script(
        'museder-restoreone-restore',
        'MusederRestoreOneRestore',
        [
            'restURL' => esc_url_raw( rest_url( 'museder-restoreone/v2/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( Museder_Restoreone_UI::NONCE ),
            'siteURL' => home_url(),
            'uploads' => trailingslashit( museder_restoreone_get_storage_root()['path'] ),
            'cap'     => current_user_can( 'manage_options' ),
            'backups' => $museder_restoreone_backups,
            'summary' => $museder_restoreone_summary,
            'progress'=> $museder_restoreone_progress,
            'history' => $museder_restoreone_history,
            'restoreToken' => '',
            'job'     => $museder_restoreone_active_job ? array_merge( [ 'id' => $museder_restoreone_active_job_id ], $museder_restoreone_active_job ) : null,
            'labels'  => [
                'noBackups'    => __( 'No backups available.', 'museder-restoreone' ),
                'noValidation' => __( 'Validation results will appear here once the job is prepared.', 'museder-restoreone' ),
                'selectFile'   => __( 'Please choose a backup file first.', 'museder-restoreone' ),
                'noDryRun'     => __( 'Dry-run results will appear here once available.', 'museder-restoreone' ),
            ],
        ]
    );

    include MUSEDER_RESTOREONE_PATH . 'templates/page-restore.php';
}

function museder_restoreone_render_schedules() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $schedules = Museder_Restoreone_Schedule_Handler::list_schedules();

    include MUSEDER_RESTOREONE_PATH . 'templates/page-schedules.php';
}

function museder_restoreone_render_logs() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $logs = Museder_Restoreone_Log_Handler::get_logs();

    include MUSEDER_RESTOREONE_PATH . 'templates/page-logs.php';
}

function museder_restoreone_render_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $settings = Museder_Restoreone_Settings::get_settings();
    $roles    = wp_roles()->roles;

    include MUSEDER_RESTOREONE_PATH . 'templates/page-settings.php';
}

add_action( 'museder_restoreone_cleanup_cron', function() {
    museder_restoreone_cleanup_temp();
    Museder_Restoreone_Chunk_V2::cleanup_expired_uploads();
} );

/**
 * Schedule a retry for a failed cron-run backup.
 *
 * @param string $schedule_id Schedule identifier.
 * @param int    $attempt     Current attempt number (1-2).
 */
function museder_restoreone_retry_cron( $schedule_id, $attempt = 1 ) {
    $attempt = max( 1, (int) $attempt );

    if ( $attempt > 2 ) {
        return;
    }

    $delay = MINUTE_IN_SECONDS * ( 5 * $attempt );

    wp_schedule_single_event(
        time() + $delay,
        Museder_Restoreone_Schedule_Handler::CRON_HOOK,
        [ $schedule_id, $attempt ]
    );
}
