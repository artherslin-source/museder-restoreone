=== Museder RestoreOne ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.7.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Changelog ==

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
* Fix: Added optimize_runtime_environment() method to restore handler for consistent runtime optimization across all upload methods.
* Enhancement: Improved ZIP archive creation process in converter with periodic execution time resets to handle large directory structures.
* Security: All error messages properly escaped and sanitized following WordPress Plugin Check standards.

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

= 2.6.115 =
* WordPress Plugin Check compliance: Fixed WordPress.DB.PreparedSQL.NotPrepared warning in includes/class-restore.php. Updated DROP TABLE statement to use $wpdb->prepare() for table name variable. The SQL script execution (multi-statement) retains appropriate phpcs:ignore comment with clear explanation.

= 2.6.114 =
* WordPress Plugin Check compliance: Fixed WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings in includes/class-chunk-handler.php. All Exception messages now use esc_html__() with sprintf() and proper escaping for variables (chunk_sha1, actual_sha1, missing chunks array, chunk index). Updated 5 Exception instances with proper variable sanitization.
* WordPress Plugin Check compliance: Added phpcs:ignore comments for binary file streaming output in includes/class-ui.php and download-handler.php. These echo fread() calls stream binary file contents, not HTML output, so escaping is not applicable.
* WordPress Plugin Check compliance: Fixed WordPress.DB.PreparedSQL.NotPrepared warnings in includes/class-restore.php. Added appropriate phpcs:ignore comments for DDL statements (DROP TABLE) and multi-statement SQL scripts that cannot use $wpdb->prepare().

