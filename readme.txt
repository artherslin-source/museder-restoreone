=== Museder RestoreOne – Backup & One-Click Restore ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 6.8
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 2.6.93
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Description ==

Museder RestoreOne lets you create complete WordPress backups (database + `wp-content`) as a single archive, and restore them in a guided 3-step wizard.

It is designed for shared hosting environments and includes safe fallbacks when `mysqldump`, `ZipArchive`, or shell functions are not available.

**Key features**

* One-click full-site backup  
  Export the database, `meta.json`, and `wp-content/` into a single archive you can download or restore later.

* Restore Center wizard  
  A clear 3-step flow: upload & analyze → review summary & options → start restore with real-time progress and logs.

* Chunked uploads with validation  
  Bypass `upload_max_filesize` / `post_max_size` limits by uploading your archive in small chunks, with retries and integrity checks.

* Shared-hosting friendly  
  Automatically falls back from `mysqldump` and `ZipArchive` to pure PHP export and PclZip compression when needed.

* Schedules and logs  
  Create at least one automatic schedule, then inspect, download, or clean up structured backup and restore logs.

* Neo-glass admin UI  
  Modern Dashboard, Backups, Restore, Schedules, Logs and Settings screens with clear calls-to-action, status messages, and responsive layout.

**External services (Pro only)**

The free (Lite) version does not contact any external APIs.

An optional Pro edition can connect to third-party services such as OpenAI to provide AI-based backup recommendations, reports, and smart scheduling. These integrations are completely opt-in and disabled by default unless configured by the site owner.

== Installation ==

1. Upload the `backup-lite` folder (or ZIP) to the `/wp-content/plugins/` directory via FTP or through the “Upload Plugin” screen in your WordPress admin.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Go to the **Museder RestoreOne** menu in your admin sidebar.
4. Open the **Backups** or **Restore** page and create your first backup.

== Frequently Asked Questions ==

= What does the backup archive contain? =

Each backup archive includes:

* `database.sql` — a full dump of your WordPress database.  
* `meta.json` — metadata about when and how the backup was created.  
* `wp-content/` — your themes, plugins, and uploads.

Together, these files are enough to recreate your site on the same or another server.

= What happens if mysqldump is not available? =

Museder RestoreOne automatically detects whether `mysqldump` is available.  
If it is not, the plugin falls back to a pure PHP export to generate the `database.sql` file. This makes the plugin suitable for shared hosting and restrictive environments.

= What if ZipArchive is not enabled on my server? =

If your server does not have the `ZipArchive` PHP extension, the plugin will automatically use WordPress’ built-in PclZip library to create and extract archives.

= Where are the logs stored? =

All logs are stored under:

`wp-content/uploads/backup-lite-logs/`

You can view or download the latest logs directly from the **Logs** page in the Museder RestoreOne admin menu.

= Does the Lite version send data to external services? =

No. The Lite version runs entirely on your server and does not send backup contents or site data to any external API or cloud service.

= Does this plugin expose my backup files publicly? =

No. Backup download and upload endpoints are protected by time-limited tokens and secret keys generated inside your WordPress site. Only users with access to your WordPress admin can generate valid links, and each link expires after a short period of time.

== Screenshots ==

1. Dashboard with environment compatibility, recent backups, and schedule overview.
2. Backups page showing available backups and the backup progress bar.
3. Restore Center 3-step wizard: upload & analyze, review options, execute restore.
4. Schedules page listing upcoming backup jobs and quick schedule builder.
5. Logs page with log file list and preview panel.
6. Settings page with general options and system diagnostics.

== Changelog ==

= 2.6.93 =
* WordPress.org compliance: Replaced all CDN references with local vendor files. Chart.js (4.4.4) and Toastify-js (1.12.0) are now loaded from plugin's assets/vendor directory instead of external CDN.
* Improved reliability: Plugin functionality no longer depends on external CDN availability, ensuring consistent performance even when CDN services are unavailable.

