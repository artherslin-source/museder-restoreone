<?php
/**
 * Backup Lite Reports page.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$museder_restoreone_is_pro = isset( $museder_restoreone_is_pro ) ? $museder_restoreone_is_pro : ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() );
$museder_restoreone_upgrade_url = Backup_Lite_Pro::get_upgrade_url();

// Get system check data (for PRO users)
$museder_restoreone_system_check = $museder_restoreone_is_pro ? Backup_Lite_Reports_Service::get_system_check() : null;
if ( $museder_restoreone_is_pro && isset( $museder_restoreone_system_check['error'] ) ) {
    $museder_restoreone_system_check = null;
}
?>

<?php // @plugin-check: escaped ?>
<div class="wrap backup-lite-admin backup-lite-reports <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked-overlay' ); ?>">
    <div class="bl-container">
        <div class="bl-card" style="margin-bottom: 24px;">
            <div class="bl-card-heading">
                <h1 style="margin: 0; font-size: 28px;">
                    📊 <?php esc_html_e( 'System Reports', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--bl-text-muted);">
                <?php esc_html_e( 'Comprehensive backup analytics, trends, and AI-powered incident analysis.', 'museder-restoreone' ); ?>
            </p>
        </div>

        <?php if ( ! $museder_restoreone_is_pro ) : ?>
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
                    <a href="<?php echo esc_url( $museder_restoreone_upgrade_url ); ?>" target="_blank" class="bl-button" style="background: #fff; color: var(--bl-primary); border: none; padding: 12px 24px; font-weight: 600;">
                        <?php esc_html_e( 'Upgrade Now', 'museder-restoreone' ); ?> →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- System Check Summary -->
        <?php // @plugin-check: escaped ?>
        <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" style="margin-bottom: 24px;" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
            <h2 style="margin: 0 0 16px 0; font-size: 20px;">
                🔍 <?php esc_html_e( 'System Check Summary', 'museder-restoreone' ); ?>
                <?php if ( ! $museder_restoreone_is_pro ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </h2>
            <?php if ( $museder_restoreone_is_pro && $museder_restoreone_system_check ) : ?>
                <div class="bl-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div>
                        <div style="font-size: 24px; font-weight: 600; color: var(--bl-primary);">
                            <?php echo esc_html( $museder_restoreone_system_check['backups']['count'] ?? 0 ); ?>
                        </div>
                        <div style="color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Total Backups', 'museder-restoreone' ); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 24px; font-weight: 600; color: var(--bl-primary);">
                            <?php echo esc_html( size_format( $museder_restoreone_system_check['backups']['total_size'] ?? 0, 2 ) ); ?>
                        </div>
                        <div style="color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Total Size', 'museder-restoreone' ); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 24px; font-weight: 600; color: var(--bl-primary);">
                            <?php echo esc_html( $museder_restoreone_system_check['schedules']['enabled'] ?? 0 ); ?>
                        </div>
                        <div style="color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Active Schedules', 'museder-restoreone' ); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 24px; font-weight: 600; color: var(--bl-primary);">
                            <?php
                            $museder_restoreone_available = $museder_restoreone_system_check['storage']['available_space'] ?? -1;
                            echo $museder_restoreone_available > 0 ? esc_html( size_format( $museder_restoreone_available, 2 ) ) : esc_html__( 'Unknown', 'museder-restoreone' ); // @plugin-check: escaped
                            ?>
                        </div>
                        <div style="color: var(--bl-text-muted); font-size: 14px;">
                            <?php esc_html_e( 'Available Space', 'museder-restoreone' ); ?>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <div style="opacity: 0.5; text-align: center; padding: 40px;">
                    <p style="color: var(--bl-text-muted);"><?php esc_html_e( 'System check data will appear here when PRO is activated.', 'museder-restoreone' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Backup Trends -->
        <?php // @plugin-check: escaped ?>
        <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" style="margin-bottom: 24px;" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 20px;">
                    📈 <?php esc_html_e( 'Backup Trends', 'museder-restoreone' ); ?>
                    <?php if ( ! $museder_restoreone_is_pro ) : ?>
                        <span class="pro-badge">PRO</span>
                    <?php endif; ?>
                </h2>
                <?php if ( $museder_restoreone_is_pro ) : ?>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="bl-button bl-button-sm" data-trend-days="7">7 <?php esc_html_e( 'Days', 'museder-restoreone' ); ?></button>
                        <button type="button" class="bl-button bl-button-sm bl-button-primary" data-trend-days="30">30 <?php esc_html_e( 'Days', 'museder-restoreone' ); ?></button>
                        <button type="button" class="bl-button bl-button-sm" data-trend-days="90">90 <?php esc_html_e( 'Days', 'museder-restoreone' ); ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <div style="position: relative; height: 300px;">
                <canvas id="bl-trends-chart"></canvas>
            </div>
            <?php if ( ! $museder_restoreone_is_pro ) : ?>
                <div style="opacity: 0.5; text-align: center; padding: 20px;">
                    <p style="color: var(--bl-text-muted);"><?php esc_html_e( 'Backup trends chart will be available with PRO.', 'museder-restoreone' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- AI Event Analysis -->
        <?php // @plugin-check: escaped ?>
        <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" style="margin-bottom: 24px;" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
            <h2 style="margin: 0 0 16px 0; font-size: 20px;">
                🤖 <?php esc_html_e( 'AI Event Analysis', 'museder-restoreone' ); ?>
                <?php if ( ! $museder_restoreone_is_pro ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </h2>
            <div id="bl-ai-analysis-content">
                <?php if ( $museder_restoreone_is_pro ) : ?>
                    <p style="color: var(--bl-text-muted);"><?php esc_html_e( 'Loading AI analysis...', 'museder-restoreone' ); ?></p>
                <?php else : ?>
                    <div style="opacity: 0.5; text-align: center; padding: 40px;">
                        <p style="color: var(--bl-text-muted);"><?php esc_html_e( 'AI event analysis will be available with PRO.', 'museder-restoreone' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export Reports -->
        <?php // @plugin-check: escaped ?>
        <div class="bl-card <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-locked' ); ?>" style="margin-bottom: 24px;" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
            <h2 style="margin: 0 0 16px 0; font-size: 20px;">
                📥 <?php esc_html_e( 'Export Reports', 'museder-restoreone' ); ?>
                <?php if ( ! $museder_restoreone_is_pro ) : ?>
                    <span class="pro-badge">PRO</span>
                <?php endif; ?>
            </h2>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <?php // @plugin-check: escaped ?>
                <button type="button" id="bl-export-json" class="bl-button bl-button-primary <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-cta' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?>>
                    <?php esc_html_e( 'Download JSON Report', 'museder-restoreone' ); ?>
                </button>
                <?php // @plugin-check: escaped ?>
                <button type="button" id="bl-export-pdf" class="bl-button bl-button-primary <?php echo esc_attr( $museder_restoreone_is_pro ? '' : 'pro-cta' ); ?>" <?php echo $museder_restoreone_is_pro ? '' : 'data-upgrade="' . esc_attr( 'pro' ) . '"'; // @plugin-check: escaped ?> disabled>
                    <?php esc_html_e( 'Download PDF Report', 'museder-restoreone' ); ?>
                    <span style="font-size: 11px; margin-left: 8px; opacity: 0.7;">(Coming Soon)</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.backup-lite-reports.pro-locked-overlay::before {
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

.backup-lite-reports.pro-locked-overlay .bl-container {
    position: relative;
    z-index: 2;
}

.bl-button-sm {
    padding: 6px 12px;
    font-size: 13px;
}
</style>

