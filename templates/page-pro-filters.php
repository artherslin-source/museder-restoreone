<?php
/**
 * Backup Lite PRO - Advanced Filters page.
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
                    🔍 <?php esc_html_e( 'Advanced Filters', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Exclude specific files, folders, and file types from backups with precision control.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $museder_restoreone_is_pro ) : ?>
            <!-- Upgrade CTA -->
            <div class="bl-card" style="background: linear-gradient(135deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); color: #fff; border: none; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="margin: 0 0 8px 0; color: #fff; font-size: 20px;">
                            <?php esc_html_e( 'Upgrade to PRO for Advanced Filters', 'museder-restoreone' ); ?>
                        </h2>
                        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">
                            <?php esc_html_e( 'Get precise control over what gets backed up with advanced exclusion rules.', 'museder-restoreone' ); ?>
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
                <h2 style="margin: 0 0 16px 0; font-size: 20px;"><?php esc_html_e( 'Exclusion Rules', 'museder-restoreone' ); ?></h2>
                <p style="color: var(--bl-text-muted); margin-bottom: 16px;">
                    <?php esc_html_e( 'Advanced filter configuration will be available in Phase E.', 'museder-restoreone' ); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Hide other plugins' admin notices on this page to avoid confusion
// These notices appear in the WordPress admin area and can be mistaken for our plugin's content
?>
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

/* Hide other plugins' admin notices on PRO pages */
.backup-lite-pro-page .notice:not(.backup-lite-notice),
.backup-lite-pro-page .update-nag:not(.backup-lite-notice),
.backup-lite-pro-page .error:not(.backup-lite-notice),
.backup-lite-pro-page .updated:not(.backup-lite-notice) {
    display: none !important;
}

/* Specifically target common plugin notice containers */
.backup-lite-pro-page > .notice,
.backup-lite-pro-page > .update-nag,
.backup-lite-pro-page > .error,
.backup-lite-pro-page > .updated {
    display: none !important;
}
</style>
<script>
(function() {
    // Remove other plugins' admin notices that appear before our content
    // This prevents confusion where users might think these are our plugin's features
    document.addEventListener('DOMContentLoaded', function() {
        var proPage = document.querySelector('.backup-lite-pro-page');
        if (proPage) {
            // Find all notices that are siblings of our page content
            var pageWrapper = proPage.closest('.wrap') || proPage.parentElement;
            if (pageWrapper) {
                // Remove notices that are not from our plugin
                var notices = pageWrapper.querySelectorAll('.notice:not(.backup-lite-notice), .update-nag:not(.backup-lite-notice), .error:not(.backup-lite-notice), .updated:not(.backup-lite-notice)');
                notices.forEach(function(notice) {
                    // Only remove if it's not immediately after our content
                    // This allows WordPress core notices to still show
                    var heroSection = proPage.querySelector('.bl-card');
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

