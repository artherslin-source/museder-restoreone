<?php
/**
 * Template for Museder RestoreOne admin page.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings              = Museder_Restoreone_Settings::get_settings();
$roles                 = Museder_Restoreone_Settings::get_available_roles();
$logs_url              = admin_url( 'admin.php?page=museder-restoreone-logs' );
$backups_url           = admin_url( 'admin.php?page=museder-restoreone-backups' );
$schedules_url         = admin_url( 'admin.php?page=museder-restoreone-schedules' );
$storage_root          = museder_restoreone_get_storage_root();
$storage_subdirs       = museder_restoreone_list_storage_subdirs();
$selected_subdir       = isset( $settings['backup_storage_subdir'] )
	? museder_restoreone_normalize_storage_subdir( (string) $settings['backup_storage_subdir'] )
	: museder_restoreone_get_configured_backup_storage_subdir();
$backup_stats          = museder_restoreone_get_backup_dir_stats();
$backup_writable       = ! empty( $backup_stats['writable'] );
$temp_dir              = function_exists( 'museder_restoreone_get_temp_dir' ) ? museder_restoreone_get_temp_dir() : ( function_exists( 'get_temp_dir' ) ? get_temp_dir() : '' );
$temp_writable         = wp_is_writable( $temp_dir );
$uploads_dir           = wp_upload_dir();
$uploads_writable      = wp_is_writable( $uploads_dir['basedir'] );
$cron_disabled         = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$cron_status           = $cron_disabled ? __( 'External cron (DISABLE_WP_CRON enabled)', 'museder-restoreone' ) : __( 'Using WP-Cron', 'museder-restoreone' );
$php_memory            = ini_get( 'memory_limit' );
$php_max_execution     = ini_get( 'max_execution_time' );
$php_upload_max        = ini_get( 'upload_max_filesize' );
$php_post_max          = ini_get( 'post_max_size' );
$zip_available         = class_exists( 'ZipArchive' );
$selected_role         = isset( $settings['min_role'], $roles[ $settings['min_role'] ] ) ? $settings['min_role'] : 'administrator';
$backup_mode_default   = isset( $settings['backup_mode_default'] ) ? (string) $settings['backup_mode_default'] : 'auto';
$smart_exclude_default = isset( $settings['backup_smart_exclude_default'] ) ? (string) $settings['backup_smart_exclude_default'] : 'auto';
$smart_threshold       = isset( $settings['backup_smart_exclude_threshold'] ) ? (int) $settings['backup_smart_exclude_threshold'] : 50000;
$custom_excludes       = isset( $settings['backup_custom_excludes'] ) ? (string) $settings['backup_custom_excludes'] : '';

if ( ! in_array( $backup_mode_default, [ 'auto', 'balanced', 'fast' ], true ) ) {
	$backup_mode_default = 'auto';
}
if ( ! in_array( $smart_exclude_default, [ 'auto', 'on', 'off' ], true ) ) {
	$smart_exclude_default = 'auto';
}

$backup_count_label = sprintf(
	/* translators: %d: number of backup archives. */
	_n( '%d archive', '%d archives', (int) $backup_stats['count'], 'museder-restoreone' ),
	(int) $backup_stats['count']
);
$backup_size_label = size_format( (int) $backup_stats['size'], 2 );
?>

