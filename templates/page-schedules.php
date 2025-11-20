<?php
/**
 * Backup Lite schedules page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$schedules       = isset( $schedules ) ? $schedules : Backup_Lite_Schedule_Handler::list_schedules();
$total_schedules = is_array( $schedules ) ? count( $schedules ) : 0;
$enabled_count   = 0;
$next_run_label  = __( 'Not scheduled', 'museder-restoreone' );
$next_run_title  = '—';
$next_run_diff   = '';

if ( $total_schedules ) {
    $now = time();
    foreach ( $schedules as $schedule ) {
        if ( isset( $schedule['status'] ) && 'disabled' !== $schedule['status'] ) {
            $enabled_count++;
        }

        if ( empty( $schedule['next_run'] ) ) {
            continue;
        }

        $timestamp = is_numeric( $schedule['next_run'] ) ? (int) $schedule['next_run'] : strtotime( $schedule['next_run'] );
        if ( ! $timestamp ) {
            continue;
        }

        if ( ! isset( $earliest_timestamp ) || $timestamp < $earliest_timestamp ) {
            $earliest_timestamp = $timestamp;
            $next_run_label     = backup_lite_local_time( 'Y-m-d H:i', $timestamp );
            $next_run_title     = ! empty( $schedule['title'] ) ? $schedule['title'] : __( '(Untitled)', 'museder-restoreone' );
            if ( $timestamp >= $now ) {
                $next_run_diff = human_time_diff( $now, $timestamp );
            }
        }
    }
}
?>

<div class="wrap backup-lite-admin backup-lite-schedules">
    <div class="schedule-hero">
        <div class="schedule-hero-header">
            <div>
                <span class="badge"><?php esc_html_e( 'Automate everything', 'museder-restoreone' ); ?></span>
                <h1><?php esc_html_e( 'Scheduled Backups', 'museder-restoreone' ); ?></h1>
                <p><?php esc_html_e( 'Keep your site safe with reliable automation. Build, monitor, and tweak recurring jobs in one place.', 'museder-restoreone' ); ?></p>
            </div>
            <button type="button" class="button button-primary button-glow" data-bl-action="new-schedule">
                ✨ <?php esc_html_e( 'Create Schedule', 'museder-restoreone' ); ?>
            </button>
        </div>
        <div class="schedule-hero-stats">
            <div class="schedule-hero-stat">
                <span class="label"><?php esc_html_e( 'Total schedules', 'museder-restoreone' ); ?></span>
                <span class="value"><?php echo esc_html( $total_schedules ); ?></span>
            </div>
            <div class="schedule-hero-stat">
                <span class="label"><?php esc_html_e( 'Active', 'museder-restoreone' ); ?></span>
                <span class="value"><?php echo esc_html( $enabled_count ); ?></span>
            </div>
            <div class="schedule-hero-stat">
                <span class="label"><?php esc_html_e( 'Next run', 'museder-restoreone' ); ?></span>
                <span class="value"><?php echo esc_html( $next_run_label ); ?></span>
                <?php // @plugin-check: escaped ?>
                <span class="label"><?php echo esc_html( $next_run_title ); ?><?php echo $next_run_diff ? ' · ' . esc_html( sprintf(
                    /* translators: %s: Relative time until the next run. */
                    __( 'in %s', 'museder-restoreone' ),
                    esc_html( $next_run_diff )
                ) ) : ''; ?></span>
            </div>
        </div>
    </div>

    <?php
    // Hide other plugins' admin notices on this page to avoid confusion
    // These notices appear in the WordPress admin area and can be mistaken for our plugin's content
    ?>
    <style>
        /* Hide other plugins' admin notices on the schedules page */
        .backup-lite-schedules .notice:not(.backup-lite-notice),
        .backup-lite-schedules .update-nag:not(.backup-lite-notice),
        .backup-lite-schedules .error:not(.backup-lite-notice),
        .backup-lite-schedules .updated:not(.backup-lite-notice) {
            display: none !important;
        }
        /* Specifically target common plugin notice containers */
        .backup-lite-schedules > .notice,
        .backup-lite-schedules > .update-nag,
        .backup-lite-schedules > .error,
        .backup-lite-schedules > .updated {
            display: none !important;
        }
    </style>
    <script>
        (function() {
            // Remove other plugins' admin notices that appear before our content
            // This prevents confusion where users might think these are our plugin's features
            document.addEventListener('DOMContentLoaded', function() {
                var schedulesPage = document.querySelector('.backup-lite-schedules');
                if (schedulesPage) {
                    // Find all notices that are siblings of our page content
                    var pageWrapper = schedulesPage.closest('.wrap') || schedulesPage.parentElement;
                    if (pageWrapper) {
                        // Remove notices that are not from our plugin
                        var notices = pageWrapper.querySelectorAll('.notice:not(.backup-lite-notice), .update-nag:not(.backup-lite-notice), .error:not(.backup-lite-notice), .updated:not(.backup-lite-notice)');
                        notices.forEach(function(notice) {
                            // Only remove if it's not immediately after our hero section
                            // This allows WordPress core notices to still show
                            var heroSection = schedulesPage.querySelector('.schedule-hero');
                            if (heroSection && notice.compareDocumentPosition(heroSection) & Node.DOCUMENT_POSITION_FOLLOWING) {
                                // Notice is before our hero, remove it
                                notice.style.display = 'none';
                            }
                        });
                    }
                }
            });
        })();
    </script>

    <?php
    // AI Smart Schedule Advisor (PRO Feature)
    $is_pro = Backup_Lite_Pro::is_pro_active();
    ?>
    <?php if ( ! $is_pro ) : ?>
        <div class="backup-lite-card" style="background: linear-gradient(135deg, #facc15 0%, #fbbf24 100%); border: none; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h3 style="margin: 0 0 8px 0; color: #000; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        🤖 <?php esc_html_e( 'AI Smart Schedule Advisor', 'museder-restoreone' ); ?>
                        <span class="pro-badge" style="background: #000; color: #facc15;">PRO</span>
                    </h3>
                    <p style="margin: 0; color: rgba(0, 0, 0, 0.8); font-size: 14px;">
                        <?php esc_html_e( 'Get AI-powered recommendations for optimal backup schedules based on your site activity.', 'museder-restoreone' ); ?>
                    </p>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro' ) ); ?>" class="button button-primary" style="background: #000; color: #facc15; border: none; font-weight: 600;">
                    <?php esc_html_e( 'Upgrade to PRO', 'museder-restoreone' ); ?> →
                </a>
            </div>
        </div>
    <?php else : ?>
        <div class="backup-lite-card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        🤖 <?php esc_html_e( 'AI Smart Schedule Advisor', 'museder-restoreone' ); ?>
                    </h3>
                    <p style="margin: 0; color: var(--bl-text-muted); font-size: 14px;">
                        <?php esc_html_e( 'Get AI-powered recommendations for optimal backup schedules.', 'museder-restoreone' ); ?>
                    </p>
                </div>
                <button type="button" id="bl-ai-schedule-advisor" class="bl-button bl-button-primary">
                    <?php esc_html_e( 'Get AI Recommendations', 'museder-restoreone' ); ?>
                </button>
            </div>
            <div id="bl-ai-advisor-results" style="margin-top: 16px; display: none;"></div>
        </div>
    <?php endif; ?>

    <div class="backup-lite-card">
        <div class="bl-inline-builder-header">
            <div>
                <h2>🗂️ <?php esc_html_e( 'List Created Schedules', 'museder-restoreone' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Each schedule runs through WP-Cron. Start, edit, or delete tasks anytime.', 'museder-restoreone' ); ?></p>
            </div>
            <?php // @plugin-check: escaped ?>
            <button type="button" class="button button-primary <?php echo esc_attr( $is_pro || $total_schedules === 0 ? '' : 'pro-locked' ); ?>" id="bl-new-schedule" <?php echo $is_pro || $total_schedules === 0 ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; ?>>
                ＋ <?php esc_html_e( 'New Schedule', 'museder-restoreone' ); ?>
                <?php if ( ! $is_pro && $total_schedules >= 1 ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </button>
        </div>
        <div class="bl-table-scroll">
            <table class="backup-lite-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Event name', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Period', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Time to start', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Next run', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Last result', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Last run', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'museder-restoreone' ); ?></th>
                    </tr>
                </thead>
                <tbody id="backup-lite-schedule-body">
                    <?php if ( empty( $schedules ) ) : ?>
                        <tr class="bl-empty-row">
                            <td colspan="8"><?php esc_html_e( 'No schedules configured yet.', 'museder-restoreone' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $schedules as $schedule ) : ?>
                            <tr>
                                <td><?php echo esc_html( $schedule['title'] ); ?></td>
                                <?php // @plugin-check: escaped ?>
                                <td class="<?php echo esc_attr( 'disabled' === $schedule['status'] ? 'bl-status-disabled' : 'bl-status-enabled' ); ?>">
                                    <?php echo 'disabled' === $schedule['status'] ? esc_html__( 'Disabled', 'museder-restoreone' ) : esc_html__( 'Enabled', 'museder-restoreone' ); ?>
                                </td>
                                <td><?php echo esc_html( ucfirst( $schedule['period'] ) ); ?></td>
                                <td><?php echo esc_html( $schedule['time'] ); ?></td>
                                <td>
                                    <?php
                                    if ( ! empty( $schedule['next_run'] ) ) {
                                        echo esc_html( backup_lite_local_time( 'Y-m-d H:i', (int) $schedule['next_run'] ) );
                                    } else {
                                        esc_html_e( '—', 'museder-restoreone' );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $result_key   = isset( $schedule['last_result'] ) ? strtolower( $schedule['last_result'] ) : 'pending';
                                    $result_map   = [
                                        'success' => [ 'icon' => '✅', 'label' => __( 'Success', 'museder-restoreone' ), 'class' => 'success' ],
                                        'failed'  => [ 'icon' => '❌', 'label' => __( 'Failed', 'museder-restoreone' ), 'class' => 'error' ],
                                        'pending' => [ 'icon' => '⏳', 'label' => __( 'Pending', 'museder-restoreone' ), 'class' => 'pending' ],
                                    ];
                                    $result_value = isset( $result_map[ $result_key ] ) ? $result_map[ $result_key ] : $result_map['pending'];
                                    ?>
                                    <span class="badge <?php echo esc_attr( $result_value['class'] ); ?>">
                                        <?php echo esc_html( $result_value['icon'] . ' ' . $result_value['label'] ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ( ! empty( $schedule['last_run'] ) ) {
                                        echo esc_html( backup_lite_local_time( 'Y-m-d H:i', strtotime( $schedule['last_run'] ) ) );
                                    } else {
                                        esc_html_e( '—', 'museder-restoreone' );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <details class="bl-actions-menu">
                                        <summary class="bl-actions-trigger" aria-label="<?php esc_attr_e( 'Schedule actions', 'museder-restoreone' ); ?>">⋮</summary>
                                        <div class="bl-actions-list">
                                            <button type="button" class="button" data-schedule-action="start" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>">▶️ <?php esc_html_e( 'Start Now', 'museder-restoreone' ); ?></button>
                                            <button type="button" class="button" data-schedule-action="edit" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>">✏️ <?php esc_html_e( 'Edit', 'museder-restoreone' ); ?></button>
                                            <button type="button" class="button" data-schedule-action="delete" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>">🗑️ <?php esc_html_e( 'Delete', 'museder-restoreone' ); ?></button>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<section class="bl-inline-builder backup-lite-card" aria-labelledby="bl-inline-builder-title">
    <div class="bl-inline-builder-header">
        <div>
            <span class="badge"><?php esc_html_e( 'Quick launch', 'museder-restoreone' ); ?></span>
            <h3 id="bl-inline-builder-title"><?php esc_html_e( 'Spin up a new schedule', 'museder-restoreone' ); ?></h3>
            <p><?php esc_html_e( 'Choose when, how often, and where alerts go. This inline builder mirrors the advanced modal experience.', 'museder-restoreone' ); ?></p>
        </div>
        <div class="bl-inline-builder-insight">
            <span class="headline">✨ <?php esc_html_e( 'Pro tip', 'museder-restoreone' ); ?></span>
            <p><?php esc_html_e( 'Weekly full backups with a 30-day archive fit most production sites perfectly.', 'museder-restoreone' ); ?></p>
        </div>
    </div>
    <form id="bl-inline-schedule-form">
        <input type="hidden" data-field="id" value="">
        <div class="bl-inline-builder-grid">
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Title', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">📝</span>
                    <input type="text" data-field="title" placeholder="<?php esc_attr_e( 'Nightly web backup', 'museder-restoreone' ); ?>" required />
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Event type', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">⚙️</span>
                    <select data-field="type">
                        <option value="backup"><?php esc_html_e( 'Backup', 'museder-restoreone' ); ?></option>
                        <option value="restore"><?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></option>
                    </select>
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Schedule interval', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">⏱</span>
                    <select data-field="period">
                        <option value="daily"><?php esc_html_e( 'Daily', 'museder-restoreone' ); ?></option>
                        <option value="weekly" selected><?php esc_html_e( 'Weekly', 'museder-restoreone' ); ?></option>
                        <option value="monthly"><?php esc_html_e( 'Monthly', 'museder-restoreone' ); ?></option>
                    </select>
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Start time', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">🕒</span>
                    <input type="time" data-field="time" value="02:00" required />
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Keep the most recent (N) backups', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">📦</span>
                    <input type="number" data-field="retain" min="1" step="1" value="5" />
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Remove backups older than (days)', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">🧹</span>
                    <input type="number" data-field="max_age" min="0" step="1" value="30" />
                </div>
            </label>
            <label class="bl-form-control">
                <span><?php esc_html_e( 'Notification email (optional)', 'museder-restoreone' ); ?></span>
                <div class="bl-input-with-icon">
                    <span class="icon">✉️</span>
                    <input type="email" data-field="notify" placeholder="admin@example.com" />
                </div>
            </label>
            <label class="bl-form-control bl-toggle bl-toggle-modern">
                <span><?php esc_html_e( 'Status', 'museder-restoreone' ); ?></span>
                <div class="bl-toggle-wrapper">
                    <input type="checkbox" data-field="status" checked />
                    <span class="bl-toggle-label" data-enabled="<?php esc_attr_e( 'Enabled', 'museder-restoreone' ); ?>" data-disabled="<?php esc_attr_e( 'Disabled', 'museder-restoreone' ); ?>"></span>
                </div>
            </label>
        </div>
        <div class="bl-inline-actions">
            <button type="reset" class="button button-secondary"><?php esc_html_e( 'Reset', 'museder-restoreone' ); ?></button>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'museder-restoreone' ); ?></button>
        </div>
    </form>
</section>

<div id="bl-schedule-modal" class="bl-modal" role="dialog" aria-hidden="true">
    <div class="bl-modal-backdrop" data-bl-modal-close></div>
    <div class="bl-modal-content" role="document">
        <div class="bl-modal-header">
            <h3 id="bl-modal-title"><?php esc_html_e( 'New Schedule', 'museder-restoreone' ); ?></h3>
            <button type="button" class="bl-modal-close" data-bl-modal-close aria-label="<?php esc_attr_e( 'Close', 'museder-restoreone' ); ?>">×</button>
        </div>
        <form id="bl-schedule-form">
            <input type="hidden" name="schedule_id" id="bl-schedule-id" data-field="id" value="" />
            <div class="bl-modal-body bl-modal-body-stylish">
                <header class="bl-schedule-form-header">
                    <span class="badge"><?php esc_html_e( 'Builder', 'museder-restoreone' ); ?></span>
                    <h4><?php esc_html_e( 'Design the perfect automated backup', 'museder-restoreone' ); ?></h4>
                    <p><?php esc_html_e( 'Customize frequency, retention, and notifications. You can revisit these settings anytime.', 'museder-restoreone' ); ?></p>
                </header>
                <div class="bl-form-grid">
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Title', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">📝</span>
                            <input type="text" id="bl-schedule-title" data-field="title" required />
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Event type', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">⚙️</span>
                            <select id="bl-schedule-type" data-field="type">
                                <option value="backup"><?php esc_html_e( 'Backup', 'museder-restoreone' ); ?></option>
                                <option value="restore"><?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></option>
                            </select>
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Schedule interval', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">⏱</span>
                            <select id="bl-schedule-period" data-field="period">
                                <option value="daily"><?php esc_html_e( 'Daily', 'museder-restoreone' ); ?></option>
                                <option value="weekly"><?php esc_html_e( 'Weekly', 'museder-restoreone' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Monthly', 'museder-restoreone' ); ?></option>
                            </select>
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Start time', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">🕒</span>
                            <input type="time" id="bl-schedule-time" data-field="time" required />
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Keep the most recent (N) backups', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">📦</span>
                            <input type="number" id="bl-schedule-retain" data-field="retain" min="1" step="1" value="5" />
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Remove backups older than (days)', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">🧹</span>
                            <input type="number" id="bl-schedule-max-age" data-field="max_age" min="0" step="1" value="30" />
                        </div>
                    </label>
                    <label class="bl-form-control">
                        <span><?php esc_html_e( 'Notification email (optional)', 'museder-restoreone' ); ?></span>
                        <div class="bl-input-with-icon">
                            <span class="icon">✉️</span>
                            <input type="email" id="bl-schedule-notify" data-field="notify" placeholder="admin@example.com" />
                        </div>
                    </label>
                    <label class="bl-form-control bl-toggle bl-toggle-modern">
                        <span><?php esc_html_e( 'Status', 'museder-restoreone' ); ?></span>
                        <div class="bl-toggle-wrapper">
                            <input type="checkbox" id="bl-schedule-status" data-field="status" checked />
                            <span class="bl-toggle-label" data-enabled="<?php esc_attr_e( 'Enabled', 'museder-restoreone' ); ?>" data-disabled="<?php esc_attr_e( 'Disabled', 'museder-restoreone' ); ?>"></span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="bl-modal-footer">
                <button type="button" class="button" data-bl-modal-close><?php esc_html_e( 'Cancel', 'museder-restoreone' ); ?></button>
                <button type="submit" class="button button-primary" id="bl-save-schedule"><?php esc_html_e( 'Save', 'museder-restoreone' ); ?></button>
            </div>
        </form>
    </div>
</div>

