# Museder RestoreOne Agent Instructions

This repository is a WordPress.org-approved plugin project. All AI agents, including Cursor Cloud Agents, must follow the project compliance guide before editing, reviewing, packaging, or releasing code.

## Required Guidance

Before making changes, read and apply:

- `.cursor/skills/museder-wporg-compliance/SKILL.md`
- `.cursor/skills/museder-wporg-compliance/REFERENCE.md`
- `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`

## Core Rules

- Keep the WordPress.org Lite package fully functional and GPL-compatible.
- Do not include locked local PRO features, license gates, trialware, quota/time restrictions, or `museder-restoreone-pro/` in the Lite package.
- Use project prefixes: `museder_restoreone_`, `MUSEDER_RESTOREONE_`, `Museder_Restoreone_`, `museder-restoreone/v*`, and `MusederRestoreOne*`.
- Every sensitive AJAX, REST, and form action must verify capability, verify nonce correctly, sanitize/validate input, and escape output.
- Use WordPress APIs for filesystem paths, uploads, HTTP requests, scripts/styles, and URLs.
- Generated runtime data belongs under `wp_upload_dir()/museder-restoreone`.
- Release packages must exclude docs, tools, logs, zip files, AI outputs, Git metadata, GitHub workflow files, review emails, and other development-only artifacts.
- Run or request Plugin Check and clean WordPress `WP_DEBUG` smoke tests before release-facing changes.

## Cloud Agent Notes

- Treat GitHub as the development repository and WordPress.org SVN as a release repository.
- Commit only source and project guidance files, not generated release zip files.
- If a task requires secrets, credentials, WordPress.org SVN access, or external service keys, stop and ask for the required Cloud Agent environment configuration.

