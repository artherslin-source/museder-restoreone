# Museder RestoreOne — Agent Instructions

This repository is a WordPress.org-approved plugin project. All AI agents, including Cursor Cloud Agents, must follow the project compliance guide before editing, reviewing, packaging, or releasing code.

## Required Guidance (WordPress.org)

Before making changes, read and apply:

- `.cursor/skills/museder-wporg-compliance/SKILL.md`
- `.cursor/skills/museder-wporg-compliance/REFERENCE.md`
- `docs/DEVELOPMENT_WORKFLOW.md`
- `docs/WORDPRESS_ORG_DEVELOPMENT_GUIDE.md`
- `docs/RELEASE_CHECKLIST.md` before release-facing work
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
| SEO audit, ranking, readme, Schema, AI search | `seo-shen` 或 `seo-audit` / `ai-seo` / `schema` |
| `<請神：SEO神>` 模式 | `seo-shen`（見 `docs/CURSOR-請神-SEO神.md`） |
| New feature / behavior change | `brainstorming` → `writing-plans` → `subagent-driven-development` or `executing-plans` |
| Bug or test failure | `systematic-debugging` |
| Implementation | `test-driven-development` |
| Before claiming done | `verification-before-completion` |
| Between tasks | `requesting-code-review` |
| All write / review / refactor | `karpathy-guidelines` |

Follow skills via the Skill tool (or read `SKILL.md` in Cloud Agent). Do not skip `using-superpowers` workflow rules.

## Marketing Skills SEO（coreyhaines31/marketingskills）

SEO 子集：`.cursor/skills/`（submodule `.cursor/marketingskills`）— `product-marketing`, `seo-audit`, `ai-seo`, `programmatic-seo`, `schema`, `site-architecture`, `content-strategy`, `competitors`

- **鎖定版本：** `.cursor/marketingskills-lock.json`
- **產品上下文：** `.agents/product-marketing.md`
- **請神：SEO神：** `<請神：SEO神>` → `seo-shen` skill（詳見 `docs/CURSOR-請神-SEO神.md`）
- **全域安裝：** `tools/cursor/setup-global-marketingskills-seo.sh` / `.ps1`

```bash
git submodule update --init --recursive .cursor/marketingskills .cursor/superpowers
```

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
- **Submodules:** Run `git submodule update --init --recursive .cursor/superpowers .cursor/marketingskills` if skill folders are empty.
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