= 2.6.92 =
* Fixed WordPress version requirement: Changed "Requires at least" from 6.8.3 to 6.8 for better compatibility with WordPress 6.8.x installations.

= 2.6.91 =
* WordPress.org compliance improvements: Added complete plugin header information (Requires at least, Tested up to, Requires PHP, License URI) to match readme.txt standards.
* Security enhancement: Removed `sslverify => false` from wp_remote_post calls to use WordPress default SSL verification.
* Documentation: Added Security/Privacy FAQ entry explaining backup file protection mechanisms (time-limited tokens and secret keys).
* All changes maintain backward compatibility and do not modify core functionality.

= 2.6.78 =
* Added an authenticated AJAX endpoint to refresh the security nonce without reloading the page; long-running restore sessions now automatically obtain a fresh nonce when the original expires.
* Updated all AJAX permission checks to return a structured `invalid_nonce` error instead of a generic 403, enabling the frontend to auto-recover.
* Updated the restore progress poller to detect `invalid_nonce` responses, refresh the nonce, and resume polling transparently so the UI no longer stalls at 85% when the nonce times out.

= 2.6.77 =
* Added job-history fallback detection so the UI marks restore completion even if the job status response is delayed by the host.
* Added raw timestamp metadata to job status and history responses, enabling the frontend to verify that the latest success entry belongs to the current restore.
* Centralized completion handling logic (progress bar, toast, overlay) via `markRestoreCompleted()` and re-used it across all completion code paths, eliminating race conditions where the progress bar stayed at 85%.
* Improved simulated progress timeout handling by reusing the same completion helper, ensuring only one toast/overlay fires.

= 2.6.76 =
* Fixed restore completion detection: when restore job status is 'success', the progress bar now correctly updates to 100% and the completion overlay is triggered immediately.
* Fixed progress bar percentage alignment: progress percentage text is now vertically centered and aligned with the progress bar container, not offset to the left.
* Improved state management: active restore job ID is now cleared when restore completes, preventing state inconsistencies.
* Enhanced completion handling: added explicit `setProgress(100, ..., true)` call when restore completes to ensure UI updates immediately.

= 2.6.75 =
* Fixed false failure detection: PclZip may throw exceptions even when extraction succeeds. The restore process now checks if files were actually extracted before declaring failure.
* Improved exception handling: added try-catch blocks around PclZip extraction to handle exceptions gracefully and continue restore if extraction actually succeeded.
* Enhanced error recovery: if PclZip throws an exception but files were extracted, the restore process continues instead of failing immediately.
* Better logging: added detailed logging for PclZip exceptions and extraction status to help diagnose issues.
* Fixed restore completion detection: restore now correctly reports success even if PclZip throws exceptions during extraction, as long as the actual restore operations (database and files) complete successfully.

= 2.6.74 =
* Fixed restore failure display: when restore fails, the UI now correctly shows failure icon (❌), failure message, and failure overlay instead of showing success indicators.
* Improved error handling: restore failures now display proper error overlay with red styling and clear failure messaging.
* Enhanced status feedback: status icon, title, and message are now updated to reflect failure state when restore job fails.
* Fixed PclZip extraction: changed from array options format to individual parameters to avoid compatibility issues with some WordPress versions.
* Better error logging: added detailed logging when both ZipArchive and PclZip extraction methods fail.

= 2.6.73 =
* Implemented file-size-based progress bar: progress bar now estimates time based on backup file size and smoothly animates from 5% to 85%, then waits for actual job completion before reaching 100%.
* Improved progress estimation: calculates estimated duration based on file size (conservative 10-50 MB/s processing speed), providing realistic progress feedback.
* Enhanced user experience: progress bar reaches 85% based on time estimation, then waits for system notification before completing to 100% and showing success/failure overlay.
* Fixed PclZip extraction error: improved handling of PclZip extract() return values to prevent "substr(): Argument #1 must be of type string, array given" errors.
* Better error handling: added type checking for PclZip entry arrays to prevent extraction failures.

