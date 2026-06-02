<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Museder_Restoreone_UI {

    /**
     * Partial slug used to detect our admin pages when enqueuing assets.
     * Keep both the current and legacy prefixes working during the UI migration.
     */
    const PAGE_SLUG = 'museder-restoreone';
    const NONCE     = 'museder_restoreone_action';
    const NONCE_V2  = 'museder_restoreone_v2';

    public static function init() {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ __CLASS__, 'render_restore_notice' ] );
        add_action( 'admin_notices', [ __CLASS__, 'render_multisite_experimental_notice' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_restore_notice_dismiss' ] );
        add_action( 'admin_post_museder_restoreone_download_restore_sql', [ __CLASS__, 'handle_download_restore_sql' ] );

        add_action( 'wp_ajax_museder_restoreone_run_backup', [ __CLASS__, 'handle_backup_request' ] );
        add_action( 'wp_ajax_museder_restoreone_run_restore', [ __CLASS__, 'handle_restore_request' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_existing', [ __CLASS__, 'handle_restore_existing' ] );
        add_action( 'wp_ajax_museder_restoreone_get_backups_list', [ __CLASS__, 'handle_get_backups_list' ] );
        add_action( 'wp_ajax_museder_restoreone_delete_backup', [ __CLASS__, 'handle_delete_backup' ] );
        add_action( 'wp_ajax_museder_restoreone_delete_backups', [ __CLASS__, 'handle_delete_backups' ] );
        add_action( 'wp_ajax_museder_restoreone_delete_restore_history', [ __CLASS__, 'handle_delete_restore_history' ] );
        add_action( 'wp_ajax_museder_restoreone_start_backup_job', [ __CLASS__, 'ajax_start_backup_job' ] );
        add_action( 'wp_ajax_museder_restoreone_get_job_status', [ __CLASS__, 'ajax_get_backup_job_status' ] );
        // Alias for newer frontend builds (keep both for backward compatibility).
        add_action( 'wp_ajax_museder_restoreone_get_backup_job_status', [ __CLASS__, 'ajax_get_backup_job_status' ] );
        add_action( 'wp_ajax_museder_restoreone_continue_backup_job', [ __CLASS__, 'ajax_continue_backup_job' ] );
        add_action( 'wp_ajax_museder_restoreone_cancel_backup_job', [ __CLASS__, 'ajax_cancel_backup_job' ] );
        add_action( 'wp_ajax_museder_restoreone_get_active_backup_job', [ __CLASS__, 'ajax_get_active_backup_job' ] );
        add_action( 'wp_ajax_museder_restoreone_refresh_nonce', [ __CLASS__, 'ajax_refresh_nonce' ] );
        add_action( 'wp_ajax_museder_restoreone_keep_alive', [ __CLASS__, 'ajax_keep_alive' ] );

        add_action( 'admin_post_museder_restoreone_download_log', [ __CLASS__, 'handle_log_download' ] );
        add_action( 'admin_post_museder_restoreone_download_backup', [ __CLASS__, 'handle_backup_download' ] );
        add_action( 'admin_post_museder_restoreone_download_report', [ __CLASS__, 'handle_report_download' ] );
    }

    /**
     * Lightweight keep-alive to reduce admin session expiry during long operations.
     */
    public static function ajax_keep_alive() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'museder-restoreone' ) ], 403 );
        }
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );
        wp_send_json_success( [ 'ok' => true, 'ts' => time() ] );
    }

    /**
     * Render an unattended restore notice for the current admin user.
     */
    public static function render_restore_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            return;
        }
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $uid <= 0 ) {
            return;
        }
        $dismissed = (int) get_user_meta( $uid, 'museder_restoreone_restore_notice_dismissed', true );
        if ( 1 === $dismissed ) {
            return;
        }
        $job_id = (string) get_user_meta( $uid, 'museder_restoreone_restore_notice_job_id', true );
        if ( '' === $job_id ) {
            return;
        }

        $status = null;
        try {
            $status = Museder_Restoreone_Restore_Service::status( $job_id );
        } catch ( Exception $e ) {
            $status = null;
        }
        if ( ! is_array( $status ) ) {
            return;
        }

        $stage    = isset( $status['stage'] ) ? (string) $status['stage'] : '';
        $progress = isset( $status['progress'] ) ? (int) $status['progress'] : 0;
        $message  = isset( $status['message'] ) ? (string) $status['message'] : '';
        $completed = ! empty( $status['completed'] );

        $type = 'info';
        $title = __( 'Restore running in background', 'museder-restoreone' );
        if ( $completed ) {
            if ( 'done' === $stage || 'rollback-done' === $stage ) {
                $type  = 'success';
                $title = __( 'Restore completed', 'museder-restoreone' );
            } elseif ( 'cancelled' === $stage ) {
                $type  = 'warning';
                $title = __( 'Restore cancelled', 'museder-restoreone' );
            } else {
                $type  = 'error';
                $title = __( 'Restore failed', 'museder-restoreone' );
            }
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( [ 'museder_restoreone_dismiss_restore_notice' => 1, 'job_id' => rawurlencode( $job_id ) ], admin_url() ),
            'museder_restoreone_dismiss_restore_notice'
        );
        $restore_url = admin_url( 'admin.php?page=museder-restoreone-restore' );

        printf(
            '<div class="notice notice-%1$s"><p><strong>%2$s</strong> — %3$s</p><p>%4$s</p></div>',
            esc_attr( $type ),
            esc_html( $title ),
            /* translators: %d: progress percentage */
            esc_html( sprintf( __( 'Progress: %d%%', 'museder-restoreone' ), $progress ) ),
            wp_kses_post(
                sprintf(
                    /* translators: 1: message, 2: restore url, 3: dismiss url */
                    __( '%1$s <a href="%2$s">Open Restore page</a> · <a href="%3$s">Dismiss</a>', 'museder-restoreone' ),
                    $message ? esc_html( $message ) : esc_html__( 'Working…', 'museder-restoreone' ),
                    esc_url( $restore_url ),
                    esc_url( $dismiss_url )
                )
            )
        );
    }

    /**
     * Warn network admins that Multisite is experimental for this plugin (readme stance).
     */
    public static function render_multisite_experimental_notice() {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || empty( $screen->id ) ) {
            return;
        }
        $screen_id = (string) $screen->id;
        if ( false === strpos( $screen_id, 'museder-restoreone' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
            esc_html__( 'WordPress Multisite', 'museder-restoreone' ),
            esc_html__( 'Museder RestoreOne is not formally tested on Multisite. Verify backups and restores on a staging copy of your network before relying on it in production.', 'museder-restoreone' )
        );
    }

    /**
     * Handle dismiss action for the restore notice.
     */
    public static function handle_restore_notice_dismiss() {
        if ( ! isset( $_GET['museder_restoreone_dismiss_restore_notice'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'museder_restoreone_dismiss_restore_notice' );
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $uid > 0 ) {
            update_user_meta( $uid, 'museder_restoreone_restore_notice_dismissed', 1 );
        }
        // Redirect to remove query args.
        wp_safe_redirect( remove_query_arg( [ 'museder_restoreone_dismiss_restore_notice', '_wpnonce', 'job_id' ] ) );
        exit;
    }

    /**
     * Download persisted database.sql for a restore job (manual DB import fallback).
     */
    public static function handle_download_restore_sql() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'museder-restoreone' ), 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below
        $job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
        if ( '' === $job_id ) {
            wp_die( esc_html__( 'Missing job id.', 'museder-restoreone' ), 400 );
        }

        check_admin_referer( 'museder_restoreone_download_restore_sql_' . $job_id );

        if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $job_id ) ) {
            wp_die( esc_html__( 'Invalid job id.', 'museder-restoreone' ), 400 );
        }

        $jobs_root = museder_restoreone_get_jobs_dir();
        $jobs_real = realpath( $jobs_root );
        if ( ! $jobs_real ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ), 400 );
        }

        $jobs_prefix = trailingslashit( wp_normalize_path( $jobs_real ) );
        $candidate    = wp_normalize_path( $jobs_prefix . $job_id . '/database.sql' );
        $file_real    = file_exists( $candidate ) ? realpath( $candidate ) : false;
        if ( ! $file_real || 0 !== strpos( wp_normalize_path( $file_real ), $jobs_prefix ) ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ), 400 );
        }
        if ( ! is_readable( $file_real ) ) {
            wp_die( esc_html__( 'SQL file not found.', 'museder-restoreone' ), 404 );
        }
        $file = $file_real;

        // WP.org compliance: do not stream arbitrary-sized files via direct PHP file operations here.
        // Instead, provide a clear manual import instruction page and the on-server path to the SQL file.
        $html  = '<div class="wrap"><h1>' . esc_html__( 'Manual database import required', 'museder-restoreone' ) . '</h1>';
        $html .= '<p>' . esc_html__( 'This host does not support automatic database import (MySQL CLI). Please import the database manually using your hosting control panel or phpMyAdmin.', 'museder-restoreone' ) . '</p>';
        $html .= '<h2>' . esc_html__( 'SQL file location', 'museder-restoreone' ) . '</h2>';
        $html .= '<p><code>' . esc_html( $file ) . '</code></p>';
        $html .= '<h2>' . esc_html__( 'Suggested steps', 'museder-restoreone' ) . '</h2>';
        $html .= '<ol>';
        $html .= '<li>' . esc_html__( 'Download the file above via your hosting File Manager or FTP.', 'museder-restoreone' ) . '</li>';
        $html .= '<li>' . esc_html__( 'Open phpMyAdmin (or your database manager) and select your WordPress database.', 'museder-restoreone' ) . '</li>';
        $html .= '<li>' . esc_html__( 'Use the Import feature and choose database.sql, then run the import.', 'museder-restoreone' ) . '</li>';
        $html .= '<li>' . esc_html__( 'Return to Restore Center to confirm your site loads correctly.', 'museder-restoreone' ) . '</li>';
        $html .= '</ol></div>';

        wp_die( wp_kses_post( $html ) );
    }

    public static function enqueue_assets( $hook ) {
        // Allow the current admin pages plus any legacy-prefixed pages during migration.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page parameter is for UI display only, not for security-sensitive operations
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        $is_plugin_hook = (
            0 === strpos( (string) $hook, 'toplevel_page_museder-restoreone-' )
            || 0 === strpos( (string) $hook, 'museder-restoreone_page_museder-restoreone-' )
            || false !== strpos( (string) $hook, 'backup-lite' )
        );

        $is_plugin_page = (
            0 === strpos( $page, 'museder-restoreone-' )
            || 0 === strpos( $page, 'backup-lite' )
        );

        if ( ! $is_plugin_hook && ! $is_plugin_page ) {
            return;
        }

        wp_enqueue_style(
            'museder-restoreone-admin',
            MUSEDER_RESTOREONE_URL . 'assets/css/admin.css',
            [],
            MUSEDER_RESTOREONE_VERSION
        );

        wp_enqueue_style(
            'museder-restoreone-ui',
            MUSEDER_RESTOREONE_URL . 'assets/css/admin-style.css',
            [ 'museder-restoreone-admin' ],
            MUSEDER_RESTOREONE_VERSION
        );

        wp_enqueue_style(
            'museder-restoreone-theme',
            MUSEDER_RESTOREONE_URL . 'assets/css/backup-lite-theme.css',
            [ 'museder-restoreone-ui' ],
            MUSEDER_RESTOREONE_VERSION
        );

        wp_enqueue_style(
            'toastify-css',
            MUSEDER_RESTOREONE_URL . 'assets/vendor/toastify.min.css',
            [],
            '1.12.0'
        );

        wp_enqueue_script(
            'toastify',
            MUSEDER_RESTOREONE_URL . 'assets/vendor/toastify.min.js',
            [],
            '1.12.0',
            true
        );

        wp_enqueue_script(
            'museder-restoreone-admin',
            MUSEDER_RESTOREONE_URL . 'assets/js/admin.js',
            [ 'jquery', 'toastify' ],
            MUSEDER_RESTOREONE_VERSION,
            true
        );

        wp_enqueue_script(
            'museder-restoreone-admin-ui',
            MUSEDER_RESTOREONE_URL . 'assets/js/admin-ui.js',
            [],
            MUSEDER_RESTOREONE_VERSION,
            false
        );

        // Localize optional add-on availability for frontend messaging.
        $is_pro = function_exists( 'museder_is_pro_active' ) ? museder_is_pro_active() : false;
        $settings = Museder_Restoreone_Settings::get_settings();

        $addon_payload = [
            'isPro'      => $is_pro,
            'upgradeUrl' => '',
            'strings'    => [
                'modalTitle'    => __( 'Optional add-on setting unavailable', 'museder-restoreone' ),
                'modalSubtitle' => __( 'This control is reserved for an optional add-on. It is not used by the base plugin.', 'museder-restoreone' ),
                'close'         => __( 'Close', 'museder-restoreone' ),
                'upgrade'       => __( 'OK', 'museder-restoreone' ),
                /* translators: %s: Name of the reserved control or feature key. */
                'featureLocked' => __( 'Setting "%s" is reserved for an optional add-on and is not available here.', 'museder-restoreone' ),
            ],
        ];
        $addon_json = wp_json_encode(
            $addon_payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        wp_add_inline_script(
            'museder-restoreone-admin-ui',
            'window.MusederRestoreOneAddon=' . $addon_json . ';window.MusederRestoreOnePro=window.MusederRestoreOneAddon;',
            'before'
        );

        // Inject basic admin preferences and feature toggles (single localize; avoid overwriting `features`).
        wp_localize_script(
            'museder-restoreone-admin-ui',
            'MusederRestoreOneAdminUI',
            [
                'theme'    => isset( $settings['ui_theme'] ) ? $settings['ui_theme'] : 'auto',
                'features' => [
                    'restoreV2'   => ! empty( $settings['feature_restore_center_v2'] ),
                    'animations'  => ! empty( $settings['feature_ui_animation'] ),
                    'extendedLog' => ! empty( $settings['feature_extended_log'] ),
                ],
            ]
        );

        wp_enqueue_script(
            'museder-restoreone-chunk-upload',
            MUSEDER_RESTOREONE_URL . 'assets/js/chunk-upload.js',
            [ 'museder-restoreone-admin' ],
            MUSEDER_RESTOREONE_VERSION,
            true
        );

        wp_enqueue_script(
            'museder-restoreone-chunk-upload-v2',
            MUSEDER_RESTOREONE_URL . 'assets/js/chunk-upload-v2.js',
            [ 'museder-restoreone-admin' ],
            MUSEDER_RESTOREONE_VERSION,
            true
        );

        $rest_url_v2 = rest_url( 'museder-restoreone/v2/' );
        $nonce_v2    = wp_create_nonce( self::NONCE_V2 );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page parameter is for UI display only, not for security-sensitive operations
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        // Add inline styles and scripts for specific pages
        self::add_page_specific_inline_assets( $current_page );

        $active_job = Museder_Restoreone_Backup_Jobs::get_active_job_summary();

        // Localize script for schedule actions (use unique globals to avoid conflicts).
        wp_localize_script(
            'museder-restoreone-admin',
            'musederRestoreoneAdmin',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'museder_restoreone_admin_actions' ),
            ]
        );

        // Also localize as MusederRestoreOne for compatibility
        wp_localize_script(
            'museder-restoreone-admin',
            'MusederRestoreOne',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'museder_restoreone_admin_actions' ),
                'i18n_confirm_delete_schedule' => __( 'Delete this schedule?', 'museder-restoreone' ),
            ]
        );

        // Localize schedule-specific strings
        wp_localize_script(
            'museder-restoreone-admin',
            'musederRestoreoneSchedulesL10n',
            [
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'museder_restoreone_admin_actions' ),
                'updateSchedule' => __( 'Update Schedule', 'museder-restoreone' ),
                'saveSchedule'   => __( 'Save Schedule', 'museder-restoreone' ),
                'confirmDelete'  => __( 'Are you sure you want to delete this schedule?', 'museder-restoreone' ),
            ]
        );

        wp_localize_script( 'museder-restoreone-admin', 'MusederRestoreOneAdmin', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE ),
            'nonceV2'        => $nonce_v2,
            'restUrlV2'      => $rest_url_v2,
            'restRouteV2'    => '/museder-restoreone/v2/',
            'restQueryBase'  => esc_url_raw( site_url( '/?rest_route=' ) ),
            'finalStatusAjaxAction' => 'museder_restoreone_restore_final_status',
            'page'           => $current_page,
            'activeJob'      => $active_job,
            'jobPollingInterval' => 2.0, // Default 2 seconds, will be adjusted dynamically based on progress
            'confirmRestore' => __( 'Restoring will overwrite your current site files and database. Continue?', 'museder-restoreone' ),
            'restoreAutotune' => [
                // Balanced defaults: allow the client to ramp up but stay within safe bounds.
                'maxConcurrency' => 4,
                'maxChunkBytes'  => 16 * 1024 * 1024,
                'ttlMs'          => 7 * 24 * 60 * 60 * 1000,
            ],
            'strings'        => [
                'runningTitle'    => __( 'Processing…', 'museder-restoreone' ),
                'runningMessage'  => __( 'Please wait while we complete your request.', 'museder-restoreone' ),
                'errorTitle'      => __( 'Something went wrong', 'museder-restoreone' ),
                'errorGeneric'    => __( 'An unexpected error occurred. Check logs for details.', 'museder-restoreone' ),
                'noFileSelected'  => __( 'Please select a backup file to restore.', 'museder-restoreone' ),
                'noConfirm'       => __( 'Please confirm you understand the restore impact.', 'museder-restoreone' ),
                'successTitle'    => __( 'Completed', 'museder-restoreone' ),
                'successBackup'   => __( 'Site backup completed successfully.', 'museder-restoreone' ),
                'successRestore'  => __( 'Site restore completed successfully.', 'museder-restoreone' ),
                'downloadLabel'   => __( 'Download backup', 'museder-restoreone' ),
                'downloadUnavailable' => __( 'Download link is not available. Please download from the backup library.', 'museder-restoreone' ),
                'downloadExpired' => __( 'Your download link has expired. Please download from the backup library.', 'museder-restoreone' ),
                'noLogs'          => __( 'No log entries yet.', 'museder-restoreone' ),
                'uploadStarting'  => __( 'Preparing upload…', 'museder-restoreone' ),
                /* translators: 1: Current chunk number, 2: Total number of chunks. */
                'uploadChunk'     => __( 'Uploading chunk %1$s of %2$s…', 'museder-restoreone' ),
                'merging'         => __( 'Merging uploaded chunks…', 'museder-restoreone' ),
                'restoring'       => __( 'Restoring site…', 'museder-restoreone' ),
                /* translators: %s: Number of retries left. */
                'retryNotice'     => __( 'Retrying chunk upload… (%1$s attempts left)', 'museder-restoreone' ),
                'uploadAborted'   => __( 'Upload aborted.', 'museder-restoreone' ),
                /* translators: %s: Transfer speed in megabytes per second. */
                'speed'           => __( 'Speed: %s MB/s', 'museder-restoreone' ),
                /* translators: %s: Estimated time remaining in seconds. */
                'eta'             => __( 'ETA: %s seconds', 'museder-restoreone' ),
                /* translators: %s: Maximum allowed file size. */
                'fileTooLarge'    => __( 'File exceeds maximum allowed size (%s).', 'museder-restoreone' ),
                /* translators: %s: Number of missing upload chunks. */
                'missingChunks'   => __( 'Missing chunks detected: %s', 'museder-restoreone' ),
                'resumeUpload'    => __( 'Re-uploading missing chunks…', 'museder-restoreone' ),
                'prepareFailed'   => __( 'Unable to start upload session.', 'museder-restoreone' ),
                /* translators: %s: SHA1 hash calculated on the server. */
                'serverSha1'      => __( 'Server SHA1: %s', 'museder-restoreone' ),
                /* translators: %s: SHA1 hash calculated on the client. */
                'clientSha1'      => __( 'Client SHA1: %s', 'museder-restoreone' ),
                'sha1Mismatch'    => __( 'Warning: SHA1 mismatch detected!', 'museder-restoreone' ),
                'errorSha1Mismatch' => __( 'Uploaded archive failed integrity check. Please re-upload the backup.', 'museder-restoreone' ),
                'errorZipVerificationFailed' => __( 'Merged archive could not be validated. Check restore.log for details.', 'museder-restoreone' ),
                'errorZipOpenFailed' => __( 'Unable to extract backup archive. See restore.log and debug.log for details.', 'museder-restoreone' ),
                /* translators: %s: List of allowed file extensions. */
                'invalidExtension'=> __( 'Unsupported file extension. Allowed: %s', 'museder-restoreone' ),
                'confirmDelete'   => __( 'Are you sure you want to delete this backup? This action cannot be undone.', 'museder-restoreone' ),
                'viewLog'         => __( 'View', 'museder-restoreone' ),
                'downloadLog'     => __( 'Download', 'museder-restoreone' ),
                'deleteLog'       => __( 'Delete', 'museder-restoreone' ),
                'logTruncated'    => __( 'Showing last 200KB (truncated).', 'museder-restoreone' ),
                'logsRefreshed'   => __( 'Logs refreshed.', 'museder-restoreone' ),
                'logDeleted'      => __( 'Log deleted.', 'museder-restoreone' ),
                'settingsSaved'   => __( 'Settings saved successfully.', 'museder-restoreone' ),
                'confirmDeleteLog'=> __( 'Delete this log file?', 'museder-restoreone' ),
                'testEmailSuccess'=> __( 'Test email sent successfully.', 'museder-restoreone' ),
                'copySuccess'     => __( 'Copied to clipboard', 'museder-restoreone' ),
                'noSchedules'     => __( 'No schedules configured yet.', 'museder-restoreone' ),
                'scheduleResultSuccess' => __( 'Success', 'museder-restoreone' ),
                'scheduleResultFailed'  => __( 'Failed', 'museder-restoreone' ),
                'scheduleResultPending' => __( 'Pending', 'museder-restoreone' ),
                'scheduleEnabled'       => __( 'Enabled', 'museder-restoreone' ),
                'scheduleDisabled'      => __( 'Disabled', 'museder-restoreone' ),
                'restoreInProgress'     => __( 'Restore in Progress', 'museder-restoreone' ),
                'restoreCompleted'      => __( 'Restore Completed', 'museder-restoreone' ),
                'awaitingRestore'       => __( 'Awaiting restore.', 'museder-restoreone' ),
                'stepUploadIdle'        => __( 'Choose a backup and run Step 1.', 'museder-restoreone' ),
                'stepUploadProcessing'  => __( 'Analyzing backup…', 'museder-restoreone' ),
                'stepUploadDone'        => __( 'Analysis complete. Continue to Step 2.', 'museder-restoreone' ),
                'stepUploadError'       => __( 'Analysis failed. Try again.', 'museder-restoreone' ),
                'stepReviewLocked'      => __( 'Complete Step 1 first to unlock these options.', 'museder-restoreone' ),
                'stepReviewReady'       => __( 'Options unlocked. Adjust restore behavior.', 'museder-restoreone' ),
                'stepReviewDone'        => __( 'Options saved. Continue to Step 3.', 'museder-restoreone' ),
                'stepExecuteLocked'     => __( 'Complete Steps 1 & 2 before starting the restore.', 'museder-restoreone' ),
                'stepExecuteReady'      => __( 'Ready to start restore.', 'museder-restoreone' ),
                'stepExecuteProcessing' => __( 'Restore running…', 'museder-restoreone' ),
                'stepExecuteDone'       => __( 'Restore finished. Review your site.', 'museder-restoreone' ),
                'restoreFinalizing'     => __( 'Finalizing restore…', 'museder-restoreone' ),
                'restoreFinalizingMessage' => __( 'Completing final steps…', 'museder-restoreone' ),
                'restoreFailed'         => __( 'Restore Failed', 'museder-restoreone' ),
                'restoreOverlayMessage' => __( 'Museder RestoreOne has finished restoring your site.', 'museder-restoreone' ),
                'restoreOverlayConfirm' => __( 'Got it', 'museder-restoreone' ),
                'restoreTickFallbackActive' => __( 'Cron appears unreliable. Using your browser to push restore progress…', 'museder-restoreone' ),
                'restoreCancelUnconfirmed' => __( 'Cancel request could not be confirmed. Restore may still be running; checking the final status now.', 'museder-restoreone' ),
                'selectAtLeastOneBackup' => __( 'Please select at least one backup.', 'museder-restoreone' ),
                /* translators: %s: Number of backups selected. */
                'downloadingBackups'     => __( 'Downloading %s backup(s)...', 'museder-restoreone' ),
                /* translators: %s: Number of backups deleted. */
                'deletedBackups'         => __( 'Deleted %s backup(s).', 'museder-restoreone' ),
                /* translators: %s: Number of backups that could not be deleted. */
                'failedDeleteBackups'    => __( 'Failed to delete %s backup(s). Check logs.', 'museder-restoreone' ),
                'manualJobStarted'       => __( 'Backup job started manually.', 'museder-restoreone' ),
                'provideScheduleTitle'   => __( 'Please provide a schedule title.', 'museder-restoreone' ),
                'scheduleDeleted'        => __( 'Schedule deleted.', 'museder-restoreone' ),
                'unableDeleteSchedule'   => __( 'Unable to delete schedule.', 'museder-restoreone' ),
                'scheduleSaved'          => __( 'Schedule saved successfully.', 'museder-restoreone' ),
                'updateSchedule'         => __( 'Update Schedule', 'museder-restoreone' ),
                'saveSchedule'           => __( 'Save Schedule', 'museder-restoreone' ),
                'licenseError'           => __( 'An error occurred while verifying the license.', 'museder-restoreone' ),
                'scheduleActionStart'    => __( 'Start Now', 'museder-restoreone' ),
                'scheduleActionEdit'     => __( 'Edit', 'museder-restoreone' ),
                'scheduleActionDelete'   => __( 'Delete', 'museder-restoreone' ),
                'scheduleActionsAria'    => __( 'Schedule actions', 'museder-restoreone' ),
                'confirmDeleteSelected'  => __( 'Are you sure you want to delete the selected backups? This action cannot be undone.', 'museder-restoreone' ),
                'confirmDeleteSchedule'  => __( 'Delete this schedule?', 'museder-restoreone' ),
                'confirmDeleteSelectedRestoreHistory' => __( 'Are you sure you want to delete the selected restore history entries? This action cannot be undone.', 'museder-restoreone' ),
                'restoreHistoryDeleted' => __( 'Restore history entries deleted.', 'museder-restoreone' ),
                'selectAtLeastOne' => __( 'Please select at least one entry.', 'museder-restoreone' ),
                'noEntriesSelected' => __( 'No valid entries selected.', 'museder-restoreone' ),
                'messageReady'           => __( 'Backup ready for restore.', 'museder-restoreone' ),
                'confirmOverwriteData'   => __( 'This will overwrite your site data. Continue?', 'museder-restoreone' ),
                'jobPreparing'    => __( 'Preparing backup…', 'museder-restoreone' ),
                'jobQueued'       => __( 'Waiting for server resources…', 'museder-restoreone' ),
                'jobProcessing'   => __( 'Processing files…', 'museder-restoreone' ),
                'jobFinalizing'   => __( 'Finalising backup archive…', 'museder-restoreone' ),
                'jobComplete'     => __( 'Backup completed successfully.', 'museder-restoreone' ),
                'jobFailed'       => __( 'Backup failed. Check logs for details.', 'museder-restoreone' ),
                'jobResuming'     => __( 'Resuming previous backup job…', 'museder-restoreone' ),
                'jobNudging'      => __( 'Continuing backup in the background…', 'museder-restoreone' ),
                'jobCancelled'    => __( 'Backup cancelled.', 'museder-restoreone' ),
                'jobButtonBusy'   => __( 'Backup in progress…', 'museder-restoreone' ),
                'jobButtonIdle'   => __( 'Backup Site', 'museder-restoreone' ),
                'jobCancelConfirm'=> __( 'Cancel the running backup job?', 'museder-restoreone' ),
                'jobCancelSuccess'=> __( 'Backup job cancelled.', 'museder-restoreone' ),
                'jobCancelFailed' => __( 'Unable to cancel backup job. Please try again.', 'museder-restoreone' ),
                'backupModeLabel' => __( 'Mode', 'museder-restoreone' ),
                'backupModeFast'  => __( 'Fast', 'museder-restoreone' ),
                'backupModeBalanced' => __( 'Balanced', 'museder-restoreone' ),
                'backupModeUnknown'  => __( '—', 'museder-restoreone' ),
                'smartExcludeOn'  => __( 'Smart Exclude: On', 'museder-restoreone' ),
                'smartExcludeOff' => __( 'Smart Exclude: Off', 'museder-restoreone' ),
                'backupModeAutoSwitched' => __( 'Auto enabled Fast mode for a large site.', 'museder-restoreone' ),
                /* translators: %d: Number of hidden notices. */
                'hiddenNoticesSummary'   => __( 'Hidden notices (%d)', 'museder-restoreone' ),
                'restoreCancelConfirm' => __( 'Cancel the current restore process?', 'museder-restoreone' ),
                'restoreCancelSuccess' => __( 'Restore process cancelled.', 'museder-restoreone' ),
                'restoreCancelFailed'  => __( 'Unable to cancel restore process. Please try again.', 'museder-restoreone' ),
                'dbMissingHint'        => __( 'Database file not found in this backup. Files-only restore is recommended.', 'museder-restoreone' ),
                'restoreProfileLabel'  => __( 'Site profile', 'museder-restoreone' ),
                'restoreSuggestFilesFirst' => __( 'Files will be restored before the database (recommended for this site profile).', 'museder-restoreone' ),
                'restoreBootstrapHint' => __( 'Copy museder-restoreone-restore-bootstrap.php from the plugin folder to your site root if wp-admin is unavailable during restore.', 'museder-restoreone' ),
                'dbSqlManualHint'      => __( 'This backup contains database.sql. Automatic database import is disabled; files will be restored and database must be imported manually.', 'museder-restoreone' ),
                'chunkPreparing'        => __( 'Preparing upload…', 'museder-restoreone' ),
                /* translators: 1: Current chunk number, 2: Total chunks, 3: Progress percentage. */
                'chunkUploading'        => __( 'Uploading %1$s of %2$s (%3$s%)…', 'museder-restoreone' ),
                'chunkMerging'          => __( 'Merging uploaded chunks…', 'museder-restoreone' ),
                'safeModeOverlayHint'   => __( 'Safe mode is active: a plugin snapshot was saved for your review. Verify your site, then exit safe mode to clear the notice.', 'museder-restoreone' ),
                'exitSafeMode'          => __( 'Exit Safe Mode', 'museder-restoreone' ),
            ],
            'chunk'         => [
                'prepareAction'  => 'museder_restoreone_prepare_upload',
                'chunkSize'     => 2 * 1024 * 1024,
                'maxFileSize'   => 4294967296,
                'uploadAction'  => 'museder_restoreone_upload_chunk',
                'finalizeAction'=> 'museder_restoreone_finalize_upload',
                'abortAction'   => 'museder_restoreone_abort_upload',
                'allowedExt'    => [ 'zip' ],
            ],
        ] );

        wp_localize_script(
            'museder-restoreone-chunk-upload-v2',
            'MusederRestoreOneV2',
            [
                'restUrl'        => esc_url_raw( $rest_url_v2 ),
                'nonce'          => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    public static function handle_backup_request() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        $options = self::get_backup_options_from_request();

        $response = Museder_Restoreone_Backup::backup_site( $options );

        if ( ! empty( $response['success'] ) ) {
            if ( ! empty( $response['file'] ) ) {
                $response['download_url'] = self::build_backup_download_link( $response['file'] );
            }
            wp_send_json_success( $response );
        }

        wp_send_json_error( $response );
    }

    public static function ajax_start_backup_job() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        try {
            $options = self::get_backup_options_from_request();
            $job     = Museder_Restoreone_Backup_Jobs::create_job( $options );

            wp_send_json_success( [
                'job'     => Museder_Restoreone_Backup_Jobs::format_job_payload( $job ),
                // @plugin-check: escaped
                'message' => esc_html__( 'Backup job created. Processing has started in the background.', 'museder-restoreone' ),
            ] );
        } catch ( Exception $exception ) {
            museder_restoreone_log( 'error', 'Failed to start backup job.', [
                'error' => $exception->getMessage(),
            ] );

            // @plugin-check: escaped - exception message is user-facing error
            wp_send_json_error( [ 'message' => esc_html( $exception->getMessage() ) ], 500 );
        }
    }

    public static function ajax_get_backup_job_status() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        $payload = Museder_Restoreone_Backup_Jobs::get_job_payload( $job_id );
        if ( ! $payload ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found or already completed.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [ 'job' => $payload ] );
    }

    public static function ajax_continue_backup_job() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        $job = Museder_Restoreone_Backup_Jobs::process_job_immediately( $job_id, Museder_Restoreone_Backup_Jobs::AJAX_BATCH_FILES, Museder_Restoreone_Backup_Jobs::AJAX_BATCH_BYTES );
        if ( ! $job ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [
            'job'     => Museder_Restoreone_Backup_Jobs::format_job_payload( $job ),
            // @plugin-check: escaped
            'message' => esc_html__( 'Backup job updated.', 'museder-restoreone' ),
        ] );
    }

    public static function ajax_cancel_backup_job() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        if ( ! Museder_Restoreone_Backup_Jobs::cancel_job( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to cancel backup job.', 'museder-restoreone' ) ], 404 );
        }

        // @plugin-check: escaped
        wp_send_json_success( [ 'message' => esc_html__( 'Backup job cancelled.', 'museder-restoreone' ) ] );
    }

    /**
     * Return current active backup job (if any) for UI recovery when initial request times out.
     */
    public static function ajax_get_active_backup_job() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        $payload = Museder_Restoreone_Backup_Jobs::get_active_job_summary();
        if ( ! $payload ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No active backup job found.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [ 'job' => $payload ] );
    }

    public static function handle_restore_request() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        // @plugin-check: sanitized + nonce - verified via verify_ajax_request() above
        $confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( empty( $confirm ) || '1' !== $confirm ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Restore not confirmed by user.', 'museder-restoreone' ) ] );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Nonce verified in verify_ajax_request() above, $_FILES array validated via isset() and is_uploaded_file(), using PHP upload file array provided by the system
        // @plugin-check: sanitized + nonce - verified via verify_ajax_request() above
        $uploaded_file = null;
        if ( isset( $_FILES['restoreFile'] ) && is_uploaded_file( $_FILES['restoreFile']['tmp_name'] ) ) {
            $uploaded_file = $_FILES['restoreFile'];
        } elseif ( isset( $_FILES['restore_file'] ) && is_uploaded_file( $_FILES['restore_file']['tmp_name'] ) ) {
            $uploaded_file = $_FILES['restore_file'];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( empty( $uploaded_file ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No restore file uploaded.', 'museder-restoreone' ) ] );
        }

        $file = $uploaded_file;

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            $file_php = function_exists( 'museder_restoreone_get_core_admin_include_path' ) ? museder_restoreone_get_core_admin_include_path( 'file.php' ) : '';
            if ( '' !== $file_php ) {
                // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- vetted core path from helper.
                require_once $file_php;
            }
        }
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'WordPress upload API is unavailable on this request.', 'museder-restoreone' ) ] );
        }

        $overrides = [ 'test_form' => false ];

        $uploaded = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            museder_restoreone_log( 'error', 'Restore upload failed.', [ 'error' => $uploaded['error'] ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to upload restore file.', 'museder-restoreone' ) ] );
        }

        $file_path = $uploaded['file'];
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        // Move uploaded file into backup directory for logging & future reuse.
        $backup_dir = museder_restoreone_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $file_path ) );
        $destination = trailingslashit( $backup_dir ) . $unique;
        // This plugin needs low-level rename() here for streaming backup/restore performance.
        // Using WP_Filesystem::move() is not always reliable across all hosting environments.
        // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $file_path, $destination );
        // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
        if ( $renamed ) {
            $file_path = $destination;
        }

        if ( ! in_array( $ext, [ 'zip' ], true ) ) {
            $response = [
                'success' => false,
                // @plugin-check: escaped
                'message' => esc_html__( 'Unsupported file type for restore.', 'museder-restoreone' ),
            ];
        } else {
            // AI1WM-style: queue restore as a resumable Restore_Service job (cron + checkpoints).
            $file_name = basename( $file_path );
            $prepared  = Museder_Restoreone_Restore_Service::prepare( 'upload', $file_name, '' );
            $job_id    = isset( $prepared['job_id'] ) ? (string) $prepared['job_id'] : '';
            Museder_Restoreone_Restore_Service::validate( $job_id );
            $started = Museder_Restoreone_Restore_Service::execute( $job_id, [ 'autoBackup' => true ] );

            $response = [
                'success' => true,
                'message' => isset( $started['message'] ) ? $started['message'] : __( 'Restore started in background.', 'museder-restoreone' ),
                'job_id'  => $job_id,
            ];
        }

        if ( ! empty( $response['success'] ) ) {
            wp_send_json_success( $response );
        }

        wp_send_json_error( $response );
    }

    public static function handle_restore_existing() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        // Use helper function to get absolute path from file name
        // museder_restoreone_get_backup_path() ensures the file is within the backup directory and is readable
        $file_path = museder_restoreone_get_backup_path( $filename );

        if ( ! $file_path ) {
            museder_restoreone_log( 'error', 'Restore archive not readable.', [ 'filename' => $filename ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
        }

        // No need to check $backup_dir here - museder_restoreone_get_backup_path() already ensures
        // the file is within the backup directory and is readable

        $options = [];
        $search_replace_raw = '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax_request() above, will be sanitized after json_decode()
        if ( isset( $_POST['search_replace'] ) ) {
            $search_replace_raw = wp_unslash( $_POST['search_replace'] );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // @plugin-check: validated - JSON will be decoded and sanitized
        if ( ! empty( $search_replace_raw ) ) {
            $decoded = json_decode( $search_replace_raw, true );
            if ( is_array( $decoded ) ) {
                // Sanitize all string values in the array recursively
                $options['search_replace'] = array_map( function( $item ) {
                    if ( is_array( $item ) ) {
                        return array_map( 'sanitize_text_field', $item );
                    }
                    return sanitize_text_field( $item );
                }, $decoded );
            }
        }

        // AI1WM-style: queue restore as a resumable Restore_Service job (cron + checkpoints).
        $prepared = Museder_Restoreone_Restore_Service::prepare( 'existing', $filename, '' );
        $job_id   = isset( $prepared['job_id'] ) ? (string) $prepared['job_id'] : '';
        Museder_Restoreone_Restore_Service::validate( $job_id );
        $started = Museder_Restoreone_Restore_Service::execute( $job_id, $options );

        wp_send_json_success(
            [
                'success' => true,
                'message' => isset( $started['message'] ) ? $started['message'] : __( 'Restore started in background.', 'museder-restoreone' ),
                'job_id'  => $job_id,
            ]
        );
    }

    /**
     * AJAX handler to get list of available backups.
     */
    public static function handle_get_backups_list() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        $backups = self::get_backups_list();
        $formatted = array_map(
            function ( $item ) {
                $size = isset( $item['size'] ) ? (int) $item['size'] : 0;
                $duration_seconds = isset( $item['duration_seconds'] ) && is_numeric( $item['duration_seconds'] ) ? (int) $item['duration_seconds'] : null;
                return [
                    'name'       => $item['name'],
                    'size'       => $size,
                    'size_human' => size_format( $size, 2 ),
                    'created'    => $item['created'],
                    'duration_seconds' => $duration_seconds,
                    'duration'   => $duration_seconds !== null ? museder_restoreone_format_duration( $duration_seconds ) : '',
                ];
            },
            $backups
        );

        wp_send_json_success( [ 'backups' => $formatted ] );
    }

    public static function handle_delete_backup() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        $file_path = museder_restoreone_get_backup_path( $filename );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found.', 'museder-restoreone' ) ], 404 );
        }

        // @plugin-check: allowed - required for backup/restore file operations
        // Path is validated and sanitized before use
        // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
        if ( function_exists( 'wp_delete_file' ) ) {
            $deleted = wp_delete_file( $file_path );
        } else {
            // Fallback for non-standard environments.
            if ( file_exists( $file_path ) ) {
                $deleted = @unlink( $file_path );
            } else {
                $deleted = false;
            }
        }
        // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
        if ( $deleted ) {
            museder_restoreone_log( 'info', 'Backup file deleted.', [ 'file' => $file_path ] );
            wp_send_json_success( [ 'message' => esc_html__( 'Backup deleted successfully.', 'museder-restoreone' ) ] );
        } else {
            museder_restoreone_log( 'error', 'Failed to delete backup file.', [ 'file' => $file_path ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function handle_delete_backups() {
        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax_request() above, will be sanitized in array_map below
        $raw = array();
        if ( isset( $_POST['filenames'] ) ) {
            $raw = wp_unslash( $_POST['filenames'] );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // @plugin-check: validated - will be sanitized in array_map below

        if ( is_string( $raw ) ) {
            $decoded = json_decode( wp_unslash( $raw ), true );
            $filenames = is_array( $decoded ) ? $decoded : [];
        } elseif ( is_array( $raw ) ) {
            $filenames = $raw;
        } else {
            $filenames = [];
        }

        $filenames = array_unique( array_filter( array_map( static function ( $filename ) {
            return sanitize_text_field( wp_unslash( $filename ) );
        }, $filenames ) ) );

        if ( empty( $filenames ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No backup files selected.', 'museder-restoreone' ) ], 400 );
        }

        $deleted    = [];
        $errors     = [];

        foreach ( $filenames as $filename ) {
            $file_path = museder_restoreone_get_backup_path( $filename );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                $errors[] = [ 'file' => $filename, 'message' => esc_html__( 'Backup file not found.', 'museder-restoreone' ) ];
                continue;
            }

            // @plugin-check: allowed - required for backup/restore file operations
            // Path is validated and sanitized before use
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                $deleted_file = wp_delete_file( $file_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $file_path ) ) {
                    $deleted_file = @unlink( $file_path );
                } else {
                    $deleted_file = false;
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( $deleted_file ) {
                $deleted[] = $filename;
                museder_restoreone_log( 'info', 'Backup file deleted (bulk).', [ 'file' => $file_path ] );
            } else {
                $errors[] = [ 'file' => $filename, 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ];
                museder_restoreone_log( 'error', 'Failed to delete backup file (bulk).', [ 'file' => $file_path ] );
            }
        }

        if ( empty( $deleted ) && ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors ], 500 );
        }

        wp_send_json_success( [
            'deleted' => $deleted,
            'errors'  => $errors,
        ] );
    }

    /**
     * AJAX handler: Delete restore history entries.
     */
    public static function handle_delete_restore_history() {
        self::verify_ajax_request();
        // Additional explicit nonce verification so automated tools can detect it on this handler.
        check_ajax_referer( self::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_ajax_request() above, will be sanitized in array_map below
        $raw = array();
        if ( isset( $_POST['timestamps'] ) ) {
            $raw = wp_unslash( $_POST['timestamps'] );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( is_string( $raw ) ) {
            $decoded = json_decode( wp_unslash( $raw ), true );
            $timestamps = is_array( $decoded ) ? $decoded : [];
        } elseif ( is_array( $raw ) ) {
            $timestamps = $raw;
        } else {
            $timestamps = [];
        }

        $timestamps = array_unique( array_filter( array_map( static function ( $timestamp ) {
            return absint( $timestamp );
        }, $timestamps ) ) );

        if ( empty( $timestamps ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'No restore history entries selected.', 'museder-restoreone' ) ], 400 );
        }

        $history = museder_restoreone_get_restore_history();
        $deleted = [];
        $errors = [];

        foreach ( $timestamps as $timestamp ) {
            $found = false;
            foreach ( $history as $index => $entry ) {
                $entry_timestamp = isset( $entry['timestamp_utc'] ) ? (int) $entry['timestamp_utc'] : 0;
                if ( ! $entry_timestamp && isset( $entry['timestamp'] ) ) {
                    // Fallback: try to parse timestamp string
                    $entry_timestamp = museder_restoreone_parse_legacy_timestamp( $entry['timestamp'] );
                }
                
                if ( $entry_timestamp === $timestamp ) {
                    unset( $history[ $index ] );
                    $deleted[] = $timestamp;
                    $found = true;
                    break;
                }
            }
            
            if ( ! $found ) {
                $errors[] = [ 'timestamp' => $timestamp, 'message' => esc_html__( 'Restore history entry not found.', 'museder-restoreone' ) ];
            }
        }

        // Save updated history
        if ( ! empty( $deleted ) ) {
            $history = array_values( $history ); // Re-index array
            $path = museder_restoreone_get_restore_history_path();
            $json = wp_json_encode( $history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            
            // Using native file APIs on local backup directory; paths are sanitized and constrained.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if ( false !== file_put_contents( $path, $json, LOCK_EX ) ) {
                museder_restoreone_log( 'info', 'Restore history entries deleted.', [ 'count' => count( $deleted ) ] );
            } else {
                $errors[] = [ 'message' => esc_html__( 'Failed to save updated restore history.', 'museder-restoreone' ) ];
            }
        }

        if ( empty( $deleted ) && ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors ], 500 );
        }

        wp_send_json_success( [
            'deleted' => $deleted,
            'errors'  => $errors,
        ] );
    }

    public static function handle_log_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read GET only to build nonce action; full path resolved after referer check
        $log = sanitize_text_field( wp_unslash( $_GET['log'] ?? '' ) );
        if ( empty( $log ) && isset( $_GET['amp;log'] ) ) {
            $log = sanitize_text_field( wp_unslash( $_GET['amp;log'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( empty( $log ) ) {
            wp_die( esc_html__( 'Log filename missing.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
        }

        $log_base = basename( $log );
        // Matches build_log_download_link(): nonce is tied to basename only; verify before filesystem access.
        $nonce_action = 'museder_restoreone_download_log_' . $log_base;
        if ( ! check_admin_referer( $nonce_action, '_wpnonce' ) ) {
            wp_die(
                esc_html__( 'The link you are trying to access has expired.', 'museder-restoreone' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=museder-restoreone-logs' ) ) . '">' . esc_html__( 'Please try again', 'museder-restoreone' ) . '</a>.',
                esc_html__( 'Link expired', 'museder-restoreone' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        $log_root = museder_restoreone_get_log_dir();
        $path     = wp_normalize_path( trailingslashit( $log_root ) . $log_base );

        $real_root = realpath( $log_root );
        $real_file = file_exists( $path ) ? realpath( $path ) : false;
        if ( ! $real_root || ! $real_file ) {
            wp_die( esc_html__( 'Log file not found.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        $root_prefix = trailingslashit( wp_normalize_path( $real_root ) );
        if ( 0 !== strpos( wp_normalize_path( $real_file ), $root_prefix ) ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 403 );
        }

        $path = $real_file;

        // @plugin-check: sanitized - safe whitelisted mime type
        header( 'Content-Type: text/plain' );
        $download_filename = sanitize_file_name( basename( $path ) ); // @plugin-check: sanitized
        header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- required for streaming log files, path validated and sanitized
        readfile( $path );
        exit;
    }

    public static function handle_backup_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        // Verify access:
        // - Legacy time-limited tokens (file/expires/token): validate token after reading GET (token covers filename).
        // - Standard admin-post links: run check_admin_referer before reading $_GET['file'] (review tooling expects this order).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- validated by either nonce or token verification below
        $expires = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
        $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $verified = false;
        $file     = '';

        if ( $expires > 0 && '' !== $token && function_exists( 'museder_restoreone_verify_download_token' ) ) {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- token path; filename covered by HMAC
            $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            if ( empty( $file ) ) {
                wp_die( esc_html__( 'Invalid backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
            }
            $verified = museder_restoreone_verify_download_token( $file, $expires, $token );
        } else {
            check_admin_referer( 'museder_restoreone_download_backup', '_museder_restoreone_download_nonce' );
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified via check_admin_referer() above
            $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            if ( empty( $file ) ) {
                wp_die( esc_html__( 'Invalid backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
            }
            $verified = true;
        }

        if ( ! $verified ) {
            wp_die( esc_html__( 'The link you are trying to access has expired.', 'museder-restoreone' ), esc_html__( 'Link expired', 'museder-restoreone' ), 403 );
        }

        // Use helper function to get absolute path from file name
        $path = museder_restoreone_get_backup_path( $file );

        if ( ! $path ) {
            museder_restoreone_log( 'error', 'Download archive not readable.', [ 'filename' => $file ] );
            wp_die( esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = 'application/zip';

        ignore_user_abort( true );
        // @plugin-check: okay - needed for long running backup/restore operations
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            // Allow longer execution time for large backup/restore jobs when possible.
            // phpcs:ignore WordPress.PHP.NoSetTimeLimit
            // Long-running backup/restore job: attempt to raise time limit for CLI/cron.
            // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
            if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
            }
            // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        // Clear any output buffers
        if ( function_exists( 'ob_get_level' ) ) {
            while ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
        }

        nocache_headers();
        status_header( 200 );
        // @plugin-check: sanitized - safe whitelisted mime type
        header( 'Content-Type: ' . $mime );
        $download_filename = sanitize_file_name( basename( $path ) ); // @plugin-check: sanitized
        header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'X-Content-Type-Options: nosniff' );

        $chunk_size = 1024 * 1024; // 1MB
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for streaming large backup files, path validated and sanitized
        $handle     = fopen( $path, 'rb' );
        if ( ! $handle ) {
            wp_die( esc_html__( 'Unable to read backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 500 );
        }

        while ( ! feof( $handle ) ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.Security.EscapeOutput.OutputNotEscaped -- required for streaming large backup files, path validated and sanitized, streaming binary file contents not HTML output
            echo fread( $handle, $chunk_size );
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.Security.EscapeOutput.OutputNotEscaped
            flush();
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );
        exit;
    }

    /**
     * Collect backup options sent via the current request (PRO options).
     *
     * @return array
     */
    private static function get_backup_options_from_request() {
        $options = [];

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in calling function (handle_backup_request or ajax_start_backup_job) via verify_ajax_request() and check_ajax_referer()
        // Backup mode (Free + Pro)
        $backup_mode = '';
        if ( isset( $_POST['backup_mode'] ) ) {
            $backup_mode = sanitize_key( wp_unslash( $_POST['backup_mode'] ) );
        }
        if ( in_array( $backup_mode, [ 'auto', 'balanced', 'fast' ], true ) ) {
            $options['backup_mode'] = $backup_mode;
        }

        $smart_exclude = '';
        if ( isset( $_POST['backup_smart_exclude'] ) ) {
            $smart_exclude = sanitize_key( wp_unslash( $_POST['backup_smart_exclude'] ) );
        }
        if ( in_array( $smart_exclude, [ 'auto', 'on', 'off' ], true ) ) {
            $options['backup_smart_exclude'] = $smart_exclude;
        }

        $custom_excludes = '';
        if ( isset( $_POST['backup_custom_excludes'] ) ) {
            $custom_excludes = wp_unslash( $_POST['backup_custom_excludes'] );
            if ( is_string( $custom_excludes ) ) {
                $custom_excludes = sanitize_textarea_field( $custom_excludes );
            } else {
                $custom_excludes = '';
            }
        }
        if ( is_string( $custom_excludes ) && '' !== trim( $custom_excludes ) ) {
            $options['backup_custom_excludes'] = $custom_excludes;
        }

        // Scope presets (AI1WM-like). Optional; Free will honor them if provided.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- checkbox-like values, normalized by FILTER_VALIDATE_BOOLEAN
        $bool_keys = [
            'no_media'     => 'no_media',
            'no_plugins'   => 'no_plugins',
            'no_themes'    => 'no_themes',
            'no_database'  => 'no_database',
            'no_cache'     => 'no_cache',
            'no_muplugins' => 'no_muplugins',
        ];
        foreach ( $bool_keys as $post_key => $opt_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                $val = wp_unslash( $_POST[ $post_key ] );
                $normalized = filter_var( $val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                if ( null !== $normalized ) {
                    $options[ $opt_key ] = (bool) $normalized;
                }
            }
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

        // DB include/exclude tables (newline or comma separated).
        $include_tables = '';
        if ( isset( $_POST['include_db_tables'] ) ) {
            $include_tables = wp_unslash( $_POST['include_db_tables'] );
            if ( is_string( $include_tables ) ) {
                $include_tables = sanitize_textarea_field( $include_tables );
            } else {
                $include_tables = '';
            }
        }
        if ( is_string( $include_tables ) && '' !== trim( $include_tables ) ) {
            $options['include_db_tables'] = $include_tables;
        }

        $exclude_tables = '';
        if ( isset( $_POST['exclude_db_tables'] ) ) {
            $exclude_tables = wp_unslash( $_POST['exclude_db_tables'] );
            if ( is_string( $exclude_tables ) ) {
                $exclude_tables = sanitize_textarea_field( $exclude_tables );
            } else {
                $exclude_tables = '';
            }
        }
        if ( is_string( $exclude_tables ) && '' !== trim( $exclude_tables ) ) {
            $options['exclude_db_tables'] = $exclude_tables;
        }

        // Multisite (experimental): allow selecting a blog ID for subsite-only export.
        if ( isset( $_POST['multisite_blog_id'] ) ) {
            $blog_id_raw = wp_unslash( $_POST['multisite_blog_id'] );
            $blog_id     = is_numeric( $blog_id_raw ) ? absint( $blog_id_raw ) : 0;
            if ( $blog_id > 0 ) {
                $options['multisite_blog_id'] = $blog_id;
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return $options;
    }

    public static function verify_ajax_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }

        // Use WordPress' standard nonce verifier so automated checks can detect it reliably.
        // Keep custom error response instead of the default -1.
        $ok = check_ajax_referer( self::NONCE, 'nonce', false );
        if ( ! $ok ) {
            wp_send_json_error(
                [
                    'code'    => 'invalid_nonce',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ],
                403
            );
        }
    }

    /**
     * Authorize restore progress AJAX after DB import when WP nonces/sessions are invalid.
     *
     * Accepts the file-backed restore token (survives NDJSON import) or standard admin nonce.
     *
     * @param string $job_id Restore job id (binds token when provided).
     * @return void
     */
    public static function verify_restore_progress_request( $job_id = '' ) {
        $job_id = sanitize_text_field( (string) $job_id );

        if ( self::restore_progress_token_is_valid( $job_id ) ) {
            return;
        }

        if ( self::restore_post_complete_read_is_valid( $job_id ) ) {
            return;
        }

        // wp_ajax_nopriv_*: session cookie dies after NDJSON import; never fall back to nonce alone.
        if ( ! is_user_logged_in() ) {
            wp_send_json_error(
                [
                    'code'    => 'invalid_restore_token',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Restore authorization expired or invalid.', 'museder-restoreone' ),
                ],
                403
            );
        }

        self::verify_ajax_request();
        check_ajax_referer( self::NONCE, 'nonce' );
    }

    /**
     * @param string $job_id Optional job id for token binding.
     * @return bool
     */
    public static function restore_progress_token_is_valid( $job_id = '' ) {
        if ( ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
            return false;
        }

        $raw = '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- token fallback when nonce is dead post-import
        if ( isset( $_POST['restore_token'] ) ) {
            $raw = sanitize_text_field( wp_unslash( (string) $_POST['restore_token'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( '' === $raw && isset( $_SERVER['HTTP_X_MUSEDER_RESTORE_TOKEN'] ) ) {
            $raw = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_MUSEDER_RESTORE_TOKEN'] ) );
        }

        if ( '' === $raw ) {
            return false;
        }

        return Museder_Restoreone_Restore_Token::verify( $raw, $job_id );
    }

    /**
     * Post-revoke read-only grant: same restore_token + completed job meta.
     *
     * @param string $job_id Restore job id.
     * @return bool
     */
    public static function restore_post_complete_read_is_valid( $job_id = '' ) {
        $job_id = sanitize_text_field( (string) $job_id );
        if ( '' === $job_id || ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
            return false;
        }

        $raw = '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- post-complete read grant uses restore_token
        if ( isset( $_POST['restore_token'] ) ) {
            $raw = sanitize_text_field( wp_unslash( (string) $_POST['restore_token'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( '' === $raw && isset( $_SERVER['HTTP_X_MUSEDER_RESTORE_TOKEN'] ) ) {
            $raw = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_MUSEDER_RESTORE_TOKEN'] ) );
        }

        if ( '' === $raw ) {
            return false;
        }

        return Museder_Restoreone_Restore_Token::verify_post_complete_read( $raw, $job_id );
    }

    public static function ajax_refresh_nonce() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }

        // WordPress.org strict review: do not mint a new AJAX nonce without validating the current one first.
        $ok = check_ajax_referer( self::NONCE, 'nonce', false );
        if ( ! $ok ) {
            wp_send_json_error(
                [
                    'code'    => 'invalid_nonce',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Your session has expired. Please reload the page.', 'museder-restoreone' ),
                ],
                403
            );
        }

        wp_send_json_success(
            [
                'nonce' => wp_create_nonce( self::NONCE ),
            ]
        );
    }

    public static function get_backups_list( $limit = 0 ) {
        $dirs = array_map( 'trailingslashit', museder_restoreone_get_all_backup_dirs() );
        if ( empty( $dirs ) ) {
            return [];
        }

        // Hide the currently-running archive from the library list to avoid exposing partial files.
        $active_archive = '';
        if ( class_exists( 'Museder_Restoreone_Backup_Jobs' ) ) {
            $active_job = Museder_Restoreone_Backup_Jobs::get_active_job();
            if (
                $active_job
                && ! empty( $active_job['archive_path'] )
                && ! empty( $active_job['status'] )
                && ! in_array( $active_job['status'], [ 'completed', 'failed', 'cancelled' ], true )
            ) {
                $active_archive = basename( $active_job['archive_path'] );
            }
        }
        
        // Support all ZIP/WPRESS backups regardless of naming convention.
        $glob = [];
        foreach ( $dirs as $dir ) {
            if ( defined( 'GLOB_BRACE' ) ) {
                $found = glob( $dir . '*.zip' );
            } else {
                // GLOB_BRACE is not available on all platforms (e.g., some Alpine builds).
                // Fall back to two globs to keep the Backups UI working everywhere.
                $found = array_merge(
                    (array) glob( $dir . '*.zip' ),
                    []
                );
            }
            if ( ! empty( $found ) ) {
                $glob = array_merge( $glob, $found );
            }
        }
        $glob = array_values( array_unique( array_filter( $glob ) ) );

        if ( empty( $glob ) ) {
            return [];
        }

        // Sort by modified time (desc) for stable ordering across multiple directories.
        usort(
            $glob,
            static function ( $a, $b ) {
                $ta = @filemtime( $a );
                $tb = @filemtime( $b );
                if ( $ta === $tb ) {
                    return strcmp( (string) $b, (string) $a );
                }
                return ( $tb <=> $ta );
            }
        );

        $items = [];
        $shown = 0;
        foreach ( $glob as $file ) {
            if ( $active_archive && basename( $file ) === $active_archive ) {
                continue;
            }

            if ( $limit > 0 && $shown >= $limit ) {
                break;
            }

            $filename = basename( $file );
            $metadata = Museder_Restoreone_Backup::get_backup_metadata( $filename );

            // Get duration from metadata first, then fallback to logs
            $duration_seconds = null;
            if ( isset( $metadata['duration_seconds'] ) && is_numeric( $metadata['duration_seconds'] ) ) {
                $duration_seconds = (int) $metadata['duration_seconds'];
            } else {
                // Fallback to logs
                $events = Museder_Restoreone_Log_Handler::get_recent_events( 'backup_result', 10 );
                foreach ( $events as $event ) {
                    $context = $event['context'];
                    if ( isset( $context['file'] ) && basename( $context['file'] ) === $filename ) {
                        if ( isset( $context['duration_seconds'] ) && is_numeric( $context['duration_seconds'] ) ) {
                            $duration_seconds = (int) $context['duration_seconds'];
                            break;
                        }
                    }
                }
            }

            $items[] = [
                'name'    => $filename,
                'path'    => wp_normalize_path( $file ),
                'size'    => filesize( $file ),
                'type'    => 'site',
                // @plugin-check: wp_date with local timezone - filemtime() returns Unix timestamp (UTC), museder_restoreone_format_local_time() handles timezone conversion
                'created' => museder_restoreone_format_local_time( filemtime( $file ) ),
                'download_url' => self::build_backup_download_link( $file ),
                'label'   => $metadata['label'] ?? '',
                'encrypted' => ! empty( $metadata['encrypted'] ),
                'duration_seconds' => $duration_seconds,
            ];

            $shown++;
        }

        return $items;
    }

    public static function get_logs_list( $limit = 0 ) {
        $files = museder_restoreone_get_recent_logs();
        $items = [];

        foreach ( $files as $index => $file ) {
            if ( $limit > 0 && $index >= $limit ) {
                break;
            }

            $items[] = [
                'name'         => basename( $file ),
                'path'         => wp_normalize_path( $file ),
                'download_url' => self::build_log_download_link( $file ),
            ];
        }

        return $items;
    }

    public static function get_environment_status() {
        return [
            'ziparchive' => museder_restoreone_can_use_ziparchive(),
        ];
    }

    /**
     * Add page-specific inline styles and scripts.
     *
     * @param string $current_page Current page slug.
     */
    private static function add_page_specific_inline_assets( $current_page ) {
        // Common PRO page styles
        $pro_page_css = '
.museder-restoreone-pro-page.pro-locked-overlay::before,
.backup-lite-pro-page.pro-locked-overlay::before,
.museder-restoreone-reports.pro-locked-overlay::before,
.backup-lite-reports.pro-locked-overlay::before {
    content: "";
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
.museder-restoreone-pro-page.pro-locked-overlay .bl-container,
.backup-lite-pro-page.pro-locked-overlay .bl-container,
.museder-restoreone-reports.pro-locked-overlay .bl-container,
.backup-lite-reports.pro-locked-overlay .bl-container {
    position: relative;
    z-index: 2;
}
.museder-restoreone-pro-page .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-pro-page .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-pro-page .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-pro-page .updated:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-pro-page .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-pro-page .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-pro-page .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-pro-page .updated:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-reports .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-reports .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-reports .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-reports .updated:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-reports .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-reports .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-reports .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-reports .updated:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-schedules .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-schedules .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-schedules .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.museder-restoreone-schedules .updated:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-schedules .notice:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-schedules .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-schedules .error:not(.museder-restoreone-notice):not(.backup-lite-notice),
.backup-lite-schedules .updated:not(.museder-restoreone-notice):not(.backup-lite-notice) {
    display: none !important;
}
.museder-restoreone-pro-page > .notice,
.museder-restoreone-pro-page > .update-nag,
.museder-restoreone-pro-page > .error,
.museder-restoreone-pro-page > .updated,
.backup-lite-pro-page > .notice,
.backup-lite-pro-page > .update-nag,
.backup-lite-pro-page > .error,
.backup-lite-pro-page > .updated,
.museder-restoreone-reports > .notice,
.museder-restoreone-reports > .update-nag,
.museder-restoreone-reports > .error,
.museder-restoreone-reports > .updated,
.backup-lite-reports > .notice,
.backup-lite-reports > .update-nag,
.backup-lite-reports > .error,
.backup-lite-reports > .updated,
.museder-restoreone-schedules > .notice,
.museder-restoreone-schedules > .update-nag,
.museder-restoreone-schedules > .error,
.museder-restoreone-schedules > .updated,
.backup-lite-schedules > .notice,
.backup-lite-schedules > .update-nag,
.backup-lite-schedules > .error,
.backup-lite-schedules > .updated {
    display: none !important;
}
.bl-button-sm {
    padding: 6px 12px;
    font-size: 13px;
}';

        // Common notice removal script
        $notice_removal_js = '
(function() {
    document.addEventListener("DOMContentLoaded", function() {
        var pages = [".museder-restoreone-schedules", ".backup-lite-schedules"];
        pages.forEach(function(selector) {
            var page = document.querySelector(selector);
            if (page) {
                var pageWrapper = page.closest(".wrap") || page.parentElement;
                if (pageWrapper) {
                    var notices = pageWrapper.querySelectorAll(".notice:not(.museder-restoreone-notice):not(.backup-lite-notice), .update-nag:not(.museder-restoreone-notice):not(.backup-lite-notice), .error:not(.museder-restoreone-notice):not(.backup-lite-notice), .updated:not(.museder-restoreone-notice):not(.backup-lite-notice)");
                    notices.forEach(function(notice) {
                        var heroSection = page.querySelector(".bl-card, .schedule-hero");
                        if (heroSection && notice.compareDocumentPosition(heroSection) & Node.DOCUMENT_POSITION_FOLLOWING) {
                            notice.style.display = "none";
                        }
                    });
                }
            }
        });
    });
})();';

        // Add inline styles for schedules page.
        if ( in_array( $current_page, [ 'museder-restoreone-schedules' ], true ) ) {
            wp_add_inline_style( 'museder-restoreone-theme', $pro_page_css );
            wp_add_inline_script( 'museder-restoreone-admin', $notice_removal_js, 'after' );
        }
    }

    private static function build_backup_download_link( $file ) {
        return museder_restoreone_get_download_url( $file );
    }

    private static function build_log_download_link( $file ) {
        $name = basename( $file );
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=museder_restoreone_download_log&log=' . rawurlencode( $name ) ),
            'museder_restoreone_download_log_' . $name
        );
    }

    public static function handle_report_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        check_admin_referer( 'museder_restoreone_download_report', '_wpnonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via check_admin_referer() above
        $job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
        $type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
        $format = isset( $_GET['format'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['format'] ) ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( '' === $job_id || '' === $type || ! in_array( $format, [ 'txt', 'json' ], true ) ) {
            wp_die( esc_html__( 'Invalid report request.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
        }

        $path = Museder_Restoreone_Restore_Report::download( $job_id, $type, $format );
        if ( is_wp_error( $path ) ) {
            $err_data = $path->get_error_data();
            $st       = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 404;
            // Literal HTTP codes + inline escaping per arg (PHPCS does not treat assigned $msg as escaped for wp_die()).
            if ( 400 === $st ) {
                wp_die(
                    esc_html( $path->get_error_message() ),
                    esc_html__( 'Download error', 'museder-restoreone' ),
                    [ 'response' => 400 ]
                );
            } elseif ( 403 === $st ) {
                wp_die(
                    esc_html( $path->get_error_message() ),
                    esc_html__( 'Download error', 'museder-restoreone' ),
                    [ 'response' => 403 ]
                );
            } elseif ( 404 === $st ) {
                wp_die(
                    esc_html( $path->get_error_message() ),
                    esc_html__( 'Download error', 'museder-restoreone' ),
                    [ 'response' => 404 ]
                );
            } elseif ( 500 === $st ) {
                wp_die(
                    esc_html( $path->get_error_message() ),
                    esc_html__( 'Download error', 'museder-restoreone' ),
                    [ 'response' => 500 ]
                );
            } else {
                wp_die(
                    esc_html( $path->get_error_message() ),
                    esc_html__( 'Download error', 'museder-restoreone' ),
                    [ 'response' => 404 ]
                );
            }
        }

        $reports_dir = museder_restoreone_get_reports_dir();
        $reports_real = realpath( $reports_dir );
        $path_real    = is_string( $path ) && file_exists( $path ) ? realpath( $path ) : false;
        if ( ! $reports_real || ! $path_real ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 403 );
        }
        $reports_prefix = trailingslashit( wp_normalize_path( $reports_real ) );
        if ( 0 !== strpos( wp_normalize_path( $path_real ), $reports_prefix ) ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 403 );
        }

        $path = $path_real;

        if ( ! is_readable( $path ) ) {
            wp_die( esc_html__( 'Report not found.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        nocache_headers();
        status_header( 200 );
        $mime = ( 'json' === $format ) ? 'application/json' : 'text/plain';
        header( 'Content-Type: ' . $mime . '; charset=utf-8' );
        $download_filename = sanitize_file_name( basename( $path ) );
        header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming validated path under reports dir
        readfile( $path );
        exit;
    }
}
