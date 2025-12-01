<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$museder_restoreone_backups = isset( $museder_restoreone_backups ) && is_array( $museder_restoreone_backups ) ? $museder_restoreone_backups : [];
?>
<div class="wrap backup-lite-restore bl-container">
    <header class="bl-card bl-restore-header">
        <div class="bl-restore-header__text">
            <h1>Restore Center</h1>
            <p><?php esc_html_e( '多階段驗證、Dry-run、正式還原與一鍵 Rollback。', 'museder-restoreone' ); ?></p>
            <p><?php esc_html_e( '建議依序完成每個步驟，確保跨主機遷移與災難復原的最高成功率。', 'museder-restoreone' ); ?></p>
        </div>
        <div class="bl-restore-header__status">
            <span class="bl-tag"><?php esc_html_e( 'Current Job', 'museder-restoreone' ); ?></span>
            <strong id="bl-restore-job-status"><?php esc_html_e( 'Restore job not started', 'museder-restoreone' ); ?></strong>
            <small id="bl-restore-job-id"></small>
        </div>
    </header>

    <section class="bl-restore-steps" id="bl-restore-step-indicator">
        <div class="bl-restore-step" data-step="1">
            <span class="bl-step-badge">Step 1</span>
            <div class="bl-restore-step__icon">📦</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Source', 'museder-restoreone' ); ?></div>
        </div>
        <div class="bl-restore-step" data-step="2">
            <span class="bl-step-badge">Step 2</span>
            <div class="bl-restore-step__icon">🛡️</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Safety', 'museder-restoreone' ); ?></div>
        </div>
        <div class="bl-restore-step" data-step="3">
            <span class="bl-step-badge">Step 3</span>
            <div class="bl-restore-step__icon">🧪</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Dry-Run', 'museder-restoreone' ); ?></div>
        </div>
        <div class="bl-restore-step" data-step="4">
            <span class="bl-step-badge">Step 4</span>
            <div class="bl-restore-step__icon">⚡</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></div>
        </div>
        <div class="bl-restore-step" data-step="5">
            <span class="bl-step-badge">Step 5</span>
            <div class="bl-restore-step__icon">⏪</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Rollback', 'museder-restoreone' ); ?></div>
        </div>
        <div class="bl-restore-step" data-step="6">
            <span class="bl-step-badge">Step 6</span>
            <div class="bl-restore-step__icon">📄</div>
            <div class="bl-restore-step__label"><?php esc_html_e( 'Activity', 'museder-restoreone' ); ?></div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-source">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">📦</span>
                <div>
                    <h3><?php esc_html_e( 'Step 1 · Select Backup Source', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Select an archive from existing backups, create a restore job and start safety validation.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right">
                <button type="button" class="bl-button bl-button-primary" id="bl-restore-prepare">
                    <?php esc_html_e( 'Create Restore Job & Validate', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
        <div class="bl-restore-source">
            <div class="bl-restore-source__instructions">
                <p class="bl-text-muted"><?php esc_html_e( 'Select the backup file to restore. The system will automatically create a restore job and start the safety validation process.', 'museder-restoreone' ); ?></p>
            </div>
            <div class="bl-restore-source__table bl-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th><?php esc_html_e( 'File Name', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'museder-restoreone' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="bl-restore-backup-list">
                        <?php if ( empty( $museder_restoreone_backups ) ) : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e( 'No backup files available.', 'museder-restoreone' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $museder_restoreone_backups as $museder_restoreone_backup ) : ?>
                                <tr data-backup-name="<?php echo esc_attr( $museder_restoreone_backup['name'] ); ?>">
                                    <td class="bl-restore-backup-radio">
                                        <input type="radio" name="restore_backup" value="<?php echo esc_attr( $museder_restoreone_backup['name'] ); ?>" />
                                    </td>
                                    <td><strong><?php echo esc_html( $museder_restoreone_backup['name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $museder_restoreone_backup['created'] ); ?></td>
                                    <td><?php echo esc_html( isset( $museder_restoreone_backup['size_human'] ) ? $museder_restoreone_backup['size_human'] : size_format( $museder_restoreone_backup['size'], 2 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-validation">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">🛡️</span>
                <div>
                    <h3><?php esc_html_e( 'Step 2 · Safety Validation', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Compare WordPress / PHP / Database / Domain information to warn of potential risks in advance.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-validation-status"></div>
        </div>
        <div class="bl-restore-validation" id="bl-restore-validation-list">
            <div class="bl-empty-state"><?php esc_html_e( 'Validation not started. Please complete Step 1 first.', 'museder-restoreone' ); ?></div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-dryrun">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">🧪</span>
                <div>
                    <h3><?php esc_html_e( 'Step 3 · Dry-Run Simulation', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Dry-Run will not modify your existing site. It simulates the restore process and generates a report.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right">
                <button type="button" class="bl-button bl-button-outline" id="bl-restore-dryrun" disabled>
                    <?php esc_html_e( 'Run Dry-Run', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
        <div class="bl-restore-dryrun">
            <div id="bl-dryrun-summary" class="bl-restore-summary">
                <div class="bl-empty-state"><?php esc_html_e( 'Dry-Run not executed yet. Complete validation to start simulation.', 'museder-restoreone' ); ?></div>
            </div>
            <div class="bl-restore-dryrun-downloads" id="bl-dryrun-downloads" hidden>
                <a href="#" id="bl-dryrun-report-txt" class="bl-button bl-button-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download TXT Report', 'museder-restoreone' ); ?></a>
                <a href="#" id="bl-dryrun-report-json" class="bl-button bl-button-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download JSON Report', 'museder-restoreone' ); ?></a>
            </div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-execute">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">⚡</span>
                <div>
                    <h3><?php esc_html_e( 'Step 4 · Execute Restore', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Overwrite the existing site according to the Dry-Run results. It is recommended to confirm the backup again before execution.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-execute-status"></div>
        </div>
        <div class="bl-restore-warning" id="bl-execute-warning">
            <strong><?php esc_html_e( 'Note:', 'museder-restoreone' ); ?></strong>
            <span><?php esc_html_e( 'This operation will overwrite the current site files and database. Please ensure you have backed up and understand cross-domain differences.', 'museder-restoreone' ); ?></span>
        </div>
        <label class="bl-restore-confirm">
            <input type="checkbox" id="bl-restore-confirm" />
            <span><?php esc_html_e( 'I understand that this operation will overwrite the current site data and I have completed the backup.', 'museder-restoreone' ); ?></span>
        </label>
        <button type="button" class="bl-button bl-button-primary bl-button-cta" id="bl-restore-execute" disabled>
            <?php esc_html_e( 'Execute Restore', 'museder-restoreone' ); ?>
        </button>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-rollback" data-state="disabled">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">⏪</span>
                <div>
                    <h3><?php esc_html_e( 'Step 5 · Rollback Snapshot Restore', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'If a Restore-Pre-Backup snapshot is available, you can immediately restore to the pre-restore state.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-rollback-status"></div>
        </div>
        <div class="bl-restore-rollback-content">
            <div class="bl-restore-warning bl-restore-warning--amber" id="bl-rollback-warning">
                <strong><?php esc_html_e( 'Warning:', 'museder-restoreone' ); ?></strong>
                <span><?php esc_html_e( 'Rollback will overwrite the current site. Please confirm again that you want to return to the Restore-Pre-Backup snapshot.', 'museder-restoreone' ); ?></span>
            </div>
            <div class="bl-rollback-meta" id="bl-rollback-meta">
                <div class="bl-empty-state"><?php esc_html_e( 'No pre-restore snapshot available. It will be created after the formal restore is completed.', 'museder-restoreone' ); ?></div>
            </div>
            <label class="bl-restore-confirm">
                <input type="checkbox" id="bl-restore-rollback-confirm" />
                <span><?php esc_html_e( 'I understand that Rollback will overwrite the current site data.', 'museder-restoreone' ); ?></span>
            </label>
            <button type="button" class="bl-button bl-button-warning" id="bl-restore-rollback" disabled>
                <?php esc_html_e( 'Execute Rollback', 'museder-restoreone' ); ?>
            </button>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-activity">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">📄</span>
                <div>
                    <h3><?php esc_html_e( 'Step 6 · Activity Log', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Complete log of each stage of the restore process and system messages.', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right bl-switch">
                <input type="checkbox" id="bl-log-autoscroll" checked />
                <label for="bl-log-autoscroll"><?php esc_html_e( 'Auto-scroll to bottom', 'museder-restoreone' ); ?></label>
            </div>
        </div>
        <div class="restore-progress-log bl-logs-line" id="bl-restore-log"></div>
    </section>
</div>