= 2.6.72 =
* Fixed progress bar UI inconsistency: when progress reaches 100% but job status is still 'running', the UI now shows "Finalizing restore..." instead of "Restore running..." to better reflect the actual state.
* Improved user experience: added visual feedback when restore is at 100% but waiting for final status update, preventing confusion about whether the restore is still running.
* Enhanced status display: status icon changes to hourglass (⏳) when at 100% but status hasn't updated yet, providing clearer visual indication.

= 2.6.71 =
* Fixed PclZip extraction error: resolved "function_exists(): Argument #1 must be of type string, array given" error by removing callback array format and filtering suspicious files after extraction instead.
* Improved PclZip compatibility: changed extract options to use array format instead of individual parameters to avoid parsing issues.
* Enhanced extraction safety: suspicious zip entries are now filtered after extraction completes, ensuring security while avoiding callback compatibility issues.
* Fixed restore failures: restore process now completes successfully without PclZip callback errors.

= 2.6.70 =
* Fixed restore job stuck at 5%: added automatic job triggering mechanism when job remains in 'pending' status.
* Added manual job trigger endpoint: new AJAX endpoint `backup_lite_trigger_restore_job` to manually start restore jobs if WordPress Cron doesn't execute immediately.
* Improved job monitoring: frontend now automatically triggers job execution if it remains pending for more than 10 seconds.
* Enhanced reliability: restore jobs now start more reliably even on hosts with disabled or slow WordPress Cron.

= 2.6.69 =
* Fixed JavaScript error in restore page: corrected undefined `percentValue` variable in `setProgress` function, replaced with `targetPercent`.
* Fixed restore page upload functionality: "Step 1 – Upload & Analyze" button now works correctly after selecting local file.
* Improved error handling: progress bar elements are now properly retrieved if not already available in scope.

= 2.6.68 =
* Improved backups page layout: moved "Environment Compatibility" card below "Available Backups" section for better visual flow.

= 2.6.67 =
* Updated download expiration time to 20 minutes as requested.

= 2.6.66 =
* Extended download expiration time from 15 minutes to 10 minutes as requested.
* Improved download expiration handling: when download link expires, shows friendly Chinese message "您的下載已過期，請從備份庫下載" (Your download has expired, please download from backup library).
* Enhanced frontend validation: download button now checks expiration before allowing download, preventing expired link clicks.
* Better user experience: expired download links automatically redirect users to backups page.

= 2.6.65 =
* Fixed download signature validation error: download handler now loads WordPress to get correct storage paths and secret file location.
* Improved download expiration check: added 30-second grace period to handle clock skew and network delays.
* Enhanced path resolution: download handler now uses WordPress functions to determine correct backup directory and secret file paths, with fallback to hardcoded paths if WordPress is unavailable.
* Fixed "Download signature invalid or expired" error when downloading backup files.

= 2.6.64 =
* Fixed progress bar stuck at 68%: progress bar now correctly reaches 100% when restore completes.
* Fixed completion overlay not showing: completion overlay now appears reliably when restore finishes.
* Improved progress animation: when progress reaches 100% or status is 'success', progress updates immediately without animation delay.
* Enhanced completion detection: reduced timeout from 10 seconds to 5 seconds for faster completion feedback when progress is 100%.
* Progress bar now always shows 100% when restore completes, ensuring users see the final state.

= 2.6.63 =
* Improved restore progress bar UX: progress bar now animates smoothly instead of jumping between values.
* Added smooth progress transitions: when progress updates, the bar smoothly animates from current value to target value over 800ms with ease-out cubic easing.
* Progress bar no longer jumps from 5% to 53% instantly - it now smoothly transitions, providing better visual feedback.
* Enhanced user experience: progress updates feel more natural and responsive, even when backend reports large progress jumps.