<div class="wrap backup-lite-admin backup-lite-settings museder-restoreone-admin museder-restoreone-settings">
	<div class="bl-page-header">
		<div>
			<h1 class="backup-lite-page-title museder-restoreone-page-title"><?php esc_html_e( 'Museder RestoreOne Settings', 'museder-restoreone' ); ?></h1>
			<p class="backup-lite-page-description museder-restoreone-page-description"><?php esc_html_e( 'Site-wide preferences, storage location, backup defaults, and environment checks.', 'museder-restoreone' ); ?></p>
		</div>
		<div class="bl-page-actions">
			<a class="bl-btn bl-btn--ghost" href="<?php echo esc_url( $logs_url ); ?>">
				<?php esc_html_e( 'View Logs', 'museder-restoreone' ); ?>
			</a>
			<span class="bl-badge" title="<?php esc_attr_e( 'Plugin version', 'museder-restoreone' ); ?>" style="background: rgba(59,130,246,.12); color:#1d4ed8;"><?php echo esc_html( defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '' ); ?></span>
		</div>
	</div>

	<div id="bl-settings-message" class="backup-lite-messages museder-restoreone-messages" role="status" aria-live="polite"></div>

	<form id="bl-settings-form" class="bl-container" data-storage-root="<?php echo esc_attr( wp_normalize_path( $storage_root['path'] ) ); ?>">
		<?php wp_nonce_field( 'museder_restoreone_save_settings', 'museder_restoreone_settings_nonce' ); ?>

		<div class="bl-card">
			<div class="bl-card-heading">
				<h3><?php esc_html_e( 'Access & Notifications', 'museder-restoreone' ); ?></h3>
				<p class="bl-card-subtitle"><?php esc_html_e( 'Who can use the plugin and where global alerts are sent.', 'museder-restoreone' ); ?></p>
			</div>
			<div class="backup-lite-settings-grid museder-restoreone-settings-grid">
				<label class="bl-form-control">
					<span><?php esc_html_e( 'Default notification email', 'museder-restoreone' ); ?></span>
					<input type="email" id="bl-setting-notify-email" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" />
					<small class="description"><?php esc_html_e( 'Used for test emails and as the fallback when a schedule has no email set.', 'museder-restoreone' ); ?></small>
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
			</div>
			<div class="settings-actions settings-actions--inline">
				<button type="button" class="bl-btn bl-btn--ghost" id="bl-test-email"><?php esc_html_e( 'Send Test Email', 'museder-restoreone' ); ?></button>
			</div>
		</div>

		<div class="bl-card">
			<div class="bl-card-heading">
				<h3><?php esc_html_e( 'Backup Storage', 'museder-restoreone' ); ?></h3>
				<p class="bl-card-subtitle"><?php esc_html_e( 'Choose a folder inside your WordPress uploads area. Backups are never stored outside this safe zone.', 'museder-restoreone' ); ?></p>
			</div>
			<div class="bl-settings-storage-panel">
				<label class="bl-form-control">
					<span><?php esc_html_e( 'Storage folder', 'museder-restoreone' ); ?></span>
					<select id="bl-setting-storage-subdir">
						<?php foreach ( $storage_subdirs as $subdir ) : ?>
							<option value="<?php echo esc_attr( $subdir ); ?>" <?php selected( $selected_subdir, $subdir ); ?>>
								<?php echo esc_html( $subdir ); ?>
							</option>
						<?php endforeach; ?>
						<option value="__new__"><?php esc_html_e( 'Create new folder?', 'museder-restoreone' ); ?></option>
					</select>
					<small class="description"><?php esc_html_e( 'Relative to wp-content/uploads/museder-restoreone/', 'museder-restoreone' ); ?></small>
				</label>
				<label class="bl-form-control bl-settings-storage-new" id="bl-setting-storage-new-wrap" hidden>
					<span><?php esc_html_e( 'New folder name', 'museder-restoreone' ); ?></span>
					<input type="text" id="bl-setting-storage-new" placeholder="<?php esc_attr_e( 'e.g. backups-2026', 'museder-restoreone' ); ?>" autocomplete="off" />
					<small class="description"><?php esc_html_e( 'Letters, numbers, and dashes only. Nested folders use slashes (folder/subfolder).', 'museder-restoreone' ); ?></small>
				</label>
				<div class="bl-settings-storage-meta">
					<div class="bl-settings-storage-path">
						<span class="label"><?php esc_html_e( 'Resolved path', 'museder-restoreone' ); ?></span>
						<code id="bl-setting-storage-path"><?php echo esc_html( $backup_stats['path'] ); ?></code>
					</div>
					<div class="bl-settings-storage-stats">
						<span class="bl-pill bl-pill--ok" id="bl-setting-storage-writable-pill" data-writable="<?php echo $backup_writable ? '1' : '0'; ?>">
							<?php echo $backup_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Check permissions', 'museder-restoreone' ); // @plugin-check: escaped ?>
						</span>
						<span class="bl-pill" id="bl-setting-storage-count-pill"><?php echo esc_html( $backup_count_label ); ?></span>
						<span class="bl-pill" id="bl-setting-storage-size-pill"><?php echo esc_html( $backup_size_label ); ?></span>
					</div>
				</div>
				<p class="bl-note bl-note--muted">
					<?php
					printf(
						/* translators: %s: Backups admin page link. */
						wp_kses_post( __( 'Manage individual archives on the <a href="%s">Backups</a> page.', 'museder-restoreone' ) ),
						esc_url( $backups_url )
					);
					?>
				</p>
			</div>
		</div>

		<div class="bl-card">
			<div class="bl-card-heading">
				<h3><?php esc_html_e( 'Backup Defaults', 'museder-restoreone' ); ?></h3>
				<p class="bl-card-subtitle"><?php esc_html_e( 'Pre-fill options on the Backups page. Each manual backup can still be changed before it runs.', 'museder-restoreone' ); ?></p>
			</div>
			<div class="backup-lite-settings-grid museder-restoreone-settings-grid">
				<label class="bl-form-control">
					<span><?php esc_html_e( 'Default backup mode', 'museder-restoreone' ); ?></span>
					<select id="bl-setting-backup-mode-default">
						<option value="auto" <?php selected( $backup_mode_default, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'museder-restoreone' ); ?></option>
						<option value="balanced" <?php selected( $backup_mode_default, 'balanced' ); ?>><?php esc_html_e( 'Balanced', 'museder-restoreone' ); ?></option>
						<option value="fast" <?php selected( $backup_mode_default, 'fast' ); ?>><?php esc_html_e( 'Fast', 'museder-restoreone' ); ?></option>
					</select>
				</label>
				<label class="bl-form-control">
					<span><?php esc_html_e( 'Default smart exclude', 'museder-restoreone' ); ?></span>
					<select id="bl-setting-smart-exclude-default">
						<option value="auto" <?php selected( $smart_exclude_default, 'auto' ); ?>><?php esc_html_e( 'Auto', 'museder-restoreone' ); ?></option>
						<option value="on" <?php selected( $smart_exclude_default, 'on' ); ?>><?php esc_html_e( 'On', 'museder-restoreone' ); ?></option>
						<option value="off" <?php selected( $smart_exclude_default, 'off' ); ?>><?php esc_html_e( 'Off', 'museder-restoreone' ); ?></option>
					</select>
				</label>
				<label class="bl-form-control">
					<span><?php esc_html_e( 'Smart exclude threshold (file count)', 'museder-restoreone' ); ?></span>
					<input type="number" id="bl-setting-smart-threshold" min="1000" max="500000" step="1000" value="<?php echo esc_attr( $smart_threshold ); ?>" />
					<small class="description"><?php esc_html_e( 'Used when smart exclude is set to Auto on large sites.', 'museder-restoreone' ); ?></small>
				</label>
				<label class="bl-form-control bl-form-control--full">
					<span><?php esc_html_e( 'Default custom excludes (one per line)', 'museder-restoreone' ); ?></span>
					<textarea id="bl-setting-custom-excludes" rows="4" placeholder="<?php esc_attr_e( "wp-content/cache/\nnode_modules/", 'museder-restoreone' ); ?>"><?php echo esc_textarea( $custom_excludes ); ?></textarea>
				</label>
			</div>
		</div>

		<div class="bl-card">
			<div class="bl-card-heading">
				<h3><?php esc_html_e( 'System Diagnostics', 'museder-restoreone' ); ?></h3>
				<p class="bl-card-subtitle"><?php esc_html_e( 'Read-only checks from your server. Fix hosting limits here before troubleshooting backups.', 'museder-restoreone' ); ?></p>
			</div>
			<div class="bl-diagnostics-grid">
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'WordPress version', 'museder-restoreone' ); ?></span>
					<span class="bl-pill bl-pill--ok"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'PHP memory limit', 'museder-restoreone' ); ?></span>
					<span class="bl-pill bl-pill--ok"><?php echo esc_html( $php_memory ? $php_memory : __( 'Unknown', 'museder-restoreone' ) ); ?></span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Max execution time', 'museder-restoreone' ); ?></span>
					<span class="bl-pill bl-pill--ok"><?php echo esc_html( $php_max_execution ? $php_max_execution . 's' : __( 'Unknown', 'museder-restoreone' ) ); ?></span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Upload max / Post max', 'museder-restoreone' ); ?></span>
					<span class="bl-pill bl-pill--ok"><?php echo esc_html( trim( ( $php_upload_max ? $php_upload_max : '?' ) . ' / ' . ( $php_post_max ? $php_post_max : '?' ) ) ); ?></span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'ZipArchive', 'museder-restoreone' ); ?></span>
					<span class="bl-pill <?php echo esc_attr( $zip_available ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
						<?php echo $zip_available ? esc_html__( 'Available', 'museder-restoreone' ) : esc_html__( 'Missing', 'museder-restoreone' ); ?>
					</span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Storage root', 'museder-restoreone' ); ?></span>
					<code><?php echo esc_html( $storage_root['path'] ); ?></code>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Server temp directory', 'museder-restoreone' ); ?></span>
					<code><?php echo esc_html( $temp_dir ); ?></code>
					<span class="bl-pill <?php echo esc_attr( $temp_writable ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
						<?php echo $temp_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Needs attention', 'museder-restoreone' ); // @plugin-check: escaped ?>
					</span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Uploads directory', 'museder-restoreone' ); ?></span>
					<span class="bl-pill <?php echo esc_attr( $uploads_writable ? 'bl-pill--ok' : 'bl-pill--warning' ); ?>">
						<?php echo $uploads_writable ? esc_html__( 'Writable', 'museder-restoreone' ) : esc_html__( 'Check permissions', 'museder-restoreone' ); // @plugin-check: escaped ?>
					</span>
				</div>
				<div class="bl-diagnostic-item">
					<span class="label"><?php esc_html_e( 'Cron status', 'museder-restoreone' ); ?></span>
					<span class="bl-pill <?php echo esc_attr( $cron_disabled ? 'bl-pill--warning' : 'bl-pill--ok' ); ?>">
						<?php echo esc_html( $cron_status ); ?>
					</span>
					<?php if ( $cron_disabled ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Schedules page link. */
								wp_kses_post( __( 'Automated jobs still need a real cron trigger. Review <a href="%s">Schedules</a> after configuring server cron.', 'museder-restoreone' ) ),
								esc_url( $schedules_url )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="settings-actions settings-actions--footer">
			<button type="submit" class="bl-btn bl-btn--primary" id="bl-settings-save"><?php esc_html_e( 'Save Settings', 'museder-restoreone' ); ?></button>
		</div>
	</form>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
