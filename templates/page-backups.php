<?php
/**
 * Backup Lite backups page.
 *
 * @package BackupLite
 *
 * @var array $backups
 * @var bool  $is_pro
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// 說明：本檔為內部後台 template，變數皆由 Museder RestoreOne 的 controller 傳入，
// 不注入至 PHP 全域命名空間，也不作為可重用 API。僅用於此畫面渲染。
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Template context: These variables are scoped to this template file and provided by the rendering function.
// They use short names for template readability but are not global namespace pollution.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$status  = isset( $status ) ? $status : Backup_Lite_UI::get_environment_status();
$backups = isset( $backups ) ? $backups : Backup_Lite_UI::get_backups_list();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>

<div class="wrap backup-lite-admin backup-lite-backups">
    <h1 class="backup-lite-page-title">📦 <?php esc_html_e( 'Backups Center', 'museder-restoreone' ); ?></h1>
    <p class="backup-lite-page-description"><?php esc_html_e( 'Create fresh snapshots, restore archives, and manage your backup library with ease.', 'museder-restoreone' ); ?></p>

    <div id="backup-lite-messages" class="backup-lite-messages" role="status" aria-live="polite"></div>

    <?php
    $is_pro = Backup_Lite_Pro::is_pro_active();
    ?>
    
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
        <form id="backup-lite-backup-form" method="post">
            <?php wp_nonce_field( Backup_Lite_UI::NONCE, 'backup_lite_nonce' ); ?>
            
            <?php if ( $is_pro ) : ?>
                <!-- PRO: Backup Label -->
                <div class="bl-form-control" style="margin-bottom: 16px;">
                    <label>
                        <span><?php esc_html_e( 'Backup Label', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <input type="text" id="bl-backup-label" name="backup_label" placeholder="<?php esc_attr_e( 'e.g., Before major update', 'museder-restoreone' ); ?>" />
                        <small class="description"><?php esc_html_e( 'Add a label to identify this backup (optional).', 'museder-restoreone' ); ?></small>
                    </label>
                </div>

                <!-- PRO: Backup Encryption -->
                <div class="bl-form-control" style="margin-bottom: 16px;">
                    <label class="backup-lite-toggle">
                        <input type="checkbox" id="bl-backup-encrypt" name="backup_encrypt" />
                        <span>
                            <?php esc_html_e( 'Encrypt backup with AES-256', 'museder-restoreone' ); ?>
                            <span class="pro-badge">PRO</span>
                        </span>
                    </label>
                    <small class="description"><?php esc_html_e( 'Encrypt the backup archive for additional security.', 'museder-restoreone' ); ?></small>
                </div>

                <!-- PRO: Dual Version Backup -->
                <div class="bl-form-control" style="margin-bottom: 16px;">
                    <label class="backup-lite-toggle">
                        <input type="checkbox" id="bl-backup-dual" name="backup_dual" />
                        <span>
                            <?php esc_html_e( 'Create dual version (Snapshot + Full)', 'museder-restoreone' ); ?>
                            <span class="pro-badge">PRO</span>
                        </span>
                    </label>
                    <small class="description"><?php esc_html_e( 'Create both a quick snapshot and a full backup.', 'museder-restoreone' ); ?></small>
                </div>

                <!-- PRO: Cloud Storage Destinations -->
                <div class="bl-form-control" style="margin-bottom: 16px;">
                    <label>
                        <span><?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <select id="bl-backup-cloud" name="backup_cloud" multiple style="min-height: 100px;">
                            <option value="local" selected><?php esc_html_e( 'Local Storage', 'museder-restoreone' ); ?></option>
                            <option value="google_drive" disabled><?php esc_html_e( 'Google Drive (Coming Soon)', 'museder-restoreone' ); ?></option>
                            <option value="s3" disabled><?php esc_html_e( 'Amazon S3 (Coming Soon)', 'museder-restoreone' ); ?></option>
                            <option value="dropbox" disabled><?php esc_html_e( 'Dropbox (Coming Soon)', 'museder-restoreone' ); ?></option>
                        </select>
                        <small class="description"><?php esc_html_e( 'Select cloud storage destinations (multiple selection supported).', 'museder-restoreone' ); ?></small>
                    </label>
                </div>
            <?php else : ?>
                <!-- Free: Locked PRO Features -->
                <div class="bl-form-control pro-locked" style="margin-bottom: 16px; opacity: 0.5;" data-upgrade="pro">
                    <label>
                        <span><?php esc_html_e( 'Backup Label', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <input type="text" disabled placeholder="<?php esc_attr_e( 'e.g., Before major update', 'museder-restoreone' ); ?>" />
                        <small class="description"><?php esc_html_e( 'Add a label to identify this backup (PRO feature).', 'museder-restoreone' ); ?></small>
                    </label>
                </div>

                <div class="bl-form-control pro-locked" style="margin-bottom: 16px; opacity: 0.5;" data-upgrade="pro">
                    <label class="backup-lite-toggle">
                        <input type="checkbox" disabled />
                        <span>
                            <?php esc_html_e( 'Encrypt backup with AES-256', 'museder-restoreone' ); ?>
                            <span class="pro-badge">PRO</span>
                        </span>
                    </label>
                </div>

                <div class="bl-form-control pro-locked" style="margin-bottom: 16px; opacity: 0.5;" data-upgrade="pro">
                    <label>
                        <span><?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?> <span class="pro-badge">PRO</span></span>
                        <select disabled>
                            <option><?php esc_html_e( 'Local Storage Only (Free)', 'museder-restoreone' ); ?></option>
                        </select>
                        <small class="description"><?php esc_html_e( 'Upgrade to PRO for cloud storage integration.', 'museder-restoreone' ); ?></small>
                    </label>
                </div>
            <?php endif; ?>

            <button type="submit" class="button button-primary button-glow" id="bl-backup-btn">
                <?php esc_html_e( 'Backup Site', 'museder-restoreone' ); ?>
            </button>
        </form>
        <div class="progress-bar" id="backup-progress-container" style="position: relative; margin-top: 5pt; height: 20pt; border-radius: 5pt; background: #e2e8f0; overflow: hidden;">
            <div class="progress-bar-fill" id="backup-progress-fill" style="height: 100%; border-radius: 5pt; width: 0; background: var(--primary); transition: width 0.3s ease;"></div>
            <span id="backup-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: #fff; z-index: 10; pointer-events: none;">0%</span>
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
                                <details class="bl-actions-menu">
                                    <summary class="bl-actions-trigger" aria-label="<?php esc_attr_e( 'Backup actions', 'museder-restoreone' ); ?>">⋮</summary>
                                    <div class="bl-actions-list">
                                        <button type="button" class="button backup-lite-restore-existing" data-filename="<?php echo esc_attr( $item['name'] ); ?>">▶️ <?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></button>
                                        <a class="button" href="<?php echo esc_url( $item['download_url'] ); ?>">⬇️ <?php esc_html_e( 'Download', 'museder-restoreone' ); ?></a>
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
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