= 2.6.62 =
* Fixed Step 3 card incorrectly showing as completed after Step 1: Step 3 card now only shows as completed after actual restore execution finishes, not after file analysis.
* Fixed Step 3 info area showing "Restore Completed" after Step 1: completion status is now only displayed when restore operation actually completes, not during file analysis.
* Improved state management: restoreCompleted flag is now only set to true after Step 3 (restore execution) completes, ensuring proper wizard state progression.

= 2.6.61 =
* Fixed restore progress bar stuck at 68%: progress bar now correctly updates to 100% and shows completion overlay when restore finishes.
* Improved job status reporting: when restore completes, status is immediately set to 'success' in the job metadata, ensuring frontend can detect completion.
* Enhanced frontend polling logic: when progress reaches 100%, the UI now waits up to 10 seconds (reduced from 1 minute) for status update before assuming completion, providing faster feedback.
* Progress bar now always displays 100% when backend reports 100% progress, even if status update is slightly delayed.

= 2.6.60 =
* Improved plugin status restoration: now extracts active_plugins from SQL file before database import, preventing issues where All-in-One WP Migration may have deactivated plugins during backup.
* Added extract_active_plugins_from_sql() method to read plugin activation status directly from the SQL dump file.
* Plugin status is now preserved even if the backup process temporarily deactivated plugins, ensuring accurate restoration of the original site's plugin configuration.

= 2.6.59 =
* Fixed restore state management: restore progress state is now properly maintained during restore operations.
* Prevented restore state from being incorrectly reset when other operations (like file analysis) are performed.
* Improved state preservation: restoreInProgress flag is now protected during active restore jobs, preventing the "Ready to start restore" state from appearing while restore is in progress.
* Fixed button state: "Step 3 – Start Restore" button now correctly remains disabled during active restore operations.

= 2.6.58 =
* Fixed restore wizard step 3 logic: step 3 card now only shows as unlocked/ready after step 2 (review) is completed, not just after step 1.
* Improved wizard state management: step 3 remains locked until user completes step 2, preventing confusion about which steps are actually completed.

= 2.6.57 =
* Fixed plugin activation status restoration: plugins are now correctly activated/deactivated according to the original backup state after restore.
* Added plugin status restoration to the main restore flow (class-restore-handler.php) to ensure it executes after database and file restoration.
* Improved plugin status restoration logic: preserves original plugin activation order from backup and clears WordPress plugin cache.
* Enhanced logging: plugin restoration now logs the number of restored, missing, and previously active plugins for better debugging.

= 2.6.56 =
* Improved restore progress bar: progress now updates smoothly from 45% to 100% instead of staying at 45% until completion.
* Added detailed progress reporting throughout the restore process:
  * 45%: Extracting backup archive
  * 50-65%: Importing database (with dynamic updates during import)
  * 70%: Restoring files from backup
  * 85%: Applying URL search & replace (if enabled)
  * 90%: Cleaning up temporary files
  * 95%: Finalizing restore
  * 100%: Restore completed
* Database import now reports progress based on file read position, providing real-time feedback during long-running imports.
* Users can now see the actual progress of restore operations instead of waiting with no visual feedback.

= 2.6.55 =
* Fixed error message when cancelling restore: improved error handling in cancel operations to prevent "An unexpected error occurred" messages.
* Added output buffer cleanup in cancel handlers to ensure clean JSON responses.
* Enhanced error handling: cancel operations now gracefully handle exceptions and provide clear error messages to users.
* UI state is now properly reset even if cancel request encounters an error, preventing stuck states.

= 2.6.54 =
* Fixed restore progress bar not reaching 100%: progress bar now correctly displays 100% when restore completes, even if status update is delayed.
* Improved progress polling logic: when progress reaches 100%, the UI continues polling for up to 1 minute to catch the final status update, ensuring completion is properly detected.
* Added timeout protection: restore job polling now has a 5-minute overall timeout and a 1-minute timeout after reaching 100% progress to prevent infinite polling.
* Users no longer need to manually refresh the browser to see restore completion - the progress bar and completion message appear automatically.

