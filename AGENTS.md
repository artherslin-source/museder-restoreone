# Museder RestoreOne — Agent Instructions

This repository is a WordPress.org-approved plugin project. All AI agents, including Cursor Cloud Agents, must follow the project compliance guide before editing, reviewing, packaging, or releasing code.

## Required Guidance (WordPress.org)

Before making changes, read and apply:

- `.cursor/skills/museder-wporg-compliance/SKILL.md`
- `.cursor/skills/museder-wporg-compliance/REFERENCE.md`
- `docs/DEVELOPMENT_WORKFLOW.md`
- `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`
- `docs/RELEASE_CHECKLIST.md` before release-facing work
- `docs/PACKAGING.md` before building or uploading Lite ZIP (Windows: never use `Compress-Archive`)
- `docs/NEW_MACHINE_BOOTSTRAP.md` before machine handoff or environment setup

### Core Rules

- Keep the WordPress.org Lite package fully functional and GPL-compatible.
- Do not include locked local PRO features, license gates, trialware, quota/time restrictions, or `museder-restoreone-pro/` in the Lite package.
- Use project prefixes: `museder_restoreone_`, `MUSEDER_RESTOREONE_`, `Museder_Restoreone_`, `museder-restoreone/v*`, and `MusederRestoreOne*`.
- Every sensitive AJAX, REST, and form action must verify capability, verify nonce correctly, sanitize/validate input, and escape output.
- Use WordPress APIs for filesystem paths, uploads, HTTP requests, scripts/styles, and URLs.
- Generated runtime data belongs under `wp_upload_dir()/museder-restoreone`.
- Release packages must exclude docs, tools, logs, zip files, AI outputs, Git metadata, GitHub workflow files, review emails, and other development-only artifacts.
- Run or request Plugin Check and clean WordPress `WP_DEBUG` smoke tests before release-facing changes.

## Superpowers (obra/superpowers)

This repository ships [Superpowers](https://github.com/obra/superpowers) for Cursor / Cloud Agents:

- **Skills:** `.cursor/skills/` (superpowers + `karpathy-guidelines` + `museder-wporg-compliance`; superpowers symlinked from `.cursor/superpowers/skills/`)
- **Session bootstrap:** `.cursor/hooks.json` → `sessionStart` injects `using-superpowers` context
- **Version lock:** `.cursor/superpowers-lock.json`

After clone, initialize the submodule:

```bash
git submodule update --init --recursive .cursor/superpowers
```

**Windows:** Git may checkout skill symlinks as plain text files (broken). After submodule init, run:

```powershell
powershell -ExecutionPolicy Bypass -File tools\cursor\fix-superpowers-skills-windows.ps1
```

On a **new machine**, also install globally (optional but recommended):

```bash
./tools/cursor/setup-global-superpowers.sh
./tools/cursor/setup-global-karpathy-guidelines.sh
```

Or in Cursor chat: `/add-plugin superpowers`

### When to use which skill

| Task | Skill |
|------|--------|
| WordPress.org / plugin compliance | `museder-wporg-compliance` |
| New feature / behavior change | `brainstorming` → `writing-plans` → `subagent-driven-development` or `executing-plans` |
| Bug or test failure | `systematic-debugging` |
| Implementation | `test-driven-development` |
| Before claiming done | `verification-before-completion` |
| Between tasks | `requesting-code-review` |
| All write / review / refactor | `karpathy-guidelines` |

Follow skills via the Skill tool (or read `SKILL.md` in Cloud Agent). Do not skip `using-superpowers` workflow rules.

## Karpathy Guidelines

Behavioral skill from [forrestchang/andrej-karpathy-skills](https://github.com/forrestchang/andrej-karpathy-skills):

- **Project skill:** `.cursor/skills/karpathy-guidelines/SKILL.md` (committed; Cloud Agents load automatically)
- **Version lock:** `.cursor/karpathy-guidelines-lock.json`

Apply **karpathy-guidelines** for all write, review, and refactor work:

1. Think before coding — state assumptions; ask when unclear
2. Simplicity first — minimum code for the request; no speculative abstractions
3. Surgical changes — touch only what the task requires
4. Goal-driven execution — verifiable success criteria (tests, repro steps)

## Cursor Cloud specific instructions

- **Plugin:** WordPress backup/restore (PHP 7.4+, GPLv2). Main file: `museder-restoreone.php`; logic in `includes/`.
- **Do not commit:** `logs/`, local zip archives, secrets, `.env.local`.
- **Testing:** Prefer changes verifiable without a full WordPress install when possible; document manual QA steps for admin UI / backup-restore flows.
- **Submodule:** Cloud Agent runs must run `git submodule update --init` if `.cursor/superpowers` is empty.
- Treat GitHub as the development repository and WordPress.org SVN as a release repository.
- Work on a feature/agent branch by default; do not push directly to `main` unless the user explicitly requests it.
- Formal packaging is triggered by pushing a `v*` Git tag; only do this after explicit user approval.
- Commit only source and project guidance files, not generated release zip files.
- If a task requires secrets, credentials, WordPress.org SVN access, or external service keys, stop and ask for the required Cloud Agent environment configuration.

## Project layout

- `includes/` — core classes (backup, restore, UI, S3, schedules)
- `templates/` — admin UI templates
- `assets/` — admin CSS/JS (vendored libs under `assets/vendor/`)
- `languages/` — translations
- `docs/` — internal documentation
