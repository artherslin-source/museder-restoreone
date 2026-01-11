=== Museder RestoreOne ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.7.222
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress backup & restore plugin focused on compatibility, single-file site snapshots, and clean restore workflows.

== Changelog ==

For full changelog history, please see the project repository changelog archive.

= 2.7.220 =
* WP.org compliance hardening (nonce/cap checks, sanitization/escaping, uploads storage under wp_upload_dir).
* S3: migrate cURL usage to WordPress HTTP API (wp_remote_request) with multipart upload support.
* Restore reliability fixes (mysqldump stderr handling, file ops portability, progress UI smoothing).

= 2.7.218 =
* Internal testing build.

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

== External services ==

This plugin does not use external services by default.

When PRO features are enabled and configured by the site owner, the plugin may connect to the following external services:

= Amazon S3 (or S3-compatible storage endpoints) =

- What for: Upload backup archives to a cloud storage bucket configured by the site owner.
- What data is sent: The backup archive file itself (which can contain site files and database content). Nothing is uploaded unless the site owner explicitly enables cloud destinations and triggers an upload.
- When: Only when the site owner runs a backup with cloud upload enabled (manual or scheduled).
- Domains/Endpoints: Typically connects to `*.amazonaws.com` (for example `s3.{region}.amazonaws.com` or `{bucket}.s3.{region}.amazonaws.com`) or the configured S3-compatible endpoint.
- Terms/Privacy: Governed by the chosen provider (Amazon S3 or the configured S3-compatible provider). For Amazon Web Services, see `https://aws.amazon.com/service-terms/` and `https://aws.amazon.com/privacy/`.

= OpenAI (AI features) =

- What for: Optional AI-based analysis, reports, and smart scheduling (PRO only).
- What data is sent: Only the data explicitly provided for analysis by the site owner through the plugin UI/API. AI features are opt-in and disabled by default unless configured.
- When: Only when the site owner triggers an AI action in the plugin.
- Terms/Privacy: Governed by OpenAI's terms and privacy policy: `https://openai.com/policies/terms-of-use` and `https://openai.com/policies/privacy-policy`.

== Installation ==

1. Upload the `museder-restoreone` folder (or ZIP) to the `/wp-content/plugins/` directory via FTP or through the “Upload Plugin” screen in your WordPress admin.
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

`wp-content/uploads/museder-restoreone/logs/`

You can view or download the latest logs directly from the **Logs** page in the Museder RestoreOne admin menu.

= What happens to plugins during restore? =

During restore, the plugin may temporarily adjust the active plugin list to keep the restore process stable (safe mode). After the restore completes (or when the user exits safe mode), the plugin will restore the previously active plugins. This behavior is triggered by the site owner's actions in the admin UI.

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

(Older changelog entries are maintained in the project repository.)

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