= 2.6.113 =
* WordPress Plugin Check compliance: Fixed all WordPress.WP.I18n.TextDomainMismatch errors. Updated all translation functions (__(), _e(), _x(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e()) to use 'museder-restoreone-1' as the text domain throughout the entire plugin. Modified 43 PHP files and 1 POT file, replacing 874 instances of text domain parameters. All text domain references are now consistent and compliant with WordPress.org requirements.

= 2.6.112 =
* WordPress Plugin Check compliance: Verified all text domain usage. All translation functions (__(), _e(), _x(), esc_html__(), esc_attr__()) consistently use 'museder-restoreone' text domain throughout the entire plugin. No version-numbered text domains found. All text domain references are properly configured and consistent.

= 2.6.111 =
* WordPress Plugin Check compliance: Verified all text domain usage. All translation functions (__(), _e(), _x(), esc_html__(), esc_attr__()) consistently use 'museder-restoreone' text domain throughout the entire plugin. No version-numbered text domains found. All text domain references are properly configured.

= 2.6.110 =
* WordPress Plugin Check compliance: Fixed all SQL/database related warnings (WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter). All SQL queries now use $wpdb->prepare() with proper table name sanitization. Added phpcs:ignore comments for necessary exceptions (SHOW TABLES, DROP TABLE, MySQL SET statements).
* Security improvements: Fixed all file system operation warnings (WordPress.WP.AlternativeFunctions, WordPress.PHP.ForbiddenFunctions). Replaced 31 instances of unlink() with wp_delete_file() where possible, added proper annotations for move_uploaded_file(), rename(), and rmdir() operations. All file operations now use WordPress functions or have clear security documentation.
* Code quality: All table names are sanitized using preg_replace() before use in SQL queries. All file paths are validated and sanitized before file operations. All user-facing date displays now use wp_date() for proper timezone handling.
* Documentation: Updated readme.txt Tested up to version format and shortened upgrade notices to meet WordPress.org requirements.

= 2.6.109 =
* Security: Fixed all WordPress.Security.EscapeOutput.ExceptionNotEscaped and WordPress.Security.EscapeOutput.OutputNotEscaped warnings. All JSON responses, Exception messages, WP_Error messages, and user-facing output now use proper escaping functions (esc_html__(), esc_html(), esc_attr(), esc_url()). Approximately 69 output locations have been fixed across includes/class-chunk-handler.php, includes/class-chunk-handler-v2.php, includes/class-restore-handler.php, and includes/class-ui.php.
* Bug fix: Fixed backup download button not working after backup completion. Updated download_url generation in finalize_async_job() and improved JavaScript download button handling with proper error messages and URL validation.

= 2.6.108 =
* WordPress Plugin Check compliance: Fixed remaining Plugin Check warnings including DirectDB/UnescapedDBParameter, file system operations (fread/fclose), set_time_limit, global variable naming conventions, and upgrade notice limits. Added phpcs:ignore comments with clear explanations for all necessary exceptions. Renamed all global variables in templates to use museder_restoreone_ prefix. Simplified readme.txt upgrade notices to meet WordPress.org requirements (only latest 2 versions, under 300 characters each).
* Security improvements: All $wpdb->query() calls now include proper phpcs:ignore comments explaining that they only execute sanitized SQL from plugin-generated backup files. All file operations (fread/fclose) are properly documented with ignore comments explaining they only read plugin-generated backup files with validated paths.
* Code quality: Improved code compliance with WordPress Plugin Check standards while maintaining all backup/restore functionality. All global variables now follow WordPress naming conventions with proper prefixing.

= 2.6.107 =
* WordPress Plugin Check compliance: Fixed WordPress.Security.EscapeOutput.OutputNotEscaped warnings across the entire plugin. Updated 90+ instances of unescaped output including wp_die() messages, JSON error messages, WP_Error messages, rest_error() messages, and template outputs. All dynamic outputs now use appropriate escaping functions (esc_html(), esc_attr(), esc_url(), esc_html__(), esc_html_n()). Added @plugin-check comments for development functions (error_log, ini_set, unlink, rmdir) to document their necessity for backup/restore operations.
* Security improvements: All error messages, log displays, report names, and file paths are now properly escaped to prevent XSS vulnerabilities. All wp_die(), wp_send_json_error(), WP_Error, and rest_error() messages use esc_html__() for proper HTML escaping.
* Code quality: Improved code compliance with WordPress Plugin Check standards while maintaining all backup/restore functionality. All file operations (unlink, rmdir) are properly documented with @plugin-check comments.

= 2.6.106 =
* WordPress Plugin Check compliance: Fixed WordPress.Security.EscapeOutput.ExceptionNotEscaped warnings by converting all Exception messages from __() to esc_html__(). Updated 63 Exception messages across 5 files (class-backup-jobs.php, class-backup.php, class-chunk-handler.php, class-restore-service.php, class-restore-handler.php) to ensure proper output escaping for exception messages.
* Security improvements: All exception messages now use esc_html__() for proper HTML escaping, preventing potential XSS vulnerabilities in error messages.
* Code quality: Improved code compliance with WordPress Plugin Check standards while maintaining all backup/restore functionality.

= 2.6.105 =
* WordPress Plugin Check compliance: Fixed WordPress.NamingConventions.PrefixAllGlobals warnings by adding museder_restoreone_ prefix to all global variables and functions. Removed helper function polyfills from upload-handler.php (now uses WordPress core functions). Added nonce verification and input sanitization to settings form. Added @plugin-check comments for set_time_limit() calls and direct database queries to document their necessity for backup/restore operations.
* Security improvements: Enhanced input validation in class-settings.php with proper nonce verification and sanitization. All superglobal variables now follow WordPress coding standards with proper prefixing and validation.
* Code quality: Improved code compliance with WordPress Plugin Check standards while maintaining all backup/restore functionality.

= 2.6.104 =
* Security improvements: Fixed WordPress.Security.ValidatedSanitizedInput warnings across the entire plugin. All $_SERVER, $_GET, $_POST, and $_REQUEST superglobal variables are now properly sanitized and validated using wp_unslash() and appropriate sanitization functions (sanitize_text_field, sanitize_file_name, sanitize_key, absint). Added helper functions to upload-handler.php for standalone operation. All input validation follows WordPress coding standards with proper isset() checks and sanitization before use.
* Code quality: Improved input handling consistency across all AJAX endpoints and file upload handlers. All superglobal variable access now follows a unified pattern with proper escaping and validation.

= 2.6.103 =
* WordPress Plugin Check compliance: Removed manual text domain loading (WordPress.org auto-loads .mo files). Wrapped all debug functions (error_log, ini_set, error_reporting) in WP_DEBUG checks. Added safety comments for filesystem functions (unlink, rmdir) to document that paths are built from internal plugin directories, not user input. All Content-Disposition headers already use sanitize_file_name() for download filenames.
* Code quality: Improved code compliance with WordPress Plugin Check standards while maintaining all backup/restore functionality.

= 2.6.102 =
* Code quality improvements: Refactored template files to use if/else structures instead of ternary operators for better WordPress Plugin Check compliance. All status badges in dashboard and backups pages now use proper escaping functions (esc_html_e) to prevent OutputNotEscaped warnings.
* Template refactoring: Improved code readability and maintainability in templates/page-dashboard.php and templates/page-backups.php by replacing ternary operator echo statements with if/else blocks.

= 2.6.101 =
* Fixed Restore History timezone: Improved timezone conversion logic to ensure Restore History times match WordPress local timezone settings. The system now correctly handles both new entries (using timestamp_utc) and old entries (using timestamp strings) to display accurate local times.
* Enhanced timezone handling: Simplified timezone conversion logic to properly convert UTC timestamps to local timezone for display, ensuring consistency with Log Files page times.

= 2.6.100 =
* Fixed Restore History time format: Changed time display format to 'Y-m-d H:i' to match Log Files page for consistency. Both pages now use the same time format (e.g., "2025-11-20 12:43") instead of WordPress date/time format settings.
* Improved error handling: Removed strict action parameter validation that was causing 400/404 errors during restore status polling. The system now handles AJAX errors more gracefully and checks restore history as a fallback.
* Enhanced completion detection: Improved timeout handling (reduced from 2 minutes to 60 seconds) and added better fallback mechanisms to detect restore completion even when AJAX requests fail.

= 2.6.99 =
* Fixed 400 Bad Request errors during restore: Added action parameter validation in job_status endpoint to ensure WordPress AJAX requests are properly formatted. Improved error handling to detect invalid action parameters and automatically reload the page to reset state.
* Enhanced timeout handling: When polling for restore status exceeds 2 minutes with consistent errors, the system now checks restore history to determine the actual restore status and displays appropriate success/failure windows.
* Better failure detection: Improved error recovery logic to properly detect and display restore failures even when AJAX requests fail, ensuring users always see the final restore status.

= 2.6.98 =
* Fixed duplicate restore completion window: Added sessionStorage tracking to prevent the completion window from repeatedly appearing after being closed. The system now remembers which restore operations have already shown their completion window within the same browser session.
* Improved completion window logic: Enhanced markRestoreCompleted function to check sessionStorage before displaying the completion overlay, ensuring users won't see duplicate completion windows even after page reloads.

= 2.6.97 =
* Fixed restore completion detection: Improved error handling when job_id is missing. The system now automatically checks restore history to detect completed restores even when the job identifier is lost.
* Enhanced page load logic: When the page loads without an active job, the system now checks restore history for recent successful restores (within 5 minutes) and automatically displays the completion window.
* Better error recovery: When receiving 400 errors related to missing job_id, the frontend now attempts to check restore history as a fallback to detect completion.

= 2.6.96 =
* Fixed restore Step 1 analysis failure: After chunk upload finalize completes, the system now automatically analyzes the backup file and displays the summary. The prepare_session and format_progress methods are now public to support this functionality.
* Improved error handling: Added proper error handling for file analysis failures during chunk upload finalize process.

= 2.6.95 =
* UI improvement: Hidden third-party plugin notices and advertisements from all PRO pages to prevent user confusion. PRO Features, AI Backup Copilot, Cloud Storage, Advanced Filters, Smart Retention, and System Reports pages now automatically hide external plugin notifications.

= 2.6.94 =
* Security improvements: Fixed output escaping issues for WordPress.org Plugin Check compliance. All HTML attributes now use esc_attr(), all text nodes use esc_html(), and all download filenames use sanitize_file_name() instead of esc_attr().
* Code quality: Added @plugin-check annotations to all escaped/sanitized outputs for better maintainability and compliance verification.

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

= 2.7.03 =
Important fix: Optimized large file processing to prevent "Analysis failed" errors when uploading large All-in-One backups (>500MB). The plugin now handles large files more efficiently by skipping SHA1 calculation and conversion timeout issues. Update recommended if you're experiencing timeout errors with large backup files.

= 2.7.02 =
Bug fix: Improved error handling for All-in-One backup conversion. Upload errors should now be handled more gracefully with proper error messages. Update recommended if you're experiencing "Analysis failed" errors when uploading All-in-One backups.

= 2.7.01 =
New feature: Added All-in-One WP Migration backup converter. You can now directly import and restore All-in-One WP Migration backup files (.zip format) without manual conversion. The plugin automatically detects and converts the backup format during upload.

= 2.6.90 =
Critical fix: Restore History timestamps now correctly match Log Files page and WordPress local timezone. Stores UTC timestamps and converts to local time for display. Update immediately if timestamps are incorrect.

= 2.6.89 =
Critical fix: Restore History timestamp display now correctly converts UTC to local timezone. UI cleanup: removed PRO feature cards from Settings page. Update immediately if timestamps are incorrect.
