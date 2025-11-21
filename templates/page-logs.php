<?php
/**
 * Backup Lite logs page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$logs = isset( $logs ) ? $logs : Backup_Lite_Log_Handler::get_logs();
?>

<div class="wrap backup-lite-admin backup-lite-logs">
    <h1 class="backup-lite-page-title">📜 <?php esc_html_e( 'Museder RestoreOne Logs', 'museder-restoreone' ); ?></h1>
    <p class="backup-lite-page-description"><?php esc_html_e( 'Inspect backup, restore, and schedule activity. Logs are stored under wp-content/uploads/backup-lite-logs/.', 'museder-restoreone' ); ?></p>

    <div class="backup-lite-log-layout">
        <div class="log-table-wrapper">
            <div class="bl-inline-builder-header">
                <div>
                    <h2>🔥 <?php esc_html_e( 'Log Files', 'museder-restoreone' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Select a file to preview the most recent entries.', 'museder-restoreone' ); ?></p>
                </div>
                <button type="button" class="button button-primary" id="bl-refresh-logs"><?php esc_html_e( 'Refresh', 'museder-restoreone' ); ?></button>
            </div>
            <div class="bl-table-scroll">
                <table class="backup-lite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'File Name', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( 'Last Modified', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'museder-restoreone' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'museder-restoreone' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="bl-log-table-body">
                        <?php if ( empty( $logs ) ) : ?>
                            <tr class="bl-empty-row">
                                <td colspan="4"><?php esc_html_e( 'No log entries yet.', 'museder-restoreone' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $museder_restoreone_log ) : ?>
                                <tr data-log="<?php echo esc_attr( $museder_restoreone_log['name'] ); ?>">
                                    <td><?php echo esc_html( $museder_restoreone_log['name'] ); ?></td>
                                    <td><?php echo esc_html( $museder_restoreone_log['modified'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $museder_restoreone_log['size'] ?? '' ); ?></td>
                                    <td>
                                        <details class="bl-actions-menu">
                                            <summary class="bl-actions-trigger" aria-label="<?php esc_attr_e( 'Log actions', 'museder-restoreone' ); ?>">⋮</summary>
                                            <div class="bl-actions-list">
                                                <button type="button" class="button" data-log-action="view" data-log="<?php echo esc_attr( $museder_restoreone_log['name'] ); ?>">👁️ <?php esc_html_e( 'Preview', 'museder-restoreone' ); ?></button>
                                                <a class="button" href="<?php echo esc_url( $museder_restoreone_log['download_url'] ); ?>">⬇️ <?php esc_html_e( 'Download', 'museder-restoreone' ); ?></a>
                                                <button type="button" class="button" data-log-action="delete" data-log="<?php echo esc_attr( $museder_restoreone_log['name'] ); ?>">🗑️ <?php esc_html_e( 'Delete', 'museder-restoreone' ); ?></button>
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

        <div class="log-preview-panel backup-lite-card">
            <h2 id="bl-log-preview-title">🪵 <?php esc_html_e( 'Preview', 'museder-restoreone' ); ?></h2>
            <pre id="bl-log-preview-content" class="log-preview" aria-live="polite"><?php esc_html_e( 'Select a log file to preview.', 'museder-restoreone' ); ?></pre>
            <p id="bl-log-preview-note" class="description"></p>
        </div>
    </div>
</div>