= 2.6.53 =
* Added automatic plugin activation status restoration: after restore, plugins are automatically activated/deactivated according to the original site state from the backup.
* Only plugins that exist in wp-content/plugins are activated, missing plugins are logged but skipped.
* This ensures the restored site matches the original site's plugin configuration without manual intervention.

= 2.6.52 =
* Fixed timezone display issue: all timestamps in restore history and job status now use WordPress date/time format and timezone settings.
* Restore history timestamps are now formatted according to the site's date and time format preferences.
* Job status timestamps (created_at, started_at, finished_at) are now displayed in the correct local timezone.

= 2.6.51 =
* Optimized SERVMASK prefix normalization for large backup files (1GB+): uses 10MB chunks instead of 1MB for better performance on massive SQL dumps.
* Increased overlap buffer to 3x placeholder length (51 bytes) for large files to ensure no cross-chunk placeholder is missed.
* Large files (1GB+) now automatically perform a second normalization pass to guarantee 100% replacement of all `SERVMASK_PREFIX_` tokens.
* Improved streaming algorithm with proper buffer management to handle multi-gigabyte SQL files without memory issues.
* This ensures reliable restoration of very large WordPress sites backed up with All-in-One WP Migration.

= 2.6.50 =
* Enhanced SERVMASK prefix normalization: increased overlap buffer to 2x placeholder length (34 bytes) to ensure cross-chunk replacements are never missed.
* Added verification pass: after normalization, the plugin now checks if any `SERVMASK_PREFIX_` remains and performs a second pass if needed.
* For large SQL files (>50MB), a second normalization pass is automatically performed to catch any edge cases.
* This fixes database import failures where `SERVMASK_PREFIX_postmeta` and other placeholder tables were not properly replaced, causing "Table doesn't exist" errors.

= 2.6.49 =
* Fixed the SERVMASK prefix normalizer so it now processes multi-gigabyte SQL dumps safely, even when the placeholder token lands on a chunk boundary. All remaining `SERVMASK_PREFIX_` references are replaced before import.
* Added overlap-aware streaming so very large SQL files are rewritten without loading the entire archive into memory.

= 2.6.48 =
* Detects All-in-One WP Migration (ServMask) database dumps and rewrites every `SERVMASK_PREFIX_` token to the current WordPress table prefix before import, guaranteeing that the real tables are overwritten.
* Automatically drops any leftover `SERVMASK_PREFIX_%` tables prior to restore so failed attempts no longer pollute the database and future restores start from a clean slate.

= 2.6.47 =
* Restore Center now enqueues restores as background jobs instead of running Step 3 over a single long `admin-ajax.php` request. This prevents hosts, proxies, or browser extensions from killing the connection mid-restore.
* Added new AJAX endpoints to create, poll, and cancel restore jobs; the UI now auto-resumes progress if you reload the page while a job is running.
* Localized data now exposes the last summary/progress/history so previously analyzed backups remain visible after reload.

= 2.6.46 =
* Moved header/output cleanup inside each REST request so normal admin pages never touch HTTP headers—this eliminates remaining “Cannot modify header information” warnings and the resulting `Unexpected token '<'` errors during Step 3.
* Added per-request safeguards so finalize/abort routes also return pure JSON even if other plugins echo content earlier.

= 2.6.45 =
* Fixed restore step 3 returning to the start when other plugins have already sent output—header cleanup now runs only before any headers are sent, eliminating the PHP warnings that polluted the JSON response.
* Added extra output buffering cleanup so chunk uploads and restore steps always return valid JSON, even on hosts that inject notices into requests.

= 2.6.44 =
* Fixed file-restore detection so archives that store `wp-content` under an extra folder (e.g. `restore-package/wp-content`) are now properly restored.
* Fallback detection now scans recursively for `plugins/`, `themes/`, `uploads/`, and `mu-plugins/` folders instead of only checking the archive root.
* Added detailed logging when no `wp-content` data is found, listing the top-level folders inside the extracted archive to simplify debugging.

