<?php
/*
Plugin Name: Museder RestoreOne
Plugin URI: https://museder.com/restoreone
Description: Museder RestoreOne is a simple backup & restore plugin for WordPress.
Version: 2.7.40.15
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Author: Jerry Lin
Author URI: https://museder.com/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: museder-restoreone
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BACKUP_LITE_VERSION', '2.7.40.15' );
define( 'BACKUP_LITE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BACKUP_LITE_URL', plugin_dir_url( __FILE__ ) );

require_once BACKUP_LITE_PATH . 'includes/helpers.php';
require_once BACKUP_LITE_PATH . 'includes/class-pro.php';
require_once BACKUP_LITE_PATH . 'includes/class-backup.php';
require_once BACKUP_LITE_PATH . 'includes/class-backup-jobs.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-jobs.php';
require_once BACKUP_LITE_PATH . 'includes/class-ui.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-handler.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-service.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-lock.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-report.php';
require_once BACKUP_LITE_PATH . 'includes/class-restore-controller.php';
require_once BACKUP_LITE_PATH . 'includes/class-schedule-handler.php';
require_once BACKUP_LITE_PATH . 'includes/class-log-handler.php';
require_once BACKUP_LITE_PATH . 'includes/class-dashboard.php';
require_once BACKUP_LITE_PATH . 'includes/class-email-handler.php';
require_once BACKUP_LITE_PATH . 'includes/class-settings.php';
require_once BACKUP_LITE_PATH . 'includes/class-chunk-handler.php';
require_once BACKUP_LITE_PATH . 'includes/class-chunk-handler-v2.php';
require_once BACKUP_LITE_PATH . 'includes/class-estimate-size.php';

// PRO Features will be loaded in backup_lite_bootstrap() after WordPress is fully loaded
// This prevents errors during activation when get_option() may not be available

register_activation_hook( __FILE__, 'backup_lite_activate' );

function backup_lite_activate() {
    // Minimal activation - defer most operations to plugins_loaded hook
    // This prevents errors during activation when WordPress functions may not be fully available
    
    // Schedule cleanup cron if not already scheduled
    if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'backup_lite_cleanup_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'backup_lite_cleanup_cron' );
    }
    
    // Set a flag to run full initialization on next page load
    // This ensures all directories and settings are created when WordPress is fully loaded
    update_option( 'backup_lite_needs_init', true );
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

add_action( 'plugins_loaded', 'backup_lite_bootstrap' );

function backup_lite_bootstrap() {
    // Check if we need to run post-activation initialization
    if ( get_option( 'backup_lite_needs_init', false ) ) {
        // Run initialization tasks that were deferred from activation hook
        if ( function_exists( 'backup_lite_get_backup_dir' ) ) {
            try {
                backup_lite_get_backup_dir();
                backup_lite_get_log_dir();
                backup_lite_get_temp_dir();
                backup_lite_ensure_access_controls();
                
                // Only synchronise cron events if class is available and method exists
                if ( class_exists( 'Backup_Lite_Schedule_Handler' ) && method_exists( 'Backup_Lite_Schedule_Handler', 'synchronise_cron_events' ) ) {
                    Backup_Lite_Schedule_Handler::synchronise_cron_events();
                }

                // Create PRO directories if PRO is active
                if ( class_exists( 'Backup_Lite_Pro' ) && method_exists( 'Backup_Lite_Pro', 'is_pro_active' ) && Backup_Lite_Pro::is_pro_active() ) {
                    if ( function_exists( 'backup_lite_get_pro_jobs_dir' ) ) {
                        backup_lite_get_pro_jobs_dir();
                    }
                    if ( function_exists( 'backup_lite_get_pro_reports_dir' ) ) {
                        backup_lite_get_pro_reports_dir();
                    }
                    backup_lite_ensure_access_controls();
                }
            } catch ( Exception $e ) {
                // Log error but continue
                if ( function_exists( 'backup_lite_log' ) ) {
                    backup_lite_log( 'error', 'Post-activation initialization error: ' . $e->getMessage() );
                }
            }
        }
        
        // Clear the flag
        delete_option( 'backup_lite_needs_init' );
    }
    
    backup_lite_ensure_access_controls();
    Backup_Lite_Pro::init();
    Backup_Lite_UI::init();
    Backup_Lite_Backup_Jobs::init();
    Backup_Lite_Restore_Jobs::init();
    Backup_Lite_Restore_Handler::init();
    Backup_Lite_Restore_Controller::init();
    Backup_Lite_Schedule_Handler::init();
    Backup_Lite_Log_Handler::init();
    Backup_Lite_Dashboard::init();
    Backup_Lite_Email_Handler::init();
    Backup_Lite_Settings::init();
    Backup_Lite_Chunk_Handler::init();
    Backup_Lite_Chunk_V2::init();
    Backup_Lite_Estimate_Size::init();

    // Load PRO features if PRO is active (deferred from file loading to prevent activation errors)
    if ( class_exists( 'Backup_Lite_Pro' ) && method_exists( 'Backup_Lite_Pro', 'is_pro_active' ) && Backup_Lite_Pro::is_pro_active() ) {
        // Load PRO feature files
        $pro_files = [
            'includes/pro/ai-service.php',
            'includes/pro/ai-controller.php',
            'includes/pro/smart-retention.php',
            'includes/pro/advanced-filters.php',
            'includes/pro/health-score.php',
            'includes/pro/cloud-storage.php',
            'includes/pro/reports-service.php',
            'includes/pro/reports-controller.php',
        ];
        
        foreach ( $pro_files as $file ) {
            $path = BACKUP_LITE_PATH . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
        
        // Initialize PRO features
        if ( class_exists( 'Backup_Lite_AI_Service' ) ) {
            Backup_Lite_AI_Service::init();
        }
        if ( class_exists( 'Backup_Lite_AI_Controller' ) ) {
            Backup_Lite_AI_Controller::init();
        }
        if ( class_exists( 'Backup_Lite_Smart_Retention' ) ) {
            Backup_Lite_Smart_Retention::init();
        }
        if ( class_exists( 'Backup_Lite_Advanced_Filters' ) ) {
            Backup_Lite_Advanced_Filters::init();
        }
        if ( class_exists( 'Backup_Lite_Health_Score' ) ) {
            Backup_Lite_Health_Score::init();
        }
        if ( class_exists( 'Backup_Lite_Cloud_Storage' ) ) {
            Backup_Lite_Cloud_Storage::init();
        }
        if ( class_exists( 'Backup_Lite_Reports_Service' ) ) {
            Backup_Lite_Reports_Service::init();
        }
        if ( class_exists( 'Backup_Lite_Reports_Controller' ) ) {
            Backup_Lite_Reports_Controller::init();
        }
    }

    if ( ! wp_next_scheduled( 'backup_lite_cleanup_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'backup_lite_cleanup_cron' );
    }
}

add_action( 'admin_menu', 'backup_lite_register_menu' );

function backup_lite_register_menu() {
    add_menu_page(
        __( 'Museder RestoreOne', 'museder-restoreone' ),
        __( 'Museder RestoreOne', 'museder-restoreone' ),
        'manage_options',
        'backup-lite-dashboard',
        'backup_lite_render_dashboard',
        'dashicons-database',
        56
    );

    add_submenu_page( 'backup-lite-dashboard', __( 'Dashboard', 'museder-restoreone' ), __( 'Dashboard', 'museder-restoreone' ), 'manage_options', 'backup-lite-dashboard', 'backup_lite_render_dashboard' );
    add_submenu_page( 'backup-lite-dashboard', __( 'Backups', 'museder-restoreone' ), __( 'Backups', 'museder-restoreone' ), 'manage_options', 'backup-lite-backups', 'backup_lite_render_backups' );
    add_submenu_page( 'backup-lite-dashboard', __( 'Restore', 'museder-restoreone' ), __( 'Restore', 'museder-restoreone' ), 'manage_options', 'backup-lite-restore', 'backup_lite_render_restore_page' );
    add_submenu_page( 'backup-lite-dashboard', __( 'Schedules', 'museder-restoreone' ), __( 'Schedules', 'museder-restoreone' ), 'manage_options', 'backup-lite-schedules', 'backup_lite_render_schedules' );
    add_submenu_page( 'backup-lite-dashboard', __( 'Logs', 'museder-restoreone' ), __( 'Logs', 'museder-restoreone' ), 'manage_options', 'backup-lite-logs', 'backup_lite_render_logs' );
    add_submenu_page( 'backup-lite-dashboard', __( 'Settings', 'museder-restoreone' ), __( 'Settings', 'museder-restoreone' ), 'manage_options', 'backup-lite-settings', 'backup_lite_render_settings' );

    // PRO Features Menu (Lite keeps a single landing page)
    add_submenu_page(
        'backup-lite-dashboard',
        __( 'PRO Features', 'museder-restoreone' ),
        __( 'PRO Features', 'museder-restoreone' ) . ' <span class="pro-badge" style="background: #facc15; color: #000; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-left: 6px;">PRO</span>',
        'manage_options',
        'backup-lite-pro',
        'backup_lite_render_pro_features'
    );
}

function backup_lite_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $status                   = Backup_Lite_UI::get_environment_status();
    $dashboard_recent_backups = Backup_Lite_Dashboard::get_recent_backups( 3 );
    $schedule_overview        = Backup_Lite_Dashboard::get_schedule_overview();
    $activity_stats           = Backup_Lite_Dashboard::get_activity_stats();
    $recent_logs              = Backup_Lite_Log_Handler::get_logs( 3 );

    wp_enqueue_script(
        'chartjs',
        BACKUP_LITE_URL . 'assets/vendor/chart.4.5.1.min.js',
        [],
        '4.5.1',
        true
    );

    wp_enqueue_script(
        'backup-lite-dashboard',
        plugins_url( 'assets/js/dashboard.js', __FILE__ ),
        [ 'chartjs' ],
        BACKUP_LITE_VERSION,
        true
    );

    $chart_success = isset( $activity_stats['success'] ) ? (int) $activity_stats['success'] : 0;
    $chart_failed  = isset( $activity_stats['failed'] ) ? (int) $activity_stats['failed'] : 0;
    $next_run      = isset( $schedule_overview['next_run'] ) ? (int) $schedule_overview['next_run'] : 0;

    wp_localize_script(
        'backup-lite-dashboard',
        'BackupLiteDashboard',
        [
            'chart' => [
                'success' => $chart_success,
                'failed'  => $chart_failed,
            ],
            'nextRunTimestamp' => $next_run,
            'strings'          => [
                'dueNow'       => __( 'Due now', 'museder-restoreone' ),
                'noData'       => __( 'No activity recorded in the last 7 days.', 'museder-restoreone' ),
                'successLabel' => __( 'Success', 'museder-restoreone' ),
                'failedLabel'  => __( 'Failed', 'museder-restoreone' ),
            ],
        ]
    );

    include BACKUP_LITE_PATH . 'templates/page-dashboard.php';
}

function backup_lite_render_backups() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $status  = Backup_Lite_UI::get_environment_status();
    $backups = Backup_Lite_UI::get_backups_list();

    include BACKUP_LITE_PATH . 'templates/page-backups.php';
}

function backup_lite_render_restore_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_summary = Backup_Lite_Restore_Handler::current_summary();
    $museder_restoreone_progress = Backup_Lite_Restore_Handler::current_progress();
    $museder_restoreone_history  = Backup_Lite_Restore_Handler::history_for_js( 10 );
    $museder_restoreone_active_job = Backup_Lite_Restore_Jobs::has_active_job();

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
        Backup_Lite_UI::get_backups_list()
    );

    wp_enqueue_style(
        'backup-lite-restore',
        BACKUP_LITE_URL . 'assets/css/restore.css',
        [ 'backup-lite-theme' ],
        BACKUP_LITE_VERSION
    );

    wp_enqueue_script(
        'backup-lite-restore',
        BACKUP_LITE_URL . 'assets/js/restore.js',
        [ 'jquery', 'toastify' ],
        BACKUP_LITE_VERSION,
        true
    );

    wp_localize_script(
        'backup-lite-restore',
        'BackupLiteRestore',
        [
            'restURL' => esc_url_raw( rest_url( 'backup-lite/v2/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( Backup_Lite_UI::NONCE ),
            'siteURL' => home_url(),
            'uploads' => trailingslashit( backup_lite_get_storage_root()['path'] ),
            'cap'     => current_user_can( 'manage_options' ),
            'backups' => $museder_restoreone_backups,
            'summary' => $museder_restoreone_summary,
            'progress'=> $museder_restoreone_progress,
            'history' => $museder_restoreone_history,
            'job'     => $museder_restoreone_active_job ? Backup_Lite_Restore_Jobs::prepare_job_response( $museder_restoreone_active_job ) : null,
            'labels'  => [
                'noBackups'    => __( 'No backups available.', 'museder-restoreone' ),
                'noValidation' => __( 'Validation results will appear here once the job is prepared.', 'museder-restoreone' ),
                'selectFile'   => __( 'Please choose a backup file first.', 'museder-restoreone' ),
                'noDryRun'     => __( 'Dry-run results will appear here once available.', 'museder-restoreone' ),
            ],
        ]
    );

    include BACKUP_LITE_PATH . 'templates/page-restore.php';
}

function backup_lite_render_schedules() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $schedules = Backup_Lite_Schedule_Handler::list_schedules();

    include BACKUP_LITE_PATH . 'templates/page-schedules.php';
}

function backup_lite_render_logs() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $logs = Backup_Lite_Log_Handler::get_logs();

    include BACKUP_LITE_PATH . 'templates/page-logs.php';
}

function backup_lite_render_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $settings = Backup_Lite_Settings::get_settings();
    $roles    = wp_roles()->roles;

    include BACKUP_LITE_PATH . 'templates/page-settings.php';
}

function backup_lite_render_pro_features() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    include BACKUP_LITE_PATH . 'templates/page-pro-features.php';
}

function backup_lite_render_pro_ai() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    include BACKUP_LITE_PATH . 'templates/page-pro-ai.php';
}

function backup_lite_render_pro_cloud() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    include BACKUP_LITE_PATH . 'templates/page-pro-cloud.php';
}

function backup_lite_render_pro_filters() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    include BACKUP_LITE_PATH . 'templates/page-pro-filters.php';
}

function backup_lite_render_pro_retention() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    include BACKUP_LITE_PATH . 'templates/page-pro-retention.php';
}

function backup_lite_render_pro_reports() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
    }

    $museder_restoreone_is_pro = Backup_Lite_Pro::is_pro_active();
    
    // Enqueue Chart.js for trend charts
    wp_enqueue_script(
        'chartjs',
        BACKUP_LITE_URL . 'assets/vendor/chart.4.5.1.min.js',
        [],
        '4.5.1',
        true
    );

    wp_enqueue_script(
        'backup-lite-reports',
        BACKUP_LITE_URL . 'assets/js/reports.js',
        [ 'jquery', 'chartjs' ],
        BACKUP_LITE_VERSION,
        true
    );

    wp_localize_script(
        'backup-lite-reports',
        'BackupLiteReports',
        [
            'restUrl' => esc_url_raw( rest_url( 'backup-lite/v2/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'isPro'   => $is_pro,
        ]
    );

    // Use the full Reports template instead of the simple pro-reports template
    include BACKUP_LITE_PATH . 'templates/page-reports.php';
}

// Note: backup_lite_render_reports() was removed as it duplicates backup_lite_render_pro_reports()
// Both now point to the same page (System Reports) which is the PRO feature

add_action( 'backup_lite_cleanup_cron', function() {
    backup_lite_cleanup_temp();
    Backup_Lite_Chunk_V2::cleanup_expired_uploads();
} );

/**
 * Schedule a retry for a failed cron-run backup.
 *
 * @param string $schedule_id Schedule identifier.
 * @param int    $attempt     Current attempt number (1-2).
 */
function backup_lite_retry_cron( $schedule_id, $attempt = 1 ) {
    $attempt = max( 1, (int) $attempt );

    if ( $attempt > 2 ) {
        return;
    }

    $delay = MINUTE_IN_SECONDS * ( 5 * $attempt );

    wp_schedule_single_event(
        time() + $delay,
        Backup_Lite_Schedule_Handler::CRON_HOOK,
        [ $schedule_id, $attempt ]
    );
}
