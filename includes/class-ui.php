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
        add_action( 'wp_ajax_backup_lite_clear_active_job', [ __CLASS__, 'ajax_clear_active_job' ] );
        add_action( 'wp_ajax_backup_lite_upload_existing_backup', [ __CLASS__, 'ajax_upload_existing_backup' ] );
        add_action( 'wp_ajax_backup_lite_refresh_nonce', [ __CLASS__, 'ajax_refresh_nonce' ] );
        add_action( 'wp_ajax_museder_ai_demo_site_scan', [ __CLASS__, 'ajax_ai_demo_site_scan' ] );
        add_action( 'wp_ajax_museder_ai_backup_report', [ __CLASS__, 'ajax_ai_backup_report' ] );
        add_action( 'wp_ajax_museder_ai_analyze_error_logs', [ __CLASS__, 'ajax_ai_analyze_error_logs' ] );
        add_action( 'wp_ajax_museder_ai_restore_guide', [ __CLASS__, 'ajax_ai_restore_guide' ] );

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
        $is_pro = function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features();
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

        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

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
        // Cloud Storage: handle upload_to_cloud flag - permission and nonce check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run backups.', 'museder-restoreone' ) );
        }

        if ( ! isset( $_POST['backup_lite_run_backup_nonce'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['backup_lite_run_backup_nonce'] ) ), 'backup_lite_run_backup' )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'museder-restoreone' ) );
        }

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

        // Check if a backup job is already running
        $current_job = get_transient( 'backup_lite_current_job' );
        if ( $current_job && isset( $current_job['status'] ) && 'running' === $current_job['status'] ) {
            // @plugin-check: escaped
            wp_send_json_error( [
                'code'    => 'backup_already_running',
                'message' => esc_html__( 'A backup is already running. Please wait until it finishes before starting a new one.', 'museder-restoreone' ),
            ], 409 );
        }

        // Server-side guard: prevent duplicate job starts within 3 seconds
        $options = self::get_backup_options_from_request();
        $job_key = self::generate_job_key( $options );
        $lock_key = 'backup_lite_job_lock_' . md5( $job_key );
        
        $existing_lock = get_transient( $lock_key );
        if ( $existing_lock ) {
            backup_lite_log( 'warning', 'Duplicate backup start request detected and blocked.', [
                'job_key' => $job_key,
                'lock_key' => $lock_key,
            ] );
            // @plugin-check: escaped
            wp_send_json_error( [
                'code'    => 'duplicate_start',
                'message' => esc_html__( 'Backup job already started.', 'museder-restoreone' ),
            ], 409 );
        }

        // Set lock for 3 seconds to prevent duplicate starts
        set_transient( $lock_key, time(), 3 );

        try {
            // Only create full backup job if create_dual_version is false
            // If create_dual_version is true, the PRO feature will handle creating both snapshot and full
            if ( ! empty( $options['create_dual_version'] ) && $options['create_dual_version'] ) {
                // PRO feature: create dual version (snapshot + full)
                // This will be handled by PRO feature logic if available
                backup_lite_log( 'info', 'Dual version backup requested (snapshot + full).', [
                    'create_dual_version' => 'true',
                ] );
            } else {
                // Only create full backup
                backup_lite_log( 'info', 'Full backup requested (no snapshot).', [
                    'create_dual_version' => 'false',
                ] );
            }

            $job = Backup_Lite_Backup_Jobs::create_job( $options );

            // Mark job as running in transient (for server-side protection)
            self::mark_job_running( $job['id'], $options );

            wp_send_json_success( [
                'job'     => Backup_Lite_Backup_Jobs::format_job_payload( $job ),
                // @plugin-check: escaped
                'message' => esc_html__( 'Backup job created. Processing has started in the background.', 'museder-restoreone' ),
            ] );
        } catch ( Throwable $exception ) {
            // Clear lock on error
            delete_transient( $lock_key );
            
            // Clear job running state on error
            self::clear_job_running();
            
            backup_lite_log( 'error', 'Failed to start backup job.', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ] );

            // @plugin-check: escaped
            wp_send_json_error( [
                'code'    => 'backup_internal_error',
                'message' => esc_html__( 'An internal error occurred while starting the backup. Please check the Logs screen for details.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    /**
     * Generate a unique key for a backup job based on options.
     * Used to detect duplicate start requests.
     * 
     * @param array $options Backup options.
     * @return string
     */
    private static function generate_job_key( $options ) {
        $key_parts = [
            'label' => isset( $options['label'] ) ? $options['label'] : '',
            'encrypt' => ! empty( $options['encrypt'] ) ? '1' : '0',
            'dual' => ! empty( $options['create_dual_version'] ) ? '1' : '0',
            'dest_s3' => ! empty( $options['dest_s3'] ) ? '1' : '0',
            'timestamp' => time(), // Round to nearest 3 seconds to catch near-simultaneous requests
        ];
        $key_parts['timestamp'] = floor( $key_parts['timestamp'] / 3 ) * 3;
        return wp_json_encode( $key_parts );
    }

    /**
     * Mark a backup job as running in transient.
     * 
     * @param string $job_id Job ID.
     * @param array  $options Backup options used for this job.
     */
    private static function mark_job_running( $job_id, $options ) {
        $data = array(
            'job_id'  => $job_id,
            'started' => time(),
            'status'  => 'running',
            'options' => $options, // Snapshot of options used for this backup
        );

        set_transient( 'backup_lite_current_job', $data, HOUR_IN_SECONDS );
    }

    /**
     * Clear the running job transient.
     */
    public static function clear_job_running() {
        delete_transient( 'backup_lite_current_job' );
    }

    public static function ajax_get_backup_job_status() {
        try {
            self::verify_ajax_request();

            $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
            if ( empty( $job_id ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ] );
                return;
            }

            $payload = Backup_Lite_Backup_Jobs::get_job_payload( $job_id );
            if ( ! $payload ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found or already completed.', 'museder-restoreone' ) ] );
                return;
            }

            // Ensure percent is 100 when status is completed
            if ( isset( $payload['status'] ) && 'completed' === $payload['status'] ) {
                $payload['percentage'] = 100;
            }

            wp_send_json_success( [ 'job' => $payload ] );
        } catch ( Throwable $e ) {
            backup_lite_log( 'error', 'Backup status check failed.', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ] );

            // @plugin-check: escaped
            wp_send_json_error( [
                'code'    => 'backup_internal_error',
                'message' => esc_html__( 'An internal error occurred during backup status check. Please check the Logs screen for details.', 'museder-restoreone' ),
            ] );
        }
    }

    public static function ajax_continue_backup_job() {
        self::verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ] );
        }

        $job = Backup_Lite_Backup_Jobs::process_job_immediately( $job_id, Backup_Lite_Backup_Jobs::AJAX_BATCH_FILES, Backup_Lite_Backup_Jobs::AJAX_BATCH_BYTES );
        if ( ! $job ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup job not found.', 'museder-restoreone' ) ] );
        }

        wp_send_json_success( [
            'job'     => Backup_Lite_Backup_Jobs::format_job_payload( $job ),
            // @plugin-check: escaped
            'message' => esc_html__( 'Backup job updated.', 'museder-restoreone' ),
        ] );
    }

    public static function ajax_cancel_backup_job() {
        try {
            self::verify_ajax_request();

            $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
            if ( empty( $job_id ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Job ID is required.', 'museder-restoreone' ) ] );
                return;
            }

            if ( ! Backup_Lite_Backup_Jobs::cancel_job( $job_id ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Unable to cancel backup job.', 'museder-restoreone' ) ] );
                return;
            }

            // Clear the current job transient to allow new backups
            self::clear_job_running();

            // @plugin-check: escaped
            wp_send_json_success( [
                'status'  => 'cancelled',
                'message' => esc_html__( 'Backup job cancelled.', 'museder-restoreone' ),
            ] );
        } catch ( Throwable $e ) {
            backup_lite_log( 'error', 'Cancel backup job failed.', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ] );

            // @plugin-check: escaped
            wp_send_json_error( [
                'code'    => 'backup_internal_error',
                'message' => esc_html__( 'Failed to cancel backup job. Please check the Logs screen for details.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    public static function handle_restore_request() {
        self::verify_ajax_request();

        // @plugin-check: sanitized + nonce - verified via verify_ajax_request() above
        $confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
        if ( empty( $confirm ) || '1' !== $confirm ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Restore not confirmed by user.', 'museder-restoreone' ) ] );
        }

        // @plugin-check: sanitized + nonce - verified via verify_ajax_request() above
        $uploaded_file = null;
        if ( isset( $_FILES['restoreFile'] ) && is_uploaded_file( $_FILES['restoreFile']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload file array provided by the system
            $uploaded_file = $_FILES['restoreFile'];
        } elseif ( isset( $_FILES['restore_file'] ) && is_uploaded_file( $_FILES['restore_file']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload file array provided by the system
            $uploaded_file = $_FILES['restore_file'];
        }

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
        if ( @rename( $file_path, $destination ) ) {
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

    /**
     * AJAX handler to upload an existing backup to S3.
     */
    public static function ajax_upload_existing_backup() {
        try {
            self::verify_ajax_request();

            $filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
            if ( empty( $filename ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Backup filename is required.', 'museder-restoreone' ) ] );
                return;
            }

            // Validate file path
            $backup_dir = trailingslashit( backup_lite_get_backup_dir() );
            $file_path = $backup_dir . basename( $filename );
            $file_path = wp_normalize_path( $file_path );

            // Security check: ensure file is within backup directory
            if ( 0 !== strpos( $file_path, wp_normalize_path( $backup_dir ) ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Invalid backup file path.', 'museder-restoreone' ) ] );
                return;
            }

            if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or not readable.', 'museder-restoreone' ) ] );
                return;
            }

            // Check if S3 is configured
            if ( ! class_exists( 'Backup_Lite_S3_Service' ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'S3 service is not available.', 'museder-restoreone' ) ] );
                return;
            }

            $s3_settings = backup_lite_get_s3_settings();
            if ( empty( $s3_settings['enabled'] ) || empty( $s3_settings['bucket'] ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'S3 is not configured. Please configure S3 settings first.', 'museder-restoreone' ) ] );
                return;
            }

            // Upload to S3 using new S3 Uploader class
            backup_lite_log( 'info', 'Manual S3 upload requested for existing backup.', [
                'file' => $file_path,
            ] );

            // Use new Backup_Lite_S3_Uploader class (provides clean architecture for future multipart upload)
            if ( ! class_exists( 'Backup_Lite_S3_Uploader' ) ) {
                // Fallback to old method if new class not available
                $s3_upload_result = self::upload_backup_to_s3_unified( $file_path );
            } else {
                $uploader = Backup_Lite_S3_Uploader::get_instance();
                $s3_upload_result = $uploader->upload_backup_file( $file_path );
                
                // Convert result format to match expected format
                if ( is_wp_error( $s3_upload_result ) ) {
                    // Already in correct format
                } elseif ( is_array( $s3_upload_result ) && isset( $s3_upload_result['status'] ) && 'success' === $s3_upload_result['status'] ) {
                    // Convert to expected format with object_key
                    $s3_upload_result = array(
                        'status'    => 'success',
                        'object_key' => isset( $s3_upload_result['object_key'] ) ? $s3_upload_result['object_key'] : '',
                    );
                } else {
                    // Unexpected format, convert to WP_Error
                    $s3_upload_result = new WP_Error( 's3_unexpected_result', __( 'S3 upload returned unexpected result.', 'museder-restoreone' ) );
                }
            }

            // Handle result
            if ( is_wp_error( $s3_upload_result ) ) {
                // Upload failed
                $error_code = $s3_upload_result->get_error_code();
                $error_message = $s3_upload_result->get_error_message();
                
                // Update backup metadata with error
                try {
                    $metadata = Backup_Lite_Backup::get_backup_metadata( basename( $file_path ) );
                    $metadata['s3_status'] = 'error';
                    $metadata['s3_error'] = $error_code;
                    Backup_Lite_Backup::store_backup_metadata( basename( $file_path ), $metadata );
                } catch ( Throwable $meta_error ) {
                    // Log but don't fail the response
                    backup_lite_log( 'warning', 'Failed to update backup metadata after S3 upload error.', [
                        'file' => $file_path,
                        'meta_error' => $meta_error->getMessage(),
                    ] );
                }

                backup_lite_log( 'error', 'Manual S3 upload failed.', [
                    'file'   => $file_path,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                ] );

                // @plugin-check: escaped
                wp_send_json_error( [
                    'message' => esc_html__( 'S3 upload failed. Please check logs for details.', 'museder-restoreone' ),
                    'reason'  => $error_code,
                ] );
            } else {
                // Upload succeeded
                try {
                    $metadata = Backup_Lite_Backup::get_backup_metadata( basename( $file_path ) );
                    $metadata['s3_status'] = 'success';
                    if ( is_array( $s3_upload_result ) && ! empty( $s3_upload_result['object_key'] ) ) {
                        $metadata['s3_object_key'] = $s3_upload_result['object_key'];
                    }
                    // Remove error status if it exists
                    if ( isset( $metadata['s3_error'] ) ) {
                        unset( $metadata['s3_error'] );
                    }
                    // Preserve existing metadata like duration
                    Backup_Lite_Backup::store_backup_metadata( basename( $file_path ), $metadata );
                } catch ( Throwable $meta_error ) {
                    // Log but don't fail the response
                    backup_lite_log( 'warning', 'Failed to update backup metadata after S3 upload success.', [
                        'file' => $file_path,
                        'meta_error' => $meta_error->getMessage(),
                    ] );
                }

                backup_lite_log( 'info', 'Manual S3 upload completed successfully.', [
                    'file'      => $file_path,
                    'object_key' => is_array( $s3_upload_result ) && isset( $s3_upload_result['object_key'] ) ? $s3_upload_result['object_key'] : '',
                ] );

                // @plugin-check: escaped
                wp_send_json_success( [
                    'message'   => esc_html__( 'Backup uploaded to S3 successfully.', 'museder-restoreone' ),
                    'object_key' => is_array( $s3_upload_result ) && isset( $s3_upload_result['object_key'] ) ? $s3_upload_result['object_key'] : '',
                ] );
            }
        } catch ( Throwable $e ) {
            // Catch any unhandled exceptions/errors
            backup_lite_log( 'error', 'Manual S3 upload fatal error.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ] );
            
            // Also log to PHP error log for easier debugging
            error_log( '[Backup Lite] Manual S3 upload fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

            // @plugin-check: escaped
            wp_send_json_error( [
                'message' => esc_html__( 'Manual S3 upload failed. Please check logs for details.', 'museder-restoreone' ),
                'code' => 'fatal_error',
            ] );
        }
    }

    /**
     * Public wrapper for unified S3 upload method.
     * 
     * @param string $file_path Absolute path to backup archive file.
     * @return array|WP_Error On success, returns array with 'status' => 'success' and 'object_key'. On failure, returns WP_Error.
     */
    public static function upload_backup_to_s3_unified( $file_path ) {
        // Call the public static method in Backup_Lite_Backup
        if ( class_exists( 'Backup_Lite_Backup' ) && method_exists( 'Backup_Lite_Backup', 'upload_backup_to_s3' ) ) {
            return Backup_Lite_Backup::upload_backup_to_s3( $file_path );
        }
        
        // Fallback: return error if method not available
        return new WP_Error( 'method_unavailable', __( 'S3 upload method is not available.', 'museder-restoreone' ) );
    }

    public static function handle_restore_existing() {
        self::verify_ajax_request();

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ] );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $file_path  = trailingslashit( $backup_dir ) . basename( $filename );
        $file_path  = wp_normalize_path( $file_path );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) ] );
        }

        if ( strpos( wp_normalize_path( $file_path ), wp_normalize_path( $backup_dir ) ) !== 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid backup file path.', 'museder-restoreone' ) ] );
        }

        $options = [];
        $search_replace_raw = '';
        if ( isset( $_POST['search_replace'] ) ) {
            $search_replace_raw = wp_unslash( $_POST['search_replace'] );
        }
        // @plugin-check: validated - JSON will be decoded and validated
        if ( ! empty( $search_replace_raw ) ) {
            $decoded = json_decode( $search_replace_raw, true );
            if ( is_array( $decoded ) ) {
                $options['search_replace'] = $decoded;
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

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ] );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $file_path  = trailingslashit( $backup_dir ) . basename( $filename );
        $file_path  = wp_normalize_path( $file_path );

        if ( ! file_exists( $file_path ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found.', 'museder-restoreone' ) ] );
        }

        if ( strpos( wp_normalize_path( $file_path ), wp_normalize_path( $backup_dir ) ) !== 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid backup file path.', 'museder-restoreone' ) ] );
        }

        // @plugin-check: allowed - required for backup/restore file operations
        // Path is validated and sanitized before use
        if ( @unlink( $file_path ) ) {
            backup_lite_log( 'info', 'Backup file deleted.', [ 'file' => $file_path ] );
            wp_send_json_success( [ 'message' => esc_html__( 'Backup deleted successfully.', 'museder-restoreone' ) ] );
        } else {
            backup_lite_log( 'error', 'Failed to delete backup file.', [ 'file' => $file_path ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ] );
        }
    }

    public static function handle_delete_backups() {
        self::verify_ajax_request();

        $raw = array();
        if ( isset( $_POST['filenames'] ) ) {
            $raw = wp_unslash( $_POST['filenames'] );
        }
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
            wp_send_json_error( [ 'message' => esc_html__( 'No backup files selected.', 'museder-restoreone' ) ] );
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
            if ( @unlink( $file_path ) ) {
                $deleted[] = $filename;
                backup_lite_log( 'info', 'Backup file deleted (bulk).', [ 'file' => $file_path ] );
            } else {
                $errors[] = [ 'file' => $filename, 'message' => esc_html__( 'Failed to delete backup file.', 'museder-restoreone' ) ];
                backup_lite_log( 'error', 'Failed to delete backup file (bulk).', [ 'file' => $file_path ] );
            }
        }

        if ( empty( $deleted ) && ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors ] );
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

        $log  = sanitize_text_field( wp_unslash( $_GET['log'] ?? '' ) );
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

        readfile( $path );
        exit;
    }

    public static function handle_backup_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        // @plugin-check: sanitized + nonce - verified via check_admin_referer() below
        $file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
        $path = backup_lite_get_backup_dir() . '/' . basename( $file );
        $path = wp_normalize_path( $path );

        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Backup file not found.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        check_admin_referer( 'backup_lite_download_' . basename( $path ) );

        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = 'application/zip';
        if ( 'wpress' === $ext ) {
            $mime = 'application/octet-stream';
        }

        ignore_user_abort( true );
        // @plugin-check: okay - needed for long running backup/restore operations
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
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
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Transfer-Encoding: binary' );

        $chunk_size = 1024 * 1024; // 1MB
        $handle     = fopen( $path, 'rb' );
        if ( ! $handle ) {
            wp_die( esc_html__( 'Unable to read backup file.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 500 );
        }

        while ( ! feof( $handle ) ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streaming binary file contents, not HTML output
            echo fread( $handle, $chunk_size );
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

        // PRO features (if Pro is active)
        if ( function_exists( 'backup_lite_has_pro_features' ) && backup_lite_has_pro_features() ) {
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

            // Dual version / snapshot: read from checkbox, default to false if not set
            $raw_create_dual = isset( $_POST['backup_dual'] ) || isset( $_POST['backup_lite_create_dual'] )
                ? ( isset( $_POST['backup_dual'] ) ? wp_unslash( $_POST['backup_dual'] ) : wp_unslash( $_POST['backup_lite_create_dual'] ) )
                : '';
            $options['create_dual_version'] = ( '1' === $raw_create_dual || 'on' === $raw_create_dual );
            // Also set legacy format for backward compatibility
            if ( $options['create_dual_version'] ) {
                $options['dual_version'] = true;
            }
        } else {
            // If PRO is not active, default to false
            $options['create_dual_version'] = false;
        }
        
        // S3 cloud storage: handle upload_to_s3 flag
        // Read checkbox value first, then validate in backup process
        $raw_dest_s3 = '';
        if ( isset( $_POST['backup_lite_dest_s3'] ) ) {
            $dest_s3_value = wp_unslash( $_POST['backup_lite_dest_s3'] );
            // Handle array case: when checkbox is checked, both hidden input (0) and checkbox (1) are sent
            // Take the last value which should be the checkbox value
            if ( is_array( $dest_s3_value ) ) {
                $raw_dest_s3 = ! empty( $dest_s3_value ) ? end( $dest_s3_value ) : '0';
            } else {
                $raw_dest_s3 = $dest_s3_value;
            }
            $raw_dest_s3 = sanitize_text_field( $raw_dest_s3 );
        }
        
        // Support both new field name (backup_lite_dest_s3) and legacy (backup_lite_upload_to_s3) for backward compatibility
        if ( empty( $raw_dest_s3 ) && isset( $_POST['backup_lite_upload_to_s3'] ) ) {
            // Legacy support
            $legacy_value = wp_unslash( $_POST['backup_lite_upload_to_s3'] );
            if ( is_array( $legacy_value ) ) {
                $raw_dest_s3 = ! empty( $legacy_value ) ? end( $legacy_value ) : '0';
            } else {
                $raw_dest_s3 = $legacy_value;
            }
            $raw_dest_s3 = sanitize_text_field( $raw_dest_s3 );
        }
        
        // Explicitly check for '1' or 'on' to set dest_s3 to true, otherwise false
        $options['dest_s3'] = ( '1' === $raw_dest_s3 || 'on' === $raw_dest_s3 );
        
        // Log backup options collection for debugging (use info level to match user's log)
        backup_lite_log(
            'info',
            'UI collected backup options.',
            array(
                'dest_s3_raw'         => $raw_dest_s3,
                'dest_s3'             => $options['dest_s3'] ? 'true' : 'false',
                'create_dual_version' => isset( $options['create_dual_version'] ) && $options['create_dual_version'] ? 'true' : 'false',
            )
        );
        
        // Unified destinations structure
        $options['destinations'] = array(
            'local' => true, // Always enabled
            's3'    => $options['dest_s3'],
        );
        
        // Also set legacy format for backward compatibility
        if ( $options['dest_s3'] ) {
            $options['upload_to_s3'] = true;
        }
        
        // Legacy: backup_lite_upload_to_cloud (for backward compatibility)
        if ( isset( $_POST['backup_lite_upload_to_cloud'] ) ) {
            $upload_to_cloud = ( '1' === sanitize_text_field( wp_unslash( $_POST['backup_lite_upload_to_cloud'] ) ) );
            if ( $upload_to_cloud ) {
                $options['cloud_destination'] = true;
                $options['upload_to_cloud'] = true;
            }
        }
        
        // Legacy: backup_lite_cloud_destination (for backward compatibility)
        // @plugin-check: validated - checkbox value
        if ( ! empty( $_POST['backup_lite_cloud_destination'] ) ) {
            $options['cloud_destination'] = true;
        }
        
        // Legacy cloud destinations (for backward compatibility)
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

        return $options;
    }

    public static function verify_ajax_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ] );
        }

        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
            wp_send_json_error(
                [
                    'code'    => 'invalid_nonce',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ]
            );
        }
    }

    public static function ajax_refresh_nonce() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ] );
        }

        wp_send_json_success(
            [
                'nonce' => wp_create_nonce( self::NONCE ),
            ]
        );
    }

    /**
     * AJAX handler for AI demo site scan.
     * 
     * This handler supports two modes:
     * - Live mode: Uses send_request() to call OpenAI API (when API key is configured)
     * - Demo mode: Uses demo_response() to return fixed demo data (when no API key)
     */
    public static function ajax_ai_demo_site_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Insufficient permissions.', 'museder-restoreone' ),
            ], 403 );
        }

        check_ajax_referer( self::NONCE, 'nonce' );

        // Check if site scan is allowed based on license tier and usage limits
        $permission = Museder_AI_Service::can_run_site_scan();
        if ( ! $permission['allowed'] ) {
            wp_send_json_error( [
                'code'    => 'limit_reached',
                'message' => $permission['message'],
            ] );
        }

        // Prepare payload with site information
        $backups = Backup_Lite_UI::get_backups_list();
        $backup_count = count( $backups );
        
        $last_backup_days = 0;
        if ( ! empty( $backups ) && isset( $backups[0]['path'] ) ) {
            $last_backup_time = filemtime( $backups[0]['path'] );
            if ( $last_backup_time ) {
                $last_backup_days = ( time() - $last_backup_time ) / DAY_IN_SECONDS;
            }
        }

        $schedules = Backup_Lite_Schedule_Handler::list_schedules();
        $schedule_active = ! empty( $schedules );

        // Get plugins list
        $plugins = [];
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( is_plugin_active( $plugin_file ) ) {
                $plugins[] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                ];
            }
        }

        // Prepare backups summary
        $backups_summary = [];
        if ( ! empty( $backups ) ) {
            $recent_backups = array_slice( $backups, 0, 5 ); // Get last 5 backups
            foreach ( $recent_backups as $backup ) {
                $backups_summary[] = [
                    'name' => $backup['name'] ?? '',
                    'size' => $backup['size'] ?? 0,
                    'created' => $backup['created'] ?? '',
                ];
            }
        }

        $payload = [
            'site_url'         => home_url(),
            'wp_version'       => get_bloginfo( 'version' ),
            'php_version'      => PHP_VERSION,
            'plugins'          => $plugins,
            'backup_count'     => $backup_count,
            'last_backup_days' => round( $last_backup_days, 1 ),
            'schedule_active'  => $schedule_active,
            'backups_summary'  => $backups_summary,
        ];

        // Check if OpenAI API key is configured
        $settings = Museder_AI_Service::get_settings();
        $api_key = $settings['openai_api_key'] ?? '';

        // Use live mode if API key is available, otherwise use demo mode
        if ( ! empty( $api_key ) ) {
            $result = Museder_AI_Service::send_request( 'site_scan', $payload );
            $mode = 'live';
        } else {
        $result = Museder_AI_Service::demo_response( 'site_scan', $payload );
            $mode = 'demo';
        }

        // Handle response
        if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
            // Add mode to result for storage
            $result['mode'] = $mode;

            // Store the last site scan result
            Museder_AI_Service::store_last_site_scan( $result );

            // Check for high risk and send alert if needed
            $alert = Museder_AI_Service::maybe_send_alert( 'site_scan', $result );
            if ( ! empty( $alert ) ) {
                $result['alert'] = $alert;
            }

            // Prepare response data
            $response_data = [
                'summary'         => $result['summary'] ?? '',
                'risk'            => $result['risk'] ?? 'medium',
                'recommendations' => $result['recommendations'] ?? [],
                'mode'            => $mode,
            ];

            // Add remaining scans for free tier (before logging)
            $settings = Museder_AI_Service::get_settings();
            $license_tier = $settings['license_tier'] ?? 'free';
            if ( $license_tier === 'free' && isset( $permission['remaining'] ) ) {
                $response_data['remaining_scans'] = $permission['remaining'] - 1; // Will be 0 after this scan
            }

            // Add alert to response if exists
            if ( ! empty( $alert ) ) {
                $response_data['alert'] = $alert;
            }

            // Log successful usage (after preparing response)
            Museder_AI_Service::log_usage( 'site_scan', 'success' );

            wp_send_json_success( $response_data );
        } else {
            // Log error usage (optional, for tracking)
            Museder_AI_Service::log_usage( 'site_scan', 'error' );

            wp_send_json_error( [
                'code'    => $result['code'] ?? 'unknown_error',
                'message' => $result['message'] ?? esc_html__( 'AI request failed.', 'museder-restoreone' ),
                'mode'    => $mode,
            ] );
        }
    }

    /**
     * AJAX handler for AI backup report.
     * 
     * This handler supports two modes:
     * - Live mode: Uses send_request() to call OpenAI API (when API key is configured)
     * - Demo mode: Uses demo_response() to return fixed demo data (when no API key)
     */
    public static function ajax_ai_backup_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Insufficient permissions.', 'museder-restoreone' ),
            ], 403 );
        }

        check_ajax_referer( self::NONCE, 'nonce' );

        // Check if backup report is allowed based on license tier and usage limits
        $permission = Museder_AI_Service::can_run_backup_report();
        if ( ! $permission['allowed'] ) {
            wp_send_json_error( [
                'code'    => 'limit_reached',
                'message' => $permission['message'],
            ] );
        }

        // Prepare payload with site information
        $backups = Backup_Lite_UI::get_backups_list();
        $backup_count = count( $backups );
        
        $last_backup_days = 0;
        if ( ! empty( $backups ) && isset( $backups[0]['path'] ) ) {
            $last_backup_time = filemtime( $backups[0]['path'] );
            if ( $last_backup_time ) {
                $last_backup_days = ( time() - $last_backup_time ) / DAY_IN_SECONDS;
            }
        }

        $schedules = Backup_Lite_Schedule_Handler::list_schedules();
        $schedule_active = ! empty( $schedules );

        // Get plugins list
        $plugins = [];
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( is_plugin_active( $plugin_file ) ) {
                $plugins[] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                ];
            }
        }

        // Prepare backups summary
        $backups_summary = [];
        if ( ! empty( $backups ) ) {
            $recent_backups = array_slice( $backups, 0, 5 ); // Get last 5 backups
            foreach ( $recent_backups as $backup ) {
                $backups_summary[] = [
                    'name' => $backup['name'] ?? '',
                    'size' => $backup['size'] ?? 0,
                    'created' => $backup['created'] ?? '',
                ];
            }
        }

        // TODO: Add backup schedules / cloud destinations info if needed
        $payload = [
            'site_url'         => home_url(),
            'wp_version'       => get_bloginfo( 'version' ),
            'php_version'      => PHP_VERSION,
            'plugins'          => $plugins,
            'backup_count'     => $backup_count,
            'last_backup_days' => round( $last_backup_days, 1 ),
            'schedule_active'  => $schedule_active,
            'backups_summary'  => $backups_summary,
        ];

        // Check if OpenAI API key is configured
        $settings = Museder_AI_Service::get_settings();
        $api_key = $settings['openai_api_key'] ?? '';

        // Use live mode if API key is available, otherwise use demo mode
        if ( ! empty( $api_key ) ) {
            $result = Museder_AI_Service::send_request( 'backup_report', $payload );
            $mode = 'live';
        } else {
            $result = Museder_AI_Service::demo_response( 'backup_report', $payload );
            $mode = 'demo';
        }

        // Handle response
        if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
            // Add mode to result for storage
            $result['mode'] = $mode;

            // Store the last backup report if overall_score exists
            if ( isset( $result['overall_score'] ) ) {
                Museder_AI_Service::store_last_backup_report( $result );
            }

            // Check for high risk and send alert if needed
            $alert = Museder_AI_Service::maybe_send_alert( 'backup_report', $result );
            if ( ! empty( $alert ) ) {
                $result['alert'] = $alert;
            }

            // Log successful usage
            Museder_AI_Service::log_usage( 'backup_report', 'success' );

            // Prepare response data
            $response_data = [
                'summary'         => $result['summary'] ?? '',
                'overall_score'   => $result['overall_score'] ?? 50,
                'risk_level'      => $result['risk_level'] ?? 'medium',
                'risk_factors'    => $result['risk_factors'] ?? [],
                'recommendations' => $result['recommendations'] ?? [],
                'mode'            => $mode,
            ];

            // Add alert to response if exists
            if ( ! empty( $alert ) ) {
                $response_data['alert'] = $alert;
            }

            wp_send_json_success( $response_data );
        } else {
            // Log error usage (optional, for tracking)
            Museder_AI_Service::log_usage( 'backup_report', 'error' );

            wp_send_json_error( [
                'code'    => $result['code'] ?? 'unknown_error',
                'message' => $result['message'] ?? esc_html__( 'AI request failed.', 'museder-restoreone' ),
                'mode'    => $mode,
            ] );
        }
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

            // Get file size - ensure file exists and is readable
            $file_size = 0;
            if ( file_exists( $file ) && is_readable( $file ) ) {
                $file_size = filesize( $file );
                // If filesize returns false, try alternative method
                if ( false === $file_size ) {
                    $file_size = 0;
                }
            }

            $items[] = [
                'name'    => $filename,
                'path'    => wp_normalize_path( $file ),
                'size'    => $file_size,
                'type'    => 'site',
                'created' => backup_lite_local_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) ),
                'download_url' => self::build_backup_download_link( $file ),
                'label'   => $metadata['label'] ?? '',
                'encrypted' => ! empty( $metadata['encrypted'] ),
                's3_status' => $metadata['s3_status'] ?? 'none',
                's3_object_key' => $metadata['s3_object_key'] ?? '',
                's3_error' => $metadata['s3_error'] ?? '',
                'duration' => isset( $metadata['duration'] ) && $metadata['duration'] > 0 ? (int) $metadata['duration'] : 0,
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

    /**
     * AJAX handler for generating restore guide with AI.
     */
    public static function ajax_ai_restore_guide() {
        check_ajax_referer( 'museder_ai_restore_guide', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'code'    => 'unauthorized',
                'message' => esc_html__( 'You do not have permission to perform this action.', 'museder-restoreone' ),
            ] );
        }

        $backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
        
        // If no backup_id provided, try to get from active or latest archive
        if ( empty( $backup_id ) ) {
            $archive = Backup_Lite_Restore_Handler::get_active_or_latest_archive();
            if ( $archive && ! empty( $archive['name'] ) ) {
                $backup_id = $archive['name'];
            } else {
                wp_send_json_error( [
                    'code'    => 'missing_backup_id',
                    'message' => esc_html__( 'Backup file not selected. Please select a backup first.', 'museder-restoreone' ),
                ] );
            }
        }

        // Development mode: Bypass all free tier limits
        if ( function_exists( 'backup_lite_is_developer_mode' ) && backup_lite_is_developer_mode() ) {
            // Skip limit check in dev mode
        } else {
            // Get license tier
            $tier = Museder_AI_Service::get_license_tier();

            // Check free tier limit (30 days)
            if ( $tier === 'free' ) {
                $last_run = get_option( Museder_AI_Service::LAST_RESTORE_GUIDE_FREE_RUN_OPTION, 0 );
                $days_since = ( current_time( 'timestamp' ) - $last_run ) / DAY_IN_SECONDS;

                if ( $days_since < 30 ) {
                    wp_send_json_error( [
                        'code'    => 'free_limit_reached',
                        'message' => esc_html__( 'You have used your free Restore AI Guide for this month. Upgrade to Pro for unlimited guides.', 'museder-restoreone' ),
                    ] );
                }
            }
        }

        // Get license tier (needed for later use)
        $tier = Museder_AI_Service::get_license_tier();

        // Get active archive or latest backup
        $archive = Backup_Lite_Restore_Handler::get_active_or_latest_archive();
        if ( ! $archive || empty( $archive['name'] ) ) {
            wp_send_json_error( [
                'code'    => 'backup_not_found',
                'message' => esc_html__( 'Selected backup file not found.', 'museder-restoreone' ),
            ] );
        }

        // Get backup summary (use archive data)
        $backup_summary = [
            'name'    => $archive['name'],
            'size'    => isset( $archive['bytes'] ) ? $archive['bytes'] : 0,
            'created' => isset( $archive['created'] ) ? $archive['created'] : '',
            'source'  => isset( $archive['source'] ) ? $archive['source'] : 'existing',
        ];

        // Get recent log content (backup/restore related)
        $log_content = Backup_Lite_Log_Handler::get_recent_log_content( 10000 );

        // Get restore options from state (if available)
        // Note: get_state() is private, so we use current_summary() to check if there's an active restore
        $current_summary = Backup_Lite_Restore_Handler::current_summary();
        $restore_options = [];
        // For now, we'll leave restore_options empty or use a simple description
        // In the future, this can be enhanced to read from restore state
        if ( $current_summary ) {
            $restore_options = [
                'backup_selected' => true,
                'backup_source' => $current_summary['source'] ?? 'unknown',
            ];
        }

        // Get environment information
        $plugins = [];
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( in_array( $plugin_file, $active_plugins, true ) ) {
                $plugins[] = [
                    'name'    => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                ];
            }
        }

        // Filter for major plugins (WooCommerce, Elementor, etc.)
        $major_plugins = array_filter( $plugins, function( $plugin ) {
            $name_lower = strtolower( $plugin['name'] );
            return (
                strpos( $name_lower, 'woocommerce' ) !== false ||
                strpos( $name_lower, 'elementor' ) !== false ||
                strpos( $name_lower, 'beaver' ) !== false ||
                strpos( $name_lower, 'divi' ) !== false ||
                strpos( $name_lower, 'avada' ) !== false ||
                strpos( $name_lower, 'wpml' ) !== false ||
                strpos( $name_lower, 'polylang' ) !== false
            );
        });

        $environment = [
            'home_url'    => home_url(),
            'wp_version'  => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'plugins'     => ! empty( $major_plugins ) ? array_values( $major_plugins ) : array_slice( $plugins, 0, 10 ), // Limit to 10 plugins or major ones
        ];

        // Prepare payload with new format
        $payload = [
            'backup_summary' => $backup_summary,
            'logs'           => $log_content,
            'restore_options' => $restore_options,
            'environment'    => $environment,
        ];

        // Check if OpenAI API key is configured
        $settings = Museder_AI_Service::get_settings();
        $api_key = $settings['openai_api_key'] ?? '';

        // Use live mode if API key is available, otherwise use demo mode
        if ( ! empty( $api_key ) ) {
            $result = Museder_AI_Service::send_request( 'restore_guide', $payload );
            $mode = 'live';
        } else {
            $result = Museder_AI_Service::demo_response( 'restore_guide', $payload );
            $mode = 'demo';
        }

        // Handle response
        if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
            // Store the last restore guide
            Museder_AI_Service::store_last_restore_guide( $backup_id, $result, $mode );

            // Check for high risk and send alert if needed
            $alert = Museder_AI_Service::maybe_send_alert( 'restore_guide', $result );
            if ( ! empty( $alert ) ) {
                $result['alert'] = $alert;
            }

            // Update free tier last run timestamp (skip in dev mode)
            if ( $tier === 'free' && ! ( function_exists( 'backup_lite_is_developer_mode' ) && backup_lite_is_developer_mode() ) ) {
                update_option( Museder_AI_Service::LAST_RESTORE_GUIDE_FREE_RUN_OPTION, current_time( 'timestamp' ), false );
            }

            // Log successful usage
            Museder_AI_Service::log_usage( 'restore_guide', 'success' );

            wp_send_json_success( [
                'report' => [
                    'backup_id'  => $backup_id,
                    'summary'    => $result['summary'] ?? '',
                    'risk_level' => $result['risk_level'] ?? '',
                    'steps'      => $result['steps'] ?? [],
                    'warnings'   => $result['warnings'] ?? [],
                    'notes'      => $result['notes'] ?? [],
                    'mode'       => $mode,
                ],
                'mode'   => $mode,
                'tier'   => $tier,
                'alert'  => $alert ?? null,
            ] );
        } else {
            // Log error usage
            Museder_AI_Service::log_usage( 'restore_guide', 'error' );

            wp_send_json_error( [
                'code'    => $result['code'] ?? 'unknown_error',
                'message' => $result['message'] ?? esc_html__( 'AI request failed.', 'museder-restoreone' ),
            ] );
        }
    }

    /**
     * AJAX handler for analyzing error logs with AI.
     */
    public static function ajax_ai_analyze_error_logs() {
        check_ajax_referer( 'museder_ai_log_analysis', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'code'    => 'unauthorized',
                'message' => esc_html__( 'You do not have permission to perform this action.', 'museder-restoreone' ),
            ] );
        }

        // Development mode: Bypass all free tier limits
        if ( function_exists( 'backup_lite_is_developer_mode' ) && backup_lite_is_developer_mode() ) {
            // Skip limit check in dev mode
        } else {
            // Get license tier (uses global helper which considers Developer Mode)
            $tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
                ? backup_lite_get_effective_license_tier() 
                : Museder_AI_Service::get_license_tier();

            // Check free tier limit (30 days)
            if ( $tier === 'free' ) {
                $last_run = get_option( Museder_AI_Service::LAST_ERROR_LOG_FREE_RUN_OPTION, 0 );
                $days_since = ( current_time( 'timestamp' ) - $last_run ) / DAY_IN_SECONDS;

                if ( $days_since < 30 ) {
                    wp_send_json_error( [
                        'code'    => 'free_limit_reached',
                        'message' => esc_html__( 'You have used your free Error Log AI Analysis for this month. Upgrade to Pro for unlimited analysis.', 'museder-restoreone' ),
                    ] );
                }
            }
        }

        // Get license tier (needed for later use)
        $tier = Museder_AI_Service::get_license_tier();

        // Get recent log content
        $log_content = Backup_Lite_Log_Handler::get_recent_log_content( 20000 );

        if ( empty( $log_content ) ) {
            wp_send_json_error( [
                'code'    => 'no_logs',
                'message' => esc_html__( 'No log files found to analyze.', 'museder-restoreone' ),
            ] );
        }

        // Prepare payload
        $payload = [
            'site_info' => [
                'home_url'      => home_url(),
                'wp_version'    => get_bloginfo( 'version' ),
                'php_version'   => PHP_VERSION,
                'plugin_version' => defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : '',
            ],
            'log_excerpt' => $log_content,
        ];

        // Check if OpenAI API key is configured
        $settings = Museder_AI_Service::get_settings();
        $api_key = $settings['openai_api_key'] ?? '';

        // Use live mode if API key is available, otherwise use demo mode
        if ( ! empty( $api_key ) ) {
            $result = Museder_AI_Service::send_request( 'log_analysis', $payload );
            $mode = 'live';
        } else {
            $result = Museder_AI_Service::demo_response( 'log_analysis', $payload );
            $mode = 'demo';
        }

        // Handle response
        if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
            // Force high risk in dev mode for testing AI Alerts (before checking alert)
            if ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE
                 && defined( 'MUSERDER_FORCE_HIGH_RISK' ) && MUSERDER_FORCE_HIGH_RISK ) {
                $result['risk_level'] = 'high';
                $result['risk']       = 'high';
            }

            // Add mode to result for storage
            $result['mode'] = $mode;

            // Store the last error log report
            Museder_AI_Service::store_last_error_log_report( $result );

            // Check for high risk and send alert if needed
            $alert = Museder_AI_Service::maybe_send_alert( 'error_log', $result );
            if ( ! empty( $alert ) ) {
                $result['alert'] = $alert;
            }

            // Update free tier last run timestamp (skip in dev mode)
            if ( $tier === 'free' && ! ( function_exists( 'backup_lite_is_developer_mode' ) && backup_lite_is_developer_mode() ) ) {
                update_option( Museder_AI_Service::LAST_ERROR_LOG_FREE_RUN_OPTION, current_time( 'timestamp' ), false );
            }

            // Log successful usage
            Museder_AI_Service::log_usage( 'log_analysis', 'success' );

            wp_send_json_success( [
                'report' => $result,
                'mode'   => $mode,
                'alert'  => $alert ?? null,
            ] );
        } else {
            // Log error usage
            Museder_AI_Service::log_usage( 'log_analysis', 'error' );

            wp_send_json_error( [
                'code'    => $result['code'] ?? 'unknown_error',
                'message' => $result['message'] ?? esc_html__( 'AI request failed.', 'museder-restoreone' ),
            ] );
        }
    }
}
