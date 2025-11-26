<?php
/**
 * Backup Lite dashboard page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$status                   = isset( $status ) ? $status : Backup_Lite_UI::get_environment_status();
$dashboard_recent_backups = isset( $dashboard_recent_backups ) ? $dashboard_recent_backups : Backup_Lite_Dashboard::get_recent_backups( 3 );
$schedule_overview        = isset( $schedule_overview ) ? $schedule_overview : Backup_Lite_Dashboard::get_schedule_overview();
$activity_stats           = isset( $activity_stats ) ? $activity_stats : Backup_Lite_Dashboard::get_activity_stats();
$recent_logs              = isset( $recent_logs ) ? $recent_logs : Backup_Lite_Log_Handler::get_logs( 3 );

$chart_success = isset( $activity_stats['success'] ) ? (int) $activity_stats['success'] : 0;
$chart_failed  = isset( $activity_stats['failed'] ) ? (int) $activity_stats['failed'] : 0;

// Check if safe mode is active
$safe_mode_active = get_option( 'backup_lite_safe_mode', '' ) === '1';
$prev_plugins_count = 0;
if ( $safe_mode_active ) {
    $prev_plugins = get_option( 'backup_lite_prev_active_plugins', [] );
    $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
}
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
            <h2>🔥 <?php esc_html_e( 'Latest Logs', 'museder-restoreone' ); ?></h2>
            <?php if ( empty( $recent_logs ) ) : ?>
                <p class="description"><?php esc_html_e( 'No log entries yet.', 'museder-restoreone' ); ?></p>
            <?php else : ?>
                <ul class="backup-lite-list">
                    <?php foreach ( $recent_logs as $log ) : ?>
                        <li>
                            <strong><?php echo esc_html( $log['name'] ); ?></strong>
                            <span><?php echo esc_html( $log['modified'] ?? '' ); ?> · <?php echo esc_html( $log['size'] ?? '' ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php
        // Site Backup Health Score (Pro) — Lite shows promo only, no score
        // Moved to last position as it's a PRO feature and appears grayed out
        $is_pro = Backup_Lite_Pro::is_pro_active();
        ?>
        <?php // @plugin-check: escaped ?>
        <div class="backup-lite-card <?php echo esc_attr( $is_pro ? '' : 'pro-locked' ); ?>" <?php echo $is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
            <h2>
                🏥 <?php esc_html_e( 'Site Backup Health Score (Pro)', 'museder-restoreone' ); ?>
                <?php if ( ! $is_pro ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </h2>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e( 'Premium sites can see an overall backup health score based on schedules, recent activity, and storage hygiene.', 'museder-restoreone' ); ?>
            </p>
            <?php if ( ! $is_pro ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro' ) ); ?>" class="button button-primary" style="margin-top: 8px;">
                    <?php esc_html_e( 'Upgrade to Pro', 'museder-restoreone' ); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