= 2.6.43 =
* Fixed “Select from Backups” showing an empty list when backups use the new domain-based filename format—backup discovery now scans all `.zip`/`.wpress` archives in the backups folder.
* The Restore page now refreshes the backups list automatically on load and surfaces AJAX errors instead of failing silently.

= 2.6.42 =
* Fixed critical plugin activation error: simplified activation hook to only perform minimal operations, deferring all initialization to plugins_loaded hook.
* Moved PRO feature file loading from plugin file loading to plugins_loaded hook to prevent errors when get_option() is not available during activation.
* Activation hook now only schedules cron and sets initialization flag, all directory creation and class initialization happens after WordPress is fully loaded.

= 2.6.41 =
* Fixed plugin activation error: added comprehensive error handling in activation hook to prevent fatal errors during plugin activation.
* Improved schedule handler robustness: added error handling and type checking in synchronise_cron_events() and normalise_schedule() methods.
* Enhanced activation safety: all class and function calls in activation hook now include existence checks.

= 2.6.40 =
* Enhanced "Select from Backups" feature: backup list is now dynamically loaded when clicking the button, ensuring all backups in the backups folder are displayed.
* Improved backup file naming: new format uses "domain-YYYYMMDDHHmmss-randomcode.zip" (网址+西元年月日+时分+乱数编码) for better organization and identification.
* Added AJAX endpoint to fetch backup list dynamically for restore page.
* Updated backup list detection to support both old and new naming formats for backward compatibility.

= 2.6.39 =
* Fixed incomplete URL search-replace during restore: now properly handles all database tables and text fields, including serialized data (arrays and objects).
* Added post-restore cleanup: automatically clears WordPress object cache, transients, and refreshes permalink rules after restore completes.
* Improved site URL validation: ensures siteurl and home options are correctly set based on current server configuration after restore.
* This fixes the issue where restored sites would not display the correct content after clicking "Got It" on the restore completion dialog.

= 2.6.38 =
* Maintenance release: bumps the version number and regenerates the distribution package so the signed-download fix and hardening updates are reflected in the latest ZIP.

= 2.6.37 =
* The “Download backup” button now calls the secure `download-handler.php` endpoint with signed URLs, preventing 404s on hosts that block direct access to `uploads/museder-restoreone`.
* Restore Center’s “Execute Restore” step now runs with `ignore_user_abort()`, higher memory limits, and full try/catch protection so MailPoet/other plugin errors are caught and surfaced instead of killing the AJAX request.
* Added clearer error messaging and logging when another plugin (notably MailPoet) interrupts the restore process.

= 2.6.36 =
* Completed WordPress-standard internationalization across the entire plugin (PHP templates, JS strings, menus, notices, etc.).
* Added a `languages/` directory plus the base `museder-restoreone.pot` file so translators can generate `.po/.mo` files.
* Updated the Backups page title to “Backups Center” and refined various in-app messages so they respect the site locale.

= 2.6.35 =
* Backup progress bar text now renders in white on the blue bar and the bar height has been increased for clearer visibility.

= 2.6.21 =
* First public release on WordPress.org.
* Includes full-site backup & restore, chunked uploads, schedules, logs, and Neo-glass admin UI.

(Older versions were internal pre-release builds and are not listed here.)

== Upgrade Notice ==

= 2.6.49 =
Critical fix for ServMask/AIO backups: normalization now works for very large SQL files and no longer leaves stray `SERVMASK_PREFIX_` entries when the placeholder crosses chunk boundaries. Update before re-running restores from All-in-One WP Migration exports.

= 2.6.48 =
Critical fix for ServMask/AIO backups: restores now replace the placeholder `SERVMASK_PREFIX_` with your real table prefix and remove orphaned tables automatically. Update before running another restore from All-in-One WP Migration exports.

