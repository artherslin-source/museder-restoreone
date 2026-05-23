# Fix Report: 2.7.264 Activation Fails with "Plugin File Does Not Exist"

## Summary

When installing and activating local build `2.7.264` on `https://ciouyinghao.com/`, WordPress shows:

```text
外掛檔案不存在。
```

The activation URL from the screenshot contains this plugin path:

```text
plugin=museder-restoreone-2.7.264%2Fmuseder-restoreone%2Fmuseder-restoreone.php
```

Decoded:

```text
museder-restoreone-2.7.264/museder-restoreone/museder-restoreone.php
```

This strongly indicates the uploaded ZIP package has an incorrect nested directory structure. WordPress is trying to activate a plugin main file under a versioned outer directory, instead of the expected single plugin slug directory.

No code change has been made in this report.

## Expected Package Layout

The Lite package should contain exactly one top-level plugin directory:

```text
museder-restoreone/
  museder-restoreone.php
  readme.txt
  uninstall.php
  assets/
  includes/
  templates/
  languages/
```

The plugin main file path expected by WordPress:

```text
wp-content/plugins/museder-restoreone/museder-restoreone.php
```

## Observed / Broken Layout

The screenshot activation URL implies WordPress installed or referenced:

```text
wp-content/plugins/museder-restoreone-2.7.264/museder-restoreone/museder-restoreone.php
```

Likely ZIP layout:

```text
museder-restoreone-2.7.264/
  museder-restoreone/
    museder-restoreone.php
    readme.txt
    assets/
    includes/
    templates/
```

This is a double-wrapped plugin folder.

## Root Cause

The package uploaded to WordPress was likely not generated from the official `create-package.sh` output, or was manually zipped from a parent/versioned folder.

The official packager is designed to create:

```text
dist/museder-restoreone-2.7.264.zip
```

with this internal structure:

```text
museder-restoreone/museder-restoreone.php
```

Relevant packager:

```text
create-package.sh
```

Relevant lines:

```bash
PLUGIN_SLUG="museder-restoreone"
PACKAGE_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"
zip -r "${OUTPUT_FILE}" "${PLUGIN_SLUG}" -q
```

Therefore the ZIP filename may include the version, but the ZIP internal top-level folder must remain:

```text
museder-restoreone/
```

not:

```text
museder-restoreone-2.7.264/
```

## Version State

Local source currently appears version-aligned:

```text
museder-restoreone.php Version: 2.7.264
MUSEDER_RESTOREONE_VERSION: 2.7.264
MUSEDER_RESTOREONE_BUILD_ID: 2.7.264-1
readme.txt Stable tag: 2.7.264
```

The source version itself is not the suspected cause of this activation failure.

## Required Fix

### Fix 1: Rebuild the Release ZIP from the Official Script

From the repository root, build the Lite package using:

```bash
bash create-package.sh
```

Expected output:

```text
dist/museder-restoreone-2.7.264.zip
```

### Fix 2: Verify ZIP Structure Before Upload

Before uploading, inspect the ZIP and confirm:

```text
museder-restoreone/museder-restoreone.php
```

is present at exactly one folder depth.

The ZIP must not contain:

```text
museder-restoreone-2.7.264/museder-restoreone/museder-restoreone.php
```

The ZIP must not contain dev-only paths:

```text
docs/
tools/
logs/
.git/
.github/
.cursor/
dist/
museder-restoreone-pro/
```

### Fix 3: Clean the Broken Install on the Test Site

On the test site, remove the broken plugin directory before reinstalling:

```text
wp-content/plugins/museder-restoreone-2.7.264/
```

If another old copy exists, also verify whether this directory is present:

```text
wp-content/plugins/museder-restoreone/
```

The final installed Lite plugin directory should be:

```text
wp-content/plugins/museder-restoreone/
```

## Recommended Developer-Agent Tasks

1. Build the package with `bash create-package.sh`.
2. Inspect `dist/museder-restoreone-2.7.264.zip` structure.
3. Confirm the ZIP contains a single top-level directory named `museder-restoreone`.
4. Confirm the ZIP does not contain source-control, logs, docs, development artifacts, or Pro add-on code.
5. Provide the verified ZIP to the user for upload.
6. If the team wants stronger safety, add a package verification script or CI check that fails if the ZIP top-level folder is not exactly `museder-restoreone/`.

## Suggested Guardrail Improvement

Add a lightweight package verification command/script, for example:

```text
tools/release/verify-lite-package-structure
```

It should assert:

- ZIP exists at `dist/museder-restoreone-<version>.zip`.
- First-level folder is exactly `museder-restoreone/`.
- `museder-restoreone/museder-restoreone.php` exists.
- No `museder-restoreone-<version>/museder-restoreone/` nested path exists.
- No disallowed dev-only paths exist.
- Main file `Version`, `MUSEDER_RESTOREONE_VERSION`, and `readme.txt Stable tag` match.

This is optional but recommended because this failure is easy to reproduce when a developer manually compresses the wrong folder.

## Validation Plan

### Local ZIP Validation

Run or manually verify:

```text
dist/museder-restoreone-2.7.264.zip
└── museder-restoreone/
    └── museder-restoreone.php
```

Reject if either of these paths exists:

```text
museder-restoreone-2.7.264/museder-restoreone/museder-restoreone.php
museder-restoreone-2.7.264/museder-restoreone.php
```

### WordPress Test Site Validation

1. Delete broken directory:

```text
wp-content/plugins/museder-restoreone-2.7.264/
```

2. Upload verified `dist/museder-restoreone-2.7.264.zip`.
3. Activate plugin.
4. Confirm activation URL uses:

```text
plugin=museder-restoreone%2Fmuseder-restoreone.php
```

not:

```text
plugin=museder-restoreone-2.7.264%2Fmuseder-restoreone%2Fmuseder-restoreone.php
```

5. Confirm WordPress does not show:

```text
外掛檔案不存在。
```

6. Confirm plugin appears as:

```text
Museder RestoreOne – WP Backup & Restore
Version 2.7.264
```

## Notes About Console Errors

The screenshot also shows browser console messages such as:

```text
MetaMask: Connected to chain...
A listener indicated an asynchronous response...
```

These are browser-extension/message-channel warnings and are not the root cause of WordPress showing `外掛檔案不存在。`.

The root cause is the server-side WordPress plugin path being wrong.

## WordPress.org Compliance Notes

The fixed Lite ZIP must continue to exclude:

- `docs/`
- `tools/`
- `.cursor/`
- `.github/`
- `logs/`
- `dist/`
- `museder-restoreone-pro/`
- generated ZIPs
- AI/debug artifacts

The current local main file already uses:

```text
Author: Adrian Lin
Author URI: https://profiles.wordpress.org/artherslin/
```

Keep `readme.txt`:

```text
Contributors: artherslin
```

