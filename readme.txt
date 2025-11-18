=== Museder RestoreOne – Backup & One-Click Restore ===
Contributors: artherslin
Tags: backup, migration, restore, site-backup, database-backup
Requires at least: 6.8.3
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 2.6.38
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

== Screenshots ==

1. Dashboard with environment compatibility, recent backups, and schedule overview.
2. Backups page showing available backups and the backup progress bar.
3. Restore Center 3-step wizard: upload & analyze, review options, execute restore.
4. Schedules page listing upcoming backup jobs and quick schedule builder.
5. Logs page with log file list and preview panel.
6. Settings page with general options and system diagnostics.

== Changelog ==

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

= 2.6.38 =
Packaging refresh so the latest download/restore fixes are present in the official ZIP. Update if you previously downloaded 2.6.36/2.6.37 directly from Git without the signed link fix.
