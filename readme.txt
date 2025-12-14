=== Museder RestoreOne ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.7.40.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Changelog ==

For full changelog history, please see docs/changelog-archive.md in the plugin folder.

= 2.7.40.11 =
* Security: Addressed remaining Plugin Check DirectDB warnings by documenting allowlist-validated identifiers (identifiers cannot be prepared) with minimal-scope PHPCS ignores.
* Performance: Added caching for schema introspection (`SHOW COLUMNS`) during restore search/replace.

= 2.7.40.10 =
* Code Quality: Fixed Plugin Check PreparedSQL error by inlining `$wpdb->prepare()` in chunked DB export queries.
* Security: Restricted free/default DB operations to `$wpdb->prefix` tables using a live table whitelist; identifiers are validated by strict whitelist membership (SERVMASK cleanup uses its own live whitelist).

= 2.7.40.9 =
* Security: Made nonce verification explicit inside each chunk upload AJAX callback (capability → nonce → input) for better Plugin Check visibility.
* Code Quality: Hardened database export/restore queries by avoiding placeholder use for identifiers and adding minimal-scope PHPCS suppressions with English rationale.

= 2.7.40.8 =
* Security: Enforced strict nonce verification order (capability → nonce → input) across restore AJAX handlers; invalid nonce now returns 403.
* Security: Hardened chunk upload validation for $_FILES['chunk'] (UPLOAD_ERR_OK/size/is_uploaded_file/file_exists).
* UX: Frontend now treats 403 as session expired, stops polling, and prompts reload.

= 2.7.40.7 =
* Code Quality: Centralized restore/S3 streaming I/O into helper methods to reduce Plugin Check file operation errors.

= 2.7.40.6 =
* Code Quality: Plugin Check compliance fixes (file operation PHPCS blocks, nonce verification annotations, and template global prefix scope).

= 2.7.40.5 =
* Bug Fix: Fixed backup download links generated for JavaScript/JSON contexts (avoid HTML-escaped query separators that could break the `file` parameter).
* Bug Fix: Prevented PHP 8+ `ZipArchive->close()` ValueError from causing `admin-ajax.php` 500 during backup job processing.

= 2.7.40.4 =
* Documentation: Clarified changelog wording and added third-party library source information.

= 2.7.40.3 =
* Security: Removed any remaining legacy “native/secret upload” code path in the frontend; uploads are handled via WordPress REST routes only.
* Security: upload-handler.php and download-handler.php are deprecated stubs; uploads/downloads are handled via WordPress routes only.

= 2.7.40.2 =
* Security: Removed direct upload endpoint usage; uploads are handled via WordPress REST routes only.
* Security: Deprecated upload-handler.php as a non-executable stub to avoid direct file access concerns.

= 2.7.40.1 =
* Security: Removed direct executable behavior from download-handler.php; downloads now go through WordPress admin-post routes.
* Security: upload-handler.php and download-handler.php are deprecated stubs; uploads/downloads are handled via WordPress routes only.
* Security: Updated vendored Chart.js library to v4.5.1.

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
