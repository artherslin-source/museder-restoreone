# Museder RestoreOne — Agent Instructions

## Superpowers (obra/superpowers)

This repository ships [Superpowers](https://github.com/obra/superpowers) for Cursor / Cloud Agents:

- **Skills:** `.cursor/skills/` (superpowers + `karpathy-guidelines`; superpowers symlinked from `.cursor/superpowers/skills/`)
- **Session bootstrap:** `.cursor/hooks.json` → `sessionStart` injects `using-superpowers` context
- **Version lock:** `.cursor/superpowers-lock.json`

After clone, initialize the submodule:

```bash
git submodule update --init --recursive .cursor/superpowers
```

On a **new machine**, also install globally (optional but recommended):

```bash
./tools/cursor/setup-global-superpowers.sh
```

Or in Cursor chat: `/add-plugin superpowers`

### When to use which skill

| Task | Skill |
|------|--------|
| New feature / behavior change | `brainstorming` → `writing-plans` → `subagent-driven-development` or `executing-plans` |
| Bug or test failure | `systematic-debugging` |
| Implementation | `test-driven-development` |
| Before claiming done | `verification-before-completion` |
| Between tasks | `requesting-code-review` |

Follow skills via the Skill tool (or read `SKILL.md` in Cloud Agent). Do not skip `using-superpowers` workflow rules.

## Karpathy Guidelines

Behavioral skill from [forrestchang/andrej-karpathy-skills](https://github.com/forrestchang/andrej-karpathy-skills) (Andrej Karpathy–style coding discipline):

- **Project skill:** `.cursor/skills/karpathy-guidelines/SKILL.md` (committed; Cloud Agents load automatically)
- **Version lock:** `.cursor/karpathy-guidelines-lock.json`

Apply **karpathy-guidelines** for all write, review, and refactor work:

1. Think before coding — state assumptions; ask when unclear
2. Simplicity first — minimum code for the request; no speculative abstractions
3. Surgical changes — touch only what the task requires
4. Goal-driven execution — verifiable success criteria (tests, repro steps)

Global install on a developer machine:

```bash
./tools/cursor/setup-global-karpathy-guidelines.sh
```

## Cursor Cloud specific instructions

- **Plugin:** WordPress backup/restore (PHP 7.4+, GPLv2). Main file: `museder-restoreone.php`; logic in `includes/`.
- **Do not commit:** `logs/`, local zip archives, secrets, `.env.local`.
- **Testing:** Prefer changes verifiable without a full WordPress install when possible; document manual QA steps for admin UI / backup-restore flows.
- **WordPress standards:** Nonces, capabilities, sanitization, escaping, `wp_upload_dir()` for storage, WP HTTP API (not raw cURL) for remote calls.
- **Submodule:** Cloud Agent runs must run `git submodule update --init` if `.cursor/superpowers` is empty.

## Project layout

- `includes/` — core classes (backup, restore, UI, S3, schedules)
- `templates/` — admin UI templates
- `assets/` — admin CSS/JS (vendored libs under `assets/vendor/`)
- `languages/` — translations
- `docs/` — internal documentation
