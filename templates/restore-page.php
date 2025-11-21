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
            <strong id="bl-restore-job-status"><?php esc_html_e( '尚未開始還原作業', 'museder-restoreone' ); ?></strong>
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
                    <h3><?php esc_html_e( 'Step 1 · 選擇備份來源', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( '從現有備份中選擇一個 archive，建立還原作業並啟動安全驗證。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right">
                <button type="button" class="bl-button bl-button-primary" id="bl-restore-prepare">
                    <?php esc_html_e( '建立還原作業並進行驗證', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
        <div class="bl-restore-source">
            <div class="bl-restore-source__instructions">
                <p class="bl-text-muted"><?php esc_html_e( '選擇要還原的備份檔案，系統會自動建立還原作業並開始安全驗證流程。', 'museder-restoreone' ); ?></p>
            </div>
            <div class="bl-restore-source__table bl-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th><?php esc_html_e( '檔案名稱', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( '建立時間', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( '大小', 'museder-restoreone' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="bl-restore-backup-list">
                        <?php if ( empty( $museder_restoreone_backups ) ) : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e( '目前沒有備份檔案。', 'museder-restoreone' ); ?></td>
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
                    <h3><?php esc_html_e( 'Step 2 · 安全驗證', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( '比對 WordPress / PHP / 資料庫 / Domain 等資訊，提前預警潛在風險。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-validation-status"></div>
        </div>
        <div class="bl-restore-validation" id="bl-restore-validation-list">
            <div class="bl-empty-state"><?php esc_html_e( '尚未開始驗證。請先完成 Step 1。', 'museder-restoreone' ); ?></div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-dryrun">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">🧪</span>
                <div>
                    <h3><?php esc_html_e( 'Step 3 · Dry-Run 模擬', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( 'Dry-Run 不會修改現有站台，可預先模擬還原影響並產生報告。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right">
                <button type="button" class="bl-button bl-button-outline" id="bl-restore-dryrun" disabled>
                    <?php esc_html_e( '執行 Dry-Run', 'museder-restoreone' ); ?>
                </button>
            </div>
        </div>
        <div class="bl-restore-dryrun">
            <div id="bl-dryrun-summary" class="bl-restore-summary">
                <div class="bl-empty-state"><?php esc_html_e( '尚未執行 Dry-Run。完成驗證後即可啟動模擬。', 'museder-restoreone' ); ?></div>
            </div>
            <div class="bl-restore-dryrun-downloads" id="bl-dryrun-downloads" hidden>
                <a href="#" id="bl-dryrun-report-txt" class="bl-button bl-button-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '下載 TXT 報告', 'museder-restoreone' ); ?></a>
                <a href="#" id="bl-dryrun-report-json" class="bl-button bl-button-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '下載 JSON 報告', 'museder-restoreone' ); ?></a>
            </div>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-execute">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">⚡</span>
                <div>
                    <h3><?php esc_html_e( 'Step 4 · 執行正式還原', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( '依照 Dry-Run 結果覆蓋現有站台。建議在執行前再次確認備份。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-execute-status"></div>
        </div>
        <div class="bl-restore-warning" id="bl-execute-warning">
            <strong><?php esc_html_e( '注意：', 'museder-restoreone' ); ?></strong>
            <span><?php esc_html_e( '此操作將覆蓋目前站台的檔案與資料庫，請確定已備份並了解跨網域差異。', 'museder-restoreone' ); ?></span>
        </div>
        <label class="bl-restore-confirm">
            <input type="checkbox" id="bl-restore-confirm" />
            <span><?php esc_html_e( '我已了解此操作會覆蓋目前站台資料，並已完成備份。', 'museder-restoreone' ); ?></span>
        </label>
        <button type="button" class="bl-button bl-button-primary bl-button-cta" id="bl-restore-execute" disabled>
            <?php esc_html_e( '執行還原（Execute Restore）', 'museder-restoreone' ); ?>
        </button>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-rollback" data-state="disabled">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">⏪</span>
                <div>
                    <h3><?php esc_html_e( 'Step 5 · Rollback 快照復原', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( '若尚有 Restore-Pre-Backup 快照，可立即回復到還原前狀態。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__badge" id="bl-rollback-status"></div>
        </div>
        <div class="bl-restore-rollback-content">
            <div class="bl-restore-warning bl-restore-warning--amber" id="bl-rollback-warning">
                <strong><?php esc_html_e( '警告：', 'museder-restoreone' ); ?></strong>
                <span><?php esc_html_e( 'Rollback 將覆蓋目前站台，請再次確認要回到 Restore-Pre-Backup 快照。', 'museder-restoreone' ); ?></span>
            </div>
            <div class="bl-rollback-meta" id="bl-rollback-meta">
                <div class="bl-empty-state"><?php esc_html_e( '目前沒有可用的預先快照。完成正式還原後才會建立。', 'museder-restoreone' ); ?></div>
            </div>
            <label class="bl-restore-confirm">
                <input type="checkbox" id="bl-restore-rollback-confirm" />
                <span><?php esc_html_e( '我已了解 Rollback 會覆蓋目前站台資料。', 'museder-restoreone' ); ?></span>
            </label>
            <button type="button" class="bl-button bl-button-warning" id="bl-restore-rollback" disabled>
                <?php esc_html_e( '執行 Rollback', 'museder-restoreone' ); ?>
            </button>
        </div>
    </section>

    <section class="bl-card bl-restore-card" id="bl-restore-card-activity">
        <div class="bl-card-heading">
            <div class="bl-card-heading__left">
                <span class="bl-icon-circle">📄</span>
                <div>
                    <h3><?php esc_html_e( 'Step 6 · Activity Log', 'museder-restoreone' ); ?></h3>
                    <p class="bl-text-muted"><?php esc_html_e( '完整記錄還原流程的每個階段與系統訊息。', 'museder-restoreone' ); ?></p>
                </div>
            </div>
            <div class="bl-card-heading__right bl-switch">
                <input type="checkbox" id="bl-log-autoscroll" checked />
                <label for="bl-log-autoscroll"><?php esc_html_e( '自動捲動到底', 'museder-restoreone' ); ?></label>
            </div>
        </div>
        <div class="restore-progress-log bl-logs-line" id="bl-restore-log"></div>
    </section>
</div>
