<?php
/**
 * Backup Lite PRO - Cloud Storage page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$museder_restoreone_is_pro = isset( $museder_restoreone_is_pro ) ? $museder_restoreone_is_pro : Backup_Lite_Pro::is_pro_active();
$museder_restoreone_upgrade_url = Backup_Lite_Pro::get_upgrade_url();
?>

<?php // @plugin-check: escaped ?>
<div class="wrap backup-lite-admin backup-lite-pro-page <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked-overlay' ); ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    ☁️ <?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                    <span style="font-size: 14px; color: var(--bl-text-muted); font-weight: normal; margin-left: 8px;">(Coming Soon)</span>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Backup to Google Drive, Amazon S3, Dropbox, and other cloud storage providers.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $museder_restoreone_is_pro ) : ?>
            <!-- Upgrade CTA -->
            <div class="bl-card" style="background: linear-gradient(135deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); color: #fff; border: none; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="margin: 0 0 8px 0; color: #fff; font-size: 20px;">
                            <?php esc_html_e( 'Upgrade to PRO for Cloud Storage', 'museder-restoreone' ); ?>
                        </h2>
                        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">
                            <?php esc_html_e( 'Store your backups securely in the cloud with automatic synchronization.', 'museder-restoreone' ); ?>
                        </p>
                    </div>
                    <a href="<?php echo esc_url( $museder_restoreone_upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php else : ?>
            <!-- PRO Content (Placeholder) -->
            <div class="bl-card" style="margin-bottom: 24px;">
                <h2 style="margin: 0 0 16px 0; font-size: 20px;"><?php esc_html_e( 'Cloud Storage Providers', 'museder-restoreone' ); ?></h2>
                <p style="color: var(--bl-text-muted); margin-bottom: 16px;">
                    <?php esc_html_e( 'Cloud storage integration will be available in a future update.', 'museder-restoreone' ); ?>
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 24px;">
                    <div class="bl-card" style="opacity: 0.6; text-align: center; padding: 24px;">
                        <div style="font-size: 32px; margin-bottom: 8px;">📦</div>
                        <div style="font-weight: 600;">Google Drive</div>
                    </div>
                    <div class="bl-card" style="opacity: 0.6; text-align: center; padding: 24px;">
                        <div style="font-size: 32px; margin-bottom: 8px;">☁️</div>
                        <div style="font-weight: 600;">Amazon S3</div>
                    </div>
                    <div class="bl-card" style="opacity: 0.6; text-align: center; padding: 24px;">
                        <div style="font-size: 32px; margin-bottom: 8px;">📁</div>
                        <div style="font-weight: 600;">Dropbox</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>


