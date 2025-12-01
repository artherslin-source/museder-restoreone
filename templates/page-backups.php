<?php
/**
 * Backup Lite backups page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$status  = isset( $status ) ? $status : Backup_Lite_UI::get_environment_status();
$backups = isset( $backups ) ? $backups : Backup_Lite_UI::get_backups_list();
?>

<?php
// Check for active backup job to persist form lock state across page reloads
$active_job_data = null;
$current_job_transient = get_transient( 'backup_lite_current_job' );
if ( $current_job_transient && isset( $current_job_transient['status'] ) && 'running' === $current_job_transient['status'] ) {
    // Also check Backup_Lite_Backup_Jobs for actual job state
    $active_job = class_exists( 'Backup_Lite_Backup_Jobs' ) ? Backup_Lite_Backup_Jobs::get_active_job() : null;
    if ( $active_job ) {
        $active_job_data = array(
            'job_id' => $active_job['id'] ?? $current_job_transient['job_id'] ?? '',
            'is_running' => true,
            'started_at' => isset( $active_job['started_at'] ) ? (int) $active_job['started_at'] : ( isset( $current_job_transient['started'] ) ? (int) $current_job_transient['started'] : time() ),
            'options' => isset( $current_job_transient['options'] ) ? $current_job_transient['options'] : ( isset( $active_job['options'] ) ? $active_job['options'] : array() ),
        );
    } elseif ( isset( $current_job_transient['job_id'] ) ) {
        // Job might have completed but transient not cleared yet - check job file
        $job = class_exists( 'Backup_Lite_Backup_Jobs' ) ? Backup_Lite_Backup_Jobs::load_job( $current_job_transient['job_id'] ) : null;
        if ( $job && ! in_array( $job['status'] ?? '', array( 'completed', 'failed', 'cancelled' ), true ) ) {
            $active_job_data = array(
                'job_id' => $current_job_transient['job_id'],
                'is_running' => true,
                'started_at' => isset( $current_job_transient['started'] ) ? (int) $current_job_transient['started'] : time(),
                'options' => isset( $current_job_transient['options'] ) ? $current_job_transient['options'] : array(),
            );
        }
    }
}
$is_pro = function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features();
?>
<div class="wrap backup-lite-admin backup-lite-backups"<?php if ( $active_job_data ) : ?> data-backup-lite-active-job="<?php echo esc_attr( wp_json_encode( $active_job_data ) ); ?>"<?php endif; ?>>
    <h1 class="backup-lite-page-title">📦 <?php esc_html_e( 'Backups Center', 'museder-restoreone' ); ?></h1>
    <p class="backup-lite-page-description"><?php esc_html_e( 'Create fresh snapshots, restore archives, and manage your backup library with ease.', 'museder-restoreone' ); ?></p>

    <div id="backup-lite-messages" class="backup-lite-messages" role="status" aria-live="polite"></div>
    
    <!-- Estimated Backup Size Card -->
    <div class="backup-lite-card" id="backup-lite-estimate-card">
        <h2>
            📦 <?php esc_html_e( 'Estimated Backup Size', 'museder-restoreone' ); ?>
        </h2>
        <div id="backup-lite-estimate-content">
            <div class="backup-lite-estimate-loading" style="text-align: center; padding: 20px;">
                <span class="spinner is-active"></span>
                <p><?php esc_html_e( 'Loading size estimates...', 'museder-restoreone' ); ?></p>
            </div>
            <div class="backup-lite-estimate-results" style="display: none;">
                <div class="backup-lite-estimate-row" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #e2e8f0;">
                    <strong><?php esc_html_e( 'Database:', 'museder-restoreone' ); ?></strong>
                    <span id="backup-lite-estimate-db-size">-</span>
                </div>
                <div class="backup-lite-estimate-row" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #e2e8f0;">
                    <strong><?php esc_html_e( 'Files (wp-content):', 'museder-restoreone' ); ?></strong>
                    <span id="backup-lite-estimate-files-size">-</span>
                </div>
                <div class="backup-lite-estimate-row" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 0; font-size: 16px; font-weight: 600; border-top: 2px solid #e2e8f0; margin-top: 8px;">
                    <strong><?php esc_html_e( 'Estimated Total:', 'museder-restoreone' ); ?></strong>
                    <span id="backup-lite-estimate-total-size" style="color: var(--bl-primary, #3b82f6);">-</span>
                </div>
                <div class="backup-lite-estimate-meta" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b;">
                    <p style="margin: 4px 0;">
                        <?php esc_html_e( 'Last scanned:', 'museder-restoreone' ); ?>
                        <span id="backup-lite-estimate-last-scanned">-</span>
                    </p>
                </div>
                <div id="backup-lite-estimate-warning" class="backup-lite-estimate-warning" style="display: none; margin-top: 16px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px; color: #92400e;">
                    <strong>⚠️ <?php esc_html_e( 'Large site detected.', 'museder-restoreone' ); ?></strong>
                    <p style="margin: 4px 0 0 0; font-size: 13px;">
                        <?php esc_html_e( 'We recommend enabling chunk mode / background mode.', 'museder-restoreone' ); ?>
                    </p>
                </div>
            </div>
            <div class="backup-lite-estimate-scanning" style="display: none; margin-top: 16px;">
                <div style="margin-bottom: 8px;">
                    <strong><?php esc_html_e( 'Scanning...', 'museder-restoreone' ); ?></strong>
                    <span id="backup-lite-estimate-progress-text">0%</span>
                </div>
                <div class="progress-bar" style="position: relative; height: 20px; border-radius: 5px; background: #e2e8f0; overflow: hidden;">
                    <div class="progress-bar-fill" id="backup-lite-estimate-progress-fill" style="height: 100%; border-radius: 5px; width: 0; background: var(--bl-primary, #3b82f6); transition: width 0.3s ease;"></div>
                </div>
                <p id="backup-lite-estimate-scan-status" style="margin: 8px 0 0 0; font-size: 12px; color: #64748b;"></p>
            </div>
            <div class="backup-lite-estimate-actions" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <button type="button" class="button button-secondary" id="backup-lite-estimate-rescan">
                    <?php esc_html_e( 'Re-scan Size', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="backup-lite-card">
        <h2>
            ✨ <?php esc_html_e( 'Create Backup', 'museder-restoreone' ); ?>
        </h2>
        <p><?php esc_html_e( 'Export your entire WordPress site into a single downloadable archive.', 'museder-restoreone' ); ?></p>
        <div id="backup-lite-backup-form-wrapper">
        <form id="backup-lite-backup-form" method="post">
            <?php wp_nonce_field( 'backup_lite_run_backup', 'backup_lite_run_backup_nonce' ); ?>
            
            <?php if ( $is_pro ) : ?>
                <!-- PRO: Backup Label -->
                <div class="bl-form-control" style="margin-bottom: 16px;">
                    <label>
                        <span><?php esc_html_e( 'Backup Label', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <input type="text" id="bl-backup-label" name="backup_label" placeholder="<?php esc_attr_e( 'e.g., Before major update', 'museder-restoreone' ); ?>" />
                        <small class="description"><?php esc_html_e( 'Add a label to identify this backup (optional).', 'museder-restoreone' ); ?></small>
                    </label>
                </div>

                <!-- PRO: Backup Options -->
                <div class="mro-backup-options">
                    <div class="mro-backup-option">
                        <label for="bl-backup-encrypt" style="display: inline-flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="bl-backup-encrypt" name="backup_encrypt" />
                            <span class="mro-backup-option-label" style="margin-left: 8px;">
                            <?php esc_html_e( 'Encrypt backup with AES-256', 'museder-restoreone' ); ?>
                        </span>
                            <span class="mro-pro-badge" style="margin-left: 8px;"><?php esc_html_e( 'PRO', 'museder-restoreone' ); ?></span>
                    </label>
                        <small class="description" style="display: block; margin-top: 4px; margin-left: 0;"><?php esc_html_e( 'Encrypt the backup archive for additional security.', 'museder-restoreone' ); ?></small>
                </div>

                    <div class="mro-backup-option">
                        <label for="bl-backup-dual" style="display: inline-flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="bl-backup-dual" name="backup_dual" />
                            <span class="mro-backup-option-label" style="margin-left: 8px;">
                            <?php esc_html_e( 'Create dual version (Snapshot + Full)', 'museder-restoreone' ); ?>
                        </span>
                            <span class="mro-pro-badge" style="margin-left: 8px;"><?php esc_html_e( 'PRO', 'museder-restoreone' ); ?></span>
                    </label>
                        <small class="description" style="display: block; margin-top: 4px; margin-left: 0;"><?php esc_html_e( 'Create both a quick snapshot and a full backup.', 'museder-restoreone' ); ?></small>
                    </div>
                </div>

                <!-- S3 cloud storage: UI toggle on backups page -->
                <?php
                $s3_ready = function_exists( 'backup_lite_is_s3_ready' ) && backup_lite_is_s3_ready();
                ?>
                <h3 class="mro-section-title">
                    <?php esc_html_e( 'Backup Destinations', 'museder-restoreone' ); ?>
                </h3>
                <?php if ( $s3_ready ) : ?>
                    <div class="mro-backup-destinations">
                        <label class="mro-backup-destination is-disabled">
                            <input type="checkbox" checked="checked" disabled="disabled" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Local storage (always enabled)', 'museder-restoreone' ); ?>
                            </span>
                        </label>

                        <input type="hidden" name="backup_lite_dest_s3" value="0" />
                        <label class="mro-backup-destination">
                            <input type="checkbox"
                                   id="backup_lite_dest_s3"
                                   name="backup_lite_dest_s3"
                                   value="1" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Also upload this backup to Amazon S3 / S3-compatible storage', 'museder-restoreone' ); ?>
                            </span>
                        </label>
                    </div>
                    <p class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                        <?php esc_html_e( 'A local backup archive will always be created. When S3 upload is enabled, the finished archive will also be uploaded to your configured S3 bucket.', 'museder-restoreone' ); ?>
                    </p>
                <?php else : ?>
                    <div class="mro-backup-destinations">
                        <label class="mro-backup-destination is-disabled">
                            <input type="checkbox" checked="checked" disabled="disabled" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Local storage only', 'museder-restoreone' ); ?>
                            </span>
                    </label>
                </div>
                    <p class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                        <?php
                        printf(
                            /* translators: %s: Cloud Storage settings page link. */
                            esc_html__( 'S3 cloud backups are not configured yet. Set up S3 under %s.', 'museder-restoreone' ),
                            sprintf(
                                '<a href="%s">%s</a>',
                                esc_url( admin_url( 'admin.php?page=backup-lite-cloud' ) ),
                                esc_html__( 'Museder RestoreOne → Cloud Storage', 'museder-restoreone' )
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <!-- Free: Locked PRO Features -->
                <div class="bl-form-control pro-locked" style="margin-bottom: 16px; opacity: 0.5;" data-upgrade="pro">
                    <label>
                        <span><?php esc_html_e( 'Backup Label', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <input type="text" disabled placeholder="<?php esc_attr_e( 'e.g., Before major update', 'museder-restoreone' ); ?>" />
                        <small class="description"><?php esc_html_e( 'Add a label to identify this backup (PRO feature).', 'museder-restoreone' ); ?></small>
                    </label>
                </div>

                <div class="mro-backup-options">
                    <div class="mro-backup-option" style="opacity: 0.5;" data-upgrade="pro">
                        <label style="display: inline-flex; align-items: center; cursor: not-allowed;">
                        <input type="checkbox" disabled />
                            <span class="mro-backup-option-label" style="margin-left: 8px;">
                            <?php esc_html_e( 'Encrypt backup with AES-256', 'museder-restoreone' ); ?>
                        </span>
                            <span class="mro-pro-badge" style="margin-left: 8px;"><?php esc_html_e( 'PRO', 'museder-restoreone' ); ?></span>
                    </label>
                    </div>
                </div>

                <!-- S3 cloud storage: UI toggle on backups page (Pro feature) -->
                <?php
                $s3_ready = $is_pro && function_exists( 'backup_lite_is_s3_ready' ) && backup_lite_is_s3_ready();
                ?>
                <h3 class="mro-section-title">
                    <?php esc_html_e( 'Backup Destinations', 'museder-restoreone' ); ?>
                </h3>
                <?php if ( $s3_ready ) : ?>
                    <div class="mro-backup-destinations">
                        <label class="mro-backup-destination is-disabled">
                            <input type="checkbox" checked="checked" disabled="disabled" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Local storage (always enabled)', 'museder-restoreone' ); ?>
                            </span>
                        </label>

                        <input type="hidden" name="backup_lite_dest_s3" value="0" />
                        <label class="mro-backup-destination">
                            <input type="checkbox"
                                   id="backup_lite_dest_s3"
                                   name="backup_lite_dest_s3"
                                   value="1" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Also upload this backup to Amazon S3 / S3-compatible storage', 'museder-restoreone' ); ?>
                            </span>
                        </label>
                    </div>
                    <p class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                        <?php esc_html_e( 'A local backup archive will always be created. When S3 upload is enabled, the finished archive will also be uploaded to your configured S3 bucket.', 'museder-restoreone' ); ?>
                    </p>
                <?php elseif ( $is_pro && ! function_exists( 'backup_lite_is_s3_ready' ) ) : ?>
                    <div class="mro-backup-destinations">
                        <label class="mro-backup-destination is-disabled">
                            <input type="checkbox" checked="checked" disabled="disabled" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Local storage only', 'museder-restoreone' ); ?>
                            </span>
                        </label>
                    </div>
                    <p class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                        <?php
                        printf(
                            /* translators: %s: Cloud Storage settings page link. */
                            esc_html__( 'S3 cloud backups are not configured yet. Set up S3 under %s.', 'museder-restoreone' ),
                            sprintf(
                                '<a href="%s">%s</a>',
                                esc_url( admin_url( 'admin.php?page=backup-lite-cloud' ) ),
                                esc_html__( 'Museder RestoreOne → Cloud Storage', 'museder-restoreone' )
                            )
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <div class="mro-backup-destinations">
                        <label class="mro-backup-destination is-disabled">
                            <input type="checkbox" checked="checked" disabled="disabled" />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Local storage only', 'museder-restoreone' ); ?>
                            </span>
                        </label>
                        <label class="mro-backup-destination" style="opacity: 0.6; cursor: not-allowed;">
                            <input type="checkbox" disabled />
                            <span class="mro-backup-destination-title">
                                <?php esc_html_e( 'Also upload this backup to Amazon S3 / S3-compatible storage', 'museder-restoreone' ); ?>
                            </span>
                            <span class="mro-pro-badge"><?php esc_html_e( 'PRO', 'museder-restoreone' ); ?></span>
                    </label>
                </div>
                    <p class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                        <?php
                        printf(
                            /* translators: %s: Upgrade URL. */
                            esc_html__( 'Cloud storage is a PRO feature. %s to unlock cloud backups.', 'museder-restoreone' ),
                            sprintf(
                                '<a href="%s" target="_blank">%s</a>',
                                esc_url( Backup_Lite_Pro::get_upgrade_url() ),
                                esc_html__( 'Upgrade to PRO', 'museder-restoreone' )
                            )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <button type="submit" class="button button-primary button-glow" id="bl-backup-btn">
                <?php esc_html_e( 'Backup Site', 'museder-restoreone' ); ?>
            </button>
        </form>
        </div>
        <div id="backup-lite-current-settings" class="backup-lite-current-settings" style="display: none; margin-top: 16px; padding: 12px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
            <strong><?php esc_html_e( 'Current backup settings:', 'museder-restoreone' ); ?></strong>
            <div id="backup-lite-settings-summary" style="margin-top: 8px; font-size: 13px; color: #1e40af;">
                <?php esc_html_e( 'Settings will be shown here while a backup is running.', 'museder-restoreone' ); ?>
            </div>
        </div>
        <div id="backup-lite-progress">
        <div class="progress-bar" id="backup-progress-container" style="position: relative; margin-top: 5pt; height: 20pt; border-radius: 5pt; background: #e2e8f0; overflow: hidden;">
            <div class="progress-bar-fill" id="backup-progress-fill" style="height: 100%; border-radius: 5pt; width: 0; background: var(--primary); transition: width 0.3s ease;"></div>
            <span id="backup-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: #fff; z-index: 10; pointer-events: none;">0%</span>
            </div>
            <p id="backup-lite-elapsed-time" class="description" style="margin-top: 8px; font-size: 13px; color: #666;">
                <?php esc_html_e( 'Elapsed time: 00:00', 'museder-restoreone' ); ?>
            </p>
        </div>
        <button type="button" id="bl-backup-cancel-btn" class="button button-secondary" style="display:none; margin-top: 12px;">
            <?php esc_html_e( 'Cancel Backup', 'museder-restoreone' ); ?>
        </button>
    </div>

    <div class="backup-lite-card">
        <div class="bl-inline-builder-header">
            <div>
                <h2>📚 <?php esc_html_e( 'Available Backups', 'museder-restoreone' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Select, download, or remove backups from your archive.', 'museder-restoreone' ); ?></p>
            </div>
            <div class="bl-inline-builder-insight">
                <span class="headline">✨ <?php esc_html_e( 'Tip', 'museder-restoreone' ); ?></span>
                <p><?php esc_html_e( 'Keep at least 3 recent backups and rotate weekly for optimal coverage.', 'museder-restoreone' ); ?></p>
            </div>
        </div>
        <div class="settings-actions">
            <button type="button" class="button button-secondary" id="bl-select-all"><?php esc_html_e( 'Select All', 'museder-restoreone' ); ?></button>
            <button type="button" class="button button-secondary" id="bl-clear-selection"><?php esc_html_e( 'Clear', 'museder-restoreone' ); ?></button>
            <button type="button" class="button button-primary" id="bl-download-selected"><?php esc_html_e( 'Download Selected', 'museder-restoreone' ); ?></button>
            <button type="button" class="button button-primary" id="bl-delete-selected"><?php esc_html_e( 'Delete Selected', 'museder-restoreone' ); ?></button>
        </div>
        <?php if ( empty( $backups ) ) : ?>
            <p><?php esc_html_e( 'No backups found yet.', 'museder-restoreone' ); ?></p>
        <?php else : ?>
            <table class="backup-lite-table" id="backup-lite-table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="bl-master-checkbox" /></th>
                        <th><?php esc_html_e( 'File Name', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Size', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Duration', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'museder-restoreone' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $backups as $item ) : ?>
                        <tr>
                            <td><input type="checkbox" class="bl-row-checkbox" data-filename="<?php echo esc_attr( $item['name'] ); ?>" data-download="<?php echo esc_url( $item['download_url'] ); ?>" /></td>
                            <td>
                                <?php echo esc_html( $item['name'] ); ?>
                                <?php if ( ! empty( $item['label'] ) ) : ?>
                                    <span class="bl-tag" style="margin-left: 8px; background: var(--bl-primary); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                        <?php echo esc_html( $item['label'] ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $item['encrypted'] ) ) : ?>
                                    <span class="bl-tag" style="margin-left: 4px; background: var(--bl-success); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                        🔒 <?php esc_html_e( 'Encrypted', 'museder-restoreone' ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php esc_html_e( 'Full Site', 'museder-restoreone' ); ?></td>
                            <td><?php echo esc_html( $item['created'] ); ?></td>
                            <td><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td>
                            <td>
                                <?php
                                if ( isset( $item['duration'] ) && $item['duration'] > 0 ) {
                                    if ( function_exists( 'backup_lite_format_duration' ) ) {
                                        echo esc_html( backup_lite_format_duration( $item['duration'] ) );
                                    } elseif ( function_exists( 'backup_lite_human_readable_duration' ) ) {
                                        echo esc_html( backup_lite_human_readable_duration( $item['duration'] ) );
                                    } else {
                                        echo esc_html( sprintf( __( '%d seconds', 'museder-restoreone' ), (int) $item['duration'] ) );
                                    }
                                } else {
                                    echo '&mdash;';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $s3_status = $item['s3_status'] ?? 'none';
                                $s3_error = $item['s3_error'] ?? '';
                                
                                if ( 'success' === $s3_status ) :
                                    ?>
                                    <span class="bl-tag" style="background: var(--bl-success); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;" title="<?php esc_attr_e( 'Stored in S3', 'museder-restoreone' ); ?>">
                                        ✅ <?php esc_html_e( 'Stored in S3', 'museder-restoreone' ); ?>
                                    </span>
                                <?php elseif ( 'pending' === $s3_status ) : ?>
                                    <span class="bl-tag" style="background: #f59e0b; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;" title="<?php esc_attr_e( 'Pending S3 upload', 'museder-restoreone' ); ?>">
                                        ⏳ <?php esc_html_e( 'Pending S3 upload', 'museder-restoreone' ); ?>
                                    </span>
                                <?php elseif ( 'error' === $s3_status ) : ?>
                                    <span class="bl-tag" style="background: var(--bl-danger); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px; cursor: help;" title="<?php echo esc_attr( ! empty( $s3_error ) ? sprintf( __( 'S3 upload failed: %s', 'museder-restoreone' ), $s3_error ) : __( 'S3 upload failed', 'museder-restoreone' ) ); ?>">
                                        ❌ <?php esc_html_e( 'S3 upload failed', 'museder-restoreone' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color: #999; font-size: 12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <details class="bl-actions-menu">
                                    <summary class="bl-actions-trigger" aria-label="<?php esc_attr_e( 'Backup actions', 'museder-restoreone' ); ?>">⋮</summary>
                                    <div class="bl-actions-list">
                                        <button type="button" class="button backup-lite-restore-existing" data-filename="<?php echo esc_attr( $item['name'] ); ?>">▶️ <?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></button>
                                        <a class="button" href="<?php echo esc_url( $item['download_url'] ); ?>">⬇️ <?php esc_html_e( 'Download', 'museder-restoreone' ); ?></a>
                                        <?php
                                        // Show "Upload to Cloud" only if S3 is configured and backup is not already uploaded
                                        $s3_status = $item['s3_status'] ?? 'none';
                                        $s3_configured = function_exists( 'backup_lite_get_s3_settings' ) && ! empty( backup_lite_get_s3_settings()['enabled'] ) && ! empty( backup_lite_get_s3_settings()['bucket'] );
                                        if ( $s3_configured && 'success' !== $s3_status ) :
                                            ?>
                                            <button type="button" class="button backup-lite-upload-to-cloud" data-filename="<?php echo esc_attr( $item['name'] ); ?>" data-path="<?php echo esc_attr( $item['path'] ); ?>">☁️ <?php esc_html_e( 'Upload to Cloud', 'museder-restoreone' ); ?></button>
                                        <?php endif; ?>
                                        <button type="button" class="button backup-lite-delete-backup" data-filename="<?php echo esc_attr( $item['name'] ); ?>">🗑️ <?php esc_html_e( 'Delete', 'museder-restoreone' ); ?></button>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

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
</div>

<?php
// Audio elements for completion sounds
// Source: assets/audio/backup-complete.mp3 and restore-complete.mp3
$settings = Backup_Lite_Settings::get_settings();
$enable_sounds = ! empty( $settings['enable_sounds'] );

if ( $enable_sounds ) :
    ?>
    <audio id="backup-lite-sound-backup-complete" preload="auto">
        <source src="<?php echo esc_url( BACKUP_LITE_URL . 'assets/audio/backup-complete.mp3' ); ?>" type="audio/mpeg">
    </audio>
    <audio id="backup-lite-sound-restore-complete" preload="auto">
        <source src="<?php echo esc_url( BACKUP_LITE_URL . 'assets/audio/restore-complete.mp3' ); ?>" type="audio/mpeg">
    </audio>
    <?php
endif;
?>

