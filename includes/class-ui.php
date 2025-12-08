<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Backup_Lite_UI {

    /**
     * Partial slug used to detect our admin pages when enqueuing assets.
     * All plugin pages registered via add_submenu_page() share the "backup-lite"
     * substring in their hook suffix (e.g. toplevel_page_backup-lite-dashboard).
     */
    const PAGE_SLUG = 'backup-lite';
    const NONCE     = 'backup_lite_action';
    const NONCE_V2  = 'backup_lite_v2';

    public static function init() {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        add_action( 'wp_ajax_backup_lite_run_backup', [ __CLASS__, 'handle_backup_request' ] );
        add_action( 'wp_ajax_backup_lite_run_restore', [ __CLASS__, 'handle_restore_request' ] );
        add_action( 'wp_ajax_backup_lite_restore_existing', [ __CLASS__, 'handle_restore_existing' ] );
        add_action( 'wp_ajax_backup_lite_get_backups_list', [ __CLASS__, 'handle_get_backups_list' ] );
        add_action( 'wp_ajax_backup_lite_delete_backup', [ __CLASS__, 'handle_delete_backup' ] );
        add_action( 'wp_ajax_backup_lite_delete_backups', [ __CLASS__, 'handle_delete_backups' ] );
        add_action( 'wp_ajax_backup_lite_start_backup_job', [ __CLASS__, 'ajax_start_backup_job' ] );
        add_action( 'wp_ajax_backup_lite_get_job_status', [ __CLASS__, 'ajax_get_backup_job_status' ] );
        add_action( 'wp_ajax_backup_lite_continue_backup_job', [ __CLASS__, 'ajax_continue_backup_job' ] );
        add_action( 'wp_ajax_backup_lite_cancel_backup_job', [ __CLASS__, 'ajax_cancel_backup_job' ] );
        add_action( 'wp_ajax_backup_lite_refresh_nonce', [ __CLASS__, 'ajax_refresh_nonce' ] );

        add_action( 'admin_post_backup_lite_download_log', [ __CLASS__, 'handle_log_download' ] );
        add_action( 'admin_post_backup_lite_download_backup', [ __CLASS__, 'handle_backup_download' ] );
        add_action( 'admin_post_backup_lite_download_report', [ __CLASS__, 'handle_report_download' ] );
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'backup-lite-admin',
            BACKUP_LITE_URL . 'assets/css/admin.css',
            [],
            BACKUP_LITE_VERSION
        );

        wp_enqueue_style(
            'backup-lite-ui',
            BACKUP_LITE_URL . 'assets/css/admin-style.css',
            [ 'backup-lite-admin' ],
            BACKUP_LITE_VERSION
        );

        wp_enqueue_style(
            'backup-lite-theme',
            BACKUP_LITE_URL . 'assets/css/backup-lite-theme.css',
            [ 'backup-lite-ui' ],
            BACKUP_LITE_VERSION
        );

        wp_enqueue_style(
            'toastify-css',
            BACKUP_LITE_URL . 'assets/vendor/toastify.min.css',
            [],
            '1.12.0'
        );

        wp_enqueue_script(
            'toastify',
            BACKUP_LITE_URL . 'assets/vendor/toastify.min.js',
            [],
            '1.12.0',
            true
        );

        wp_enqueue_script(
            'backup-lite-admin',
            BACKUP_LITE_URL . 'assets/js/admin.js',
            [ 'jquery', 'toastify' ],
            BACKUP_LITE_VERSION,
            true
        );

        wp_enqueue_script(
            'backup-lite-admin-ui',
            BACKUP_LITE_URL . 'assets/js/admin-ui.js',
            [],
            BACKUP_LITE_VERSION,
            false
        );

        // Localize PRO status for frontend
        $is_pro = class_exists( 'Backup_Lite_Pro' ) && Backup_Lite_Pro::is_pro_active();
        $settings = Backup_Lite_Settings::get_settings();

        wp_localize_script(
            'backup-lite-admin-ui',
            'BackupLitePro',
            [
                'isPro'      => $is_pro,
                'upgradeUrl' => class_exists( 'Backup_Lite_Pro' ) ? Backup_Lite_Pro::get_upgrade_url() : 'https://your-site.com/pro',
                'strings'    => [
                    'modalTitle'    => __( 'Museder RestoreOne PRO Required', 'museder-restoreone' ),
                    'modalSubtitle' => __( 'This feature requires Museder RestoreOne PRO to activate.', 'museder-restoreone' ),
                    'close'         => __( 'Close', 'museder-restoreone' ),
                    'upgrade'       => __( 'Upgrade to PRO', 'museder-restoreone' ),
                    /* translators: %s: Name of the locked feature. */
                    'featureLocked' => __( 'Feature "%s" is available in Museder RestoreOne PRO.', 'museder-restoreone' ),
                ],
            ]
        );

        // Inject basic admin preferences and feature toggles
        $settings = Backup_Lite_Settings::get_settings();
        wp_localize_script(
            'backup-lite-admin-ui',
            'BackupLiteAdmin',
            [
                'theme'    => isset( $settings['ui_theme'] ) ? $settings['ui_theme'] : 'auto',
                'features' => [
                    'restoreV2'   => ! empty( $settings['feature_restore_center_v2'] ),
                    'animations'  => ! empty( $settings['feature_ui_animation'] ),
                    'extendedLog' => ! empty( $settings['feature_extended_log'] ),
                ],
            ]
        );

        wp_localize_script(
            'backup-lite-admin-ui',
            'BackupLiteAdmin',
            [
                'theme' => isset( $settings['ui_theme'] ) ? $settings['ui_theme'] : 'auto',
            ]
        );

        wp_enqueue_script(
            'backup-lite-chunk-upload',
            BACKUP_LITE_URL . 'assets/js/chunk-upload.js',
            [ 'backup-lite-admin' ],
            BACKUP_LITE_VERSION,
            true
        );

        wp_enqueue_script(
            'backup-lite-chunk-upload-v2',
            BACKUP_LITE_URL . 'assets/js/chunk-upload-v2.js',
            [ 'backup-lite-admin' ],
            BACKUP_LITE_VERSION,
            true
        );

        $rest_url_v2 = rest_url( 'backup-lite/v2/' );
        $nonce_v2    = wp_create_nonce( self::NONCE_V2 );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page parameter is for UI display only, not for security-sensitive operations
        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        // Add inline styles and scripts for specific pages
        self::add_page_specific_inline_assets( $current_page );

        $active_job = Backup_Lite_Backup_Jobs::get_active_job_summary();

        wp_localize_script( 'backup-lite-admin', 'BackupLite', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE ),
            'nonceV2'        => $nonce_v2,
            'restUrlV2'      => $rest_url_v2,
            'page'           => $current_page,
            'activeJob'      => $active_job,
            'jobPollingInterval' => 3,
            'confirmRestore' => __( 'Restoring will overwrite your current site files and database. Continue?', 'museder-restoreone' ),
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
                'licenseError'           => __( 'An error occurred while verifying the license.', 'museder-restoreone' ),
                'scheduleActionStart'    => __( 'Start Now', 'museder-restoreone' ),
                'scheduleActionEdit'     => __( 'Edit', 'museder-restoreone' ),
                'scheduleActionDelete'   => __( 'Delete', 'museder-restoreone' ),
                'scheduleActionsAria'    => __( 'Schedule actions', 'museder-restoreone' ),
                'confirmDeleteSelected'  => __( 'Are you sure you want to delete the selected backups? This action cannot be undone.', 'museder-restoreone' ),
                'confirmDeleteSchedule'  => __( 'Delete this schedule?', 'museder-restoreone' ),
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
                'restoreCancelConfirm' => __( 'Cancel the current restore process?', 'museder-restoreone' ),
                'restoreCancelSuccess' => __( 'Restore process cancelled.', 'museder-restoreone' ),
                'restoreCancelFailed'  => __( 'Unable to cancel restore process. Please try again.', 'museder-restoreone' ),
                'chunkPreparing'        => __( 'Preparing upload…', 'museder-restoreone' ),
                /* translators: 1: Current chunk number, 2: Total chunks, 3: Progress percentage. */
                'chunkUploading'        => __( 'Uploading %1$s of %2$s (%3$s%)…', 'museder-restoreone' ),
                'chunkMerging'          => __( 'Merging uploaded chunks…', 'museder-restoreone' ),
            ],
            'chunk'         => [
                'prepareAction'  => 'backup_lite_prepare_upload',
                'chunkSize'     => 2 * 1024 * 1024,
                'maxFileSize'   => 4294967296,
                'uploadAction'  => 'backup_lite_upload_chunk',
                'finalizeAction'=> 'backup_lite_finalize_upload',
                'abortAction'   => 'backup_lite_abort_upload',
                'allowedExt'    => [ 'zip', 'wpress' ],
            ],
        ] );

        $upload_handler_url = plugins_url( 'upload-handler.php', BACKUP_LITE_PATH . 'upload-handler.php' );

        wp_localize_script(
            'backup-lite-chunk-upload-v2',
            'BackupLiteV2',
            [
                'restUrl'        => esc_url_raw( $rest_url_v2 ),
                'nonce'          => wp_create_nonce( 'wp_rest' ),
                'uploadHandler'  => esc_url_raw( $upload_handler_url ),
                'uploadSecret'   => Backup_Lite_Upload_Secret::get_secret(),
            ]
        );
    }

    public static function handle_backup_request() {
        self::verify_ajax_request();

        $options = self::get_backup_options_from_request();

        $response = Backup_Lite_Backup::backup_site( $options );

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

        try {
            $options = self::get_backup_options_from_request();
            $job     = Backup_Lite_Backup_Jobs::create_job( $options );

            wp_send_json_success( [
                'job'     => Backup_Lite_Backup_Jobs::format_job_payload( $job ),
                // @plugin-check: escaped
                'message' => esc_html__( 'Backup job created. Processing has started in the background.', 'museder-restoreone' ),
            ] );
        } catch ( Exception $exception ) {
            backup_lite_log( 'error', 'Failed to start backup job.', [
                'error' => $exception->getMessage(),
            ] );

            // @plugin-check: escaped - exception message is user-facing error
            wp_send_json_error( [ 'message' => esc_html( $exception->getMessage() ) ], 500 );
        }
    }

    public static function ajax_get_backup_job_status() {
        self::verify_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        $payload = Backup_Lite_Backup_Jobs::get_job_payload( $job_id );
        if ( ! $payload ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found or already completed.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [ 'job' => $payload ] );
    }

    public static function ajax_continue_backup_job() {
        self::verify_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        $job = Backup_Lite_Backup_Jobs::process_job_immediately( $job_id, Backup_Lite_Backup_Jobs::AJAX_BATCH_FILES, Backup_Lite_Backup_Jobs::AJAX_BATCH_BYTES );
        if ( ! $job ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [
            'job'     => Backup_Lite_Backup_Jobs::format_job_payload( $job ),
            // @plugin-check: escaped
            'message' => esc_html__( 'Backup job updated.', 'museder-restoreone' ),
        ] );
    }

    public static function ajax_cancel_backup_job() {
        self::verify_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ], 400 );
        }

        if ( ! Backup_Lite_Backup_Jobs::cancel_job( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to cancel backup job.', 'museder-restoreone' ) ], 404 );
        }

        // @plugin-check: escaped
        wp_send_json_success( [ 'message' => esc_html__( 'Backup job cancelled.', 'museder-restoreone' ) ] );
    }

    public static function handle_restore_request() {
        self::verify_ajax_request();

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

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = [ 'test_form' => false ];

        $uploaded = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            backup_lite_log( 'error', 'Restore upload failed.', [ 'error' => $uploaded['error'] ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to upload restore file.', 'museder-restoreone' ) ] );
        }

        $file_path = $uploaded['file'];
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        // Move uploaded file into backup directory for logging & future reuse.
        $backup_dir = backup_lite_get_backup_dir();
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

        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            $response = [
                'success' => false,
                // @plugin-check: escaped
                'message' => esc_html__( 'Unsupported file type for restore.', 'museder-restoreone' ),
            ];
        } else {
            $response = Backup_Lite_Restore::restore_site( $file_path );
        }

        if ( ! empty( $response['success'] ) ) {
            wp_send_json_success( $response );
        }

        wp_send_json_error( $response );
    }

    public static function handle_restore_existing() {
        self::verify_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        // Use helper function to get absolute path from file name
        // backup_lite_get_backup_path() ensures the file is within the backup directory and is readable
        $file_path = backup_lite_get_backup_path( $filename );

        if ( ! $file_path ) {
            backup_lite_log( 'error', 'Restore archive not readable.', [ 'filename' => $filename ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
        }

        // No need to check $backup_dir here - backup_lite_get_backup_path() already ensures
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

        $response = Backup_Lite_Restore::restore_site( $file_path, $options );

        if ( ! empty( $response['success'] ) ) {
            wp_send_json_success( $response );
        }

        wp_send_json_error( $response );
    }

    /**
     * AJAX handler to get list of available backups.
     */
    public static function handle_get_backups_list() {
        self::verify_ajax_request();

        $backups = self::get_backups_list();
        $formatted = array_map(
            function ( $item ) {
                $size = isset( $item['size'] ) ? (int) $item['size'] : 0;
                return [
                    'name'       => $item['name'],
                    'size'       => $size,
                    'size_human' => size_format( $size, 2 ),
                    'created'    => $item['created'],
                ];
            },
            $backups
        );

        wp_send_json_success( [ 'backups' => $formatted ] );
    }

    public static function handle_delete_backup() {
        self::verify_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() above
        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $file_path  = trailingslashit( $backup_dir ) . basename( $filename );
        $file_path  = wp_normalize_path( $file_path );

        if ( ! file_exists( $file_path ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found.', 'museder-restoreone' ) ], 404 );
        }

        if ( strpos( wp_normalize_path( $file_path ), wp_normalize_path( $backup_dir ) ) !== 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid backup file path.', 'museder-restoreone' ) ], 403 );
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
            backup_lite_log( 'info', 'Backup file deleted.', [ 'file' => $file_path ] );
            wp_send_json_success( [ 'message' => esc_html__( 'Backup deleted successfully.', 'museder-restoreone' ) ] );
        } else {
            backup_lite_log( 'error', 'Failed to delete backup file.', [ 'file' => $file_path ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function handle_delete_backups() {
        self::verify_ajax_request();

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

        $backup_dir = backup_lite_get_backup_dir();
        $deleted    = [];
        $errors     = [];

        foreach ( $filenames as $filename ) {
            $file_path = trailingslashit( $backup_dir ) . basename( $filename );
            $file_path = wp_normalize_path( $file_path );

            if ( strpos( $file_path, wp_normalize_path( $backup_dir ) ) !== 0 ) {
                $errors[] = [ 'file' => $filename, 'message' => __( 'Invalid backup file path.', 'museder-restoreone' ) ];
                continue;
            }

            if ( ! file_exists( $file_path ) ) {
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
                backup_lite_log( 'info', 'Backup file deleted (bulk).', [ 'file' => $file_path ] );
            } else {
                $errors[] = [ 'file' => $filename, 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ];
                backup_lite_log( 'error', 'Failed to delete backup file (bulk).', [ 'file' => $file_path ] );
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

        // Sanitize log parameter before nonce check
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified via check_admin_referer() below
        $log  = sanitize_text_field( wp_unslash( $_GET['log'] ?? '' ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        
        $path = backup_lite_get_log_dir() . '/' . basename( $log );
        $path = wp_normalize_path( $path );

        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Log file not found.', 'museder-restoreone' ) );
        }

        check_admin_referer( 'backup_lite_download_log_' . basename( $path ) );

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

        // Sanitize file parameter before nonce check
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified via check_admin_referer() below
        $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        
        if ( empty( $file ) ) {
            wp_die( esc_html__( 'Invalid backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
        }

        // Verify nonce
        check_admin_referer( 'backup_lite_download_backup', '_backup_lite_download_nonce' );

        // Use helper function to get absolute path from file name
        $path = backup_lite_get_backup_path( $file );

        if ( ! $path ) {
            backup_lite_log( 'error', 'Download archive not readable.', [ 'filename' => $file ] );
            wp_die( esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = 'application/zip';
        if ( 'wpress' === $ext ) {
            $mime = 'application/octet-stream';
        }

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

        if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
            @ob_end_clean();
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

        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in calling function (handle_backup_request or ajax_start_backup_job) via verify_ajax_request()
        if ( Backup_Lite_Pro::is_pro_active() ) {
            $backup_label = '';
            if ( isset( $_POST['backup_label'] ) ) {
                $backup_label = sanitize_text_field( wp_unslash( $_POST['backup_label'] ) );
            }
            // @plugin-check: sanitized
            if ( ! empty( $backup_label ) ) {
                $options['label'] = $backup_label;
            }

            // @plugin-check: validated - checkbox value
            if ( ! empty( $_POST['backup_encrypt'] ) ) {
                $options['encrypt'] = true;
            }

            // @plugin-check: validated - checkbox value
            if ( ! empty( $_POST['backup_dual'] ) ) {
                $options['dual_version'] = true;
            }
            $backup_cloud_raw = '';
            if ( isset( $_POST['backup_cloud'] ) ) {
                $backup_cloud_raw = wp_unslash( $_POST['backup_cloud'] );
            }
            // @plugin-check: validated - will be sanitized in array_map or sanitize_text_field below
            if ( ! empty( $backup_cloud_raw ) ) {
                $cloud = $backup_cloud_raw;
                if ( is_array( $cloud ) ) {
                    $options['cloud_destinations'] = array_map( 'sanitize_text_field', $cloud );
                } elseif ( is_string( $cloud ) ) {
                    $decoded = json_decode( $cloud, true );
                    $options['cloud_destinations'] = is_array( $decoded ) ? array_map( 'sanitize_text_field', $decoded ) : [];
                }
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

        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
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

    public static function ajax_refresh_nonce() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }

        wp_send_json_success(
            [
                'nonce' => wp_create_nonce( self::NONCE ),
            ]
        );
    }

    public static function get_backups_list( $limit = 0 ) {
        $dir = trailingslashit( backup_lite_get_backup_dir() );
        
        // Support all ZIP/WPRESS backups regardless of naming convention.
        // Older versions created names like backup-lite-*.zip or museder-restoreone-*.zip,
        // while the new format uses domain-YYYYMMDDHHmmss-random.zip.
        // Matching on *.zip/*.wpress ensures future naming changes still work.
        $glob = glob( $dir . '*.{zip,wpress}', GLOB_BRACE );
        
        if ( empty( $glob ) ) {
            return [];
        }

        rsort( $glob );

        $items = [];
        foreach ( $glob as $index => $file ) {
            if ( $limit > 0 && $index >= $limit ) {
                break;
            }

            $filename = basename( $file );
            $metadata = Backup_Lite_Backup::get_backup_metadata( $filename );

            $items[] = [
                'name'    => $filename,
                'path'    => wp_normalize_path( $file ),
                'size'    => filesize( $file ),
                'type'    => 'site',
                // @plugin-check: wp_date with local timezone - filemtime() returns Unix timestamp (UTC), backup_lite_format_local_time() handles timezone conversion
                'created' => backup_lite_format_local_time( filemtime( $file ) ),
                'download_url' => self::build_backup_download_link( $file ),
                'label'   => $metadata['label'] ?? '',
                'encrypted' => ! empty( $metadata['encrypted'] ),
            ];
        }

        return $items;
    }

    public static function get_logs_list( $limit = 0 ) {
        $files = backup_lite_get_recent_logs();
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
            'shell'      => backup_lite_is_shell_available(),
            'mysqldump'  => backup_lite_can_use_mysqldump(),
            'mysql_cli'  => backup_lite_can_use_mysql_cli(),
            'ziparchive' => backup_lite_can_use_ziparchive(),
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
.backup-lite-pro-page.pro-locked-overlay::before,
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
.backup-lite-pro-page.pro-locked-overlay .bl-container,
.backup-lite-reports.pro-locked-overlay .bl-container {
    position: relative;
    z-index: 2;
}
.backup-lite-pro-page .notice:not(.backup-lite-notice),
.backup-lite-pro-page .update-nag:not(.backup-lite-notice),
.backup-lite-pro-page .error:not(.backup-lite-notice),
.backup-lite-pro-page .updated:not(.backup-lite-notice),
.backup-lite-reports .notice:not(.backup-lite-notice),
.backup-lite-reports .update-nag:not(.backup-lite-notice),
.backup-lite-reports .error:not(.backup-lite-notice),
.backup-lite-reports .updated:not(.backup-lite-notice),
.backup-lite-schedules .notice:not(.backup-lite-notice),
.backup-lite-schedules .update-nag:not(.backup-lite-notice),
.backup-lite-schedules .error:not(.backup-lite-notice),
.backup-lite-schedules .updated:not(.backup-lite-notice) {
    display: none !important;
}
.backup-lite-pro-page > .notice,
.backup-lite-pro-page > .update-nag,
.backup-lite-pro-page > .error,
.backup-lite-pro-page > .updated,
.backup-lite-reports > .notice,
.backup-lite-reports > .update-nag,
.backup-lite-reports > .error,
.backup-lite-reports > .updated,
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
        var pages = [".backup-lite-pro-page", ".backup-lite-reports", ".backup-lite-schedules"];
        pages.forEach(function(selector) {
            var page = document.querySelector(selector);
            if (page) {
                var pageWrapper = page.closest(".wrap") || page.parentElement;
                if (pageWrapper) {
                    var notices = pageWrapper.querySelectorAll(".notice:not(.backup-lite-notice), .update-nag:not(.backup-lite-notice), .error:not(.backup-lite-notice), .updated:not(.backup-lite-notice)");
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

        // Add inline styles for PRO pages, reports, and schedules
        if ( in_array( $current_page, [ 'backup-lite-pro', 'backup-lite-pro-features', 'backup-lite-pro-cloud', 'backup-lite-pro-ai', 'backup-lite-pro-filters', 'backup-lite-pro-retention', 'backup-lite-pro-reports', 'backup-lite-reports', 'backup-lite-schedules' ], true ) ) {
            wp_add_inline_style( 'backup-lite-theme', $pro_page_css );
            wp_add_inline_script( 'backup-lite-admin', $notice_removal_js, 'after' );
        }
    }

    private static function build_backup_download_link( $file ) {
        return backup_lite_get_download_url( $file );
    }

    private static function build_log_download_link( $file ) {
        $name = basename( $file );
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=backup_lite_download_log&log=' . rawurlencode( $name ) ),
            'backup_lite_download_log_' . $name
        );
    }

    public static function handle_report_download() {
        Backup_Lite_Reports_Controller::handle_report_download();
    }
}
