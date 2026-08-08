---
name: museder-restoreone-release-ops
description: >-
  Operate Museder RestoreOne Free (WordPress backup/restore plugin): WP.org
  compliance checks, Docker small/large functional tests, backup finalize/repack
  pitfalls, version bump + create-package.sh, and knowledge-base updates.
  Use when releasing, debugging production backup stuck near 95%, running FT,
  or updating docs/knowledge or review risk lists.
---

# Museder RestoreOne — Release & Ops Skill

## When to use

- Shipping a Free plugin version / ZIP for WP.org or staging.
- Production backup appears stuck near **95%**.
- Running Docker functional tests (small vs **8080** large stack).
- Updating review risk lists or `docs/knowledge/*`.

## Authority order (conflicts)

1. **Code + `readme.txt` Stable tag** (product truth).  
2. `docs/knowledge/指引__operating-guidelines.md` + `EVOLUTION-INDEX.md` (**現行**).  
3. Latest unclosed items in `docs/2026-05-06__v2.7.258*` / `259*` risk lists.  
4. `docs/wp-compliance-checklist.md`.  
5. Older WP-REVIEW / VERSION_DEVELOPMENT_HIGHLIGHTS — **historical only** if marked stale in the evolution index.

Do **not** treat `VERSION_DEVELOPMENT_HIGHLIGHTS.md` as current (may describe removed Pro/S3/2.8 narratives).

## Hard rules

- Never ship `docs/`, `tools/`, `logs/`, `reports/`, `dist/`, giant demo ZIPs in the WP.org package (`./create-package.sh`).  
- Free core paths (backup / restore wizard / schedules) must not be paywalled.  
- No new third-party HTTP without readme External services / Privacy updates.  
- Path checks: `realpath` + trailing-slash directory prefix.  
- If `pack_method` is `pclzip`, **do not** keep a long-lived `ZipArchive` handle on the same archive file.  
- Large-site FT: pin `COMPOSE_FILE` to repo-root `docker-compose.yml` and a stable `COMPOSE_PROJECT_NAME` (default stack port **8080**).

## Workflows

### A) Production backup stuck ~95%

1. Collect plugin log; search `Archive verify`, `repack`, `Closed ZipArchive`, `Time budget`, `pack_method`.  
2. Confirm running `version` / `build_id` in log heartbeat.  
3. If verify failed then PclZip repack + slow closes: ensure fix from **≥2.7.262** is deployed.  
4. Prefer fixing false-negative verify or Zip/PclZip handle conflict over “just raising timeouts”.

### B) Small + large FT

```bash
# Small
./tools/functional-test/run-clean-small-site-ft.sh

# Large (same 8080 stack)
COMPOSE_PROJECT_NAME=museder-restoreone \
MR_FT_SKIP_SMALL=1 MR_FT_RUN_LARGE=1 \
./tools/functional-test/run-functional-test.sh
```

Mail smoke may normalize `wp_mail_from` for Docker; `wp_mail` false without MTA is OK if `phpmailer_init` fired.

### C) Version bump + package

1. Bump `museder-restoreone.php` Version / VERSION / BUILD_ID.  
2. Sync `readme.txt` Stable tag + Changelog + Upgrade Notice.  
3. `./create-package.sh` → `dist/museder-restoreone-<ver>.zip`.  
4. Add/update `reports/museder-restoreone-<ver>-validation.md`.  
5. Append a row to `docs/knowledge/EVOLUTION-INDEX.md` for material changes.

### D) Knowledge archive updates

- Lessons → `docs/knowledge/心得__lessons-learned.md`  
- Procedures → `docs/knowledge/指引__operating-guidelines.md`  
- Supersede/conflict → `docs/knowledge/EVOLUTION-INDEX.md` (never delete old review docs)  
- Session digests → `docs/knowledge/session-digests/` (not `archive/` — root `.gitignore` ignores `archive/`)

## References

- `docs/knowledge/README.md`  
- `tools/functional-test/README.md`  
- `docs/PROJECT-ORGANIZATION-PLAN.md`
