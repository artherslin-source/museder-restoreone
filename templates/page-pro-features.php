<?php
/**
 * Backup Lite PRO Features overview page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$museder_restoreone_is_pro = isset( $museder_restoreone_is_pro ) ? $museder_restoreone_is_pro : ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() );
$museder_restoreone_upgrade_url = Backup_Lite_Pro::get_upgrade_url();
?>

<?php // @plugin-check: escaped ?>
<div class="wrap backup-lite-admin backup-lite-pro-features <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked-overlay' ); ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    ⭐ <?php esc_html_e( 'Museder RestoreOne PRO Features', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Unlock advanced backup and restore capabilities with AI-powered insights and cloud storage integration.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $museder_restoreone_is_pro ) : ?>
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
                    <a href="<?php echo esc_url( $museder_restoreone_upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Feature Cards Grid -->
        <div class="bl-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            
            <!-- AI Backup Copilot -->
            <?php // @plugin-check: escaped ?>
            <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🤖</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'AI Backup Copilot', 'museder-restoreone' ); ?>
                            <?php if ( ! $museder_restoreone_is_pro ) : ?>
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
            <?php // @plugin-check: escaped ?>
            <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">☁️</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Cloud Storage', 'museder-restoreone' ); ?>
                            <?php if ( ! $museder_restoreone_is_pro ) : ?>
                                <span class="pro-badge">PRO</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 16px 0; color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Backup to Amazon S3 or S3-compatible storage. More providers coming soon.', 'museder-restoreone' ); ?>
                        </p>
                        <?php if ( $museder_restoreone_is_pro ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=backup-lite-cloud' ) ); ?>" class="bl-button bl-button-primary" style="width: 100%;">
                                <?php esc_html_e( 'Configure', 'museder-restoreone' ); ?>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $museder_restoreone_upgrade_url ); ?>" target="_blank" class="bl-button bl-button-primary" style="width: 100%;">
                                <?php esc_html_e( 'Upgrade to Pro', 'museder-restoreone' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Advanced Filters -->
            <?php // @plugin-check: escaped ?>
            <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🔍</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Advanced Filters', 'museder-restoreone' ); ?>
                            <?php if ( ! $museder_restoreone_is_pro ) : ?>
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
            <?php // @plugin-check: escaped ?>
            <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">🧠</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'Smart Retention', 'museder-restoreone' ); ?>
                            <?php if ( ! $museder_restoreone_is_pro ) : ?>
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
            <?php // @plugin-check: escaped ?>
            <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="font-size: 32px; line-height: 1;">📊</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                            <?php esc_html_e( 'System Reports', 'museder-restoreone' ); ?>
                            <?php if ( ! $museder_restoreone_is_pro ) : ?>
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

<?php
// Hide other plugins' admin notices on this page to avoid confusion
// These notices appear in the WordPress admin area and can be mistaken for our plugin's content
?>
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

/* Hide other plugins' admin notices on the PRO features page */
.backup-lite-pro-features .notice:not(.backup-lite-notice),
.backup-lite-pro-features .update-nag:not(.backup-lite-notice),
.backup-lite-pro-features .error:not(.backup-lite-notice),
.backup-lite-pro-features .updated:not(.backup-lite-notice) {
    display: none !important;
}

/* Specifically target common plugin notice containers */
.backup-lite-pro-features > .notice,
.backup-lite-pro-features > .update-nag,
.backup-lite-pro-features > .error,
.backup-lite-pro-features > .updated {
    display: none !important;
}
</style>
<script>
(function() {
    // Remove other plugins' admin notices that appear before our content
    // This prevents confusion where users might think these are our plugin's features
    document.addEventListener('DOMContentLoaded', function() {
        var proFeaturesPage = document.querySelector('.backup-lite-pro-features');
        if (proFeaturesPage) {
            // Find all notices that are siblings of our page content
            var pageWrapper = proFeaturesPage.closest('.wrap') || proFeaturesPage.parentElement;
            if (pageWrapper) {
                // Remove notices that are not from our plugin
                var notices = pageWrapper.querySelectorAll('.notice:not(.backup-lite-notice), .update-nag:not(.backup-lite-notice), .error:not(.backup-lite-notice), .updated:not(.backup-lite-notice)');
                notices.forEach(function(notice) {
                    // Only remove if it's not immediately after our content
                    // This allows WordPress core notices to still show
                    var heroSection = proFeaturesPage.querySelector('.bl-card');
                    if (heroSection && notice.compareDocumentPosition(heroSection) & Node.DOCUMENT_POSITION_FOLLOWING) {
                        // Notice is before our content, remove it
                        notice.style.display = 'none';
                    }
                });
            }
        }
    });
})();
</script>

