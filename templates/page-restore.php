<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$museder_restoreone_summary      = isset( $museder_restoreone_summary ) ? $museder_restoreone_summary : null;
$museder_restoreone_history_rows = isset( $museder_restoreone_history ) && is_array( $museder_restoreone_history ) ? $museder_restoreone_history : [];
$museder_restoreone_backups      = isset( $museder_restoreone_backups ) && is_array( $museder_restoreone_backups ) ? $museder_restoreone_backups : [];

// Check if safe mode is active
$safe_mode_active = get_option( 'backup_lite_safe_mode', '' ) === '1';
$prev_plugins_count = 0;
if ( $safe_mode_active ) {
    $prev_plugins = get_option( 'backup_lite_prev_active_plugins', [] );
    $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
}

// Get AI settings and last restore guide
$ai_settings = Museder_AI_Service::get_settings();
// Use global helper for license tier (considers Developer Mode)
$ai_license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
    ? backup_lite_get_effective_license_tier() 
    : ( isset( $ai_settings['license_tier'] ) ? $ai_settings['license_tier'] : 'free' );
$last_restore_guide = Museder_AI_Service::get_last_restore_guide();
?>
    <div class="wrap backup-lite-restore">
        <h1>🧩 <?php esc_html_e( 'Restore Center', 'museder-restoreone' ); ?></h1>

        <?php if ( $safe_mode_active ) : ?>
        <div class="notice notice-warning is-dismissible" id="backup-lite-safe-mode-notice" style="border-left-color: #ffb900; padding: 12px 20px; margin: 20px 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div style="flex: 1; min-width: 300px;">
                    <p style="margin: 0 0 8px 0; font-weight: 600;">
                        <span style="font-size: 20px; margin-right: 8px;">🛡️</span>
                        <?php esc_html_e( 'Safe Mode Active', 'museder-restoreone' ); ?>
                    </p>
                    <p style="margin: 0; color: #646970;">
                        <?php
                        printf(
                            /* translators: %d: Number of plugins that were deactivated. */
                            esc_html__( 'RestoreOne has enabled safe mode after restore, temporarily disabling %d plugin(s) to prevent conflicts. Please verify your site is working correctly, then click the button below to restore all plugins.', 'museder-restoreone' ),
                            $prev_plugins_count
                        );
                        ?>
                    </p>
                </div>
                <div>
                    <button type="button" id="backup-lite-exit-safe-mode-btn" class="button button-primary" style="white-space: nowrap;">
                        <?php esc_html_e( 'Exit Safe Mode & Restore Plugins', 'museder-restoreone' ); ?>
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

        <!-- Restore AI Guide (Preview) -->
        <section class="backup-lite-card" id="museder-ai-restore-guide-card" style="margin-bottom: 24px;">
            <h2>🤖 <?php esc_html_e( 'Restore AI Guide (Preview)', 'museder-restoreone' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Use AI to generate a step-by-step guide before running a restore, based on the selected backup and recent logs.', 'museder-restoreone' ); ?>
            </p>
            
            <?php if ( ! empty( $museder_restoreone_summary ) && ! empty( $museder_restoreone_summary['name'] ) ) : ?>
                <p class="description" style="font-size: 13px; color: #666; margin-top: 8px;">
                    <?php
                    printf(
                        /* translators: %s: backup file name */
                        esc_html__( 'Currently analyzing backup: %s', 'museder-restoreone' ),
                        '<strong>' . esc_html( $museder_restoreone_summary['name'] ) . '</strong>'
                    );
                    ?>
                </p>
            <?php else : ?>
                <p class="description" style="font-size: 13px; color: #d63638; margin-top: 8px;">
                    <?php esc_html_e( 'No backup is selected yet. Please select or upload a backup in Step 1 below before using the Restore AI Guide.', 'museder-restoreone' ); ?>
                </p>
            <?php endif; ?>
            
            <button
                type="button"
                id="museder-ai-restore-guide-run"
                class="button button-primary"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'museder_ai_restore_guide' ) ); ?>"
                data-backup-id="<?php echo ! empty( $museder_restoreone_summary ) && ! empty( $museder_restoreone_summary['name'] ) ? esc_attr( $museder_restoreone_summary['name'] ) : ''; ?>"
            >
                <?php esc_html_e( 'Ask AI for Restore Steps', 'museder-restoreone' ); ?>
            </button>
            
            <div id="museder-ai-restore-guide-loading" style="display: none; margin-top: 16px;">
                <span class="spinner is-active"></span>
                <span><?php esc_html_e( 'Generating restore guide…', 'museder-restoreone' ); ?></span>
            </div>
            
            <div id="museder-ai-restore-guide-error" class="backup-lite-messages is-error" style="display: none; margin-top: 16px;">
                <p id="museder-ai-restore-guide-error-message"></p>
            </div>
            
            <div id="museder-ai-restore-guide-results" style="<?php echo ! empty( $last_restore_guide['summary'] ) ? 'display: block;' : 'display: none;'; ?> margin-top: 16px; padding: 16px; background: #f9f9f9; border-radius: 4px;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Restore Guide', 'museder-restoreone' ); ?></h3>
                
                <?php if ( ! empty( $last_restore_guide['backup_id'] ) && ! empty( $museder_restoreone_summary ) && $last_restore_guide['backup_id'] !== $museder_restoreone_summary['name'] ) : ?>
                    <p style="font-size: 12px; color: #666; font-style: italic; margin-bottom: 12px;">
                        <?php
                        printf(
                            esc_html__( 'This guide was generated for backup %s.', 'museder-restoreone' ),
                            '<strong>' . esc_html( $last_restore_guide['backup_id'] ) . '</strong>'
                        );
                        ?>
                    </p>
                <?php endif; ?>
                
                <div id="museder-ai-restore-guide-mode" style="margin-bottom: 12px; font-size: 12px; color: #666; font-style: italic;">
                    <?php if ( ! empty( $last_restore_guide['summary'] ) ) : ?>
                        <?php echo esc_html( $last_restore_guide['mode'] === 'demo' ? 'Demo mode (no external AI call).' : 'Powered by Museder AI (OpenAI).' ); ?>
                    <?php endif; ?>
                </div>
                
                <div id="museder-ai-restore-guide-summary" style="margin-bottom: 12px;">
                    <strong><?php esc_html_e( 'Summary:', 'museder-restoreone' ); ?></strong>
                    <p id="museder-ai-restore-guide-summary-text" style="margin: 8px 0;">
                        <?php echo ! empty( $last_restore_guide['summary'] ) ? esc_html( $last_restore_guide['summary'] ) : ''; ?>
                    </p>
                </div>
                
                <div id="museder-ai-restore-guide-risk" style="margin-bottom: 12px;">
                    <strong><?php esc_html_e( 'Risk Level:', 'museder-restoreone' ); ?></strong>
                    <span id="museder-ai-restore-guide-risk-badge" style="display: inline-block; margin-left: 8px; padding: 4px 12px; border-radius: 4px; font-weight: 600; <?php
                        if ( ! empty( $last_restore_guide['risk_level'] ) ) {
                            $risk = strtolower( $last_restore_guide['risk_level'] );
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
                        if ( ! empty( $last_restore_guide['risk_level'] ) ) {
                            $risk = strtolower( $last_restore_guide['risk_level'] );
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
                
                <!-- Steps (new format with title, description, priority) -->
                <div id="museder-ai-restore-guide-steps" style="margin-top: 16px; margin-bottom: 16px;">
                    <strong><?php esc_html_e( 'Step-by-Step Guide:', 'museder-restoreone' ); ?></strong>
                    <ol id="museder-ai-restore-guide-steps-list" style="margin: 8px 0; padding-left: 20px;">
                        <?php
                        if ( ! empty( $last_restore_guide['steps'] ) && is_array( $last_restore_guide['steps'] ) ) {
                            $display_steps = ( $ai_license_tier === 'free' ) ? array_slice( $last_restore_guide['steps'], 0, 2 ) : $last_restore_guide['steps'];
                            foreach ( $display_steps as $step ) {
                                if ( is_array( $step ) && isset( $step['title'] ) ) {
                                    echo '<li style="margin-bottom: 8px;">';
                                    echo '<strong>' . esc_html( $step['title'] ) . ':</strong> ';
                                    echo esc_html( $step['description'] ?? '' );
                                    if ( ! empty( $step['priority'] ) && $step['priority'] !== 'normal' ) {
                                        $priority_style = '';
                                        $priority_text = '';
                                        if ( $step['priority'] === 'high' ) {
                                            $priority_style = 'background-color: #f8d7da; color: #721c24;';
                                            $priority_text = __( 'High Priority', 'museder-restoreone' );
                                        } elseif ( $step['priority'] === 'optional' ) {
                                            $priority_style = 'background-color: #e2e3e5; color: #383d41;';
                                            $priority_text = __( 'Optional', 'museder-restoreone' );
                                        }
                                        if ( $priority_style ) {
                                            echo ' <span style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; ' . esc_attr( $priority_style ) . '">' . esc_html( $priority_text ) . '</span>';
                                        }
                                    }
                                    echo '</li>';
                                }
                            }
                        }
                        ?>
                    </ol>
                    <?php if ( $ai_license_tier === 'free' && ! empty( $last_restore_guide['steps'] ) && count( $last_restore_guide['steps'] ) > 2 ) : ?>
                        <p style="font-size: 12px; color: #666; font-style: italic; margin-top: 8px;">
                            <?php esc_html_e( 'Upgrade to Pro to view full restore guide.', 'museder-restoreone' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- Warnings -->
                <div id="museder-ai-restore-guide-warnings" style="margin-top: 16px; margin-bottom: 16px; <?php echo ( empty( $last_restore_guide['warnings'] ) || ! is_array( $last_restore_guide['warnings'] ) || count( $last_restore_guide['warnings'] ) === 0 ) ? 'display: none;' : ''; ?>">
                    <strong style="color: #856404;"><?php esc_html_e( '⚠️ Warnings:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-restore-guide-warnings-list" style="margin: 8px 0; padding-left: 20px; color: #856404;">
                        <?php
                        if ( ! empty( $last_restore_guide['warnings'] ) && is_array( $last_restore_guide['warnings'] ) ) {
                            foreach ( $last_restore_guide['warnings'] as $warning ) {
                                echo '<li>' . esc_html( $warning ) . '</li>';
                            }
                        }
                        ?>
                    </ul>
                </div>
                
                <!-- Notes -->
                <div id="museder-ai-restore-guide-notes" style="margin-top: 16px; margin-bottom: 16px; <?php echo ( empty( $last_restore_guide['notes'] ) || ! is_array( $last_restore_guide['notes'] ) || count( $last_restore_guide['notes'] ) === 0 ) ? 'display: none;' : ''; ?>">
                    <strong style="color: #666;"><?php esc_html_e( '📝 Notes:', 'museder-restoreone' ); ?></strong>
                    <ul id="museder-ai-restore-guide-notes-list" style="margin: 8px 0; padding-left: 20px; color: #666; font-size: 13px;">
                        <?php
                        if ( ! empty( $last_restore_guide['notes'] ) && is_array( $last_restore_guide['notes'] ) ) {
                            foreach ( $last_restore_guide['notes'] as $note ) {
                                echo '<li>' . esc_html( $note ) . '</li>';
                            }
                        }
                        ?>
                    </ul>
                </div>
            </div>
            
            <div id="museder-ai-restore-guide-error" style="display: none; margin-top: 16px; padding: 12px; background: #ffeaea; border-left: 4px solid #dc3232; border-radius: 4px; color: #721c24;">
                <strong><?php esc_html_e( 'Error:', 'museder-restoreone' ); ?></strong>
                <span id="museder-ai-restore-guide-error-message"></span>
            </div>
        </section>

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
                <?php foreach ( $museder_restoreone_backups as $museder_restoreone_backup ) : ?>
                    <option value="<?php echo esc_attr( $museder_restoreone_backup['name'] ); ?>"><?php echo esc_html( $museder_restoreone_backup['name'] . ' (' . size_format( $museder_restoreone_backup['size'] ) . ')' ); ?></option>
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
            <?php if ( $museder_restoreone_summary ) : ?>
                <p><strong><?php esc_html_e( 'File:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $museder_restoreone_summary['name'] ); ?></p>
                <p><strong><?php esc_html_e( 'Size:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( $museder_restoreone_summary['size'] ); ?></p>
                <?php if ( ! empty( $museder_restoreone_summary['sha1'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'SHA1:', 'museder-restoreone' ); ?></strong> <code><?php echo esc_html( $museder_restoreone_summary['sha1'] ); ?></code></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e( 'Source:', 'museder-restoreone' ); ?></strong> <?php echo esc_html( ucfirst( $museder_restoreone_summary['source'] ) ); ?></p>
                <p class="description" style="margin-top: 12px; font-size: 13px; color: #666;">
                    <?php esc_html_e( 'To analyze or restore a different backup, choose it below in Step 1.', 'museder-restoreone' ); ?>
                    <a href="#backup-lite-source" class="button-link backup-lite-change-backup" style="margin-left: 8px;">
                        <?php esc_html_e( 'Change backup…', 'museder-restoreone' ); ?>
                    </a>
                </p>
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
            <div class="progress-bar" style="height: 24px; background: rgba(0, 0, 0, 0.05); border-radius: 12px; overflow: hidden; margin-bottom: 12px; position: relative;">
                <div id="restore-progress-fill" class="progress-bar-fill" style="width:0%; height: 100%; background: linear-gradient(90deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); transition: width 0.3s ease;"></div>
                <span id="restore-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #fff; font-weight: 600; font-size: 12px; pointer-events: none; text-align: center; width: 100%;">0%</span>
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
        <table class="wp-list-table widefat striped">
            <thead><tr><th><?php esc_html_e( 'Date/Time', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'File', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'Result', 'museder-restoreone' ); ?></th><th><?php esc_html_e( 'Log', 'museder-restoreone' ); ?></th></tr></thead>
            <tbody id="restoreHistory">
                <?php if ( ! empty( $museder_restoreone_history_rows ) ) : ?>
                    <?php foreach ( $museder_restoreone_history_rows as $museder_restoreone_row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $museder_restoreone_row['timestamp'] ); ?></td>
                            <td><?php echo esc_html( $museder_restoreone_row['file'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( $museder_restoreone_row['result'] ) ); ?></td>
                            <td>
                                <?php if ( ! empty( $museder_restoreone_row['log_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $museder_restoreone_row['log_url'] ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download', 'museder-restoreone' ); ?></a>
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
