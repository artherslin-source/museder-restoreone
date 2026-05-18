<?php
/**
 * Template for Museder RestoreOne admin page.
 *
 * 注意：此檔案中的變數（例如 $is_pro, $backups 等）皆由上層控制器在 include 前建立，
 * 作用範圍僅限此模板檔案，並非在 WordPress 全域命名空間中到處使用的真正「全域變數」。
 * 為了維持模板可讀性與向後相容性，我們在此關閉 PrefixAllGlobals 警告。
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$status  = isset( $status ) ? $status : Museder_Restoreone_UI::get_environment_status();
$backups = isset( $backups ) ? $backups : Museder_Restoreone_UI::get_backups_list();
$settings = class_exists( 'Museder_Restoreone_Settings' ) ? Museder_Restoreone_Settings::get_settings() : [];
?>

<div class="wrap backup-lite-admin backup-lite-backups">
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
                <p class="description" style="margin-top: 8px; margin-bottom: 0;">
                    <?php esc_html_e( 'Actual backup archives are compressed and usually smaller than the estimated total size.', 'museder-restoreone' ); ?>
                </p>
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
        <p class="description" style="margin-top: 8px;">
            <?php esc_html_e( 'Note: Single files larger than 2GB are skipped for safety. If files are skipped, the backup completion message will show the reason and examples.', 'museder-restoreone' ); ?>
        </p>
        <form id="backup-lite-backup-form" method="post">
            <?php wp_nonce_field( Museder_Restoreone_UI::NONCE, 'museder_restoreone_nonce' ); ?>
            
            <?php
            $bl_backup_mode_default = isset( $settings['backup_mode_default'] ) ? (string) $settings['backup_mode_default'] : 'auto';
            if ( ! in_array( $bl_backup_mode_default, [ 'auto', 'balanced', 'fast' ], true ) ) {
                $bl_backup_mode_default = 'auto';
            }

            $bl_smart_exclude_default = isset( $settings['backup_smart_exclude_default'] ) ? (string) $settings['backup_smart_exclude_default'] : 'auto';
            if ( ! in_array( $bl_smart_exclude_default, [ 'auto', 'on', 'off' ], true ) ) {
                $bl_smart_exclude_default = 'auto';
            }

            $bl_custom_excludes_default = isset( $settings['backup_custom_excludes'] ) ? (string) $settings['backup_custom_excludes'] : '';
            ?>

            <div class="bl-form-control" style="margin-bottom: 16px;">
                <label for="bl-backup-mode">
                    <span><?php esc_html_e( 'Backup Mode', 'museder-restoreone' ); ?></span>
                </label>
                <select id="bl-backup-mode" name="backup_mode" style="min-width: 240px;">
                    <option value="auto" <?php selected( $bl_backup_mode_default, 'auto' ); ?>>
                        <?php esc_html_e( 'Auto (recommended)', 'museder-restoreone' ); ?>
                    </option>
                    <option value="balanced" <?php selected( $bl_backup_mode_default, 'balanced' ); ?>>
                        <?php esc_html_e( 'Balanced (smaller archive, slower)', 'museder-restoreone' ); ?>
                    </option>
                    <option value="fast" <?php selected( $bl_backup_mode_default, 'fast' ); ?>>
                        <?php esc_html_e( 'Fast (larger archive, much faster on shared hosting)', 'museder-restoreone' ); ?>
                    </option>
                </select>
                <small class="description">
                    <?php esc_html_e( 'Auto will speed up very large sites by reducing compression work and skipping safe-to-regenerate caches.', 'museder-restoreone' ); ?>
                </small>
            </div>

            <div class="bl-form-control" style="margin-bottom: 16px;">
                <label for="bl-backup-smart-exclude">
                    <span><?php esc_html_e( 'Smart Exclude (cache/temp)', 'museder-restoreone' ); ?></span>
                </label>
                <select id="bl-backup-smart-exclude" name="backup_smart_exclude" style="min-width: 240px;">
                    <option value="auto" <?php selected( $bl_smart_exclude_default, 'auto' ); ?>>
                        <?php esc_html_e( 'Auto (enable for large sites)', 'museder-restoreone' ); ?>
                    </option>
                    <option value="on" <?php selected( $bl_smart_exclude_default, 'on' ); ?>>
                        <?php esc_html_e( 'On', 'museder-restoreone' ); ?>
                    </option>
                    <option value="off" <?php selected( $bl_smart_exclude_default, 'off' ); ?>>
                        <?php esc_html_e( 'Off', 'museder-restoreone' ); ?>
                    </option>
                </select>
                <small class="description">
                    <?php esc_html_e( 'Safely skips common cache/temp directories that can be regenerated (helps when there are tons of small files).', 'museder-restoreone' ); ?>
                </small>
            </div>

            <div class="bl-form-control" style="margin-bottom: 16px;">
                <label for="bl-backup-custom-excludes">
                    <span><?php esc_html_e( 'Custom Excludes (one per line)', 'museder-restoreone' ); ?></span>
                </label>
                <textarea id="bl-backup-custom-excludes" name="backup_custom_excludes" rows="4" style="width: 100%; max-width: 680px;" placeholder="<?php esc_attr_e( "Example:\nwp-content/cache/\nwp-content/uploads/cache/\nnode_modules/", 'museder-restoreone' ); ?>"><?php echo esc_textarea( $bl_custom_excludes_default ); ?></textarea>
                <small class="description">
                    <?php esc_html_e( 'Optional. Use relative paths like wp-content/cache/ or directory names like node_modules. Avoid excluding important content.', 'museder-restoreone' ); ?>
                </small>
            </div>
            
            <button type="submit" class="button button-primary button-glow" id="bl-backup-btn">
                <?php esc_html_e( 'Backup Site', 'museder-restoreone' ); ?>
            </button>
        </form>
        <div class="progress-bar" id="backup-progress-container" style="position: relative; margin-top: 5pt; height: 20pt; border-radius: 5pt; background: #e2e8f0; overflow: hidden;">
            <div class="progress-bar-fill" id="backup-progress-fill" style="height: 100%; border-radius: 5pt; width: 0; background: var(--primary); transition: width 0.3s ease;"></div>
            <span id="backup-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: #fff; z-index: 10; pointer-events: none;">0%</span>
        </div>
        <p id="backup-elapsed-time" style="margin: 8px 0 0 0; font-size: 12px; color: #64748b; display: none;"></p>
        <p id="bl-backup-mode-status" style="margin: 6px 0 0 0; font-size: 12px; color: #64748b;"></p>
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
                                $duration_seconds = isset( $item['duration_seconds'] ) && is_numeric( $item['duration_seconds'] ) ? (int) $item['duration_seconds'] : null;
                                if ( $duration_seconds !== null && $duration_seconds > 0 ) {
                                    echo esc_html( museder_restoreone_format_duration( $duration_seconds ) );
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
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
                <span class="badge success">
                    <?php esc_html_e( 'Database backup/restore uses WordPress APIs (WP.org compliant)', 'museder-restoreone' ); ?>
                </span>
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