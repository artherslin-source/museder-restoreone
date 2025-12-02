<?php
/**
 * Backup Lite dashboard page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Permission check
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'museder-restoreone' ) );
}

$status                   = isset( $status ) ? $status : Backup_Lite_UI::get_environment_status();
$dashboard_recent_backups = isset( $dashboard_recent_backups ) ? $dashboard_recent_backups : Backup_Lite_Dashboard::get_recent_backups( 3 );
$schedule_overview        = isset( $schedule_overview ) ? $schedule_overview : Backup_Lite_Dashboard::get_schedule_overview();
$activity_stats           = isset( $activity_stats ) ? $activity_stats : Backup_Lite_Dashboard::get_activity_stats();
$recent_logs              = isset( $recent_logs ) ? $recent_logs : Backup_Lite_Log_Handler::get_logs( 5 );

// Get status summaries for dashboard
$last_backup_summary  = isset( $last_backup_summary ) ? $last_backup_summary : Backup_Lite_Status_Service::get_last_backup_summary();
$last_restore_summary  = isset( $last_restore_summary ) ? $last_restore_summary : Backup_Lite_Status_Service::get_last_restore_summary();
$recent_backup_stats  = isset( $recent_backup_stats ) ? $recent_backup_stats : Backup_Lite_Status_Service::get_recent_backup_stats( 7 );

// Use recent_backup_stats for chart (consistent with "Recent 7 Days" display)
$chart_success = isset( $recent_backup_stats['success_count'] ) ? (int) $recent_backup_stats['success_count'] : 0;
$chart_failed  = isset( $recent_backup_stats['failed_count'] ) ? (int) $recent_backup_stats['failed_count'] : 0;

// Check if safe mode is active
$safe_mode_active = get_option( 'backup_lite_safe_mode', '' ) === '1';
$prev_plugins_count = 0;
if ( $safe_mode_active ) {
    $prev_plugins = get_option( 'backup_lite_prev_active_plugins', [] );
    $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
}

// Get AI settings and last results for AI cards
$ai_settings         = Museder_AI_Service::get_settings();
// Use global helper for license tier (considers Developer Mode)
$ai_license_tier     = function_exists( 'backup_lite_get_effective_license_tier' ) 
    ? backup_lite_get_effective_license_tier() 
    : ( isset( $ai_settings['license_tier'] ) ? $ai_settings['license_tier'] : 'free' );
$last_backup_report  = Museder_AI_Service::get_last_backup_report();
$last_backup_score   = isset( $last_backup_report['overall_score'] ) ? (int) $last_backup_report['overall_score'] : null;
$last_backup_updated = isset( $last_backup_report['updated_at'] ) ? (int) $last_backup_report['updated_at'] : 0;
$last_backup_risk    = isset( $last_backup_report['risk_level'] ) ? $last_backup_report['risk_level'] : '';
$last_site_scan      = Museder_AI_Service::get_last_site_scan();
?>

<div class="wrap backup-lite-admin backup-lite-dashboard">
    <h1 class="backup-lite-page-title">💾 <?php esc_html_e( 'Museder RestoreOne Dashboard', 'museder-restoreone' ); ?></h1>
    <p class="backup-lite-page-description"><?php esc_html_e( 'Quick overview of your environment, schedules, and the latest backup health signals.', 'museder-restoreone' ); ?></p>

    <?php if ( $safe_mode_active ) : ?>
    <div class="notice notice-warning is-dismissible" id="backup-lite-safe-mode-notice" style="border-left-color: #ffb900; padding: 12px 20px; margin: 20px 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="flex: 1; min-width: 300px;">
                <p style="margin: 0 0 8px 0; font-weight: 600;">
                    <span style="font-size: 20px; margin-right: 8px;">🛡️</span>
                    <?php esc_html_e( 'Safe Mode Active', 'museder-restoreone' ); ?>
                </p>
                <p style="margin: 0; color: #646970;">
                    <?php
                    printf(
                        /* translators: %d: Number of plugins that were deactivated. */
                        esc_html__( 'RestoreOne has enabled safe mode after restore, temporarily disabling %d plugin(s) to prevent conflicts. Please verify your site is working correctly, then click the button below to restore all plugins.', 'museder-restoreone' ),
                        $prev_plugins_count
                    );
                    ?>
                </p>
            </div>
            <div>
                <button type="button" id="backup-lite-exit-safe-mode-btn" class="button button-primary" style="white-space: nowrap;">
                    <?php esc_html_e( 'Exit Safe Mode & Restore Plugins', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="backup-lite-dashboard-actions">
        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-settings' ) ); ?>">⚙️ <?php esc_html_e( 'Settings', 'museder-restoreone' ); ?></a>
        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-schedules' ) ); ?>">🗓️ <?php esc_html_e( 'View Schedules', 'museder-restoreone' ); ?></a>
    </div>

    <div class="backup-lite-grid">
        <div class="backup-lite-card">
            <h2>⚙️ <?php esc_html_e( 'Environment Compatibility', 'museder-restoreone' ); ?></h2>
            <ul class="backup-lite-status-list">
                <li>
                    <?php if ( ! empty( $status['shell'] ) ) : ?>
                        <span class="badge success">
                            <?php esc_html_e( 'Shell commands available', 'museder-restoreone' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="badge pending">
                            <?php esc_html_e( 'Shell commands disabled (fallback active)', 'museder-restoreone' ); ?>
                        </span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ( ! empty( $status['mysqldump'] ) ) : ?>
                        <span class="badge success">
                            <?php esc_html_e( 'mysqldump detected', 'museder-restoreone' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="badge pending">
                            <?php esc_html_e( 'mysqldump unavailable (using PHP export)', 'museder-restoreone' ); ?>
                        </span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ( ! empty( $status['mysql_cli'] ) ) : ?>
                        <span class="badge success">
                            <?php esc_html_e( 'mysql client detected', 'museder-restoreone' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="badge pending">
                            <?php esc_html_e( 'mysql client unavailable (using PHP import)', 'museder-restoreone' ); ?>
                        </span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ( ! empty( $status['ziparchive'] ) ) : ?>
                        <span class="badge success">
                            <?php esc_html_e( 'ZipArchive available', 'museder-restoreone' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="badge pending">
                            <?php esc_html_e( 'ZipArchive missing (using PclZip)', 'museder-restoreone' ); ?>
                        </span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>

        <div class="backup-lite-card">
            <h2>📦 <?php esc_html_e( 'Recent Backups', 'museder-restoreone' ); ?></h2>
            <?php if ( empty( $dashboard_recent_backups ) ) : ?>
                <p class="description"><?php esc_html_e( 'No backups created yet. Head to the Backups page to create your first snapshot.', 'museder-restoreone' ); ?></p>
            <?php else : ?>
                <ul class="backup-lite-list">
                    <?php foreach ( $dashboard_recent_backups as $museder_restoreone_item ) : ?>
                        <li>
                            <strong><?php echo esc_html( $museder_restoreone_item['name'] ); ?></strong>
                            <span><?php echo esc_html( $museder_restoreone_item['created'] ); ?> · <?php echo esc_html( $museder_restoreone_item['size_human'] ); ?></span>
                            <?php
                            $museder_restoreone_status_key  = strtolower( $museder_restoreone_item['status'] ?? 'pending' );
                            $museder_restoreone_status_map  = [
                                'success' => [ 'label' => __( 'Success', 'museder-restoreone' ), 'icon' => '✅', 'class' => 'success' ],
                                'failed'  => [ 'label' => __( 'Failed', 'museder-restoreone' ), 'icon' => '❌', 'class' => 'error' ],
                                'pending' => [ 'label' => __( 'Pending', 'museder-restoreone' ), 'icon' => '⏳', 'class' => 'pending' ],
                            ];
                            $museder_restoreone_status_item = $museder_restoreone_status_map[ $museder_restoreone_status_key ] ?? $museder_restoreone_status_map['pending'];
                            ?>
                            <span class="badge <?php echo esc_attr( $museder_restoreone_status_item['class'] ); ?>">
                                <?php echo esc_html( $museder_restoreone_status_item['icon'] . ' ' . $museder_restoreone_status_item['label'] ); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="backup-lite-card">
            <h2>🗓️ <?php esc_html_e( 'Schedule Overview', 'museder-restoreone' ); ?></h2>
            <?php if ( ! empty( $schedule_overview['next_run'] ) ) : ?>
                <p class="backup-lite-next-run">
                    <?php
                        printf(
                            /* translators: 1: Schedule title, 2: Schedule frequency, 3: Next run time. */
                            esc_html__( 'The next backup "%1$s" (%2$s) runs at %3$s.', 'museder-restoreone' ),
                            esc_html( $schedule_overview['title'] ),
                            esc_html( ucfirst( $schedule_overview['period'] ) ),
                            esc_html( $schedule_overview['next_run_human'] )
                        );
                    ?>
                </p>
                <p class="description">
                    <span id="bl-dashboard-countdown" data-next-run="<?php echo esc_attr( $schedule_overview['next_run'] ); ?>">
                        <?php echo esc_html( $schedule_overview['countdown'] ?? '' ); ?>
                    </span>
                </p>
                <?php if ( ! empty( $schedule_overview['last_run'] ) ) : ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: Date and time when the schedule last ran. */
                            esc_html__( 'Last run: %s', 'museder-restoreone' ),
                            esc_html( backup_lite_local_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $schedule_overview['last_run'] ) ) )
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ( ! empty( $schedule_overview['last_result'] ) ) : ?>
                    <p class="description">
                        <?php
                        $museder_restoreone_result_key  = strtolower( $schedule_overview['last_result'] );
                        $museder_restoreone_result_map  = [
                            'success' => '✅ ' . esc_html__( 'Success', 'museder-restoreone' ),
                            'failed'  => '❌ ' . esc_html__( 'Failed', 'museder-restoreone' ),
                            'pending' => '⏳ ' . esc_html__( 'Pending', 'museder-restoreone' ),
                        ];
                        ?>
                        <?php
                        printf(
                            /* translators: %s: Result of the most recent schedule run. */
                            esc_html__( 'Last result: %s', 'museder-restoreone' ),
                            esc_html( $museder_restoreone_result_map[ $museder_restoreone_result_key ] ?? ucfirst( $museder_restoreone_result_key ) )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'No active schedules found. Create one to automate backups.', 'museder-restoreone' ); ?></p>
            <?php endif; ?>
        </div>

        <div class="backup-lite-card">
            <h2>📊 <?php esc_html_e( 'Activity (Last 7 Days)', 'museder-restoreone' ); ?></h2>
            <div class="backup-lite-chart-area">
                <canvas id="backup-lite-activity-chart" aria-label="<?php esc_attr_e( 'Backup success vs failure chart', 'museder-restoreone' ); ?>"></canvas>
                <?php // @plugin-check: escaped ?>
                <p id="bl-dashboard-chart-empty" class="backup-lite-chart-empty" <?php echo esc_attr( ( $chart_success + $chart_failed ) > 0 ? 'hidden' : '' ); ?>>
                    <?php esc_html_e( 'No activity recorded in the last 7 days.', 'museder-restoreone' ); ?>
                </p>
            </div>
        </div>

        <div class="backup-lite-card">
            <h2>📊 <?php esc_html_e( 'System Status & Latest Logs', 'museder-restoreone' ); ?></h2>
            
            <!-- Status Summary Section -->
            <div class="mrestore-status-summary" style="margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e2e8f0;">
                <!-- Last Backup -->
                <div class="mrestore-status-row" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9;">
                    <div style="flex: 1;">
                        <span class="mrestore-status-label" style="font-weight: 600; display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Last Backup', 'museder-restoreone' ); ?>
                        </span>
                        <?php if ( ! $last_backup_summary['exists'] ) : ?>
                            <p class="description" style="margin: 0; color: #64748b;">
                                <?php esc_html_e( 'No backup has been executed yet.', 'museder-restoreone' ); ?>
                            </p>
                        <?php else : ?>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <?php
                                $backup_status = $last_backup_summary['status'];
                                $status_badge_class = 'pending';
                                $status_badge_text = __( 'Unknown', 'museder-restoreone' );
                                $status_badge_icon = '⏳';
                                
                                if ( 'success' === $backup_status ) {
                                    $status_badge_class = 'success';
                                    $status_badge_text = __( 'Success', 'museder-restoreone' );
                                    $status_badge_icon = '✅';
                                } elseif ( 'failed' === $backup_status ) {
                                    $status_badge_class = 'error';
                                    $status_badge_text = __( 'Failed', 'museder-restoreone' );
                                    $status_badge_icon = '❌';
                                } elseif ( 'in_progress' === $backup_status ) {
                                    $status_badge_class = 'pending';
                                    $status_badge_text = __( 'In Progress', 'museder-restoreone' );
                                    $status_badge_icon = '⏳';
                                }
                                ?>
                                <span class="badge <?php echo esc_attr( $status_badge_class ); ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                                    <?php echo esc_html( $status_badge_icon . ' ' . $status_badge_text ); ?>
                                </span>
                                <span style="color: #64748b; font-size: 13px;">
                                    <?php
                                    $parts = [];
                                    
                                    // Job type
                                    if ( ! empty( $last_backup_summary['job_type'] ) ) {
                                        $job_type_map = [
                                            'full' => __( 'Full backup', 'museder-restoreone' ),
                                            'dual' => __( 'Dual version', 'museder-restoreone' ),
                                        ];
                                        $parts[] = $job_type_map[ $last_backup_summary['job_type'] ] ?? ucfirst( $last_backup_summary['job_type'] );
                                    }
                                    
                                    // Date and time
                                    if ( ! empty( $last_backup_summary['finished_at'] ) ) {
                                        $parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_backup_summary['finished_at'] );
                                    } elseif ( ! empty( $last_backup_summary['started_at'] ) ) {
                                        $parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_backup_summary['started_at'] );
                                    }
                                    
                                    // Size
                                    if ( ! empty( $last_backup_summary['size_bytes'] ) ) {
                                        $parts[] = size_format( $last_backup_summary['size_bytes'], 2 );
                                    }
                                    
                                    // Destinations
                                    if ( ! empty( $last_backup_summary['destinations'] ) ) {
                                        $dest_map = [
                                            'local' => __( 'Local', 'museder-restoreone' ),
                                            's3'    => __( 'S3', 'museder-restoreone' ),
                                        ];
                                        $dest_parts = array_map( function( $dest ) use ( $dest_map ) {
                                            return $dest_map[ $dest ] ?? $dest;
                                        }, $last_backup_summary['destinations'] );
                                        $parts[] = implode( ' + ', $dest_parts );
                                    }
                                    
                                    echo esc_html( implode( ' · ', $parts ) );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Last Restore -->
                <div class="mrestore-status-row" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9;">
                    <div style="flex: 1;">
                        <span class="mrestore-status-label" style="font-weight: 600; display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Last Restore', 'museder-restoreone' ); ?>
                        </span>
                        <?php if ( ! $last_restore_summary['exists'] ) : ?>
                            <p class="description" style="margin: 0; color: #64748b;">
                                <?php esc_html_e( 'No restore has been executed yet.', 'museder-restoreone' ); ?>
                            </p>
                        <?php else : ?>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <?php
                                $restore_status = $last_restore_summary['status'];
                                $restore_badge_class = 'pending';
                                $restore_badge_text = __( 'Unknown', 'museder-restoreone' );
                                $restore_badge_icon = '⏳';
                                
                                if ( 'success' === $restore_status ) {
                                    $restore_badge_class = 'success';
                                    $restore_badge_text = __( 'Success', 'museder-restoreone' );
                                    $restore_badge_icon = '✅';
                                } elseif ( 'failed' === $restore_status ) {
                                    $restore_badge_class = 'error';
                                    $restore_badge_text = __( 'Failed', 'museder-restoreone' );
                                    $restore_badge_icon = '❌';
                                } elseif ( 'in_progress' === $restore_status ) {
                                    $restore_badge_class = 'pending';
                                    $restore_badge_text = __( 'In Progress', 'museder-restoreone' );
                                    $restore_badge_icon = '⏳';
                                }
                                ?>
                                <span class="badge <?php echo esc_attr( $restore_badge_class ); ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                                    <?php echo esc_html( $restore_badge_icon . ' ' . $restore_badge_text ); ?>
                                </span>
                                <span style="color: #64748b; font-size: 13px;">
                                    <?php
                                    $restore_parts = [];
                                    
                                    // Get restore file name from history
                                    $restore_file = '';
                                    $last_restore = backup_lite_get_last_successful_restore();
                                    if ( $last_restore && ! empty( $last_restore['file'] ) ) {
                                        $restore_file = basename( $last_restore['file'] );
                                    }
                                    
                                    // Status badge text
                                    $restore_parts[] = $restore_badge_text;
                                    
                                    // Date and time
                                    if ( ! empty( $last_restore_summary['finished_at'] ) ) {
                                        $restore_parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_restore_summary['finished_at'] );
                                    } elseif ( ! empty( $last_restore_summary['started_at'] ) ) {
                                        $restore_parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_restore_summary['started_at'] );
                                    }
                                    
                                    // File name
                                    if ( ! empty( $restore_file ) ) {
                                        $restore_parts[] = esc_html( $restore_file );
                                    }
                                    
                                    echo esc_html( implode( ' · ', $restore_parts ) );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent 7 Days -->
                <div class="mrestore-status-row" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0;">
                    <div style="flex: 1;">
                        <span class="mrestore-status-label" style="font-weight: 600; display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Recent 7 Days', 'museder-restoreone' ); ?>
                        </span>
                        <?php if ( $recent_backup_stats['total_count'] === 0 ) : ?>
                            <p class="description" style="margin: 0; color: #64748b;">
                                <?php esc_html_e( 'No backups have been executed in the last 7 days.', 'museder-restoreone' ); ?>
                            </p>
                        <?php else : ?>
                            <p style="margin: 0; color: #64748b; font-size: 13px;">
                                <?php
                                printf(
                                    /* translators: 1: Number of successful backups, 2: Number of failed backups, 3: Number of days */
                                    esc_html__( '%1$d successful backup(s), %2$d failed (last %3$d days)', 'museder-restoreone' ),
                                    (int) $recent_backup_stats['success_count'],
                                    (int) $recent_backup_stats['failed_count'],
                                    (int) $recent_backup_stats['days']
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Latest Logs Section -->
            <div class="mrestore-latest-logs backup-lite-latest-logs">
                <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 14px; font-weight: 600;">
                    <?php esc_html_e( 'Latest Logs', 'museder-restoreone' ); ?>
                </h3>
                <?php if ( empty( $recent_logs ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No log files available.', 'museder-restoreone' ); ?></p>
                <?php else : ?>
                    <ul class="backup-lite-log-list backup-lite-list" data-collapsed="true">
                        <?php foreach ( $recent_logs as $index => $log ) : ?>
                            <li class="backup-lite-log-item <?php echo $index >= 3 ? 'is-extra' : ''; ?>">
                                <div class="backup-lite-log-title">
                                    <strong><?php echo esc_html( $log['name'] ); ?></strong>
                                </div>
                                <div class="backup-lite-log-meta">
                                    <span><?php echo esc_html( $log['modified'] ?? '' ); ?> · <?php echo esc_html( $log['size'] ?? '' ); ?></span>
                                </div>
                                <?php if ( ! empty( $log['download_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $log['download_url'] ); ?>" class="button button-small button-secondary" style="margin-left: 8px;">
                                        <?php esc_html_e( 'View', 'museder-restoreone' ); ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ( count( $recent_logs ) > 3 ) : ?>
                        <button type="button" class="button-link backup-lite-toggle-logs" aria-expanded="false" style="margin-top: 8px;">
                            <?php esc_html_e( 'Show all logs', 'museder-restoreone' ); ?>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Site Backup Health Score (Pro)
        // Free tier: Show upgrade prompt
        // Pro/Agency tier: Show actual score or prompt to generate report
        $is_pro_license = function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features();
        ?>
        <div class="backup-lite-card <?php echo esc_attr( $is_pro_license ? '' : 'pro-locked' ); ?>" id="museder-ai-health-score-card">
            <h2>
                🏥 <?php esc_html_e( 'Site Backup Health Score (Pro)', 'museder-restoreone' ); ?>
                <?php if ( ! $is_pro_license ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </h2>

            <?php if ( ! $is_pro_license ) : ?>
                <!-- Free tier: Upgrade prompt -->
                <p class="description" style="margin-top: 8px;">
                    <?php esc_html_e( 'Premium sites can see an overall backup health score based on schedules, recent activity, and storage hygiene.', 'museder-restoreone' ); ?>
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro' ) ); ?>" class="button button-primary" style="margin-top: 8px;">
                    <?php esc_html_e( 'Upgrade to Pro', 'museder-restoreone' ); ?>
                </a>
                <p style="margin-top: 12px; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'AI Site Scan and Backup AI Report are available in Free, but the full health score dashboard is a Pro feature.', 'museder-restoreone' ); ?>
                </p>
            <?php else : ?>
                <!-- Pro/Agency tier: Show score or prompt -->
                <?php if ( $last_backup_score === null ) : ?>
                    <!-- No report yet -->
                    <p class="description" style="margin-top: 8px; font-weight: 600;">
                        <?php esc_html_e( 'No AI backup report yet.', 'museder-restoreone' ); ?>
                    </p>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e( 'Run a Backup AI Report to generate your first health score.', 'museder-restoreone' ); ?>
                    </p>
                    <a href="#museder-ai-backup-report" class="button button-primary backup-lite-ai-scroll" style="margin-top: 8px;" data-museder-scroll="#museder-ai-backup-report">
                        <?php esc_html_e( 'Run Backup AI Report', 'museder-restoreone' ); ?>
                    </a>
                <?php else : ?>
                    <!-- Show score -->
                    <div style="margin-top: 16px;">
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 12px;">
                            <div style="font-size: 48px; font-weight: 700; line-height: 1;">
                                <?php echo esc_html( $last_backup_score ); ?><span style="font-size: 24px; color: #666;">/100</span>
                            </div>
                            <?php
                            // Determine risk badge class and color
                            $risk_class = 'backup-lite-badge--success';
                            $risk_bg = '#d4edda';
                            $risk_color = '#155724';
                            $risk_text = __( 'Low', 'museder-restoreone' );
                            
                            switch ( strtolower( $last_backup_risk ) ) {
                                case 'high':
                                    $risk_class = 'backup-lite-badge--danger';
                                    $risk_bg = '#f8d7da';
                                    $risk_color = '#721c24';
                                    $risk_text = __( 'High', 'museder-restoreone' );
                                    break;
                                case 'medium':
                                    $risk_class = 'backup-lite-badge--warning';
                                    $risk_bg = '#fff3cd';
                                    $risk_color = '#856404';
                                    $risk_text = __( 'Medium', 'museder-restoreone' );
                                    break;
                                case 'low':
                                default:
                                    $risk_class = 'backup-lite-badge--success';
                                    $risk_bg = '#d4edda';
                                    $risk_color = '#155724';
                                    $risk_text = __( 'Low', 'museder-restoreone' );
                                    break;
                            }
                            ?>
                            <span class="<?php echo esc_attr( $risk_class ); ?>" style="display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: 600; background-color: <?php echo esc_attr( $risk_bg ); ?>; color: <?php echo esc_attr( $risk_color ); ?>;">
                                <?php echo esc_html( $risk_text ); ?>
                            </span>
                        </div>

                        <?php if ( ! empty( $last_backup_report['summary'] ) ) : ?>
                            <?php
                            // Truncate summary to 100-120 characters using mb_substr for multi-byte support
                            $summary = $last_backup_report['summary'];
                            $summary_length = mb_strlen( $summary, 'UTF-8' );
                            $max_length = 120;
                            
                            if ( $summary_length > $max_length ) {
                                $truncated = mb_substr( $summary, 0, $max_length, 'UTF-8' );
                                // Try to cut at a word boundary (space or punctuation)
                                $last_space = mb_strrpos( $truncated, ' ', null, 'UTF-8' );
                                if ( $last_space !== false && $last_space > 100 ) {
                                    $truncated = mb_substr( $truncated, 0, $last_space, 'UTF-8' );
                                }
                                $summary = $truncated . '...';
                            }
                            ?>
                            <p style="margin: 12px 0; color: #555;">
                                <?php echo esc_html( $summary ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( $last_backup_updated > 0 ) : ?>
                            <p style="margin: 8px 0; font-size: 12px; color: #666;">
                                <?php
                                printf(
                                    esc_html__( 'Last updated: %s', 'museder-restoreone' ),
                                    esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_backup_updated ) )
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( ! empty( $last_backup_report['summary'] ) ) : ?>
                            <a href="#museder-ai-backup-report" class="button button-secondary museder-view-full-report" style="margin-top: 12px;" data-museder-scroll="#museder-ai-backup-report">
                                <?php esc_html_e( 'View Full AI Report', 'museder-restoreone' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- AI Site Scan (Demo) -->
        <div class="backup-lite-card" id="museder-ai-scan-card">
            <h2>🤖 <?php esc_html_e( 'AI Site Scan (Demo)', 'museder-restoreone' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Run a demo AI scan to see how Museder will analyze your site and backup health.', 'museder-restoreone' ); ?>
            </p>
            
            <div id="museder-ai-scan-content">
                <?php
                $ai_settings = Museder_AI_Service::get_settings();
                // Use global helper for license tier (considers Developer Mode)
                $license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
                    ? backup_lite_get_effective_license_tier() 
                    : ( $ai_settings['license_tier'] ?? 'free' );
                $button_text = ( $license_tier === 'free' ) 
                    ? __( 'Run Free AI Scan (1 per month)', 'museder-restoreone' )
                    : __( 'Run AI Scan', 'museder-restoreone' );
                ?>
                <button type="button" class="button button-primary" id="museder-ai-scan-btn">
                    <?php echo esc_html( $button_text ); ?>
                </button>
                
                <div id="museder-ai-scan-loading" style="display: none; margin-top: 16px;">
                    <span class="spinner is-active"></span>
                    <span><?php esc_html_e( 'Scanning…', 'museder-restoreone' ); ?></span>
                </div>
                
                <div id="museder-ai-scan-results" style="<?php echo ! empty( $last_site_scan['summary'] ) ? 'display: block;' : 'display: none;'; ?> margin-top: 16px; padding: 16px; background: #f9f9f9; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e( 'Scan Results', 'museder-restoreone' ); ?></h3>
                    <div id="museder-ai-scan-mode" style="margin-bottom: 12px; font-size: 12px; color: #666; font-style: italic;">
                        <?php if ( ! empty( $last_site_scan['summary'] ) ) : ?>
                            <?php echo esc_html( $last_site_scan['mode'] === 'demo' ? 'Demo mode (no external AI call).' : 'Powered by Museder AI (OpenAI).' ); ?>
                        <?php endif; ?>
                    </div>
                    <div id="museder-ai-scan-summary" style="margin-bottom: 12px;">
                        <strong><?php esc_html_e( 'Summary:', 'museder-restoreone' ); ?></strong>
                        <p id="museder-ai-scan-summary-text" style="margin: 8px 0;">
                            <?php echo ! empty( $last_site_scan['summary'] ) ? esc_html( $last_site_scan['summary'] ) : ''; ?>
                        </p>
                    </div>
                    <div id="museder-ai-scan-risk" style="margin-bottom: 12px;">
                        <strong><?php esc_html_e( 'Risk Level:', 'museder-restoreone' ); ?></strong>
                        <span id="museder-ai-scan-risk-badge" style="display: inline-block; margin-left: 8px; padding: 4px 12px; border-radius: 4px; font-weight: 600; <?php
                            if ( ! empty( $last_site_scan['risk_level'] ) ) {
                                $risk = strtolower( $last_site_scan['risk_level'] );
                                $risk_colors = [
                                    'low' => [ 'bg' => '#d4edda', 'color' => '#155724', 'text' => __( 'Low', 'museder-restoreone' ) ],
                                    'medium' => [ 'bg' => '#fff3cd', 'color' => '#856404', 'text' => __( 'Medium', 'museder-restoreone' ) ],
                                    'high' => [ 'bg' => '#f8d7da', 'color' => '#721c24', 'text' => __( 'High', 'museder-restoreone' ) ],
                                ];
                                $risk_style = $risk_colors[ $risk ] ?? $risk_colors['medium'];
                                echo 'background-color: ' . esc_attr( $risk_style['bg'] ) . '; color: ' . esc_attr( $risk_style['color'] ) . ';';
                            }
                        ?>">
                            <?php
                            if ( ! empty( $last_site_scan['risk_level'] ) ) {
                                $risk = strtolower( $last_site_scan['risk_level'] );
                                $risk_texts = [
                                    'low' => __( 'Low', 'museder-restoreone' ),
                                    'medium' => __( 'Medium', 'museder-restoreone' ),
                                    'high' => __( 'High', 'museder-restoreone' ),
                                ];
                                echo esc_html( $risk_texts[ $risk ] ?? $risk_texts['medium'] );
                            }
                            ?>
                        </span>
                    </div>
                    <div id="museder-ai-scan-recommendations" style="margin-top: 16px;">
                        <strong><?php esc_html_e( 'Recommendations:', 'museder-restoreone' ); ?></strong>
                        <ul id="museder-ai-scan-recommendations-list" style="margin: 8px 0; padding-left: 20px;">
                            <?php
                            if ( ! empty( $last_site_scan['recommendations'] ) && is_array( $last_site_scan['recommendations'] ) ) {
                                foreach ( $last_site_scan['recommendations'] as $rec ) {
                                    echo '<li>' . esc_html( $rec ) . '</li>';
                                }
                            }
                            ?>
                        </ul>
                    </div>
                </div>
                
                <div id="museder-ai-scan-error" style="display: none; margin-top: 16px; padding: 12px; background: #ffeaea; border-left: 4px solid #dc3232; border-radius: 4px; color: #721c24;">
                    <strong><?php esc_html_e( 'Error:', 'museder-restoreone' ); ?></strong>
                    <span id="museder-ai-scan-error-message"></span>
                </div>
            </div>
        </div>

        <!-- Backup AI Report (Preview) -->
        <div class="backup-lite-card" id="museder-ai-backup-report">
            <h2>📊 <?php esc_html_e( 'Backup AI Report (Preview)', 'museder-restoreone' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Get a detailed AI report about your backup strategy, schedules and restore risks.', 'museder-restoreone' ); ?>
            </p>
            
            <div id="museder-ai-backup-report-content">
                <?php
                $ai_settings = Museder_AI_Service::get_settings();
                // Use global helper for license tier (considers Developer Mode)
                $license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
                    ? backup_lite_get_effective_license_tier() 
                    : ( $ai_settings['license_tier'] ?? 'free' );
                $button_text = ( $license_tier === 'free' ) 
                    ? __( 'Run Free Backup Report (1 per month)', 'museder-restoreone' )
                    : __( 'Run Backup AI Report', 'museder-restoreone' );
                ?>
                <button type="button" class="button button-primary" id="museder-ai-backup-report-btn">
                    <?php echo esc_html( $button_text ); ?>
                </button>
                
                <div id="museder-ai-backup-report-loading" style="display: none; margin-top: 16px;">
                    <span class="spinner is-active"></span>
                    <span><?php esc_html_e( 'Generating report…', 'museder-restoreone' ); ?></span>
                </div>
                
                <div id="museder-ai-backup-report-results" style="<?php echo ! empty( $last_backup_report['summary'] ) ? 'display: block;' : 'display: none;'; ?> margin-top: 16px; padding: 16px; background: #f9f9f9; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e( 'Report Results', 'museder-restoreone' ); ?></h3>
                    <div id="museder-ai-backup-report-mode" style="margin-bottom: 12px; font-size: 12px; color: #666; font-style: italic;">
                        <?php if ( ! empty( $last_backup_report['summary'] ) ) : ?>
                            <?php echo esc_html( $last_backup_report['mode'] === 'demo' ? 'Demo mode (no external AI call).' : 'Powered by Museder AI (OpenAI).' ); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="museder-ai-backup-report-summary" style="margin-bottom: 12px;">
                        <strong><?php esc_html_e( 'Summary:', 'museder-restoreone' ); ?></strong>
                        <p id="museder-ai-backup-report-summary-text" style="margin: 8px 0;">
                            <?php echo ! empty( $last_backup_report['summary'] ) ? esc_html( $last_backup_report['summary'] ) : ''; ?>
                        </p>
                    </div>
                    
                    <div id="museder-ai-backup-report-score" style="margin-bottom: 12px;">
                        <strong><?php esc_html_e( 'Overall Score:', 'museder-restoreone' ); ?></strong>
                        <span id="museder-ai-backup-report-score-badge" style="display: inline-block; margin-left: 8px; padding: 4px 12px; border-radius: 4px; font-weight: 600; <?php
                            if ( $last_backup_score !== null ) {
                                if ( $last_backup_score >= 80 ) {
                                    echo 'background-color: #d4edda; color: #155724;';
                                } elseif ( $last_backup_score >= 60 ) {
                                    echo 'background-color: #fff3cd; color: #856404;';
                                } else {
                                    echo 'background-color: #f8d7da; color: #721c24;';
                                }
                            }
                        ?>">
                            <?php echo $last_backup_score !== null ? esc_html( $last_backup_score . '/100' ) : ''; ?>
                        </span>
                    </div>
                    
                    <div id="museder-ai-backup-report-risk" style="margin-bottom: 12px;">
                        <strong><?php esc_html_e( 'Risk Level:', 'museder-restoreone' ); ?></strong>
                        <span id="museder-ai-backup-report-risk-badge" style="display: inline-block; margin-left: 8px; padding: 4px 12px; border-radius: 4px; font-weight: 600; <?php
                            if ( ! empty( $last_backup_risk ) ) {
                                $risk = strtolower( $last_backup_risk );
                                $risk_colors = [
                                    'low' => [ 'bg' => '#d4edda', 'color' => '#155724', 'text' => __( 'Low', 'museder-restoreone' ) ],
                                    'medium' => [ 'bg' => '#fff3cd', 'color' => '#856404', 'text' => __( 'Medium', 'museder-restoreone' ) ],
                                    'high' => [ 'bg' => '#f8d7da', 'color' => '#721c24', 'text' => __( 'High', 'museder-restoreone' ) ],
                                ];
                                $risk_style = $risk_colors[ $risk ] ?? $risk_colors['medium'];
                                echo 'background-color: ' . esc_attr( $risk_style['bg'] ) . '; color: ' . esc_attr( $risk_style['color'] ) . ';';
                            }
                        ?>">
                            <?php
                            if ( ! empty( $last_backup_risk ) ) {
                                $risk = strtolower( $last_backup_risk );
                                $risk_texts = [
                                    'low' => __( 'Low', 'museder-restoreone' ),
                                    'medium' => __( 'Medium', 'museder-restoreone' ),
                                    'high' => __( 'High', 'museder-restoreone' ),
                                ];
                                echo esc_html( $risk_texts[ $risk ] ?? $risk_texts['medium'] );
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div id="museder-ai-backup-report-risk-factors" style="margin-top: 16px; margin-bottom: 16px;">
                        <strong><?php esc_html_e( 'Risk Factors:', 'museder-restoreone' ); ?></strong>
                        <ul id="museder-ai-backup-report-risk-factors-list" style="margin: 8px 0; padding-left: 20px;">
                            <?php
                            if ( ! empty( $last_backup_report['risk_factors'] ) && is_array( $last_backup_report['risk_factors'] ) ) {
                                foreach ( $last_backup_report['risk_factors'] as $factor ) {
                                    $name = isset( $factor['name'] ) ? $factor['name'] : '';
                                    $severity = isset( $factor['severity'] ) ? strtolower( $factor['severity'] ) : 'medium';
                                    $details = isset( $factor['details'] ) ? $factor['details'] : '';
                                    $severity_colors = [
                                        'low' => '#28a745',
                                        'medium' => '#ffc107',
                                        'high' => '#dc3545'
                                    ];
                                    $severity_color = $severity_colors[ $severity ] ?? '#666';
                                    echo '<li>';
                                    if ( $name ) {
                                        echo '<strong style="color: ' . esc_attr( $severity_color ) . ';">' . esc_html( $name ) . '</strong>';
                                    }
                                    if ( $details ) {
                                        echo ': ' . esc_html( $details );
                                    }
                                    echo '</li>';
                                }
                            }
                            ?>
                        </ul>
                    </div>
                    
                    <div id="museder-ai-backup-report-recommendations" style="margin-top: 16px;">
                        <strong><?php esc_html_e( 'Recommendations:', 'museder-restoreone' ); ?></strong>
                        <ul id="museder-ai-backup-report-recommendations-list" style="margin: 8px 0; padding-left: 20px;">
                            <?php
                            if ( ! empty( $last_backup_report['recommendations'] ) && is_array( $last_backup_report['recommendations'] ) ) {
                                foreach ( $last_backup_report['recommendations'] as $rec ) {
                                    echo '<li>' . esc_html( is_array( $rec ) ? ( $rec['text'] ?? '' ) : $rec ) . '</li>';
                                }
                            }
                            ?>
                        </ul>
                    </div>
                </div>
                
                <div id="museder-ai-backup-report-error" style="display: none; margin-top: 16px; padding: 12px; background: #ffeaea; border-left: 4px solid #dc3232; border-radius: 4px; color: #721c24;">
                    <strong><?php esc_html_e( 'Error:', 'museder-restoreone' ); ?></strong>
                    <span id="museder-ai-backup-report-error-message"></span>
                </div>
            </div>
        </div>
    </div>
</div>

