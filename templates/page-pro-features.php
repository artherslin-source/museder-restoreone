<?php
/**
 * Backup Lite PRO Features overview page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_pro = isset( $is_pro ) ? $is_pro : Backup_Lite_Pro::is_pro_active();
$upgrade_url = Backup_Lite_Pro::get_upgrade_url();
?>

<div class="wrap backup-lite-admin backup-lite-pro-features <?php echo $is_pro ? '' : 'pro-locked-overlay'; ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    ⭐ <?php esc_html_e( 'Museder RestoreOne PRO Features', 'museder-restoreone' ); ?>
                    <?php if ( ! $is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Unlock advanced backup and restore capabilities with AI-powered insights and cloud storage integration.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $is_pro ) : ?>
            <!-- Upgrade CTA Banner -->
            <div class="bl-card" style="background: linear-gradient(135deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); color: #fff; border: none; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="margin: 0 0 8px 0; color: #fff; font-size: 20px;">
                            <?php esc_html_e( 'Upgrade to Museder RestoreOne PRO', 'museder-restoreone' ); ?>
                        </h2>
                        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">
                            <?php esc_html_e( 'Get access to AI Backup Copilot, cloud storage, advanced filters, and more.', 'museder-restoreone' ); ?>
                        </p>
                    </div>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Feature Cards Grid -->
        <div class="bl-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            
            <!-- AI Backup Copilot -->
            <div class="bl-card <?php echo $is_pro ? '' : 'pro-locked'; ?>" <?php echo $is_pro ? '' : 'data-upgrade="pro"'; ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🤖</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'AI Backup Copilot', 'museder-restoreone' ); ?>
                            <?php if ( ! $is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'AI-powered backup recommendations, risk analysis, and intelligent scheduling.', 'museder-restoreone' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro-ai' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                            <?php esc_html_e( 'Configure', 'museder-restoreone' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Cloud Storage -->
            <div class="bl-card <?php echo $is_pro ? '' : 'pro-locked'; ?>" <?php echo $is_pro ? '' : 'data-upgrade="pro"'; ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">☁️</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?>
                            <?php if ( ! $is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                            <span style="font-size: 11px; color: var(--bl-text-muted); font-weight: normal;">(Coming Soon)</span>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Backup to Google Drive, Amazon S3, Dropbox, and more cloud storage providers.', 'museder-restoreone' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro-cloud' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                            <?php esc_html_e( 'Configure', 'museder-restoreone' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Advanced Filters -->
            <div class="bl-card <?php echo $is_pro ? '' : 'pro-locked'; ?>" <?php echo $is_pro ? '' : 'data-upgrade="pro"'; ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🔍</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Advanced Filters', 'museder-restoreone' ); ?>
                            <?php if ( ! $is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Exclude specific files, folders, and file types from backups with precision control.', 'museder-restoreone' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro-filters' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                            <?php esc_html_e( 'Configure', 'museder-restoreone' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Smart Retention -->
            <div class="bl-card <?php echo $is_pro ? '' : 'pro-locked'; ?>" <?php echo $is_pro ? '' : 'data-upgrade="pro"'; ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🧠</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Smart Retention', 'museder-restoreone' ); ?>
                            <?php if ( ! $is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'AI-powered backup retention policies that automatically manage storage space.', 'museder-restoreone' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro-retention' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                            <?php esc_html_e( 'Configure', 'museder-restoreone' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- System Reports -->
            <div class="bl-card <?php echo $is_pro ? '' : 'pro-locked'; ?>" <?php echo $is_pro ? '' : 'data-upgrade="pro"'; ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">📊</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'System Reports', 'museder-restoreone' ); ?>
                            <?php if ( ! $is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Comprehensive backup analytics, trends, and AI-powered incident analysis.', 'museder-restoreone' ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-pro-reports' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                            <?php esc_html_e( 'View Reports', 'museder-restoreone' ); ?>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
.backup-lite-pro-features.pro-locked-overlay::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(2px);
    z-index: 1;
    pointer-events: none;
}

.backup-lite-pro-features.pro-locked-overlay .bl-container {
    position: relative;
    z-index: 2;
}
</style>

