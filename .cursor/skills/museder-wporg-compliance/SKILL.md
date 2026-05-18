---
name: museder-wporg-compliance
description: Enforce Museder RestoreOne WordPress.org compliance for PHP, JS, readme, packaging, release, backup/restore, Lite/Add-on separation, security, filesystem, REST/AJAX, external services, and Plugin Check work. Use before modifying or reviewing this WordPress plugin.
---

# Museder RestoreOne WordPress.org Compliance

## Always Start Here

Before writing, refactoring, or reviewing Museder RestoreOne code:

1. Decide whether the change belongs to Lite or Add-on.
2. Read [REFERENCE.md](REFERENCE.md) for the relevant checklist.
3. Keep Lite WordPress.org package fully functional, GPL-compatible, and free of locked local PRO functionality.
4. Keep release packages clean: no `docs/`, `tools/`, `.git/`, `.github/`, `logs/`, zip files, AI outputs, review emails, or `museder-restoreone-pro/` in Lite.

## Non-Negotiable Rules

- Lite must not include license gates, trialware, quota/time locks, or PRO-only local code hidden behind `is_pro_active()`.
- New project identifiers must use `museder_restoreone_`, `MUSEDER_RESTOREONE_`, `Museder_Restoreone_`, `museder-restoreone/v*`, or `MusederRestoreOne*`. Add-on code must use the matching `pro` / `addon` prefix.
- Do not add new `backup_lite_*` names except documented one-time migration/legacy reads.
- Every sensitive AJAX handler needs `current_user_can()` and `check_ajax_referer()`.
- Every sensitive REST route needs a real `permission_callback` that verifies capability and nonce; never use public `__return_true` unless the endpoint is intentionally public and documented.
- Sanitize early, validate always, escape late. Treat `json_decode()` output as unsanitized.
- Use WordPress path and URL APIs. Write generated files only under `wp_upload_dir()['basedir']/museder-restoreone/`.
- Use WordPress HTTP API for network calls. Any third-party service requires readme disclosure: purpose, data sent, timing, Terms, Privacy.
- Use enqueue APIs for JS/CSS; avoid raw `<script>` / `<style>` in templates.
- Run or request Plugin Check and clean-install smoke tests before release-facing changes.

## Backup/Restore Specifics

- Large-file stream I/O is allowed only with controlled paths and narrow PHPCS annotations explaining why WP_Filesystem is unsuitable.
- Direct SQL is allowed only when identifiers are allowlisted/validated and values are prepared. Keep PHPCS ignores local and explanatory.
- Never modify `active_plugins` to enable/disable other plugins automatically.
- HMAC download tokens must validate file name, expiry, action, path boundary, and use `hash_equals()`.

## Release Checklist

- Main plugin `Version`, `readme.txt` `Stable tag`, Git tag, package name, and SVN tag agree.
- Lite package excludes Add-on and dev-only paths.
- `readme.txt` has one changelog, accurate external-service/privacy notes, reachable Plugin URI / Author URI, and no marketplace-style locked-feature copy.
- WordPress.org SVN is treated as a release system: commit only ready-to-use files, tag every release, never upload zip files to SVN.

## Reference

- Full guide: `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`
- Detailed checklist: [REFERENCE.md](REFERENCE.md)

