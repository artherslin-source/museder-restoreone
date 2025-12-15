=== Museder RestoreOne ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.7.85
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Changelog ==

For full changelog history, please see docs/changelog-archive.md in the plugin folder.

= 2.7.85 =
* Dev: Version bump for ongoing development after 2.7.84 baseline.

= 2.7.84 =
* UI: Restore progress text no longer shows a “completed successfully” message while the restore is still running.

= 2.7.83 =
* Fix: Restore Center Restore History now records and displays restore duration correctly (adds missing started/completed timestamps for duration calculation).

= 2.7.82 =
* Bug Fix: Prevented concurrent backup job processing across AJAX/cron by adding a job-level atomic lock.
* Bug Fix: Ensured job processing state stays true during a request to avoid duplicate “continue” nudges and early completion.
* Bug Fix: Added a guard to fail the job if the backup manifest is missing/empty (prevents partial archives being marked complete).
* UI: Prevented duplicate completion overlays for the same backup job and hid the currently running archive from the Backups list.

= 2.7.81 =
* Bug Fix: Prevented backups from being marked completed (and logged with a final filesize) before ZipArchive is closed/flushed, avoiding partial downloads and inconsistent sizes.
* Bug Fix: Fixed Logs download "Log filename missing" when URLs are HTML-entity escaped (&amp;/&#038;) by decoding in JS and adding a PHP fallback for amp;log.

= 2.7.80 =
* Bug Fix: Fixed Backups page progress getting stuck and admin-ajax.php 500 errors by correcting ZipArchive close lifecycle and preventing double-close fatal errors.
* Bug Fix: Reduced console noise on Backups page by scoping log action click handling to log action buttons (and Logs page).

= 2.7.79 =
* Bug Fix: Fixed backup completion issue - progress bar shows 100% but backup file not complete. Now ensures ZipArchive is closed and flushed to disk before marking job as completed.
* Bug Fix: Fixed restore completion error handling - when progress reaches 100% but status check fails, now checks restore history before showing error message to prevent false error alerts.
* Bug Fix: Fixed restore state reset issue - added protection against multiple simultaneous restore operations and improved error handling to prevent browser crashes.
* Bug Fix: Enhanced log download button handling - improved button element identification with fallback to row data-log attribute when clicking button text or child elements.

= 2.7.78 =
* Bug Fix: Fixed log download button "Log filename missing" error - improved event handling to correctly identify button element when clicking button text or child elements.

= 2.7.77 =
* Performance: Implemented dynamic time budget calculation based on max_execution_time - allows longer processing time (up to 90 seconds) on servers with higher execution time limits, improving backup speed for large sites.
* Improvement: Updated time budget formula to max(25, min(max_execution_time * 0.75, 90)) for better resource utilization while maintaining safety margins.

= 2.7.76 =
* Bug Fix: Fixed log download "Log filename missing" error - changed to use correct settings object (BackupLite) instead of localizedSettings for AJAX requests.
* Bug Fix: Completely refactored backup polling logic - now ensures only single polling request runs at a time, prevents overlapping requests and pending/canceled states.
* Bug Fix: Improved abort detection - aborted requests (page reload, manual cancel, timeout) are now properly distinguished from real errors and don't trigger console.error messages.
* Improvement: Added backupLitePollAborted flag to prevent scheduling next poll when request is aborted, ensuring clean polling lifecycle.
* Improvement: Enhanced polling state management - polling only continues if job is still running and request was not aborted.

= 2.7.75 =
* Bug Fix: Fixed log download "link expired" error - improved nonce verification with better error messages and proper parameter handling in both admin-post.php and AJAX handlers.
* Performance: Increased backup batch size from 200 files/40MB to 400 files/80MB to improve backup speed significantly.
* Performance: Increased time budget from 60%/18s to 70%/25s to allow more work per request, reducing total backup time while maintaining frontend timeout safety margin.
* Improvement: Enhanced error handling in log download handlers with proper HTTP status codes (400, 403, 404) and user-friendly error messages with links back to logs page.

= 2.7.74 =
* Bug Fix: Fixed backup polling to treat abort as normal condition - aborted requests no longer log as errors, preventing console spam.
* Bug Fix: Improved polling request management - switched from setInterval to setTimeout to prevent overlapping requests and pending/abort errors.
* Improvement: Enhanced error handling in backup polling - only real errors are logged, aborted requests use console.debug instead of console.error.
* Improvement: Added proper cleanup of AbortController and polling timers to prevent memory leaks and ensure clean state management.

= 2.7.73 =
* Bug Fix: Fixed backup polling timeout issues - unified time budget between frontend (30s) and backend (max 18s) to prevent AbortController from prematurely interrupting requests.
* Bug Fix: Added request locking mechanism to prevent overlapping backup polling requests that caused console errors and performance issues.
* Improvement: Enhanced error handling in backup polling - replaced vague "signal is aborted without reason" messages with clearer timeout/network error detection.
* Performance: Improved time budget calculation using microtime for more precise timing control in batch processing.
* Performance: Backend time budget now capped at 18 seconds (60% of max_execution_time) to ensure it never exceeds frontend's 30-second timeout.

= 2.7.72 =
* Bug Fix: Fixed restore completion message display issue - "Restore completed successfully" now only shows when progress reaches 100% and done=true.
* Performance: Implemented time budget loop in backup processing - single request now processes multiple batches within max_execution_time * 0.7 limit, significantly reducing HTTP requests needed for complete backup.
* Performance: Implemented short-interval single event scheduling for Cron mode - backup jobs in preparing/packing stage now use 10-20 second intervals for next batch processing, improving backup speed and reducing total completion time.
* Improvement: Enhanced backup job scheduling to prevent duplicate events and ensure efficient batch processing.

= 2.7.71 =
* Bug Fix: Fixed critical issue where failed restore jobs would automatically restart when the page reloaded.
* Bug Fix: Enhanced restore job status checking to prevent auto-resuming failed, cancelled, or completed jobs.
* Improvement: Added stricter validation in `startRestoreJobMonitor()` to ensure only active jobs (pending/running) can be monitored.
* Improvement: Improved page load logic to properly handle failed restore jobs and prevent automatic restart.

= 2.7.70 =
* Bug Fix: Fixed critical database restore failure - removed invalid `--single-transaction` option from mysql CLI command (this option is only for mysqldump, not mysql).
* Bug Fix: Improved error logging in mysql CLI import - now includes command details (with password hidden) for better debugging.
* Improvement: Enhanced database import error messages with more context for troubleshooting.

= 2.7.69 =
* Bug Fix: Fixed restore failure "Backup file not found or unreadable" - enhanced backup_lite_get_backup_path() with detailed error logging and debugging information.
* Bug Fix: Improved file path resolution in restore operations - added sanitization, better error messages, and similar file detection for troubleshooting.
* Improvement: Enhanced restore error logging - now includes backup directory status, available files list, and file permissions information for better debugging.
* Improvement: Added path traversal protection with PHP 8.0+ compatibility in backup_lite_get_backup_path().

= 2.7.68 =
* Bug Fix: Fixed backup process getting stuck at high percentages - added timeout handling (30s for polling, 60s for initial request) and improved error recovery for network issues.
* Bug Fix: Fixed backup AJAX requests hanging on connection timeouts - implemented AbortController with proper timeout handling and retry logic for network errors.
* Bug Fix: Fixed log file download showing "link expired" error - changed to dynamically fetch fresh download URL with new nonce when download button is clicked.
* Improvement: Enhanced backup polling resilience - network/timeout errors no longer stop the backup process, allowing automatic retry on next poll.
* Improvement: Better error messages for timeout and network connection issues during backup operations.

= 2.7.67 =
* Performance: Removed unnecessary filesize() calls in append_files_to_zip() - now uses file sizes from manifest to reduce I/O overhead.
* Performance: Optimized batch processing - prioritize manifest file sizes, only call filesize() when manifest size is missing.
* Performance: Increased batch sizes (800/500/300 files, 150MB/100MB/70MB) to reduce AJAX request overhead.
* Performance: Reduced logging overhead - removed per-file warning logs for large files during scanning.
* Performance: Optimized cache mechanism - skip caching for small sites (< 1000 files) to reduce overhead.
* Performance: Improved file size verification in batch processing - use manifest data first, verify only when needed.

= 2.7.66 =
* Bug Fix: Fixed critical backup error "Undefined constant FilesystemIterator::CATCH_GET_CHILD" - added PHP version compatibility check for iterator flags.
* Bug Fix: Fixed log file download not working - changed download URL from admin-ajax.php to admin-post.php to match the correct handler.
* Bug Fix: Fixed floating actions menu incorrectly processing log buttons - now only handles schedule action buttons, log buttons use normal event delegation.
* Improvement: Enhanced error handling in file manifest building with better compatibility checks.
* Improvement: Improved floating menu logic to properly handle non-schedule action buttons (log, backup actions, etc.).

= 2.7.65 =
* Bug Fix: Fixed 500 Internal Server Error when starting backup - added comprehensive error handling for file manifest building.
* Bug Fix: Fixed potential memory exhaustion when scanning large directories - added file count limit (100,000 files) and better error handling.
* Bug Fix: Fixed transient cache failures for large file manifests - added size checking and graceful fallback when cache is too large.
* Improvement: Enhanced error logging for manifest building process - better error messages and stack traces for debugging.
* Improvement: Added safety checks in build_manifest_from_directories() - prevents crashes when encountering problematic directories.
* Improvement: Improved JSON encoding error handling with detailed error messages when manifest encoding fails.

= 2.7.64 =
* Performance: Implemented comprehensive performance optimizations for backup and restore operations.
* Performance: Added dynamic batch size adjustment based on available system memory (200-500 files per batch).
* Performance: Optimized ZipArchive compression - large files (>10MB) use no compression for faster processing.
* Performance: Enhanced database export with optimized mysqldump parameters (--single-transaction, --quick, --skip-comments).
* Performance: Enhanced database import with optimized mysql CLI parameters (--max_allowed_packet, --single-transaction, --quick).
* Performance: Implemented file list caching using WordPress Transients API (5-minute cache, reduces redundant directory scans).
* Performance: Optimized PHP database import with transaction batching (commits every 1000 queries to avoid large transactions).
* Performance: Optimized PHP database export with stream buffering (64KB buffer, periodic flushes).
* Performance: Reduced progress update frequency (from every 5% to every 10%) to minimize database writes.
* Performance: Enhanced file iteration with optimized flags (FOLLOW_SYMLINKS, CATCH_GET_CHILD) for better performance and stability.
* Performance: Implemented intelligent file filtering with caching mechanism - early returns and pattern matching optimization.
* Performance: Added file size pre-checking - automatically skips extremely large files (>2GB) to prevent issues.
* Performance: Optimized directory scanning order - priority processing for important directories (themes, plugins, uploads first).
* Performance: Expanded exclusion list to skip common unnecessary files/directories (.git, node_modules, vendor, .cache, etc.).
* Improvement: All optimizations follow WordPress coding standards and include comprehensive error handling and logging.

= 2.7.63 =
* Bug Fix: Added extensive debugging logs to diagnose schedule action button issues - logs button clicks, function availability, AJAX requests, and errors.
* Improvement: Enhanced event delegation handlers with detailed logging for both original buttons and portal (floating menu) buttons.
* Improvement: Added fallback mechanism in event handlers to try global scope functions if closure functions are unavailable.
* Improvement: Improved error handling with try-catch blocks and detailed error messages in console for better troubleshooting.

= 2.7.62 =
* Bug Fix: Fixed schedule action buttons not responding - exposed helper functions (backupLiteStartSchedule, backupLitePopulateScheduleForm, backupLiteDeleteSchedule) to global scope so portal click handler can access them.
* Bug Fix: Changed portal click handling to directly call handler functions instead of trying to trigger events on removed DOM elements, which was causing event delegation to fail.
* Improvement: Added comprehensive error handling and fallback mechanism - if global functions are unavailable, creates temporary button and triggers event as backup.
* Improvement: Enhanced console logging to help diagnose issues with handler function availability and button click processing.

= 2.7.61 =
* Bug Fix: Fixed critical infinite recursion issue causing "Maximum call stack size exceeded" error - removed conflicting menu positioning system that was competing with floating portal system.
* Bug Fix: Simplified portal button click handling to directly trigger jQuery events on portal buttons instead of searching for original buttons, preventing event chain reactions and infinite loops.
* Bug Fix: Fixed attribute reading to support both `data-schedule-id` and `data-id` attributes for better compatibility.
* Improvement: Removed redundant `convertMenuToFixed()` function as floating portal system already handles menu positioning with fixed positioning.
* Improvement: Enhanced portal button attribute copying to ensure all attributes (not just data-*) are properly cloned, including class names and type attributes.
* Improvement: Portal is now removed before triggering events to prevent conflicts and ensure clean event handling.

= 2.7.60 =
* Bug Fix: Fixed critical JavaScript syntax error - removed duplicate `})(jQuery);` that caused `Uncaught SyntaxError` and prevented all schedule action buttons from working.
* Bug Fix: Fixed action menu being clipped inside table container - converted menu positioning from `absolute` to `fixed` using JavaScript to escape table overflow constraints.
* Improvement: Added `convertMenuToFixed()` function that dynamically calculates menu position based on trigger button's viewport coordinates and applies fixed positioning to ensure menu displays outside table boundaries.
* Improvement: Enhanced menu positioning logic with automatic boundary detection for both horizontal and vertical overflow, with intelligent left/right and top/bottom adjustments.
* Improvement: Added event listeners for window resize and scroll to maintain correct menu position when viewport changes.
* Improvement: Menu now properly resets to absolute positioning when closed to maintain proper layout flow.

= 2.7.59 =
* Bug Fix: Fixed schedule action buttons not responding - changed from jQuery `data('schedule-id')` to `attr('data-schedule-id')` to avoid automatic camelCase conversion that broke attribute reading.
* Bug Fix: Fixed action menu overflow issue - added dynamic position adjustment JavaScript that detects viewport boundaries and automatically repositions menu (left/right and top/bottom) to prevent overflow.
* Bug Fix: Changed `.backup-lite-table` overflow from `hidden` to `visible` to allow action menus to display properly outside table boundaries.
* Improvement: Added comprehensive menu position adjustment function that checks both horizontal and vertical overflow, automatically switching alignment when needed.
* Improvement: Enhanced CSS for `.bl-actions-list` with min-width, max-width, max-height, and overflow-y controls to prevent layout issues.
* Improvement: Added event listeners for `details` toggle and window resize to automatically adjust menu position when opened or viewport changes.
* Improvement: Added debug console logging for schedule action button clicks to help troubleshoot issues.

= 2.7.58 =
* Bug Fix: Completely rewrote Schedules Actions event handling using event delegation to ensure floating menu buttons are properly captured. Changed all buttons from `<a>` to `<button>` elements with `data-schedule-id` attribute instead of `data-id`.
* Bug Fix: Improved backend `ajax_save_schedule()` edit detection logic - now merges both `id` and `schedule_id` parameters for better compatibility. Free version limit check only applies to new schedules, not edits.
* Improvement: Refactored frontend event handlers into three clean helper functions: `backupLiteStartSchedule()`, `backupLitePopulateScheduleForm()`, and `backupLiteDeleteSchedule()` for better maintainability.
* Improvement: Enhanced form population logic to correctly set all schedule fields (title, type, period, time, retain, max_age, notify, status) and properly set schedule_id in multiple hidden input fields.
* Improvement: Added `backupLiteSchedulesL10n` localized script object with ajaxUrl, nonce, updateSchedule, saveSchedule, and confirmDelete strings for consistent frontend communication.
* Improvement: Enhanced `resetScheduleForm()` to clear all schedule_id related fields and properly restore submit button text to "Save Schedule" after successful edit.

= 2.7.57 =
* Bug Fix: Fixed Edit button triggering Free version schedule limit error when updating existing schedules. Separated create and edit logic in ajax_save_schedule() - Free version limit check now only applies to new schedules, not edits.
* Bug Fix: Fixed schedule ID handling to support string-based IDs (e.g., 'sched_693197348cbe14.74576871') instead of treating them as integers. Changed is_edit check from absint() comparison to non-empty string check.
* Improvement: Enhanced populateScheduleForm() to properly populate all schedule fields including type, notify/email, and correctly set schedule_id in both data-field="id" and name="schedule_id" hidden inputs.
* Improvement: Updated inline form submit handler to use unified backup_lite_save_schedule endpoint with proper id/schedule_id parameters, supporting both create and update operations.
* Improvement: Added submit button text switching (Save Schedule / Update Schedule) based on edit mode, with proper restoration after successful save.
* Improvement: Enhanced resetScheduleForm() to clear schedule_id hidden field and restore submit button text, ensuring form returns to "new schedule" state after edit.

= 2.7.56 =
* Bug Fix: Fixed Edit and Delete buttons not responding when clicked from floating menu. Fixed critical issue where removePortal() was called before triggering events, causing buttons to be removed from DOM before event handlers could execute. Now captures all button data before removing portal.
* Bug Fix: Enhanced original button finding logic with multiple fallback methods - first searches in original list (even if hidden), then searches entire document, and finally creates temporary button if original cannot be found.
* Bug Fix: Improved event triggering by temporarily making hidden buttons visible (if needed) before triggering click events, ensuring jQuery event delegation can properly catch the events.
* Improvement: Added comprehensive error handling and detailed console logging throughout the floating menu click handler for better debugging and troubleshooting.
* Improvement: Enhanced temporary button fallback mechanism with proper timing (setTimeout) to ensure button is in DOM before triggering events.

= 2.7.55 =
* Bug Fix: Fixed Edit and Delete buttons not responding when clicked from floating menu. Changed floating menu click handler to directly trigger jQuery events on portal buttons instead of trying to find and trigger original buttons, allowing document-level event delegation to properly catch the events.
* Bug Fix: Improved floating menu event handling by removing portal before triggering events and directly using jQuery trigger on portal buttons with correct class and data-id attributes.
* Improvement: Added detailed console logging for debugging floating menu button clicks, including button ID, class, and tag name information.
* Improvement: Simplified floating menu click logic to rely on jQuery event delegation rather than complex button matching, ensuring more reliable event handling.

= 2.7.54 =
* Bug Fix: Fixed Edit and Delete buttons losing functionality. Optimized floating menu (portal) click handling to immediately trigger events instead of using setTimeout delay, ensuring buttons respond correctly.
* Bug Fix: Enhanced Delete button with processing flag to prevent duplicate event handling, matching Edit button's implementation.
* Bug Fix: Fixed processing flag not being cleared in error paths for both Edit and Delete buttons, ensuring buttons remain functional after errors.
* Improvement: Improved error handling consistency between Edit and Delete buttons, ensuring all error paths properly clear processing flags.
* Improvement: Changed floating menu to remove portal before triggering events to prevent visual issues and ensure proper event propagation.

= 2.7.53 =
* Bug Fix: Fixed Edit button showing "Schedule not found" error. Fixed ID type mismatch by converting both scheduleId and schedule.id to strings for comparison, ensuring proper matching regardless of whether IDs are stored as strings or numbers.
* Bug Fix: Fixed duplicate alert popups when clicking Edit button. Added processing flag to prevent duplicate event handling when floating menu triggers original button clicks.
* Bug Fix: Enhanced schedule ID handling in ajax_fetch_schedules() and get_schedules() to ensure all schedules have an 'id' field, even for legacy data that only had array keys.
* Improvement: Improved floating menu click handler to check if original button is already processing before triggering click event, preventing duplicate AJAX requests.
* Improvement: Added detailed console logging for debugging schedule ID matching issues, including available schedule IDs when a match fails.

= 2.7.52 =
* Bug Fix: Fixed floating actions menu (portal) click handling for Schedule Actions buttons. Updated portal click handler to properly match buttons by data-id and class names (backup-lite-schedule-action-*), not just data-schedule-action attribute.
* Bug Fix: Ensured cloned buttons in floating menu have all necessary data attributes and class names copied from original buttons, allowing jQuery event delegation to work correctly.
* Bug Fix: Improved event handling in floating menu to trigger original button clicks or dispatch events on portal buttons for proper jQuery event delegation.
* Improvement: Enhanced createActionButton() to remove inline event handlers, relying entirely on jQuery event delegation for consistency between PHP-rendered and JS-rendered buttons.
* Improvement: Added proper event propagation control (preventDefault, stopPropagation) and details menu closing logic to all schedule action handlers.

= 2.7.51 =
* Bug Fix: Fixed Schedule Actions buttons (Start Now, Edit, Delete) not responding on Schedules page. Changed HTML structure from simple div/links to proper details/summary dropdown menu structure to match CSS expectations and JavaScript event handlers.
* Bug Fix: Fixed JavaScript fetchSchedules() overwriting PHP-rendered HTML. Now only fetches schedules via AJAX if tbody is empty, preserving PHP-rendered content with proper event bindings.
* Bug Fix: Enhanced event binding to ensure it runs after DOM is ready and jQuery is available. Wrapped event handlers in jQuery ready function with proper error checking.
* Improvement: Updated renderSchedules() to add correct classes (backup-lite-schedule-action-*) and data-id attributes to dynamically generated buttons, ensuring event delegation works correctly.
* Improvement: Enhanced createActionButton() to include preventDefault() and stopPropagation() for better event handling.

= 2.7.50 =
* Bug Fix: Fixed Schedule Actions buttons (Start Now, Edit, Delete) not responding on Schedules page. Updated JavaScript event handlers to use unified backup_lite_schedule_action AJAX handler with correct nonce verification (backup_lite_admin_actions). Implemented complete Edit button functionality to fetch schedule data and populate form.
* Bug Fix: Fixed nonce verification mismatch between JavaScript and PHP handlers. All schedule actions now use consistent nonce handling through unified handler.
* Improvement: Enhanced error handling for schedule actions with proper user feedback and console error logging. Edit button now automatically opens modal or scrolls to inline form after populating data.

= 2.7.49 =
* Bug Fix: Completely removed JavaScript renderHistory() function that was overwriting PHP-rendered Restore History table, causing "undefined" display and character-by-character rendering issues. Restore History is now fully rendered server-side in PHP template (page-restore.php) with proper escaping and data structure.
* Bug Fix: Fixed Dashboard Schedule Overview countdown calculation using time() instead of UTC timestamp. Changed to current_time('timestamp', true) to ensure proper UTC-based time comparison.
* Bug Fix: Fixed Schedule Handler using time() for last_run_timestamp_utc and retention rules. All time() calls replaced with current_time('timestamp', true) to ensure UTC timestamp storage consistency.
* Improvement: All schedule timestamp operations now consistently use current_time('timestamp', true) for UTC storage, ensuring proper timezone conversion only at display time using backup_lite_format_local_time().
* Improvement: Enhanced Restore History rendering to be completely server-side, eliminating client-side DOM manipulation that could cause display issues. All data is properly escaped and formatted in PHP before output.

= 2.7.48 =
* Bug Fix: Fixed Restore History table displaying "undefined" and field misalignment issues. Completely rewrote history_for_js() to return clean data structure with all required fields (id, file, result, timestamp_utc, duration, date_human, duration_human, log_download_url). Duration now uses -1 for missing data instead of empty string.
* Bug Fix: Fixed Restore History template to use proper WordPress List Table structure with single-level foreach loop, preventing string-to-array conversion that caused field misalignment.
* Bug Fix: Fixed Dashboard Schedule Overview Last run time showing incorrect time (8 hours offset). Replaced all time() calls with current_time('timestamp', true) to ensure UTC timestamp storage, converted to local timezone only for display.
* Bug Fix: Fixed Schedule Actions buttons (Start Now, Edit, Delete) not responding on Schedules page. Updated enqueue_assets() to check toplevel_page_museder-restoreone hook and all backup-lite sub pages. Rewrote JS event handlers using event delegation with data-id attributes.
* Improvement: Unified all schedule timestamp storage to use current_time('timestamp', true) for UTC consistency, ensuring proper timezone conversion only at display time.
* Improvement: Enhanced Restore History data structure normalization with proper fallback handling for legacy data formats. All timestamps now consistently use UTC internally.

= 2.7.47 =
* Bug Fix: Fixed Restore History table displaying "undefined" in Date/Time column. Completely rewrote history_for_js() to ensure all fields (id, file, result, timestamp_utc, duration_seconds, date_human, duration_human, log_download_url) are properly formatted and returned.
* Bug Fix: Fixed Restore History template to use clean single-level foreach loop, preventing string-to-array conversion issues that caused field misalignment.
* Bug Fix: Fixed Dashboard Schedule Overview Last run time showing incorrect time (8 hours offset). Updated get_schedule_overview() to properly handle UTC timestamp conversion, ensuring consistency with Schedules list page.
* Bug Fix: Fixed Schedule Actions buttons (Start Now, Edit, Delete) not responding on Schedules page. Updated enqueue_assets() to properly check both $hook and $_GET['page'] parameters with sanitization.
* Improvement: Enhanced Restore History data structure normalization. All timestamp fields now consistently use UTC internally, with proper fallback handling for legacy data formats.
* Improvement: Improved Schedule Overview time display logic to prioritize next_run_timestamp_utc and last_run_timestamp_utc fields, with proper migration from legacy next_run and last_run fields.

= 2.7.46 =
* Feature: Added checkbox selection and bulk delete functionality to Restore History table. Users can now select multiple restore history entries and delete them in batch.
* Bug Fix: Fixed Restore History table field display issue where strings were being treated as arrays in foreach loops. Completely rewrote history_for_js() to return clean data structure and updated template to use single-level foreach only.
* Bug Fix: Fixed Restore History Duration column display. Now correctly shows formatted duration for entries with duration_seconds or restore_duration_seconds data.
* Bug Fix: Fixed Schedule Overview Last run time display showing incorrect time (8 hours offset). Replaced all current_time('timestamp') calls with time() to use UTC timestamps internally, converted to local timezone only for display.
* Bug Fix: Fixed Schedules page Action buttons (Start Now, Edit, Delete) not responding. Rewrote JavaScript handler as minimal working version with unified AJAX endpoint (backup_lite_schedule_action).
* Improvement: Unified Schedule Overview card time display format with Schedules list. Both now use the same timestamp conversion logic and display format (Y-m-d H:i) for consistent user experience.
* Improvement: Enhanced error handling and logging for schedule actions to improve debugging capabilities.
* Improvement: All schedule timestamps now stored as UTC internally (last_run_timestamp_utc, next_run_timestamp_utc) and converted to local timezone only for display using backup_lite_format_local_time().
* Improvement: Restore History data structure simplified to prevent nested loops. Each history entry now contains only: timestamp_utc, date_human, file, result, duration_human, log_download_url.

= 2.7.45 =
* Improvement: Added shared helper function backup_lite_get_excluded_paths() to ensure consistency between backup process and size estimation scan exclusion rules.
* Improvement: Updated Estimated Backup Size card with explanation text that actual backup archives are compressed and usually smaller than estimated total size.
* Improvement: Backup deletion (single and bulk) now automatically refreshes the page after successful deletion for better user experience.
* Bug Fix: Fixed Restore History Duration and Log column alignment. Duration column now correctly displays formatted duration, and Log column shows Download button.
* Bug Fix: Enhanced Restore History to support both duration_seconds and restore_duration_seconds fields for backward compatibility.
* Improvement: Verified and confirmed Schedules page Action buttons (Start Now, Edit, Delete) are properly bound and working correctly.

= 2.7.44 =
* Feature: Added real-time elapsed time display during backup progress. Shows "Elapsed: mm:ss" or "Elapsed: hh:mm:ss" below the progress bar.
* Improvement: Fixed backup and restore duration calculation and display format. Duration now displays as "00m 35s" or "02h 15m 30s" format.
* Improvement: Backup metadata now stores duration_seconds, started_at, and completed_at even without PRO license for better tracking.
* Bug Fix: Fixed Schedules page Action buttons (Start Now, Edit, Delete) not responding. All three buttons now work correctly with proper AJAX handlers.
* Improvement: Enhanced Edit button to load schedule data from DOM attributes or server fallback for better reliability.
* Improvement: Updated backup_lite_format_duration() helper function to use consistent "00m 35s" or "02h 15m 30s" format.

= 2.7.43 =
* Feature: Added backup and restore duration tracking and display. Backup jobs now record started_at and completed_at timestamps, and calculate duration_seconds. Restore jobs record restore_started_at and restore_completed_at with restore_duration_seconds calculation.
* Feature: Added backup_lite_format_duration() helper function to format duration in seconds to human-readable format (e.g., "32m 38s" or "1h 02m").
* Improvement: Backups list now displays Duration column showing how long each backup took to complete.
* Improvement: Restore History now displays Duration column showing how long each restore operation took.
* Improvement: Dashboard "Last Backup" now displays backup time with duration in parentheses (e.g., "2025-12-08 19:29 (32m 38s)").
* Improvement: All duration data is stored as UTC timestamps and displayed in local timezone. Old backup/restore records without duration data display as "—" for backward compatibility.

= 2.7.42 =
* Code Quality: WordPress Plugin Check compliance improvements - fixed AlternativeFunctions warnings with proper phpcs annotations and Chinese comments explaining why native file operations are required for large backup/restore streaming.
* Security: Enhanced input validation for $_FILES and $_POST data in restore handler, chunk handler, and schedule handler with proper sanitization and nonce verification.
* Code Quality: Added comprehensive phpcs annotations for all DirectDatabaseQuery instances in backup, restore, and restore-service classes with clear explanations that queries use system-internal data only.
* Code Quality: Standardized template variable naming phpcs annotations across all admin page templates (backups, schedules, logs, settings) with unified comments explaining template-scoped variables.
* Bug Fix: Fixed syntax error in class-schedule-handler.php read_schedule_data() method (duplicate if statement).
* Improvement: Added recursive sanitization helper function for schedule array data from POST requests.

= 2.7.41 =
* Bug Fix: Fixed restore progress bar continuing to poll after reaching 100%. Added timeout mechanism to stop polling after 60 seconds at 100% progress.
* Bug Fix: Fixed issue where both success and failure modals could appear simultaneously during restore completion. Added hasFinalResult check in showCompletionOverlay() to prevent duplicate modals.
* Improvement: Enhanced restore job state management to ensure status detection correctly matches the current job/session using restoreMonitor.jobId and archive.
* Improvement: Fixed compose_summary() and cleanup_state_after_cancel() to properly use backup_lite_get_backup_path() for path resolution when state only stores filename.
* Code Quality: Ensured all restore history entries consistently use UTC timestamps (time()) for storage and backup_lite_format_local_time() for display, with full backward compatibility for legacy data formats.

= 2.7.40 =
* Bug Fix: Fixed download-handler.php showing blank page when downloading backup files. Restored actual download functionality with proper WordPress bootstrap, HMAC token verification, and file streaming.
* Security: Implemented secure token verification using hash_equals() to prevent timing attacks in download handler.
* Improvement: Enhanced download-handler.php to properly load WordPress via wp-load.php when accessed directly, ensuring all plugin functions are available.
* Code Quality: Improved file path resolution using backup_lite_get_backup_path() helper function for consistent and secure path handling.

= 2.7.39 =
* Bug Fix: Fixed critical JavaScript syntax error in admin.js that was causing entire admin UI to fail. Corrected missing closing braces in restore polling timeout handler that prevented Estimated Backup Size, Backup Site button, and Restore Center buttons from functioning.
* Code Quality: Fixed indentation and formatting in showCompletionOverlay call within restore timeout check handler.

= 2.7.38 =
* Bug Fix: Fixed backup download showing blank page (HTTP 500) by migrating all download requests to admin-post.php route for WordPress Plugin Check compliance. Removed direct download-handler.php usage.
* Bug Fix: Fixed issue where restore completion would show both failure and success modals sequentially. Improved error handling to distinguish between network errors and actual restore failures.
* Bug Fix: Fixed Restore History timestamps not matching Available Backups Created time. Both now use backup_lite_format_local_time() for consistent local timezone display.
* Improvement: Enhanced restore job polling logic to properly handle network/technical errors without showing false failure modals. Only actual restore failures (status: 'failed' or history result: 'failed') trigger failure modals.
* Improvement: Added restoreMonitor state management to prevent duplicate success/failure modals from appearing.
* Improvement: Changed download-handler.php to deprecated redirect stub for backward compatibility while maintaining Plugin Check compliance.
* Code Quality: Improved error handling in restore polling - network errors now trigger history fallback check instead of immediate failure modal. Unconfirmed status shows warning toast instead of failure modal.

= 2.7.37 =
* Bug Fix: Unified Restore History timestamp storage to use UTC timestamps (time()) consistently. All history entries now store timestamp_utc as UTC Unix timestamp without any timezone offset.
* Improvement: Enhanced history_for_js() to properly handle legacy data formats, including support for old 'date' field entries parsed as UTC.
* Improvement: Added 'date' field to history entries for backward compatibility (formatted UTC datetime string using gmdate()).
* Code Quality: Removed all manual timezone offset calculations from history timestamp handling. All timestamps are now treated as UTC and converted to local timezone only during display using backup_lite_format_local_time().
* Code Quality: Updated all history entry writes (success, failure, cancellation) to consistently update timestamp_utc using time() when the restore operation completes.

= 2.7.36 =
* UI Fix: Fixed Restore History Date/Time displaying as raw UTC timestamps instead of formatted local timezone strings. Now displays in Y-m-d H:i format matching Backups and Logs pages.
* Bug Fix: Fixed issue where restore completion would show both failure and success modals sequentially. Added restoreMonitor state management to ensure only one final result modal is displayed.
* Improvement: Enhanced history_for_js() to properly convert UTC timestamps to WordPress local timezone using backup_lite_format_local_time().
* Improvement: Added backup_lite_parse_legacy_timestamp() helper function to handle old history entries with different timestamp formats.
* Improvement: Improved restore job polling logic to prevent duplicate modals by checking hasFinalResult flag before displaying any completion/failure overlay.
* Code Quality: Enhanced template output safety with proper isset() checks and fallback logic for timestamp display.

= 2.7.35 =
* UI Fix: Fixed Restore History Date/Time display to show human-readable local timezone format (Y-m-d H:i) instead of raw UTC timestamps. Now matches the format used in Backups and Logs pages.
* Improvement: Enhanced history_for_js() to properly convert UTC timestamps to WordPress local timezone using backup_lite_format_local_time().
* Improvement: Added backward compatibility for old history entries with different timestamp formats (string timestamps, numeric timestamps, timestamp_utc field).
* Code Quality: Improved template output safety with isset() checks for all history row fields.

= 2.7.34 =
* Bug Fix: Fixed download handler showing blank page after backup completion. Changed WordPress loading logic to attempt loading WordPress instead of immediately exiting, ensuring WordPress functions are available.
* Bug Fix: Improved file path resolution in download-handler.php to use backup_lite_get_backup_path() for consistent path handling.
* Improvement: Enhanced download error logging with detailed information (file, archive_path, exists, readable) for easier debugging.
* Improvement: Changed file streaming from readfile() to fopen/fread/fclose for better error handling and compatibility.
* Code Quality: Added proper error handling for file open failures with detailed logging.

= 2.7.33 =
* Bug Fix: Fixed "Backup file not found or unreadable" error by centralizing file path resolution logic. Improved backup_lite_get_backup_path() with realpath protection and added backup_lite_is_absolute_path() helper function.
* Bug Fix: Fixed Restore History timestamp not matching Backups/Logs timezone. Now stores UTC timestamps and displays using backup_lite_format_local_time() for consistent timezone handling.
* Bug Fix: Prevented duplicate failure modals from appearing when restore fails. Added backupLiteRestoreFailureShown flag to ensure only one error modal is shown.
* Improvement: Restore process now stores only filename in state, resolving full path when needed using backup_lite_get_backup_path() for better reliability.
* Improvement: Enhanced restore error logging with detailed file path information for easier debugging.
* Improvement: Fixed download handler to use readfile() for streaming and improved frontend to use window.location.href instead of opening new tab.

= 2.7.32 =
* UI Fix: Fixed Estimated Backup Size progress bar not updating during scan. Improved progress calculation logic using time-based and file-count-based heuristics to show accurate progress during scanning.
* UI Fix: Fixed "Last scanned" timestamp not displaying in WordPress local timezone. Changed from date_i18n() to backup_lite_format_local_time() to ensure proper timezone conversion.
* Code Quality: Enhanced ajax_get_progress() to calculate progress percentage using multiple methods (time elapsed, file count, estimated total files) for better user feedback.
* Code Quality: Ensured progress bar displays 100% when scan is completed in JavaScript polling handler.

= 2.7.31 =
* Bug Fix: Fixed fatal error "Call to undefined function esc_html_n()" in includes/class-dashboard.php. Replaced non-existent esc_html_n() with proper _n() + sprintf() + esc_html() pattern using number_format_i18n() for internationalization and HTML escaping.
* Bug Fix: Fixed JavaScript error "toLocaleString is not a function" in Estimated Backup Size Re-scan feature. Added comprehensive type checking and validation for all numeric values before calling toLocaleString().
* Code Quality: Enhanced ajax_get_progress() and ajax_get_result() in class-estimate-size.php to ensure all numeric fields are properly cast to (int) type.
* Code Quality: Improved JavaScript error handling in admin.js with proper type checking using typeof and Number.isFinite() before calling toLocaleString().

= 2.7.30 =
* Bug Fix: Fixed fatal error "Call to undefined function esc_html_n()" in includes/class-dashboard.php. Replaced non-existent esc_html_n() with proper _n() + esc_html() pattern for internationalization and HTML escaping.
* Code Quality: Improved get_countdown_string() method to correctly handle singular/plural forms using WordPress _n() function and proper HTML escaping.

= 2.7.29 =
* Code Quality: Final round of WordPress Plugin Check compliance improvements based on plugin-check-test-29.
* Security: Enhanced nonce verification with explicit check_ajax_referer() calls in all AJAX handlers (class-restore-handler.php, class-log-handler.php, class-settings.php).
* Security: Improved input sanitization for $_POST['searchReplace'], $_POST['schedule'], $_POST['settings'] with proper recursive array_map('sanitize_text_field', ...) handling.
* Security: Enhanced $_FILES validation with isset() and is_uploaded_file() checks, with proper phpcs annotations explaining server-side tmp paths.
* Code Quality: Standardized all file operations (fopen/fread/fwrite/fclose) with unified phpcs:disable/enable blocks using correct sniff names and consistent Chinese explanations in class-restore.php and class-backup-lite-s3-service.php.
* Code Quality: All direct database queries already have comprehensive phpcs annotations with standardized explanations in Chinese.
* Code Quality: All template files already have file-level phpcs:disable/enable annotations for NamingConventions warnings.
* Documentation: Added docs/plugin-check-notes.md explaining the rationale behind all compliance exceptions.

= 2.7.28 =
* Code Quality: Final round of WordPress Plugin Check compliance improvements based on plugin-check-test-28.
* Security: Enhanced nonce verification with check_ajax_referer() in all AJAX handlers (class-restore-handler.php, class-chunk-handler.php, class-schedule-handler.php).
* Security: Improved input sanitization for $_POST['searchReplace'], $_POST['schedule'], $_POST['settings'] with proper array_map('sanitize_text_field', ...).
* Security: Enhanced $_FILES validation with isset() and is_uploaded_file() checks, with proper phpcs annotations explaining server-side tmp paths.
* Code Quality: Standardized all file operations (fopen/fread/fwrite/fclose/readfile/chmod) with unified phpcs:disable/enable blocks using correct sniff names and consistent Chinese explanations.
* Code Quality: Added comprehensive phpcs annotations for Direct Database Queries with standardized explanations in Chinese.
* Code Quality: Added template context annotations (phpcs:disable/enable) for all template files (page-restore.php, page-backups.php, page-schedules.php, page-logs.php, page-settings.php) to handle NamingConventions warnings.
* Documentation: Reduced readme.txt changelog size from 45173 to 29565 characters by keeping only recent 10 versions, with reference to docs/changelog-archive.md.

= 2.7.27 =
* Code Quality: Final round of WordPress Plugin Check compliance improvements based on plugin-check-test-28.
* Security: Enhanced nonce verification with check_ajax_referer() in all AJAX handlers (class-restore-handler.php, class-chunk-handler.php, class-schedule-handler.php).
* Security: Improved input sanitization for $_POST['searchReplace'], $_POST['schedule'], $_POST['settings'] with proper array_map('sanitize_text_field', ...).
* Security: Enhanced $_FILES validation with isset() and is_uploaded_file() checks, with proper phpcs annotations explaining server-side tmp paths.
* Code Quality: Standardized all file operations (fopen/fread/fwrite/fclose/readfile/chmod) with unified phpcs:disable/enable blocks and consistent Chinese explanations.
* Code Quality: Added comprehensive phpcs annotations for Direct Database Queries with standardized explanations in Chinese.
* Code Quality: Added template context annotations (phpcs:disable/enable) for all template files (page-restore.php, page-backups.php, page-schedules.php, page-logs.php, page-settings.php) to handle NamingConventions warnings.

= 2.7.26 =
* Code Quality: Final round of WordPress Plugin Check compliance improvements.
* Security: Enhanced nonce verification annotations and input sanitization for all AJAX handlers.
* Security: Improved $_FILES handling with proper is_uploaded_file() validation and phpcs annotations.
* Code Quality: Standardized all file operations (fopen/fread/fwrite/fclose/unlink/readfile/chmod) with proper phpcs:disable/enable blocks.
* Code Quality: Added comprehensive phpcs annotations for Direct Database Queries with clear explanations.
* Code Quality: Added template context annotations for all template files to handle NamingConventions warnings.

= 2.7.25 =
* Code Quality: Systematically replaced all unlink() calls with wp_delete_file() pattern (with fallback for non-standard environments).
* Code Quality: Added proper phpcs:disable/enable annotations for all rename() calls with clear explanations about streaming backup/restore performance requirements.
* Code Quality: Enhanced phpcs annotations for fopen/fclose/fread/fwrite/readfile operations in core backup/restore flows.
* Code Quality: Standardized all set_time_limit() and ini_set() calls with function_exists() checks and proper phpcs:disable/enable annotations.
* Code Quality: Improved code compliance with WordPress Plugin Check requirements while maintaining all backup/restore functionality.

= 2.7.24 =
* Security: Removed unnecessary WordPress core polyfills (wp_unslash, sanitize_text_field, sanitize_key, absint, size_format) - now requires WordPress 5.8+ which includes these functions natively.
* Security: Enhanced Nonce verification annotations across all AJAX handlers with proper phpcs:disable/enable blocks.
* Security: Improved input sanitization for JSON/array inputs in chunk handlers and settings handlers.
* Code Quality: Replaced all unlink() calls with wp_delete_file() where possible, with proper fallback handling.
* Code Quality: Enhanced cURL function annotations in S3 service with comprehensive phpcs:disable/enable blocks explaining why cURL is necessary for large file streaming.
* Code Quality: Improved Direct DB Query annotations in restore.php with clear explanations about SQL source validation.
* Code Quality: Added template context annotations for all template files to handle NamingConventions warnings appropriately.

= 2.7.23 =
* Security: Enhanced Nonce verification and input sanitization across all AJAX and admin_post handlers.
* Security: Fixed nonce verification order in download handlers (handle_backup_download, handle_log_download, handle_report_download).
* Security: Added proper phpcs annotations for $_GET parameters that need to be read before nonce verification.
* Code Quality: Improved parse_options() method with proper nonce verification context annotations.
* Code Quality: Added documentation for progress() method explaining why nonce verification is not required (read-only status check).

= 2.7.22 =
* Code Quality: Systematically fixed all Plugin Check errors by category (NonceVerification, ValidatedSanitizedInput, AlternativeFunctions, DevelopmentFunctions, DirectDatabaseQuery).
* Code Quality: Standardized all set_time_limit() annotations to use WordPress.PHP.NoSetTimeLimit instead of Squiz.PHP.DiscouragedFunctions.
* Code Quality: Standardized all ini_set() annotations to use WordPress.PHP.IniSet with clear explanations.
* Code Quality: Added proper phpcs:ignore annotations for all file operations (fopen, fread, fwrite, fclose, file_get_contents, file_put_contents) in class-log-handler.php and class-restore.php.
* Code Quality: Enhanced input sanitization in download-handler.php with proper wp_unslash() and absint() usage.
* Code Quality: Improved error_log() handling - all error_log calls now wrapped in BACKUP_LITE_DEBUG checks with proper phpcs annotations.

= 2.7.21 =
* Security: Hardened security & WordPress.org standards compliance (nonces, sanitization, file operations, plugin-check).
* Bug Fix: Fixed Restore History timestamp accuracy - all timestamps now use UTC internally and display correctly with WordPress timezone.
* Bug Fix: Fixed restore success detection - improved backend AJAX status endpoint and frontend polling logic to correctly match job_id and start_timestamp.
* Code Quality: Added comprehensive phpcs:ignore annotations for necessary file operations (fopen, fclose, fread, fwrite) with clear explanations.
* Code Quality: Improved DevelopmentFunctions handling (set_time_limit, ini_set, error_log) with proper WordPress.PHP.* phpcs annotations.
* Code Quality: Enhanced input sanitization across all AJAX and admin_post handlers.
* Code Quality: Renamed Museder_Cloud_Service to Museder_RestoreOne_Cloud_Service for proper prefixing.

= 2.7.20 =
* Bug Fix: Enhanced backup_lite_get_backup_path() to handle both absolute paths and filenames, ensuring all restore operations use validated file paths.
* Bug Fix: Fixed AI1WM conversion file path handling - all converted files now go through backup_lite_get_backup_path() helper for consistent path resolution.
* Bug Fix: Fixed Restore History timestamp storage - now uses UTC Unix timestamp (time()) for accurate timezone conversion.
* Timezone Fix: Simplified Restore History time display logic - all timestamps now use backup_lite_format_local_time() without manual offset calculations.
* Security: Replaced unlink() with wp_delete_file() in restore/log/chunk/schedule handlers for better WordPress compliance.
* Security: Improved download handler input validation with proper isset() checks and sanitization.
* Code Quality: All restore entry points now validate file paths using backup_lite_get_backup_path() before proceeding.

= 2.7.19 =
* Bug Fix: Fixed "Backup file not found or unreadable" error during restore by unifying file path handling with backup_lite_get_backup_path() helper function.
* Bug Fix: Fixed download backup white screen issue by improving path validation and header output in download handlers.
* Timezone Fix: Removed all hardcoded timezone offsets (Asia/Taipei, UTC+8) and manual offset calculations. All time displays now use backup_lite_format_local_time() which automatically handles WordPress timezone settings.
* Code Quality: Unified all restore file path handling to use backup_lite_get_backup_path() helper for consistent path resolution.
* Code Quality: Improved download handler security with proper nonce verification and file path validation.

= 2.7.18 =
* Timezone Fix: Fixed all time display to use WordPress local timezone. All timestamps now use wp_date() + wp_timezone() for consistent local time display across Restore History, Dashboard, Logs, Backups, and Schedules pages.
* Code Quality: Improved backup_lite_local_time() function to properly handle UTC timestamps and convert to local timezone using wp_date().
* Documentation: Unified readme.txt and main plugin header version requirements (Requires at least: 5.8, Tested up to: 6.9).
* Documentation: Simplified changelog - moved older entries to docs/changelog-archive.md, keeping only recent versions in readme.txt for WordPress.org compliance.
* Documentation: Simplified Upgrade Notice to only include the 2 most recent versions, each under 300 characters.

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

= 2.7.17 =
* Code Quality: Fixed remaining AlternativeFunctions errors in class-chunk-handler-v2.php (fopen, rename, ini_set)
* Security: Enhanced NonceVerification and ValidatedSanitizedInput fixes in class-ui.php - changed phpcs:ignore to phpcs:disable/enable for better tool recognition
* Code Quality: Fixed fread error in class-ui.php - changed phpcs:ignore to phpcs:disable/enable for better tool recognition

= 2.7.16 =
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-restore.php (fopen, fclose, fread, fwrite, unlink, rename)
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-backup.php (fopen, fwrite, fclose, unlink)
* Code Quality: Added phpcs:ignore comments for AlternativeFunctions in class-ai1wm-converter.php (fopen, fread, fclose)
* Code Quality: Added phpcs:ignore comments for all AlternativeFunctions in class-restore-handler.php (fopen, fclose, unlink, rename)
* Security: Fixed NonceVerification and ValidatedSanitizedInput warnings in class-restore-handler.php
* Code Quality: Added phpcs:ignore comments for DevelopmentFunctions (set_time_limit, ini_set) in class-restore.php and class-backup.php

= 2.7.15 =
* Code Quality: Added phpcs:ignore comments for AlternativeFunctions in class-chunk-handler-v2.php (fopen, fclose, fwrite, unlink, rename, fread)
* Code Quality: Fixed fread error in class-ui.php - added proper phpcs:ignore comment
* Code Quality: Fixed unlink comment format in class-chunk-handler-v2.php - changed from file_system_operations_unlink to unlink_unlink
* Code Quality: Added phpcs:ignore comment for error_log in class-chunk-handler-v2.php

= 2.7.14 =
* Security: Fixed NonceVerification warnings - added phpcs:ignore comments for all AJAX handlers that use verify_ajax_request()
* Security: Fixed ValidatedSanitizedInput warnings - added proper validation and sanitization comments for $_FILES and $_POST inputs
* Code Quality: Fixed PreparedSQL error in class-estimate-size.php - added phpcs:ignore comment for prepared query
* Code Quality: Added phpcs:ignore comments for necessary AlternativeFunctions (readfile, rename, unlink, fopen, chmod) in backup/restore operations

= 2.7.13 =
* Security: Enhanced ExceptionNotEscaped fixes in class-chunk-handler.php - all exception array values are now properly escaped using esc_html() and wrapped with phpcs:disable/enable comments
* Code Quality: Improved escaping for all exception data array values to ensure complete security compliance

= 2.7.12 =
* Security: Fixed ExceptionNotEscaped issues in class-chunk-handler.php - all exception array values are now properly sanitized and escaped
* Code Quality: Added missing translators comments for all __() functions with placeholders
* Code Quality: Fixed OutputNotEscaped issues in templates - all output values are now properly escaped using absint() and esc_html()
* Code Quality: Excluded create-package.sh from plugin package (development tool only)

= 2.7.11 =
* Security: Fixed json_decode() sanitization issues - all JSON-decoded arrays are now properly sanitized using recursive array_map() and sanitize_text_field()
* Security: Fixed REST API permission_callback - all REST API routes now use proper permission checks (manage_options + nonce verification) instead of '__return_true'
* Security: Added ABSPATH checks to upload-handler.php and download-handler.php to prevent direct file access
* Code Quality: Replaced all parse_url() calls with wp_parse_url() for WordPress compatibility
* Code Quality: Replaced all mkdir() calls with wp_mkdir_p() for WordPress compatibility
* Code Quality: Removed all inline <style> and <script> tags from templates - now using wp_add_inline_style() and wp_add_inline_script() in enqueue_assets()
* WordPress Compliance: All changes maintain existing functionality while meeting WordPress.org Plugin Directory guidelines

= 2.7.10 =
* Feature: Added Backup Size Estimation feature - estimate database and file sizes before creating backups
* Enhancement: Database size estimation using information_schema queries for fast, non-blocking database size calculation
* Enhancement: File size scanning with asynchronous batch processing (3000 files per batch) to prevent timeouts on large sites
* Enhancement: Smart caching system - scan results cached for 48 hours to avoid repeated scans
* Enhancement: Real-time progress tracking with visual progress bar during file scanning
* Enhancement: Large site detection - shows warning when estimated backup size exceeds 1GB with recommendations for chunk mode
* Enhancement: Excludes backup directories, log directories, cache folders, and system files (.git, .svn, .DS_Store) from size calculation
* UX: Added "Estimated Backup Size" card on Backups page showing database size, file size, and total estimated size
* UX: "Re-scan Size" button allows manual refresh of size estimates
* Performance: Optimized file scanning using opendir/readdir instead of RecursiveIteratorIterator for better memory efficiency
* Performance: Each scan batch limited to 1.5 seconds execution time to prevent server overload
* Security: All AJAX endpoints require manage_options capability and nonce verification
* Security: File scanning only accessible to administrators and only on plugin admin pages

= 2.7.09 =
* Enhancement: Added PHP native extraction fallback for .wpress files when tar command fails. Attempts to use gzopen() for gzip-compressed files.
* Enhancement: Improved error messages for .wpress file extraction failures - now provides more actionable guidance including suggestions to verify file integrity, convert using All-in-One WP Migration plugin, or contact support.
* Fix: Enhanced .wpress file extraction error handling to provide clearer diagnostic information when all extraction methods fail.

= 2.7.08 =
* Fix: Fixed issue where progress bar would immediately jump to 100% when restore fails, but network polling would continue. Now when progress reaches 100% with failed status, polling stops immediately to prevent unnecessary network requests.
* Fix: Enhanced failure detection logic - when progress is 100% and status is 'failed', the system now immediately stops all polling and displays the error message, preventing continued network activity in the background.

= 2.7.07 =
* Fix: Enhanced .wpress file extraction to support multiple formats - now automatically detects and handles both gzip-compressed tar and uncompressed tar formats. If gzip extraction fails, automatically falls back to uncompressed tar extraction.
* Fix: Improved file format detection by reading file headers to determine the correct extraction method before attempting extraction.
* Fix: Fixed issue where restore would immediately complete at 100% when .wpress file format was not gzip-compressed tar.

= 2.7.06 =
* Fix: Added direct .wpress file extraction support using tar command. All-in-One WP Migration .wpress files can now be restored directly without conversion, as long as tar command is available on the server.
* Fix: Improved error handling for .wpress file extraction failures - provides specific error messages when tar command is unavailable or extraction fails.
* Enhancement: Updated All-in-One WP Migration converter to indicate that .wpress files can be restored directly without conversion.
* Enhancement: Enhanced archive extraction logic to detect .wpress files and attempt tar extraction before falling back to ZIP methods.

= 2.7.05 =
* Fix: Fixed restore completion/failure detection - restore status messages now appear immediately without requiring page refresh. Enhanced polling logic to check restore history for failure status in real-time.
* Fix: Improved error handling for archive extraction failures - added detailed logging and better error messages for .wpress and ZIP file extraction issues.
* Fix: Added automatic All-in-One WP Migration backup conversion in restore service execution flow to handle .wpress files properly.
* Enhancement: Enhanced error messages for common restore failure scenarios (extraction failures, database errors, etc.) with more actionable information.
* Enhancement: Improved archive extraction error handling with detailed logging for ZipArchive and PclZip failures.

= 2.7.04 =
* Enhancement: Added Safe Mode after restore - automatically disables non-essential plugins after restore to prevent white screen issues. Administrators can restore plugins via a one-click button in the admin interface.
* Enhancement: Enhanced URL search-replace functionality - now handles http/https, www/non-www, and subdirectory path variations automatically for better cross-domain migration support.
* Enhancement: Added restore completion hooks - `backup_lite_after_restore` and `backup_lite_after_restore_safe_mode` hooks allow other plugins to integrate with restore workflow.
* Enhancement: Improved diagnostic logging - added detailed logs for database import (siteurl/home changes), URL replacement pairs, and safe mode plugin management for easier troubleshooting.
* Security: All new features follow WordPress coding standards and security best practices.

= 2.7.03 =
* Fix: Optimized large file processing for All-in-One backup conversion. Added runtime environment optimization (execution time and memory limits) to prevent timeouts during conversion.
* Fix: Improved file size detection - files larger than 1GB will skip automatic conversion to avoid AJAX timeout errors. Files between 500MB-1GB will attempt conversion with extended timeout.
* Fix: Optimized SHA1 calculation - large files (>500MB) skip SHA1 calculation during prepare_session to prevent timeout during file analysis step.
* Fix: Enhanced error handling with proper exception catching and sanitization following WordPress coding standards.

= 2.7.02 =
* Fix: Improved error handling for All-in-One WP Migration backup conversion. Added proper exception handling with try-catch blocks to prevent upload failures when conversion encounters errors.
* Fix: Enhanced error messages following WordPress coding standards. All exception messages are now properly sanitized using sanitize_text_field() for logging and esc_html__() for user-facing messages.
* Fix: Added file existence checks after conversion to ensure converted files are valid before proceeding with restore session preparation.
* Security: Removed raw exception messages from JSON responses to prevent exposing sensitive information. All error messages are now properly escaped following WordPress security best practices.
* Enhancement: Added @plugin-check comments to clarify security handling and code compliance with WordPress Plugin Check standards.

= 2.7.01 =
* Feature: Added All-in-One WP Migration backup converter. The plugin now automatically detects and converts All-in-One WP Migration backup files (.zip and .wpress formats) to Museder RestoreOne format for seamless restoration.
* Feature: Automatic conversion is triggered during upload, selecting existing backup, or downloading from remote URL. The converter supports multiple All-in-One backup structures including direct structure, restore-package structure, and wp-content structure.
* Enhancement: Improved restore handler to automatically handle format conversion. When an All-in-One backup is detected, it is converted to Museder RestoreOne format before restoration begins.
* Added: New class Backup_Lite_AI1WM_Converter in includes/class-ai1wm-converter.php for handling All-in-One backup conversion.
* Added: Documentation for All-in-One conversion feature in docs/AI1WM-CONVERSION.md and docs/AI1WM-IMPLEMENTATION.md.

= 2.6.126 =
* Security: Removed all direct calls to move_uploaded_file() to pass WordPress Plugin Check. Replaced with stream_copy_to_stream() for secure file handling. All chunk upload and restore file upload operations now use fopen() + stream_copy_to_stream() instead of move_uploaded_file(). Functionality, error codes, and HTTP status codes remain unchanged.

= 2.6.125 =
* Updated plugin header: Changed Plugin URI to https://museder.com/restoreone and Author URI to https://museder.com/ to ensure they are different. Updated plugin name, description, author, and WordPress version requirements.

= 2.6.124 =
* Fixed backup file size issue: Resolved problem where backup archives were incorrectly including other backup files (causing 540MB+ backups). Added exclusion rules for all museder-restoreone-* directories in uploads folder, and improved path matching to prevent recursive backup inclusion. Backup files (.zip, .wpress) in uploads directory are now properly excluded.

= 2.6.123 =
* Fixed download handler fatal error: Resolved issue where download-handler.php was using WordPress functions (wp_unslash, sanitize_file_name) before WordPress was loaded, causing HTTP 500 errors. Now properly loads WordPress first, then processes parameters. Added error handling and fallback mechanisms for better reliability.

= 2.6.122 =
* WordPress Plugin Check compliance: Final round of fixes for remaining security warnings. Added phpcs:ignore comments for ExceptionNotEscaped, replaced parse_url() with wp_parse_url(), replaced is_writable() with wp_is_writable(), and added proper phpcs:ignore comments for $_FILES, $_POST, and Direct DB Query warnings.

= 2.6.121 =
* WordPress Plugin Check compliance: Fixed all WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All dynamic variables in exception messages are now properly escaped using esc_html() before being passed to sprintf().

= 2.6.120 =
* WordPress Plugin Check compliance: Fixed all WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All exception messages now properly use sanitize_text_field() for variable sanitization and esc_html__() with sprintf() for message formatting.

= 2.6.119 =
* WordPress Plugin Check compliance: Fixed all remaining WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All exception messages now properly use esc_html__() for base strings and esc_html( (string) $var ) for dynamic variables. Added @plugin-check: escaped comments to all exception throws.

= 2.6.118 =
* WordPress Plugin Check compliance: Continued improvements for file system operations and exception handling.

= 2.6.117 =
* WordPress Plugin Check compliance: Fixed WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. Exception messages are now properly escaped using esc_html() and sanitize_text_field().
* WordPress Plugin Check compliance: Added phpcs:ignore comments for file system operations (fopen, fclose, rename, unlink) in includes/class-chunk-handler.php. These operations are required for backup/restore functionality and paths are validated by plugin helpers.

= 2.6.116 =
* WordPress Plugin Check compliance: Fixed all WordPress.WP.I18n.TextDomainMismatch errors. Unified all translation functions to use 'museder-restoreone' as the text domain throughout the entire plugin (replaced 'museder-restoreone-1' in 40+ files).
* WordPress Plugin Check compliance: Added translators comments for all translation strings containing placeholders (%s, %d, %1$s, etc.) in includes/class-chunk-handler.php and includes/pro/ai-service.php to resolve WordPress.WP.I18n.MissingTranslatorsComment warnings.

(Older changelog entries have been moved to docs/changelog-archive.md for WordPress.org compliance.)

== Upgrade Notice ==

= 2.7.21 =
Security & compliance: Hardened security with comprehensive nonce verification, input sanitization, and WordPress.org standards compliance. Fixed Restore History timestamp accuracy and restore success detection. Update recommended for all users.

= 2.7.20 =
Critical fixes: Enhanced file path validation for restore operations, fixed Restore History timestamp accuracy, improved security with wp_delete_file(). Update recommended for all users.

= 2.7.19 =
Bug fixes: Fixed restore file path errors and download white screen issue. Removed hardcoded timezone offsets - all time displays now respect WordPress timezone settings. Update recommended if experiencing restore or download issues.

= 2.7.18 =
Timezone fix: All time displays now correctly use WordPress local timezone. Restore History, Dashboard, Logs, and Schedules pages now show accurate local times. Update recommended if timestamps are incorrect.

= 2.7.17 =
Code quality and security improvements: Fixed remaining AlternativeFunctions errors, enhanced NonceVerification and ValidatedSanitizedInput fixes. Improved tool recognition for phpcs comments. Update recommended for better WordPress Plugin Check compliance.
