<?php
/**
 * Restore page template.
 *
 * @var array  $museder_restoreone_backup
 * @var bool   $safe_mode_active
 * @var array  $museder_restoreone_history_rows
 * @var array  $museder_restoreone_backups
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// 說明：本檔為內部後台 template，變數皆由 Museder RestoreOne 的 controller 傳入，
// 不注入至 PHP 全域命名空間，也不作為可重用 API。僅用於此畫面渲染。
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Template context: These variables use the museder_restoreone_ prefix and are scoped to this template file.
// They are provided by the rendering function and are not global namespace pollution.
$museder_restoreone_summary      = isset( $museder_restoreone_summary ) ? $museder_restoreone_summary : null;
$museder_restoreone_history_rows = isset( $museder_restoreone_history ) && is_array( $museder_restoreone_history ) ? $museder_restoreone_history : [];
$museder_restoreone_backups      = isset( $museder_restoreone_backups ) && is_array( $museder_restoreone_backups ) ? $museder_restoreone_backups : [];

// Check if safe mode is active
$safe_mode_active = get_option( 'museder_restoreone_safe_mode', '' ) === '1';
$prev_plugins_count = 0;
if ( $safe_mode_active ) {
    $prev_plugins = get_option( 'museder_restoreone_prev_active_plugins', [] );
    $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
}

// Manual DB import notice (CLI-only DB restore fallback).
$manual_db_notice = null;
if ( class_exists( 'Museder_Restoreone_Restore_Service' ) && method_exists( 'Museder_Restoreone_Restore_Service', 'get_active_job_id' ) ) {
    $active_job_id = (string) Museder_Restoreone_Restore_Service::get_active_job_id();
    if ( '' !== $active_job_id ) {
        try {
            $active_meta = Museder_Restoreone_Restore_Service::get_job_meta( $active_job_id );
            if ( is_array( $active_meta ) && ! empty( $active_meta['manual_db']['required'] ) ) {
                $download_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => 'museder_restoreone_download_restore_sql',
                            'job_id' => rawurlencode( $active_job_id ),
                        ],
                        admin_url( 'admin-post.php' )
                    ),
                    'museder_restoreone_download_restore_sql_' . $active_job_id
                );
                $manual_db_notice = [
                    'job_id' => $active_job_id,
                    'url'    => $download_url,
                ];
            }
        } catch ( Exception $e ) {
            // Ignore.
        }
    }
}
?>
    <div class="wrap backup-lite-restore">
        <h1>🧩 <?php esc_html_e( 'Restore Center', 'museder-restoreone' ); ?></h1>

        <?php if ( is_array( $manual_db_notice ) ) : ?>
        <div class="notice notice-warning" style="border-left-color:#dba617; padding: 12px 20px; margin: 20px 0;">
            <p style="margin: 0 0 6px 0; font-weight: 600;">
                <?php esc_html_e( 'Manual database import required', 'museder-restoreone' ); ?>
            </p>
            <p style="margin: 0 0 8px 0;">
                <?php esc_html_e( 'This host does not support automatic database import (MySQL CLI). RestoreOne will restore files, but you must import the database manually before the site can function normally.', 'museder-restoreone' ); ?>
            </p>
            <p style="margin: 0;">
                <a class="button button-secondary" href="<?php echo esc_url( $manual_db_notice['url'] ); ?>">
                    <?php esc_html_e( 'View manual database import steps', 'museder-restoreone' ); ?>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( $safe_mode_active ) : ?>
        <div class="notice notice-warning is-dismissible" id="backup-lite-safe-mode-notice" style="border-left-color: #ffb900; padding: 12px 20px; margin: 20px 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div style="flex: 1; min-width: 300px;">
                    <p style="margin: 0 0 8px 0; font-weight: 600;">
                        <span style="font-size: 20px; margin-right: 8px;">🛡️</span>
                        <?php esc_html_e( 'Safe Mode Active', 'museder-restoreone' ); ?>
                    </p>
                    <p style="margin: 0; color: var(--text-muted, #646970);">
                        <?php
                        printf(
                            /* translators: %d: Number of plugins recorded in the safe mode snapshot. */
                            esc_html__( 'RestoreOne saved a snapshot of %d active plugin(s) when safe mode was enabled after restore. Verify your site, then exit safe mode to clear this notice. Other plugins are not changed automatically.', 'museder-restoreone' ),
                            absint( $prev_plugins_count )
                        );
                        ?>
                    </p>
                </div>
                <div>
                    <button type="button" id="museder-restoreone-exit-safe-mode-btn" class="button button-primary" style="white-space: nowrap;">
                        <?php esc_html_e( 'Exit Safe Mode', 'museder-restoreone' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="restore-stepper" id="restore-stepper">
            <div class="restore-step-node" id="restore-step-upload" data-bl-state="active">
                <span class="restore-step-pill">Step 1</span>
                <div>
                    <strong><?php esc_html_e( 'Upload & Analyze', 'museder-restoreone' ); ?></strong>
                    <p><?php esc_html_e( 'Choose a backup archive and let RestoreOne inspect it.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="restore-step-node" id="restore-step-review" data-bl-state="locked">
                <span class="restore-step-pill">Step 2</span>
                <div>
                    <strong><?php esc_html_e( 'Review & Options', 'museder-restoreone' ); ?></strong>
                    <p><?php esc_html_e( 'Verify details and decide how the restore should behave.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="restore-step-node" id="restore-step-execute" data-bl-state="locked">
                <span class="restore-step-pill">Step 3</span>
                <div>
                    <strong><?php esc_html_e( 'Execute Restore', 'museder-restoreone' ); ?></strong>
                    <p><?php esc_html_e( 'Trigger the restore job and monitor progress.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
        </div>

        <section class="backup-lite-card restore-methods restore-step-card" data-step-card="upload">
            <div class="step-card-header">
                <div>
                    <span class="step-pill">Step 1</span>
                    <h2><?php esc_html_e( 'Backup Archive Source', 'museder-restoreone' ); ?></h2>
                </div>
                <p class="step-description"><?php esc_html_e( 'Upload a backup file or select an existing archive to begin analysis.', 'museder-restoreone' ); ?></p>
                <div class="step-status" id="step-upload-status" data-status="idle">
                    <span class="status-icon"></span>
                    <span class="status-text"><?php esc_html_e( 'Choose a backup and run Step 1.', 'museder-restoreone' ); ?></span>
                </div>
            </div>
        <div class="method-tabs">
                <button class="button-primary active" data-method="upload"><?php esc_html_e( 'Upload Local File', 'museder-restoreone' ); ?></button>
                <button class="button-secondary" data-method="existing"><?php esc_html_e( 'Select from Backups', 'museder-restoreone' ); ?></button>
        </div>
        <div id="restore-upload" class="method-panel active">
            <form id="backup-lite-restore-form-v2">
                <input type="file" id="backup-lite-restore-file-v2" accept=".zip">

                <label style="display: block; margin-top: 10px;">
                    <input type="checkbox" name="museder_restoreone_confirm" value="1">
                    <?php esc_html_e( 'I understand this will upload the selected archive for analysis.', 'museder-restoreone' ); ?>
                </label>

                <div class="backup-lite-progress" aria-live="polite">
                    <div class="progress-bar restore-upload-progress-track" style="height: 10px; width: 100%; border-radius: 4px; overflow: hidden;">
                        <div class="progress-bar-fill" style="height: 100%; width: 0%;"></div>
                    </div>
                    <div class="backup-lite-progress-status" style="margin-top: 8px;"></div>
                    <div class="backup-lite-progress-meta" style="margin-top: 4px;">
                        <span class="backup-lite-progress-speed"></span>
                        <span class="backup-lite-progress-eta" style="margin-left: 8px;"></span>
                        <div class="backup-lite-progress-sha1" style="margin-top: 4px;"></div>
                    </div>
                </div>

                <button type="submit" class="button-primary step-action">
                    <?php esc_html_e( 'Step 1 – Upload & Analyze', 'museder-restoreone' ); ?>
                </button>
            </form>
        </div>
        <div id="restore-existing" class="method-panel">
            <select id="existingBackup">
                <option value=""><?php esc_html_e( 'Select a backup…', 'museder-restoreone' ); ?></option>
                <?php foreach ( $museder_restoreone_backups as $museder_restoreone_backup ) : ?>
                    <option value="<?php echo esc_attr( $museder_restoreone_backup['name'] ); ?>"><?php echo esc_html( $museder_restoreone_backup['name'] . ' (' . size_format( $museder_restoreone_backup['size'] ) . ')' ); ?></option>
                <?php endforeach; ?>
            </select>
                <button id="selectRestore" class="button-primary step-action"><?php esc_html_e( 'Step 1 – Load Info', 'museder-restoreone' ); ?></button>
        </div>
    </section>

        <section class="backup-lite-card restore-summary">
        <h2>📄 <?php esc_html_e( 'File Summary', 'museder-restoreone' ); ?></h2>
        <div id="fileSummary">
            <?php if ( $museder_restoreone_summary ) : ?>
                <p><strong><?php esc_html_e( 'File:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $museder_restoreone_summary['name'] ); ?></p>
                <p><strong><?php esc_html_e( 'Size:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $museder_restoreone_summary['size'] ); ?></p>
                <?php if ( ! empty( $museder_restoreone_summary['sha1'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'SHA1:', 'museder-restoreone' ); ?></strong> <code><?php echo esc_html( $museder_restoreone_summary['sha1'] ); ?></code></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e( 'Source:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( ucfirst( $museder_restoreone_summary['source'] ) ); ?></p>
                <?php if ( ! empty( $museder_restoreone_summary['db_prefix_source'] ) && ! empty( $museder_restoreone_summary['db_prefix_target'] ) ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'DB Prefix (backup → target):', 'museder-restoreone' ); ?></strong>
                        <code><?php echo esc_html( $museder_restoreone_summary['db_prefix_source'] ); ?></code>
                        →
                        <code><?php echo esc_html( $museder_restoreone_summary['db_prefix_target'] ); ?></code>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p><?php esc_html_e( 'No file selected yet.', 'museder-restoreone' ); ?></p>
            <?php endif; ?>
        </div>
    </section>

        <section class="backup-lite-card restore-options restore-step-card" data-step-card="review">
            <div class="step-card-header">
                <div>
                    <span class="step-pill">Step 2</span>
                    <h2>⚙️ <?php esc_html_e( 'Review Restore Options', 'museder-restoreone' ); ?></h2>
                </div>
                <p class="step-description"><?php esc_html_e( 'Choose how the restore will handle existing data, configuration files, and safety backups.', 'museder-restoreone' ); ?></p>
                <div class="step-status" id="step-review-status" data-status="locked">
                    <span class="status-icon"></span>
                    <span class="status-text"><?php esc_html_e( 'Complete Step 1 first to unlock these options.', 'museder-restoreone' ); ?></span>
                </div>
            </div>
            <label><input type="checkbox" id="overwriteData"> <?php esc_html_e( 'Overwrite existing data', 'museder-restoreone' ); ?></label><br>
            <label><input type="checkbox" id="applyReplace"> <?php esc_html_e( 'Apply URL Search & Replace', 'museder-restoreone' ); ?></label><br>
            <label><input type="checkbox" id="skipConfig"> <?php esc_html_e( 'Skip site configuration file', 'museder-restoreone' ); ?></label><br>
            <label><input type="checkbox" id="autoBackup" checked> <?php esc_html_e( 'Backup current site before restore', 'museder-restoreone' ); ?></label><br>
            <div id="restore-files-only-wrap" style="margin-top: 12px; display: none;">
                <label>
                    <input type="checkbox" id="filesOnly">
                    <?php esc_html_e( 'Files-only restore (skip database)', 'museder-restoreone' ); ?>
                </label>
                <p class="description" style="margin: 6px 0 0 0;">
                    <?php esc_html_e( 'Use this when the backup archive does not contain a database file. Your files will be restored, but the site may not work until you import the database separately.', 'museder-restoreone' ); ?>
                </p>
            </div>
            <div style="margin-top: 12px;">
                <label>
                    <input type="checkbox" id="safeMode" checked>
                    <?php esc_html_e( 'Enter Safe Mode after restore (recommended)', 'museder-restoreone' ); ?>
                </label>
                <p class="description" style="margin: 6px 0 0 0;">
                    <?php esc_html_e( 'Safe mode saves which plugins were active and shows an admin reminder after restore. It does not change plugin activation for you; use Plugins screen as needed, then exit safe mode to clear the notice.', 'museder-restoreone' ); ?>
                </p>
            </div>
            <!-- .wpress / encrypted backups are not supported in this build. -->
            <div style="margin-top: 12px;">
                <label for="restoreTargetBlogId" style="display:block; margin-bottom: 6px;">
                    <?php esc_html_e( 'Target Blog ID (Multisite only)', 'museder-restoreone' ); ?>
                </label>
                <input
                    type="number"
                    id="restoreTargetBlogId"
                    min="1"
                    step="1"
                    placeholder="<?php echo esc_attr__( 'e.g. 2', 'museder-restoreone' ); ?>"
                    style="max-width: 160px; width: 100%;"
                />
                <p class="description" style="margin-top: 6px;">
                    <?php esc_html_e( 'If you are restoring a subsite backup into a multisite, set which Blog ID should receive the data.', 'museder-restoreone' ); ?>
                </p>
            </div>
    </section>

        <section class="backup-lite-card restore-step-card" data-step-card="execute">
            <div class="step-card-header">
                <div>
                    <span class="step-pill">Step 3</span>
                    <h2>⚡ <?php esc_html_e( 'Execute Restore', 'museder-restoreone' ); ?></h2>
                </div>
                <p class="step-description"><?php esc_html_e( 'Ready when you are. We will show real-time progress and notify you when it finishes.', 'museder-restoreone' ); ?></p>
                <div class="step-status" id="step-execute-status" data-status="locked">
                    <span class="status-icon"></span>
                    <span class="status-text"><?php esc_html_e( 'Complete Steps 1 & 2 before starting the restore.', 'museder-restoreone' ); ?></span>
                </div>
            </div>
            <button id="startRestore" class="button-primary button-glow step-cta"><?php esc_html_e( 'Step 3 – Start Restore', 'museder-restoreone' ); ?></button>
            <button
                id="forceRestoreUnlock"
                type="button"
                class="button button-secondary"
                style="margin-left: 8px;"
                title="<?php echo esc_attr__( 'Use only if you are sure no restore is running. This will clear a stuck restore lock so you can start again.', 'museder-restoreone' ); ?>"
            >
                <?php esc_html_e( 'Force Unlock', 'museder-restoreone' ); ?>
            </button>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e( 'If Step 3 is blocked with “Another restore is already in progress” but you are sure nothing is running, click Force Unlock to clear the stuck lock.', 'museder-restoreone' ); ?>
            </p>
            <div id="restore-progress-container" class="restore-progress-panel" style="display: none;">
            <div style="text-align: center; margin-bottom: 16px;">
                <div id="restore-status-icon" style="font-size: 48px; margin-bottom: 12px;">⚡</div>
                <div id="restore-status-title" style="font-size: 20px; font-weight: 600; color: var(--bl-primary); margin-bottom: 8px; display: flex; align-items: center; justify-content: center; gap: 30px;">
                    <span id="restore-status-icon-inline" style="font-size: 24px; display: none;">✅</span>
                    <span id="restore-status-title-text"><?php esc_html_e( 'Restore in Progress', 'museder-restoreone' ); ?></span>
                </div>
                <div id="restore-status-message" style="font-size: 14px; color: var(--bl-text-muted);">
                    <?php esc_html_e( 'Please wait while we restore your site...', 'museder-restoreone' ); ?>
                </div>
            </div>
            <div class="progress-bar restore-execute-progress-track" style="height: 24px; border-radius: 12px; overflow: hidden; margin-bottom: 12px; position: relative;">
                <div id="restore-progress-fill" class="progress-bar-fill" style="width:0%; height: 100%; background: linear-gradient(90deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); transition: width 0.3s ease;"></div>
                <span id="restore-progress-text" class="restore-progress-percent" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: 600; font-size: 12px; pointer-events: none; text-align: center; width: 100%;">0%</span>
            </div>
            <p id="restore-progress-status" class="progress-status" style="text-align: center; font-size: 13px; color: var(--bl-text-muted); margin: 0;">
                <?php echo esc_html( isset( $museder_restoreone_progress['message'] ) ? $museder_restoreone_progress['message'] : __( 'Waiting for action...', 'museder-restoreone' ) ); ?>
            </p>
            <button type="button" id="restore-cancel-btn" class="button button-secondary" style="display:none; margin-top: 16px;">
                <?php esc_html_e( 'Cancel Restore', 'museder-restoreone' ); ?>
            </button>
        </div>
            <div id="restore-waiting-message" style="text-align: center; padding: 24px; color: var(--bl-text-muted);">
            <p style="margin: 0;"><?php esc_html_e( 'Waiting for action...', 'museder-restoreone' ); ?></p>
        </div>
    </section>

    <section class="backup-lite-card restore-history">
        <h2>🧾 <?php esc_html_e( 'Restore History', 'museder-restoreone' ); ?></h2>
        <?php if ( ! empty( $museder_restoreone_history_rows ) ) : ?>
            <div style="margin-bottom: 12px;">
                <button type="button" class="button button-secondary" id="bl-delete-selected-restore-history" style="display: none;">
                    <?php esc_html_e( 'Delete Selected', 'museder-restoreone' ); ?>
                </button>
            </div>
        <?php endif; ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="bl-restore-history-master-checkbox" />
                    </td>
                    <th scope="col"><?php esc_html_e( 'Date/Time', 'museder-restoreone' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'File', 'museder-restoreone' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Result', 'museder-restoreone' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Duration', 'museder-restoreone' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Log', 'museder-restoreone' ); ?></th>
                </tr>
            </thead>
            <tbody id="restoreHistory">
                <?php if ( ! empty( $museder_restoreone_history_rows ) ) : ?>
                    <?php foreach ( $museder_restoreone_history_rows as $entry ) : ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <label class="screen-reader-text" for="restore-history-<?php echo esc_attr( $entry['id'] ); ?>">
                                    <?php esc_html_e( 'Select restore record', 'museder-restoreone' ); ?>
                                </label>
                                <input
                                    id="restore-history-<?php echo esc_attr( $entry['id'] ); ?>"
                                    type="checkbox"
                                    name="restore_history_ids[]"
                                    value="<?php echo esc_attr( $entry['id'] ); ?>"
                                />
                            </th>
                            <td>
                                <span class="screen-reader-text">
                                    <?php esc_html_e( 'Date/Time', 'museder-restoreone' ); ?>
                                </span>
                                <?php
                                echo esc_html(
                                    isset( $entry['date_human'] ) && '' !== $entry['date_human']
                                        ? $entry['date_human']
                                        : '—'
                                );
                                ?>
                            </td>
                            <td class="column-primary">
                                <strong><?php echo esc_html( $entry['file'] ); ?></strong>
                            </td>
                            <td>
                                <span class="backup-lite-restore-status backup-lite-restore-status--<?php echo esc_attr( strtolower( $entry['result'] ) ); ?>">
                                    <?php echo esc_html( $entry['result'] ); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $duration = isset( $entry['duration'] ) ? (int) $entry['duration'] : -1;
                                $duration_human = museder_restoreone_format_duration( $duration );
                                echo esc_html( '' !== $duration_human ? $duration_human : '—' );
                                ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $entry['log_download_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $entry['log_download_url'] ); ?>" class="button button-secondary">
                                        <?php esc_html_e( 'Download', 'museder-restoreone' ); ?>
                                    </a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="6">
                            <?php esc_html_e( 'No restore history found.', 'museder-restoreone' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
