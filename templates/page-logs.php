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

// Get AI settings and last error log report
$ai_settings = Museder_AI_Service::get_settings();
// Use global helper for license tier (considers Developer Mode)
$ai_license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
    ? backup_lite_get_effective_license_tier() 
    : ( isset( $ai_settings['license_tier'] ) ? $ai_settings['license_tier'] : 'free' );
$last_log_report = Museder_AI_Service::get_last_error_log_report();
?>

<div class="wrap backup-lite-admin backup-lite-logs">
    <h1 class="backup-lite-page-title">📜 <?php esc_html_e( 'Museder RestoreOne Logs', 'museder-restoreone' ); ?></h1>
    <p class="backup-lite-page-description"><?php esc_html_e( 'Inspect backup, restore, and schedule activity. Logs are stored under wp-content/uploads/backup-lite-logs/.', 'museder-restoreone' ); ?></p>

    <!-- Error Log AI (Preview) -->
    <div class="backup-lite-card" id="museder-ai-log-analysis-card" style="margin-bottom: 24px;">
        <h2>🤖 <?php esc_html_e( 'Error Log AI (Preview)', 'museder-restoreone' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Use AI to analyze backup-related error logs and get actionable recommendations for troubleshooting.', 'museder-restoreone' ); ?>
        </p>
        
        <button
            type="button"
            id="museder-ai-log-analysis-run"
            class="button button-primary"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'museder_ai_log_analysis' ) ); ?>"
        >
            <?php esc_html_e( 'Run Log AI Analysis', 'museder-restoreone' ); ?>
        </button>
        
        <div id="museder-ai-log-analysis-loading" style="display: none; margin-top: 16px;">
            <span class="spinner is-active"></span>
            <span><?php esc_html_e( 'Analyzing logs…', 'museder-restoreone' ); ?></span>
        </div>
        
        <div id="museder-ai-log-analysis-results" style="<?php echo ! empty( $last_log_report['summary'] ) ? 'display: block;' : 'display: none;'; ?> margin-top: 16px; padding: 16px; background: #f9f9f9; border-radius: 4px;">
            <h3 style="margin-top: 0;"><?php esc_html_e( 'Analysis Results', 'museder-restoreone' ); ?></h3>
            
            <div id="museder-ai-log-analysis-mode" style="margin-bottom: 12px; font-size: 12px; color: #666; font-style: italic;">
                <?php if ( ! empty( $last_log_report['summary'] ) ) : ?>
                    <?php echo esc_html( $last_log_report['mode'] === 'demo' ? 'Demo mode (no external AI call).' : 'Powered by Museder AI (OpenAI).' ); ?>
                <?php endif; ?>
            </div>
            
            <div id="museder-ai-log-analysis-summary" style="margin-bottom: 12px;">
                <strong><?php esc_html_e( 'Summary:', 'museder-restoreone' ); ?></strong>
                <p id="museder-ai-log-analysis-summary-text" style="margin: 8px 0;">
                    <?php echo ! empty( $last_log_report['summary'] ) ? esc_html( $last_log_report['summary'] ) : ''; ?>
                </p>
            </div>
            
            <div id="museder-ai-log-analysis-risk" style="margin-bottom: 12px;">
                <strong><?php esc_html_e( 'Risk Level:', 'museder-restoreone' ); ?></strong>
                <span id="museder-ai-log-analysis-risk-badge" style="display: inline-block; margin-left: 8px; padding: 4px 12px; border-radius: 4px; font-weight: 600; <?php
                    if ( ! empty( $last_log_report['risk_level'] ) ) {
                        $risk = strtolower( $last_log_report['risk_level'] );
                        $risk_colors = [
                            'low' => [ 'bg' => '#d4edda', 'color' => '#155724', 'text' => __( 'Low', 'museder-restoreone' ) ],
                            'medium' => [ 'bg' => '#fff3cd', 'color' => '#856404', 'text' => __( 'Medium', 'museder-restoreone' ) ],
                            'high' => [ 'bg' => '#f8d7da', 'color' => '#721c24', 'text' => __( 'High', 'museder-restoreone' ) ],
                        ];
                        $risk_style = $risk_colors[ $risk ] ?? $risk_colors['medium'];
                        echo 'background-color: ' . esc_attr( $risk_style['bg'] ) . '; color: ' . esc_attr( $risk_style['color'] ) . ';';
                    }
                ?>">
                    <?php
                    if ( ! empty( $last_log_report['risk_level'] ) ) {
                        $risk = strtolower( $last_log_report['risk_level'] );
                        $risk_texts = [
                            'low' => __( 'Low', 'museder-restoreone' ),
                            'medium' => __( 'Medium', 'museder-restoreone' ),
                            'high' => __( 'High', 'museder-restoreone' ),
                        ];
                        echo esc_html( $risk_texts[ $risk ] ?? $risk_texts['medium'] );
                    }
                    ?>
                </span>
            </div>
            
            <?php if ( $ai_license_tier === 'free' ) : ?>
                <!-- Free tier: Limited display -->
                <div id="museder-ai-log-analysis-main-causes" style="margin-top: 16px; margin-bottom: 16px;">
                    <strong><?php esc_html_e( 'Main Causes:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-log-analysis-main-causes-list" style="margin: 8px 0; padding-left: 20px;">
                        <?php
                        if ( ! empty( $last_log_report['main_causes'] ) && is_array( $last_log_report['main_causes'] ) && count( $last_log_report['main_causes'] ) > 0 ) {
                            $first_cause = $last_log_report['main_causes'][0];
                            echo '<li><strong>' . esc_html( $first_cause['title'] ?? '' ) . '</strong>';
                            if ( ! empty( $first_cause['description'] ) ) {
                                echo ': ' . esc_html( $first_cause['description'] );
                            }
                            echo '</li>';
                        }
                        ?>
                    </ul>
                    <p style="font-size: 12px; color: #666; font-style: italic; margin-top: 8px;">
                        <?php esc_html_e( 'More detailed error breakdown and fix steps are available in Pro version.', 'museder-restoreone' ); ?>
                    </p>
                </div>
                
                <div id="museder-ai-log-analysis-recommendations" style="margin-top: 16px;">
                    <strong><?php esc_html_e( 'Recommendations:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-log-analysis-recommendations-list" style="margin: 8px 0; padding-left: 20px;">
                        <?php
                        if ( ! empty( $last_log_report['recommendations'] ) && is_array( $last_log_report['recommendations'] ) && count( $last_log_report['recommendations'] ) > 0 ) {
                            $first_rec = $last_log_report['recommendations'][0];
                            echo '<li>' . esc_html( is_array( $first_rec ) ? ( $first_rec['text'] ?? '' ) : $first_rec ) . '</li>';
                        }
                        ?>
                    </ul>
                </div>
            <?php else : ?>
                <!-- Pro/Agency tier: Full display -->
                <div id="museder-ai-log-analysis-main-causes" style="margin-top: 16px; margin-bottom: 16px;">
                    <strong><?php esc_html_e( 'Main Causes:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-log-analysis-main-causes-list" style="margin: 8px 0; padding-left: 20px;">
                        <?php
                        if ( ! empty( $last_log_report['main_causes'] ) && is_array( $last_log_report['main_causes'] ) ) {
                            foreach ( $last_log_report['main_causes'] as $cause ) {
                                echo '<li><strong>' . esc_html( $cause['title'] ?? '' ) . '</strong>';
                                if ( ! empty( $cause['description'] ) ) {
                                    echo ': ' . esc_html( $cause['description'] );
                                }
                                echo '</li>';
                            }
                        }
                        ?>
                    </ul>
                </div>
                
                <div id="museder-ai-log-analysis-recommendations" style="margin-top: 16px;">
                    <strong><?php esc_html_e( 'Recommendations:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-log-analysis-recommendations-list" style="margin: 8px 0; padding-left: 20px;">
                        <?php
                        if ( ! empty( $last_log_report['recommendations'] ) && is_array( $last_log_report['recommendations'] ) ) {
                            foreach ( $last_log_report['recommendations'] as $rec ) {
                                echo '<li>' . esc_html( is_array( $rec ) ? ( $rec['text'] ?? '' ) : $rec ) . '</li>';
                            }
                        }
                        ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        
        <div id="museder-ai-log-analysis-error" style="display: none; margin-top: 16px; padding: 12px; background: #ffeaea; border-left: 4px solid #dc3232; border-radius: 4px; color: #721c24;">
            <strong><?php esc_html_e( 'Error:', 'museder-restoreone' ); ?></strong>
            <span id="museder-ai-log-analysis-error-message"></span>
        </div>
    </div>

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

