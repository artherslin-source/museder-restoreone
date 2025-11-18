<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$summary      = isset( $summary ) ? $summary : null;
$history_rows = isset( $history ) && is_array( $history ) ? $history : [];
$backups      = isset( $backups ) && is_array( $backups ) ? $backups : [];
?>
    <div class="wrap backup-lite-restore">
        <h1>🧩 <?php esc_html_e( 'Restore Center', 'museder-restoreone' ); ?></h1>

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
                <p class="step-description"><?php esc_html_e( 'Upload a backup file, select an existing archive, or provide a remote URL to begin analysis.', 'museder-restoreone' ); ?></p>
                <div class="step-status" id="step-upload-status" data-status="idle">
                    <span class="status-icon"></span>
                    <span class="status-text"><?php esc_html_e( 'Choose a backup and run Step 1.', 'museder-restoreone' ); ?></span>
                </div>
            </div>
        <div class="method-tabs">
                <button class="button-primary active" data-method="upload"><?php esc_html_e( 'Upload Local File', 'museder-restoreone' ); ?></button>
                <button class="button-secondary" data-method="existing"><?php esc_html_e( 'Select from Backups', 'museder-restoreone' ); ?></button>
                <button class="button-secondary" data-method="remote"><?php esc_html_e( 'Remote URL Restore', 'museder-restoreone' ); ?></button>
        </div>
        <div id="restore-upload" class="method-panel active">
            <input type="file" id="restoreFile" accept=".zip,.wpress">
                <button id="uploadRestore" class="button-primary step-action"><?php esc_html_e( 'Step 1 – Upload & Analyze', 'museder-restoreone' ); ?></button>
        </div>
        <div id="restore-existing" class="method-panel">
            <select id="existingBackup">
                <option value=""><?php esc_html_e( 'Select a backup…', 'museder-restoreone' ); ?></option>
                <?php foreach ( $backups as $backup ) : ?>
                    <option value="<?php echo esc_attr( $backup['name'] ); ?>"><?php echo esc_html( $backup['name'] . ' (' . size_format( $backup['size'] ) . ')' ); ?></option>
                <?php endforeach; ?>
            </select>
                <button id="selectRestore" class="button-primary step-action"><?php esc_html_e( 'Step 1 – Load Info', 'museder-restoreone' ); ?></button>
        </div>
        <div id="restore-remote" class="method-panel">
            <input type="text" id="remoteUrl" placeholder="https://example.com/backup.zip">
                <button id="downloadRestore" class="button-primary step-action"><?php esc_html_e( 'Step 1 – Download & Prepare', 'museder-restoreone' ); ?></button>
        </div>
    </section>

        <section class="backup-lite-card restore-summary">
        <h2>📄 <?php esc_html_e( 'File Summary', 'museder-restoreone' ); ?></h2>
        <div id="fileSummary">
            <?php if ( $summary ) : ?>
                <p><strong><?php esc_html_e( 'File:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $summary['name'] ); ?></p>
                <p><strong><?php esc_html_e( 'Size:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $summary['size'] ); ?></p>
                <?php if ( ! empty( $summary['sha1'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'SHA1:', 'museder-restoreone' ); ?></strong> <code><?php echo esc_html( $summary['sha1'] ); ?></code></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e( 'Source:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( ucfirst( $summary['source'] ) ); ?></p>
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
            <label><input type="checkbox" id="skipConfig"> <?php esc_html_e( 'Skip wp-config.php', 'museder-restoreone' ); ?></label><br>
            <label><input type="checkbox" id="autoBackup" checked> <?php esc_html_e( 'Backup current site before restore', 'museder-restoreone' ); ?></label><br>
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
            <div id="restore-progress-container" style="display: none; padding: 24px; background: linear-gradient(135deg, rgba(58, 123, 255, 0.1) 0%, rgba(36, 93, 255, 0.05) 100%); border-radius: 12px; margin: 16px 0; border: 2px solid rgba(58, 123, 255, 0.2);">
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
            <div class="progress-bar" style="height: 24px; background: rgba(0, 0, 0, 0.05); border-radius: 12px; overflow: hidden; margin-bottom: 12px;">
                <div id="restore-progress-fill" class="progress-bar-fill" style="width:0%; height: 100%; background: linear-gradient(90deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 12px;">
                    <span id="restore-progress-text">0%</span>
                </div>
            </div>
            <p id="restore-progress-status" class="progress-status" style="text-align: center; font-size: 13px; color: var(--bl-text-muted); margin: 0;">
                <?php echo esc_html( isset( $progress['message'] ) ? $progress['message'] : __( 'Waiting for action...', 'museder-restoreone' ) ); ?>
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
        <table class="wp-list-table widefat striped">
            <thead><tr><th><?php esc_html_e( 'Date/Time', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'File', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'Result', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'Log', 'museder-restoreone' ); ?></th></tr></thead>
            <tbody id="restoreHistory">
                <?php if ( ! empty( $history_rows ) ) : ?>
                    <?php foreach ( $history_rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['timestamp'] ); ?></td>
                            <td><?php echo esc_html( $row['file'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( $row['result'] ) ); ?></td>
                            <td>
                                <?php if ( ! empty( $row['log_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $row['log_url'] ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download', 'museder-restoreone' ); ?></a>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'N/A', 'museder-restoreone' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No restore history recorded yet.', 'museder-restoreone' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
