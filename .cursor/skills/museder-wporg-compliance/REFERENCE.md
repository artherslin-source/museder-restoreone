# Museder RestoreOne WordPress.org Compliance Reference

This reference condenses the WordPress.org review history in `docs/000WP開發團隊/` and the official Plugin Handbook pages under `developer.wordpress.org/plugins/wordpress-org/`.

## Review History Lessons

- Initial automated review flagged sanitization, nonce/capability checks, prefixes, enqueue usage, trialware/locked features, REST `permission_callback`, outdated libraries, and direct file access.
- Manual reviews repeatedly flagged external-service disclosure, path detection, direct core loading, global PHP limits, unsafe SQL, processing whole input, direct plugin activation changes, and unneeded AI/development files in packages.
- Approval came after the project converged on: Lite-only package, PRO as separate add-on, no third-party service by default except local `wp-cron.php` loopback disclosure, stricter REST/AJAX nonce checks, path boundary checks, clean package contents, and Plugin Check passing.

## Lite vs Add-on

- Lite on WordPress.org must be complete and usable without payment, license key, trial, quota, or hidden local code.
- Premium/local-implementable functionality must live outside the WordPress.org package as a separate add-on.
- Lite may mention optional add-ons neutrally, but must not ship disabled feature code or aggressive upsell UI.

## Naming

- Use `museder_restoreone_` for functions, options, transients, cron hooks, AJAX actions, filters, and actions.
- Use `MUSEDER_RESTOREONE_` for constants.
- Use `Museder_Restoreone_` for PHP classes.
- Use `museder-restoreone/v1` or `museder-restoreone/v2` for REST namespaces.
- Use `MusederRestoreOne*` for JavaScript globals.
- Add-on code must include a `pro` / `addon` distinction in identifiers.

## Request Security

- AJAX:
  - Verify capability before action.
  - Verify nonce with `check_ajax_referer()`.
  - Sanitize only fields used by that handler.
- REST:
  - Always provide `permission_callback`.
  - For admin/sensitive routes, require `current_user_can( 'manage_options' )`.
  - Verify `X-WP-Nonce` / `wp_rest`; do not merely check it is non-empty.
- Forms:
  - Use `wp_nonce_field()` and `check_admin_referer()`.
- Download links:
  - Prefer nonce for authenticated admin flows.
  - If using HMAC tokens, validate expiry, filename, action, controlled path, and compare using `hash_equals()`.

## Input and Output

- Sanitize as soon as input is read.
- Validate against expected shape, enum, range, extension, MIME, size, UUID, or path boundary.
- Escape at the output site.
- After `json_decode()`, sanitize and validate each decoded field.
- Do not process all of `$_POST`, `$_GET`, or `$_REQUEST`; read only expected fields.

## File and Path Rules

- Prefer `plugin_dir_path()`, `plugin_dir_url()`, `plugins_url()`, `plugin_basename()`, `wp_upload_dir()`, `admin_url()`, and related WordPress helpers.
- Do not hardcode `wp-content`, `uploads`, `plugins`, or theme paths.
- Avoid direct use of `ABSPATH`, `WP_CONTENT_DIR`, and `WP_PLUGIN_DIR`; use helper wrappers if unavoidable.
- Store writable runtime data only under `uploads/museder-restoreone/`.
- Resolve user-controlled filenames through dedicated helpers, `sanitize_file_name()`, normalized path joins, and prefix/realpath boundary checks.
- Every executable PHP file needs an `ABSPATH` guard.

## Large File I/O

- Stream APIs (`fopen`, `fread`, `fwrite`, `fgets`, `stream_copy_to_stream`) are acceptable for backup/restore hot paths when:
  - Paths are plugin-controlled.
  - User input cannot select arbitrary paths.
  - PHPCS annotations are local and explain large-file streaming / avoiding OOM / WP_Filesystem limits.
- Prefer `wp_delete_file()` for deletion.

## Database

- Prepare values with `$wpdb->prepare()`.
- Use placeholders for each item in arrays.
- Validate table/column identifiers using allowlists or database introspection, never raw input.
- Keep direct-query ignores narrow, with comments explaining trusted source, identifier validation, and value preparation.
- Do not automatically alter `active_plugins`.

## External Services

- Default Lite behavior should avoid third-party services.
- If a service is used, it must provide real external processing and be documented in `readme.txt`.
- Required disclosure: service name, purpose, data sent, when sent, Terms link, Privacy link.
- Tracking/telemetry/error reporting must be opt-in and documented.
- Use WordPress HTTP API, not direct cURL.

## UI and Assets

- Use `wp_enqueue_script()`, `wp_enqueue_style()`, `wp_add_inline_script()`, and `wp_add_inline_style()`.
- Avoid raw `<script>` / `<style>` blocks in PHP templates.
- Use plugin-prefixed handles and JS globals.
- Localize strings with text domain `museder-restoreone`; escape translated output.

## Packaging and SVN

- WordPress.org SVN is a release repository. Do not commit every development change.
- Never upload zip files to SVN.
- Keep `trunk` updated with ready code and copy `trunk` to `tags/<version>` for releases.
- Version must be incremented for each release.
- Exclude development-only files from distributable packages.

## Pre-Release Gate

Before publishing:

- Build the zip from scripts.
- Inspect package contents for dev-only paths and Add-on leakage.
- Run Plugin Check / PHPCS WPCS.
- Test on clean WordPress with `WP_DEBUG` true.
- Smoke-test Dashboard, Backup, Restore, Schedules, Logs, Settings.
- Verify readme, external services, changelog, privacy, version, and stable tag.

