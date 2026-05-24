<?php
/**
 * Template for Museder RestoreOne admin page.
 *
 * 注意：此檔案中的變數（例如 $is_pro, $museder_restoreone_schedule 等）皆由上層控制器在 include 前建立，
 * 作用範圍僅限此模板檔案，並非在 WordPress 全域命名空間中到處使用的真正「全域變數」。
 * 為了維持模板可讀性與向後相容性，我們在此關閉 PrefixAllGlobals 警告。
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Template context: These variables use the museder_restoreone_ prefix and are scoped to this template file.
// They are provided by the rendering function and are not global namespace pollution.
$museder_restoreone_schedules       = isset( $schedules ) ? $schedules : Museder_Restoreone_Schedule_Handler::list_schedules();
$museder_restoreone_total_schedules = is_array( $museder_restoreone_schedules ) ? count( $museder_restoreone_schedules ) : 0;
$museder_restoreone_enabled_count   = 0;
$museder_restoreone_next_run_label  = __( 'Not scheduled', 'museder-restoreone' );
$museder_restoreone_next_run_title  = '—';
$museder_restoreone_next_run_diff   = '';

if ( $museder_restoreone_total_schedules ) {
	$museder_restoreone_now = time();
	foreach ( $museder_restoreone_schedules as $museder_restoreone_schedule ) {
		if ( isset( $museder_restoreone_schedule['status'] ) && 'disabled' !== $museder_restoreone_schedule['status'] ) {
			$museder_restoreone_enabled_count++;
		}

		if ( empty( $museder_restoreone_schedule['next_run'] ) ) {
			continue;
		}

		$museder_restoreone_timestamp = is_numeric( $museder_restoreone_schedule['next_run'] ) ? (int) $museder_restoreone_schedule['next_run'] : strtotime( $museder_restoreone_schedule['next_run'] );
		if ( ! $museder_restoreone_timestamp ) {
			continue;
		}

		if ( ! isset( $museder_restoreone_earliest_timestamp ) || $museder_restoreone_timestamp < $museder_restoreone_earliest_timestamp ) {
			$museder_restoreone_earliest_timestamp = $museder_restoreone_timestamp;
			// @plugin-check: wp_date with local timezone - $museder_restoreone_timestamp is UTC timestamp, museder_restoreone_format_local_time() handles timezone conversion
			$museder_restoreone_next_run_label     = museder_restoreone_format_local_time( $museder_restoreone_timestamp, 'Y-m-d H:i' );
			$museder_restoreone_next_run_title     = ! empty( $museder_restoreone_schedule['title'] ) ? $museder_restoreone_schedule['title'] : __( '(Untitled)', 'museder-restoreone' );
			if ( $museder_restoreone_timestamp >= $museder_restoreone_now ) {
				$museder_restoreone_next_run_diff = human_time_diff( $museder_restoreone_now, $museder_restoreone_timestamp );
			}
		}
	}
}
?>

<div class="wrap backup-lite-admin backup-lite-schedules museder-restoreone-admin museder-restoreone-schedules">
	<div class="schedule-hero schedule-hero--compact">
		<div class="schedule-hero-header">
			<div>
				<span class="badge"><?php esc_html_e( 'Automation', 'museder-restoreone' ); ?></span>
				<h1><?php esc_html_e( 'Schedules', 'museder-restoreone' ); ?></h1>
				<p><?php esc_html_e( 'Automate recurring backups with WP-Cron. Review status here, then add or edit jobs in one place.', 'museder-restoreone' ); ?></p>
			</div>
		</div>
		<div class="schedule-hero-stats">
			<div class="schedule-hero-stat">
				<span class="label"><?php esc_html_e( 'Total schedules', 'museder-restoreone' ); ?></span>
				<span class="value"><?php echo esc_html( $museder_restoreone_total_schedules ); ?></span>
			</div>
			<div class="schedule-hero-stat">
				<span class="label"><?php esc_html_e( 'Active', 'museder-restoreone' ); ?></span>
				<span class="value"><?php echo esc_html( $museder_restoreone_enabled_count ); ?></span>
			</div>
			<div class="schedule-hero-stat">
				<span class="label"><?php esc_html_e( 'Next run', 'museder-restoreone' ); ?></span>
				<span class="value"><?php echo esc_html( $museder_restoreone_next_run_label ); ?></span>
				<?php // @plugin-check: escaped ?>
				<span class="label"><?php echo esc_html( $museder_restoreone_next_run_title ); ?><?php echo $museder_restoreone_next_run_diff ? ' · ' . esc_html( sprintf(
					/* translators: %s: Relative time until the next run. */
					esc_html__( 'in %s', 'museder-restoreone' ),
					esc_html( $museder_restoreone_next_run_diff )
				) ) : ''; ?></span>
			</div>
		</div>
	</div>

	<div class="backup-lite-card bl-schedules-panel">
		<div class="bl-schedules-panel-header">
			<div>
				<h2><?php esc_html_e( 'Your schedules', 'museder-restoreone' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each job runs through WP-Cron. Open ⋮ and choose Edit inline to change settings below the row.', 'museder-restoreone' ); ?></p>
			</div>
			<button type="button" class="button button-primary bl-schedule-add-trigger" id="bl-new-schedule">
				<span aria-hidden="true">＋</span> <?php esc_html_e( 'Add schedule', 'museder-restoreone' ); ?>
			</button>
		</div>
		<div class="bl-table-scroll">
			<table class="backup-lite-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event name', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Status', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Period', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Time to start', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Next run', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Last result', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'museder-restoreone' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'museder-restoreone' ); ?></th>
					</tr>
				</thead>
				<tbody id="backup-lite-schedule-body">
					<?php if ( empty( $museder_restoreone_schedules ) ) : ?>
						<tr class="bl-schedules-empty-row">
							<td colspan="8">
								<div class="bl-schedules-empty" id="bl-schedules-empty-state">
									<p class="bl-schedules-empty__title"><?php esc_html_e( 'No schedules yet', 'museder-restoreone' ); ?></p>
									<p class="bl-schedules-empty__text"><?php esc_html_e( 'Create your first automated backup job. You can set frequency, retention, and optional email alerts.', 'museder-restoreone' ); ?></p>
									<button type="button" class="button button-primary bl-schedule-add-trigger">
										<span aria-hidden="true">＋</span> <?php esc_html_e( 'Add your first schedule', 'museder-restoreone' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $museder_restoreone_schedules as $museder_restoreone_schedule ) : ?>
							<tr class="bl-schedule-row" data-schedule-id="<?php echo esc_attr( $museder_restoreone_schedule['id'] ); ?>">
								<td><?php echo esc_html( $museder_restoreone_schedule['title'] ); ?></td>
								<?php // @plugin-check: escaped ?>
								<td class="<?php echo esc_attr( 'disabled' === $museder_restoreone_schedule['status'] ? 'bl-status-disabled' : 'bl-status-enabled' ); ?>">
									<?php echo 'disabled' === $museder_restoreone_schedule['status'] ? esc_html__( 'Disabled', 'museder-restoreone' ) : esc_html__( 'Enabled', 'museder-restoreone' ); ?>
								</td>
								<td><?php echo esc_html( ucfirst( $museder_restoreone_schedule['period'] ) ); ?></td>
								<td><?php echo esc_html( $museder_restoreone_schedule['time'] ); ?></td>
								<td>
									<?php
									$next_run_timestamp = isset( $museder_restoreone_schedule['next_run_timestamp_utc'] ) ? (int) $museder_restoreone_schedule['next_run_timestamp_utc'] : ( isset( $museder_restoreone_schedule['next_run'] ) ? (int) $museder_restoreone_schedule['next_run'] : 0 );
									if ( $next_run_timestamp > 0 ) {
										// @plugin-check: wp_date with local timezone - next_run_timestamp_utc is UTC timestamp, museder_restoreone_format_local_time() handles timezone conversion
										echo esc_html( museder_restoreone_format_local_time( $next_run_timestamp, 'Y-m-d H:i' ) );
									} else {
										esc_html_e( '—', 'museder-restoreone' );
									}
									?>
								</td>
								<td>
									<?php
									$museder_restoreone_result_key   = isset( $museder_restoreone_schedule['last_result'] ) ? strtolower( $museder_restoreone_schedule['last_result'] ) : 'pending';
									$museder_restoreone_result_map   = [
										'success' => [ 'icon' => '✅', 'label' => __( 'Success', 'museder-restoreone' ), 'class' => 'success' ],
										'failed'  => [ 'icon' => '❌', 'label' => __( 'Failed', 'museder-restoreone' ), 'class' => 'error' ],
										'pending' => [ 'icon' => '⏳', 'label' => __( 'Pending', 'museder-restoreone' ), 'class' => 'pending' ],
									];
									$museder_restoreone_result_value = isset( $museder_restoreone_result_map[ $museder_restoreone_result_key ] ) ? $museder_restoreone_result_map[ $museder_restoreone_result_key ] : $museder_restoreone_result_map['pending'];
									?>
									<span class="badge <?php echo esc_attr( $museder_restoreone_result_value['class'] ); ?>">
										<?php echo esc_html( $museder_restoreone_result_value['icon'] . ' ' . $museder_restoreone_result_value['label'] ); ?>
									</span>
								</td>
								<td>
									<?php
									$last_run_timestamp = isset( $museder_restoreone_schedule['last_run_timestamp_utc'] ) ? (int) $museder_restoreone_schedule['last_run_timestamp_utc'] : 0;
									if ( $last_run_timestamp <= 0 && ! empty( $museder_restoreone_schedule['last_run'] ) ) {
										// Migrate old MySQL datetime string to UTC timestamp
										$last_run_timestamp = strtotime( $museder_restoreone_schedule['last_run'] . ' UTC' );
									}
									if ( $last_run_timestamp > 0 ) {
										// @plugin-check: wp_date with local timezone - last_run_timestamp_utc is UTC timestamp, museder_restoreone_format_local_time() handles timezone conversion
										echo esc_html( museder_restoreone_format_local_time( $last_run_timestamp, 'Y-m-d H:i' ) );
									} else {
										esc_html_e( '—', 'museder-restoreone' );
									}
									?>
								</td>
								<td class="bl-schedule-actions-cell">
									<details class="bl-actions-menu">
										<summary class="bl-actions-trigger" aria-label="<?php esc_attr_e( 'More schedule actions', 'museder-restoreone' ); ?>">⋮</summary>
										<div class="bl-actions-list">
											<button type="button" class="button backup-lite-schedule-action-start bl-actions-list__item" data-schedule-id="<?php echo esc_attr( $museder_restoreone_schedule['id'] ); ?>">
												▶️ <?php esc_html_e( 'Start Now', 'museder-restoreone' ); ?>
											</button>
											<button type="button" class="button backup-lite-schedule-action-edit bl-actions-list__item" data-schedule-id="<?php echo esc_attr( $museder_restoreone_schedule['id'] ); ?>">
												✏️ <?php esc_html_e( 'Edit inline', 'museder-restoreone' ); ?>
											</button>
											<button type="button" class="button backup-lite-schedule-action-delete bl-actions-list__item" data-schedule-id="<?php echo esc_attr( $museder_restoreone_schedule['id'] ); ?>">
												🗑️ <?php esc_html_e( 'Delete', 'museder-restoreone' ); ?>
											</button>
										</div>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php if ( ! empty( $museder_restoreone_schedules ) ) : ?>
	<script type="application/json" id="museder-restoreone-schedules-data"><?php echo wp_json_encode( array_values( $museder_restoreone_schedules ) ); ?></script>
<?php endif; ?>

<div id="bl-schedule-modal" class="bl-modal" role="dialog" aria-hidden="true">
	<div class="bl-modal-backdrop" data-bl-modal-close></div>
	<div class="bl-modal-content" role="document">
		<div class="bl-modal-header">
			<h3 id="bl-modal-title"><?php esc_html_e( 'New Schedule', 'museder-restoreone' ); ?></h3>
			<button type="button" class="bl-modal-close" data-bl-modal-close aria-label="<?php esc_attr_e( 'Close', 'museder-restoreone' ); ?>">×</button>
		</div>
		<form id="bl-schedule-form">
			<input type="hidden" name="schedule_id" id="bl-schedule-id" data-field="id" value="" />
			<div class="bl-modal-body bl-modal-body-stylish">
				<header class="bl-schedule-form-header">
					<span class="badge"><?php esc_html_e( 'Schedule builder', 'museder-restoreone' ); ?></span>
					<h4><?php esc_html_e( 'Configure automated backup', 'museder-restoreone' ); ?></h4>
					<p><?php esc_html_e( 'Set frequency, retention, and optional notifications. You can change these settings anytime.', 'museder-restoreone' ); ?></p>
				</header>
				<div class="bl-form-grid">
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Title', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">📝</span>
							<input type="text" id="bl-schedule-title" data-field="title" placeholder="<?php esc_attr_e( 'Nightly web backup', 'museder-restoreone' ); ?>" required />
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Event type', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">⚙️</span>
							<select id="bl-schedule-type" data-field="type">
								<option value="backup"><?php esc_html_e( 'Backup', 'museder-restoreone' ); ?></option>
								<option value="restore"><?php esc_html_e( 'Restore', 'museder-restoreone' ); ?></option>
							</select>
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Schedule interval', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">⏱</span>
							<select id="bl-schedule-period" data-field="period">
								<option value="daily"><?php esc_html_e( 'Daily', 'museder-restoreone' ); ?></option>
								<option value="weekly" selected><?php esc_html_e( 'Weekly', 'museder-restoreone' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Monthly', 'museder-restoreone' ); ?></option>
							</select>
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Start time', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">🕒</span>
							<input type="time" id="bl-schedule-time" data-field="time" value="02:00" required />
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Keep the most recent (N) backups', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">📦</span>
							<input type="number" id="bl-schedule-retain" data-field="retain" min="1" step="1" value="5" />
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Remove backups older than (days)', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">🧹</span>
							<input type="number" id="bl-schedule-max-age" data-field="max_age" min="0" step="1" value="30" />
						</div>
					</label>
					<label class="bl-form-control">
						<span><?php esc_html_e( 'Notification email (optional)', 'museder-restoreone' ); ?></span>
						<div class="bl-input-with-icon">
							<span class="icon">✉️</span>
							<input type="email" id="bl-schedule-notify" data-field="notify" placeholder="admin@example.com" />
						</div>
					</label>
					<label class="bl-form-control bl-toggle bl-toggle-modern">
						<span><?php esc_html_e( 'Status', 'museder-restoreone' ); ?></span>
						<div class="bl-toggle-wrapper">
							<input type="checkbox" id="bl-schedule-status" data-field="status" checked />
							<span class="bl-toggle-label" data-enabled="<?php esc_attr_e( 'Enabled', 'museder-restoreone' ); ?>" data-disabled="<?php esc_attr_e( 'Disabled', 'museder-restoreone' ); ?>"></span>
						</div>
					</label>
				</div>
			</div>
			<div class="bl-modal-footer">
				<button type="button" class="button" data-bl-modal-close><?php esc_html_e( 'Cancel', 'museder-restoreone' ); ?></button>
				<button type="submit" class="button button-primary" id="bl-save-schedule"><?php esc_html_e( 'Save schedule', 'museder-restoreone' ); ?></button>
			</div>
		</form>
	</div>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
