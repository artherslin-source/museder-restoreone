<?php
/**
 * Backup Lite PRO - System Reports page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_pro = isset( $is_pro ) ? $is_pro : Backup_Lite_Pro::is_pro_active();
$upgrade_url = Backup_Lite_Pro::get_upgrade_url();
?>

<div class="wrap backup-lite-admin backup-lite-pro-page <?php echo $is_pro ? '' : 'pro-locked-overlay'; ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    📊 <?php esc_html_e( 'System Reports', 'museder-restoreone' ); ?>
                    <?php if ( ! $is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Comprehensive backup analytics, trends, and AI-powered incident analysis.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $is_pro ) : ?>
            <!-- Upgrade CTA -->
            <div class="bl-card" style="background: linear-gradient(135deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); color: #fff; border: none; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="margin: 0 0 8px 0; color: #fff; font-size: 20px;">
                            <?php esc_html_e( 'Upgrade to PRO for System Reports', 'museder-restoreone' ); ?>
                        </h2>
                        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">
                            <?php esc_html_e( 'Get detailed analytics, backup trends, and AI-powered incident analysis.', 'museder-restoreone' ); ?>
                        </p>
                    </div>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php else : ?>
            <!-- PRO Content (Placeholder) -->
            <div class="bl-card" style="margin-bottom: 24px;">
                <h2 style="margin: 0 0 16px 0; font-size: 20px;"><?php esc_html_e( 'Backup Analytics', 'museder-restoreone' ); ?></h2>
                <p style="color: var(--bl-text-muted); margin-bottom: 16px;">
                    <?php esc_html_e( 'System reports and analytics will be available in Phase G.', 'museder-restoreone' ); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.backup-lite-pro-page.pro-locked-overlay::before {
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

.backup-lite-pro-page.pro-locked-overlay .bl-container {
    position: relative;
    z-index: 2;
}
</style>