= 2.6.90 =
Critical fix: corrected Restore History timestamp storage and parsing. Now stores UTC Unix timestamp (time()) instead of local time (current_time('mysql')). Timestamp parsing correctly handles both new UTC entries and old local time entries by converting them to UTC before displaying. This ensures Restore History timestamps match Log Files page timestamps and correctly align with WordPress local timezone. Update immediately if Restore History timestamps are still incorrect.

= 2.6.89 =
Critical fix: fixed Restore History timestamp display to correctly convert UTC timestamps to local timezone. Now stores both MySQL datetime and Unix timestamp (UTC) for accurate timezone conversion. Timestamp parsing now correctly handles UTC timestamps and converts them to WordPress local timezone using backup_lite_local_time(). UI cleanup: removed PRO feature cards (AI Backup Copilot, AI Settings, Pro Modules, License) from Settings page to reduce clutter and avoid confusion. Update immediately if Restore History timestamps are incorrect.

= 2.6.88 =
UI improvements: fixed Restore History timestamp formatting to correctly align with user's local timezone (falls back to UTC if WordPress timezone is not set). Added CSS and JavaScript to hide other plugins' admin notices on Scheduled Backups page to prevent user confusion. Moved Site Backup Health Score (Pro) card to last position on Dashboard page since it's a PRO feature and appears grayed out. Update for better user experience and UI clarity.

= 2.6.87 =
Critical fix: fixed update_job_progress() to automatically set finished_at timestamp when status is set to success or failed, ensuring frontend can correctly detect completion. Enhanced copy_directory() with comprehensive error handling, permission checks, and partial success tolerance. Improved backup_lite_ensure_directory() to return success/failure status. Enhanced failure detection in checkRestoreCompletionFromHistory() to show failure overlay with proper progress display. Fixed all failure paths to show failure overlay and ensure teardown() properly resets progress and reloads page. Update immediately if restore fails or completion windows don't appear correctly.

= 2.6.86 =
Critical fix: improved restore error handling to validate restore result before checking success status. Enhanced error logging to include error codes and full restore result for better diagnostics. Fixed report_job_progress() to explicitly pass 'success' status when restore completes successfully, ensuring consistent state management. Added validation to ensure restore result is always a valid array before processing. Update immediately if restore fails halfway through step 3 or if error messages are unclear.

= 2.6.85 =
Critical fix: improved markRestoreCompleted() to verify completion overlay is actually visible in DOM before skipping display. Enhanced completion detection to reset restoreCompletionShown flag if overlay is not found, ensuring completion window always appears even if previous attempt failed. Added overlay verification with retry mechanism to handle cases where overlay creation fails. Improved all completion detection paths to check for overlay visibility before skipping display. Update immediately if restore completes successfully but completion window does not appear.

= 2.6.84 =
Critical fix: improved job_status() to handle nonce expiration gracefully during long restore operations. Enhanced error handling to allow status checks to continue even when nonce expires, preventing 400 errors from interrupting restore monitoring. Improved report_job_progress() to accept explicit status parameter, ensuring failed restores correctly set job status to 'failed'. Enhanced copy_directory() error logging with detailed file permission diagnostics. Frontend now handles nonce_expired flag in job status responses and automatically refreshes nonce. Update immediately if restore fails with 400 errors or if failure status is not detected correctly.

= 2.6.83 =
Critical fix: improved page load state detection to properly reset progress bar when no active restore job exists. Enhanced error handling to always check completion status from history even when AJAX requests fail. Added failure detection in checkRestoreCompletionFromHistory() to handle failed restores. Improved state reset logic to prevent progress bar from staying at 100% after page reload. Added timeout-based state reset (2 minutes) when all status checks fail to prevent stuck progress. Update immediately if progress bar stays at 100% after page reload or if completion window doesn't appear after restore completes.

