<?php
/**
 * Backup Lite PRO - AI Backup Copilot page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Template context: These variables use the museder_restoreone_ prefix and are scoped to this template file.
// They are provided by the rendering function and are not global namespace pollution.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$museder_restoreone_is_pro = isset( $museder_restoreone_is_pro ) ? $museder_restoreone_is_pro : Backup_Lite_Pro::is_pro_active();
$museder_restoreone_upgrade_url = Backup_Lite_Pro::get_upgrade_url();
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>

<?php // @plugin-check: escaped ?>
<div class="wrap backup-lite-admin backup-lite-pro-page <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked-overlay' ); ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    🤖 <?php esc_html_e( 'AI Backup Copilot', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'AI-powered backup recommendations, risk analysis, and intelligent scheduling assistance.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $museder_restoreone_is_pro ) : ?>
            <!-- Upgrade CTA -->
            <div class="bl-card" style="background: linear-gradient(135deg, var(--bl-primary) 0%, var(--bl-primary-alt) 100%); color: #fff; border: none; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 style="margin: 0 0 8px 0; color: #fff; font-size: 20px;">
                            <?php esc_html_e( 'Upgrade to PRO to Unlock AI Backup Copilot', 'museder-restoreone' ); ?>
                        </h2>
                        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 14px;">
                            <?php esc_html_e( 'Get AI-powered backup insights, risk analysis, and intelligent scheduling recommendations.', 'museder-restoreone' ); ?>
                        </p>
                    </div>
                    <a href="<?php echo esc_url( $museder_restoreone_upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php else : ?>
            <!-- PRO Content (Placeholder for Phase D) -->
            <div class="bl-card" style="margin-bottom: 24px;">
                <h2 style="margin: 0 0 16px 0; font-size: 20px;"><?php esc_html_e( 'AI Setup Wizard', 'museder-restoreone' ); ?></h2>
                <p style="color: var(--bl-text-muted); margin-bottom: 16px;">
                    <?php esc_html_e( 'Run the AI Setup Wizard to analyze your site and get personalized backup recommendations.', 'museder-restoreone' ); ?>
                </p>
                <button class="bl-button bl-button-primary" disabled>
                    <?php esc_html_e( 'Run AI Setup Wizard', 'museder-restoreone' ); ?>
                    <span style="font-size: 11px; margin-left: 8px; opacity: 0.7;">(Coming in Phase D)</span>
                </button>
            </div>

            <div class="bl-card" style="margin-bottom: 24px;">
                <h2 style="margin: 0 0 16px 0; font-size: 20px;"><?php esc_html_e( 'AI Settings', 'museder-restoreone' ); ?></h2>
                <p style="color: var(--bl-text-muted);">
                    <?php esc_html_e( 'AI configuration will be available in Phase D.', 'museder-restoreone' ); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>


