<?php
/**
 * Template for Museder RestoreOne admin page.
 *
 * 注意：此檔案中的變數（例如 $is_pro, $settings 等）皆由上層控制器在 include 前建立，
 * 作用範圍僅限此模板檔案，並非在 WordPress 全域命名空間中到處使用的真正「全域變數」。
 * 為了維持模板可讀性與向後相容性，我們在此關閉 PrefixAllGlobals 警告。
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Template context: These variables are scoped to this template file and provided by the rendering function.
// They use short names for template readability but are not global namespace pollution.
$settings         = Backup_Lite_Settings::get_settings();
$roles            = get_editable_roles();
$logs_url         = admin_url( 'admin.php?page=backup-lite-logs' );
$is_pro           = class_exists( 'Backup_Lite_Pro' ) && Backup_Lite_Pro::is_pro_active();
$backup_dir       = backup_lite_get_backup_dir();
$backup_writable  = wp_is_writable( $backup_dir );
$temp_dir         = function_exists( 'get_temp_dir' ) ? get_temp_dir() : ( function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : ABSPATH );
$temp_writable    = wp_is_writable( $temp_dir );
$uploads_dir      = wp_upload_dir();
$uploads_writable = wp_is_writable( $uploads_dir['basedir'] );
$cron_disabled    = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$cron_status      = $cron_disabled ? __( 'External cron (DISABLE_WP_CRON enabled)', 'museder-restoreone' ) : __( 'Using WP-Cron', 'museder-restoreone' );
$php_memory       = ini_get( 'memory_limit' );
$selected_role    = isset( $settings['min_role'], $roles[ $settings['min_role'] ] ) ? $settings['min_role'] : 'administrator';
?>

<div class="wrap backup-lite-admin backup-lite-settings">
    <div class="bl-page-header">
        <div>
            <h1 class="backup-lite-page-title"><?php esc_html_e( 'Museder RestoreOne Settings', 'museder-restoreone' ); ?></h1>
            <p class="backup-lite-page-description"><?php esc_html_e( 'Configure global preferences, AI modules, and system-level options.', 'museder-restoreone' ); ?></p>
        </div>
        <div class="bl-page-actions">
            <a class="bl-btn bl-btn--ghost" href="<?php echo esc_url( $logs_url ); ?>">
                📜 <?php esc_html_e( 'View Logs', 'museder-restoreone' ); ?>
            </a>
            <span class="bl-badge" title="Plugin version" style="background: rgba(59,130,246,.12); color:#1d4ed8;"><?php echo esc_html( defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : '' ); ?></span>
        </div>
    </div>

    <div id="bl-settings-message" class="backup-lite-messages" role="status" aria-live="polite"></div>

    <form id="bl-settings-form" class="bl-container">
        <?php wp_nonce_field( 'museder_restoreone_save_settings', 'museder_restoreone_settings_nonce' ); ?>
        <div class="bl-card">
            <div class="bl-card-heading">
                <h3><span class="bl-icon-circle">🌐</span><?php esc_html_e( 'General Settings', 'museder-restoreone' ); ?></h3>
                <p class="bl-card-subtitle"><?php esc_html_e( 'Global preferences that apply to all Backup Lite / RestoreOne features.', 'museder-restoreone' ); ?></p>
            </div>
            <div class="backup-lite-settings-grid">
                <label class="bl-form-control">
                    <span><?php esc_html_e( 'Backup directory', 'museder-restoreone' ); ?></span>
                    <input type="text" id="bl-setting-backup-dir" value="<?php echo esc_attr( $settings['backup_directory'] ); ?>" />
                    <small class="description"><?php esc_html_e( 'All generated backups will be stored here.', 'museder-restoreone' ); ?></small>
                </label>
                <label class="bl-form-control">
                    <span><?php esc_html_e( 'Notification email', 'museder-restoreone' ); ?></span>
                    <input type="email" id="bl-setting-notify-email" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" />
                    <small class="description"><?php esc_html_e( 'Receive completion summaries and alerts.', 'museder-restoreone' ); ?></small>
                </label>
                <label class="bl-form-control">
                    <span><?php esc_html_e( 'Minimum role required', 'museder-restoreone' ); ?></span>
                    <select id="bl-setting-role">
                        <?php foreach ( $roles as $role_key => $role ) : ?>
                            <option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $selected_role, $role_key ); ?>>
                                <?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="description"><?php esc_html_e( 'Only users with this role (or higher) can access Museder RestoreOne.', 'museder-restoreone' ); ?></small>
                </label>
                <label class="bl-form-control">
                    <span><?php esc_html_e( 'UI Theme', 'museder-restoreone' ); ?></span>
                    <select id="bl-setting-theme-mode">
                        <option value="auto" <?php selected( $settings['ui_theme'], 'auto' ); ?>><?php esc_html_e( 'Auto (match system)', 'museder-restoreone' ); ?></option>
                        <option value="light" <?php selected( $settings['ui_theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'museder-restoreone' ); ?></option>
                        <option value="dark" <?php selected( $settings['ui_theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'museder-restoreone' ); ?></option>
                    </select>
                    <small class="description"><?php esc_html_e( 'Only affects Museder RestoreOne admin pages.', 'museder-restoreone' ); ?></small>
                </label>
                <label class="bl-toggle-row js-bl-pro-locked" data-pro-feature="debug_mode">
                    <div>
                        <span class="bl-toggle-title">
                            <?php esc_html_e( 'Debug Mode', 'museder-restoreone' ); ?>
                            <span class="bl-badge bl-badge--pro">PRO</span>
                        </span>
                        <p class="bl-toggle-description"><?php esc_html_e( 'Log verbose restore/backup events for troubleshooting.', 'museder-restoreone' ); ?></p>
                    </div>
                    <div class="bl-toggle bl-toggle--disabled">
                        <input type="checkbox" id="bl-setting-debug-mode" disabled />
                        <span class="bl-toggle-slider" aria-hidden="true"></span>
                    </div>
                </label>
            </div>
            <p class="bl-note bl-note--muted"><?php esc_html_e( 'All changes here affect how Backup Lite behaves on this site.', 'museder-restoreone' ); ?></p>
            <div class="settings-actions">
                <button type="button" class="bl-btn bl-btn--ghost" id="bl-test-email">📧 <?php esc_html_e( 'Send Test Email', 'museder-restoreone' ); ?></button>
                <button type="submit" class="bl-btn bl-btn--primary" id="bl-settings-save"><?php esc_html_e( 'Save Settings', 'museder-restoreone' ); ?></button>
            </div>
        </div>

        <div class="bl-card">
            <div class="bl-card-heading">
                <h3><span class="bl-icon-circle">🛠️</span><?php esc_html_e( 'System Diagnostics', 'museder-restoreone' ); ?></h3>
                <p class="bl-card-subtitle"><?php esc_html_e( 'Read-only checks to help you verify server compatibility.', 'museder-restoreone' ); ?></p>
            </div>
            <div class="bl-diagnostics-grid">
                <div class="bl-diagnostic-item">
                    <span class="label"><?php esc_html_e( 'PHP memory limit', 'museder-restoreone' ); ?></span>
                    <span class="bl-pill bl-pill--ok"><?php echo esc_html( $php_memory ? $php_memory : __( 'Unknown', 'museder-restoreone' ) ); ?></span>
                </div>
                <div class="bl-diagnostic-item">
                    <span class="label"><?php esc_html_e( 'Server temp directory', 'museder-restoreone' ); ?></span>
                    <code><?php echo esc_html( $temp_dir ); ?></code>
                    <?php // @plugin-check: escaped ?>
                    <span class="bl-pill <?php echo esc_attr( $temp_writable ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
                        <?php echo $temp_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Needs attention', 'museder-restoreone' ); // @plugin-check: escaped ?>
                    </span>
                </div>
                <div class="bl-diagnostic-item">
                    <span class="label"><?php esc_html_e( 'Backup directory writable', 'museder-restoreone' ); ?></span>
                    <?php // @plugin-check: escaped ?>
                    <span class="bl-pill <?php echo esc_attr( $backup_writable ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
                        <?php echo $backup_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Check permissions', 'museder-restoreone' ); // @plugin-check: escaped ?>
                    </span>
                </div>
                <div class="bl-diagnostic-item">
                    <span class="label"><?php esc_html_e( 'Uploads directory writable', 'museder-restoreone' ); ?></span>
                    <?php // @plugin-check: escaped ?>
                    <span class="bl-pill <?php echo esc_attr( $uploads_writable ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
                        <?php echo $uploads_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Check permissions', 'museder-restoreone' ); // @plugin-check: escaped ?>
                    </span>
                </div>
                <div class="bl-diagnostic-item">
                    <span class="label"><?php esc_html_e( 'Cron status', 'museder-restoreone' ); ?></span>
                    <?php // @plugin-check: escaped ?>
                    <span class="bl-pill <?php echo esc_attr( $cron_disabled ? 'bl-pill--warning' : 'bl-pill--ok' ); ?>">
                        <?php echo esc_html( $cron_status ); ?>
                    </span>
                </div>
            </div>
            <p class="bl-note bl-note--muted"><?php esc_html_e( 'These values are detected from your server environment. If something looks wrong, contact your hosting provider.', 'museder-restoreone' ); ?></p>
        </div>
    </form>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