= 2.6.82 =
Fix: added resetRestoreProgress() function to properly reset progress bar to 0% when completion overlay is closed. Improved teardown() function to call reset function before page reload. Added WordPress heartbeat API integration to keep session alive during long restore operations, reducing the frequency of re-login popups. The heartbeat runs every 30 seconds during restore to prevent session expiration. Update if you see progress bar stuck at 100% after closing completion window, or if re-login popups appear too frequently during restore.

= 2.6.81 =
Critical fix: improved nonce expiration handling during restore. Enhanced error detection to handle 400/403 errors and nonces_expired responses. Added checkRestoreCompletionFromHistory() fallback function to detect completion even when AJAX requests fail due to nonce expiration. Improved page load detection to mark restore as completed if job is already finished (e.g., after re-login). Added multiple completion check points (at 100% progress, after nonce refresh, during polling). Update immediately if restore completes but progress bar stays at 85% or completion window doesn't appear after re-login.

= 2.6.80 =
Critical fix: fixed JavaScript ReferenceError (isComplete is not defined) that prevented restore from completing. Fixed 409 Conflict error by improving job state cleanup and stale job detection. Enhanced enqueue_restore_job to automatically mark stale jobs (older than 6 hours) as failed. Improved markRestoreCompleted to clear activeRestoreJobId, allowing new restores to start. Update immediately if you see "isComplete is not defined" errors or "Another restore is already in progress" messages.

= 2.6.79 =
Critical fix: improved restore completion detection and overlay display. Fixed issue where progress bar and completion window would not appear after restore completes. Enhanced markRestoreCompleted logic with better error handling and debugging logs. Improved status detection from job history and progress-based fallbacks. Update immediately if restore completes but progress bar stays at 85% or completion window doesn't appear.

= 2.6.78 =
Added job-history fallback detection so the UI marks restore completion even if the job status response is delayed by the host. Added raw timestamp metadata to job status and history responses, enabling the frontend to verify that the latest success entry belongs to the current restore. Centralized completion handling logic (progress bar, toast, overlay) via markRestoreCompleted() and re-used it across all completion code paths, eliminating race conditions where the progress bar stayed at 85%. Improved simulated progress timeout handling by reusing the same completion helper, ensuring only one toast/overlay fires.

Restore Step 3 now runs asynchronously and survives page reloads. Update immediately if admin-ajax requests were timing out or if you want the restore UI to reconnect to an in-progress job after leaving the page.

= 2.6.46 =
Critical fix: completely removes the last header warnings that were still breaking Step 3. Update immediately if you still see `Unexpected token '<'` or “waiting for action…” that never completes.

= 2.6.45 =
Critical fix: resolves “Restore error: Unexpected token '<'” by preventing PHP header warnings from corrupting AJAX/REST responses. Update immediately if Step 3 never finishes or returns to the beginning.

= 2.6.44 =
Critical fix: restores now correctly detect `wp-content` even when archives contain an extra root folder. Update immediately if your restores only applied the database but not files.

= 2.6.42 =
Critical fix: completely redesigned activation process to prevent fatal errors. Activation hook now performs minimal operations and defers all initialization until WordPress is fully loaded. This should resolve all activation issues. All users experiencing activation problems must update immediately.

= 2.6.41 =
Critical fix: resolves plugin activation errors that prevented the plugin from being enabled. All users experiencing activation issues should update immediately.

= 2.6.40 =
Enhanced restore page with dynamic backup loading and improved backup file naming. The "Select from Backups" feature now automatically refreshes the backup list, and new backups use a more descriptive naming format. Recommended update.

= 2.6.39 =
Critical fix for restore functionality: URL replacement now works correctly for all database fields, and post-restore cleanup ensures the site displays correctly after restore. Recommended update for all users.

= 2.6.38 =
Packaging refresh so the latest download/restore fixes are present in the official ZIP. Update if you previously downloaded 2.6.36/2.6.37 directly from Git without the signed link fix.
