# Free / PRO Two-Phase Split Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restructure `Museder RestoreOne` so Phase 1 keeps Free and PRO in one shared codebase with cleaner boundaries, and Phase 2 moves PRO into a separate add-on plugin without breaking Free core compatibility.

**Architecture:** Phase 1 is a logical separation pass only: centralize capabilities, replace scattered `is_pro_active()` checks with a stable integration contract, and make Free core expose extension points that future PRO code can plug into. Phase 2 is the first physical split: create a `museder-restoreone-pro` add-on plugin, move PRO modules into it, and keep compatibility/version checks explicit between Free and PRO.

**Tech Stack:** WordPress plugin architecture, PHP 7.4+, WordPress hooks/options/admin pages, existing `Museder RestoreOne` backup/restore/schedule UI modules, plugin packaging/build scripts.

---

## Assumptions and Constraints

- Phase 1 must **not** physically split Free and PRO into two installable plugin packages.
- Free must remain reviewer-friendly for WP.org during the transition.
- PRO may continue to be developed during Phase 1, but any new PRO work must target extraction-ready boundaries.
- Do not fork backup/restore core into parallel Free and PRO implementations.
- Keep existing data structures compatible unless the migration path is explicitly implemented and tested.

## Reference Material

- Master strategy: `docs/plans/2026-03-12-museder-restoreone-pro-master-plan.md`
- Main bootstrap: `museder-restoreone.php`
- Current PRO gate: `includes/class-pro.php`
- Admin/UI integration: `includes/class-ui.php`
- Backup integration points: `includes/class-backup.php`
- Schedule integration points: `includes/class-schedule-handler.php`
- Restore flow pages and orchestration:
  - `includes/class-restore-handler.php`
  - `includes/class-restore-service.php`
  - `includes/class-restore-controller.php`
  - `templates/page-restore.php`
  - `assets/js/admin-ui.js`

## Phase Overview

```mermaid
flowchart LR
    currentState[CurrentSingleCodebase] --> phase1[Phase1_LogicalSeparation]
    phase1 --> phase2[Phase2_PhysicalSplit]
    phase2 --> result[FreeCorePlusProAddon]
```

### Phase 1 Outcome

- Free and PRO still ship from one shared codebase.
- PRO features use a central contract instead of scattered direct checks.
- Core backup/restore/schedule/UI code knows less about PRO internals.
- Future extraction of `includes/pro/*` becomes low-risk.

### Phase 2 Outcome

- `Museder RestoreOne` becomes the Free core plugin.
- `Museder RestoreOne PRO` becomes a separate add-on plugin.
- Free owns core backup/restore/schedule/jobs/logs/UI.
- PRO owns enhancement layers and depends on Free.

## Task 1: Audit and Freeze the Split Contract

**Files:**
- Modify: `docs/plans/2026-03-12-museder-restoreone-pro-master-plan.md`
- Create: `docs/plans/2026-03-12-free-pro-compatibility-policy.md`
- Modify: `museder-restoreone.php`
- Modify: `includes/class-pro.php`
- Test: `museder-restoreone.php`
- Test: `includes/class-pro.php`

**Step 1: Write the failing test**

Document the contract the code does **not** currently satisfy:

```markdown
- Free bootstrap still requires `includes/class-pro.php` directly.
- Core modules call `Museder_Restoreone_Pro::is_pro_active()` directly.
- No explicit Free/PRO compatibility API exists.
- No separate compatibility policy file exists.
```

Expected failure condition: there is no documented, versioned integration contract to guide split-safe development.

**Step 2: Run test to verify it fails**

Run:

```bash
php -l "museder-restoreone.php" && php -l "includes/class-pro.php"
```

Expected: `No syntax errors detected`, but the architecture audit still fails because the split contract is undocumented and not versioned.

**Step 3: Write minimal implementation**

Create a compatibility policy document that defines:

- what Free owns
- what PRO owns
- what Phase 1 may and may not do
- minimum compatibility rules between Free and future PRO
- a target integration API surface

Update `includes/class-pro.php` planning notes so the class is treated as a capability registry/bootstrap contract, not only a boolean on/off helper.

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "museder-restoreone.php" && php -l "includes/class-pro.php"
```

Expected: syntax passes, and the repository now contains an explicit compatibility policy file referenced by the plan.

**Step 5: Commit**

```bash
git add docs/plans/2026-03-12-free-pro-compatibility-policy.md docs/plans/2026-03-12-museder-restoreone-pro-master-plan.md museder-restoreone.php includes/class-pro.php
git commit -m "docs: define free and pro split contract"
```

## Task 2: Centralize Capability Resolution in Free Core

**Files:**
- Modify: `includes/class-pro.php`
- Modify: `museder-restoreone.php`
- Modify: `includes/class-ui.php`
- Test: `includes/class-pro.php`
- Test: `museder-restoreone.php`
- Test: `includes/class-ui.php`

**Step 1: Write the failing test**

Define the failing condition:

```php
// Current failure mode:
// Core files ask "is PRO active?" directly instead of asking
// "is capability X available?" through a stable contract.
```

Required target behaviors:

- Free core can ask for named capabilities such as `verified_recovery`, `restore_preview`, `cloud_destinations`
- capability metadata can later be supplied by a PRO add-on
- UI localization can read a single capability payload instead of inferring feature state ad hoc

**Step 2: Run test to verify it fails**

Run:

```bash
php -l "includes/class-pro.php" && php -l "museder-restoreone.php" && php -l "includes/class-ui.php"
```

Expected: syntax passes, but architecture still fails because capabilities are not centrally resolved.

**Step 3: Write minimal implementation**

Refactor `includes/class-pro.php` to support:

- capability lookup methods
- normalized feature metadata
- bootstrap registration points for future add-on injection

Refactor `includes/class-ui.php` and `museder-restoreone.php` to consume capability helpers instead of hard-coded PRO boolean decisions where feasible.

Example target API:

```php
Museder_Restoreone_Pro::is_capability_enabled( 'restore_preview' );
Museder_Restoreone_Pro::get_capability_map();
Museder_Restoreone_Pro::register_provider( $provider );
```

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "includes/class-pro.php" && php -l "museder-restoreone.php" && php -l "includes/class-ui.php"
```

Expected: syntax passes and capability access is no longer tied purely to one boolean check.

**Step 5: Commit**

```bash
git add includes/class-pro.php museder-restoreone.php includes/class-ui.php
git commit -m "refactor: centralize pro capability resolution"
```

## Task 3: Refactor Backup and Schedule Core to Use Split-Safe Extension Points

**Files:**
- Modify: `includes/class-backup.php`
- Modify: `includes/class-schedule-handler.php`
- Modify: `includes/helpers.php`
- Test: `includes/class-backup.php`
- Test: `includes/class-schedule-handler.php`
- Test: `includes/helpers.php`

**Step 1: Write the failing test**

Define the failing condition:

```markdown
- Backup labels, cloud destinations, metadata, and retention-related fields are gated with direct PRO checks inside core classes.
- Schedule creation and updates also assume PRO by calling `Museder_Restoreone_Pro::is_pro_active()` inline.
```

This creates extraction risk because core code knows too much about commercial features.

**Step 2: Run test to verify it fails**

Run:

```bash
php -l "includes/class-backup.php" && php -l "includes/class-schedule-handler.php" && php -l "includes/helpers.php"
```

Expected: syntax passes, but the architectural failure remains because feature access is still embedded inline in core classes.

**Step 3: Write minimal implementation**

Replace direct PRO assumptions with split-safe extension points such as:

- capability checks for optional metadata
- helper methods that sanitize optional PRO options
- filters or normalization layers for schedule fields

Example target direction:

```php
if ( museder_restoreone_feature_enabled( 'backup_labels' ) ) {
    $backup_metadata['label'] = sanitize_text_field( $options['label'] );
}
```

and:

```php
$schedule = apply_filters( 'museder_restoreone_schedule_payload', $schedule, $data );
```

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "includes/class-backup.php" && php -l "includes/class-schedule-handler.php" && php -l "includes/helpers.php"
```

Expected: syntax passes, and optional commercial behavior now depends on explicit extension points instead of raw PRO branching.

**Step 5: Commit**

```bash
git add includes/class-backup.php includes/class-schedule-handler.php includes/helpers.php
git commit -m "refactor: make backup and schedule paths split-safe"
```

## Task 4: Refactor Restore and Admin Surfaces for Extraction Readiness

**Files:**
- Modify: `includes/class-restore-handler.php`
- Modify: `includes/class-restore-service.php`
- Modify: `includes/class-restore-controller.php`
- Modify: `templates/page-restore.php`
- Modify: `assets/js/admin-ui.js`
- Test: `includes/class-restore-handler.php`
- Test: `includes/class-restore-service.php`
- Test: `includes/class-restore-controller.php`

**Step 1: Write the failing test**

Define the failing condition:

```markdown
- Restore UI and admin JS know about locked PRO features, but no extraction-ready feature contract exists end-to-end.
- Future `restore_preview` and `verified_recovery` work could become too tightly coupled to the shared admin surface.
```

**Step 2: Run test to verify it fails**

Run:

```bash
php -l "includes/class-restore-handler.php" && php -l "includes/class-restore-service.php" && php -l "includes/class-restore-controller.php" && php -l "templates/page-restore.php"
```

Expected: syntax passes, but there is still no clear handoff boundary between Free restore UI and future PRO enhancement layers.

**Step 3: Write minimal implementation**

Make the restore/admin surface extraction-ready by:

- localizing capability payloads into JS in one consistent shape
- keeping Free restore workflow usable without PRO
- defining placeholders/hooks where `restore_preview` and `verified_recovery` can attach later

Example target payload:

```php
'proCapabilities' => [
    'restore_preview'   => Museder_Restoreone_Pro::is_capability_enabled( 'restore_preview' ),
    'verified_recovery' => Museder_Restoreone_Pro::is_capability_enabled( 'verified_recovery' ),
],
```

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "includes/class-restore-handler.php" && php -l "includes/class-restore-service.php" && php -l "includes/class-restore-controller.php" && php -l "templates/page-restore.php"
```

Expected: syntax passes and restore/admin surfaces are prepared for later extraction without breaking Lite behavior.

**Step 5: Commit**

```bash
git add includes/class-restore-handler.php includes/class-restore-service.php includes/class-restore-controller.php templates/page-restore.php assets/js/admin-ui.js
git commit -m "refactor: prepare restore surfaces for pro extraction"
```

## Task 5: Define the Physical Split Package and Compatibility Checks

**Files:**
- Create: `pro/museder-restoreone-pro.php`
- Create: `pro/includes/bootstrap.php`
- Create: `pro/includes/class-pro-plugin.php`
- Create: `docs/plans/2026-03-12-review-safe-build-and-release-strategy.md`
- Modify: `docs/plans/2026-03-12-free-pro-compatibility-policy.md`
- Test: `pro/museder-restoreone-pro.php`
- Test: `pro/includes/bootstrap.php`
- Test: `pro/includes/class-pro-plugin.php`

**Step 1: Write the failing test**

Define the failing condition:

```markdown
- There is no separate PRO plugin bootstrap.
- There is no explicit version compatibility check between Free and PRO.
- There is no release/build strategy for a WP.org-safe Free package and a commercial PRO package.
```

**Step 2: Run test to verify it fails**

Run:

```bash
ls "pro" 2>/dev/null
```

Expected: missing or incomplete PRO package skeleton for actual split deployment.

**Step 3: Write minimal implementation**

Create a Phase 2 package skeleton with:

- plugin header for `Museder RestoreOne PRO`
- dependency check that Free is installed and active
- minimum supported Free version check
- bootstrap file that loads extracted PRO modules only after Free core is ready

Example compatibility guard:

```php
if ( ! defined( 'MUSEDER_RESTOREONE_VERSION' ) ) {
    return;
}

if ( version_compare( MUSEDER_RESTOREONE_VERSION, 'X.Y.Z', '<' ) ) {
    return;
}
```

Also create a build/release strategy document that explains:

- which files stay in WP.org Free builds
- which files move to the commercial PRO package
- how version compatibility is communicated and enforced

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "pro/museder-restoreone-pro.php" && php -l "pro/includes/bootstrap.php" && php -l "pro/includes/class-pro-plugin.php"
```

Expected: syntax passes, and the repository now contains the first physical split skeleton for Phase 2.

**Step 5: Commit**

```bash
git add pro/museder-restoreone-pro.php pro/includes/bootstrap.php pro/includes/class-pro-plugin.php docs/plans/2026-03-12-review-safe-build-and-release-strategy.md docs/plans/2026-03-12-free-pro-compatibility-policy.md
git commit -m "build: scaffold phase-two pro addon package"
```

## Task 6: Move Existing PRO Modules into the Add-on Without Breaking Free

**Files:**
- Modify: `museder-restoreone.php`
- Modify: `includes/class-pro.php`
- Modify: `includes/class-ui.php`
- Modify: `pro/includes/bootstrap.php`
- Move: `includes/pro/ai-service.php` -> `pro/includes/pro/ai-service.php`
- Move: `includes/pro/ai-controller.php` -> `pro/includes/pro/ai-controller.php`
- Move: `includes/pro/smart-retention.php` -> `pro/includes/pro/smart-retention.php`
- Move: `includes/pro/advanced-filters.php` -> `pro/includes/pro/advanced-filters.php`
- Move: `includes/pro/health-score.php` -> `pro/includes/pro/health-score.php`
- Move: `includes/pro/cloud-storage.php` -> `pro/includes/pro/cloud-storage.php`
- Move: `includes/pro/reports-service.php` -> `pro/includes/pro/reports-service.php`
- Move: `includes/pro/reports-controller.php` -> `pro/includes/pro/reports-controller.php`
- Test: `museder-restoreone.php`
- Test: `pro/includes/bootstrap.php`

**Step 1: Write the failing test**

Define the failing condition:

```markdown
- Extracted PRO package exists, but Free still owns actual PRO module loading.
- PRO services are not yet loaded from the add-on package.
- Free cannot function as a clean core while PRO remains embedded in the same paths.
```

**Step 2: Run test to verify it fails**

Run:

```bash
php -l "museder-restoreone.php" && php -l "includes/class-pro.php" && php -l "includes/class-ui.php" && php -l "pro/includes/bootstrap.php"
```

Expected: syntax passes, but physical ownership of PRO modules is still unresolved.

**Step 3: Write minimal implementation**

Move PRO modules under the new package and update bootstrapping so:

- Free no longer requires or initializes embedded `includes/pro/*` files
- PRO add-on owns loading and initialization of its service/controller modules
- Free remains functional when PRO is absent
- UI and feature locks degrade gracefully when the add-on is missing

**Step 4: Run test to verify it passes**

Run:

```bash
php -l "museder-restoreone.php" && php -l "includes/class-pro.php" && php -l "includes/class-ui.php" && php -l "pro/includes/bootstrap.php"
```

Expected: syntax passes and Free can boot without directly shipping the commercial module implementations.

**Step 5: Commit**

```bash
git add museder-restoreone.php includes/class-pro.php includes/class-ui.php pro/includes/bootstrap.php pro/includes/pro
git commit -m "refactor: move pro modules into addon package"
```

## Task 7: Validate Build, Compatibility, and Reviewer Safety

**Files:**
- Modify: `create-package.sh`
- Modify: `readme.txt`
- Modify: `docs/plans/2026-03-12-review-safe-build-and-release-strategy.md`
- Test: `create-package.sh`
- Test: `readme.txt`

**Step 1: Write the failing test**

Define the failing condition:

```markdown
- The repo may build a mixed package accidentally.
- Free package contents may still include commercial-only files.
- Version compatibility and commercial packaging rules are not enforced end-to-end.
```

**Step 2: Run test to verify it fails**

Run:

```bash
sh -n "create-package.sh"
```

Expected: shell syntax may pass, but the packaging strategy still fails because the script does not yet prove Free and PRO outputs are separated clearly enough.

**Step 3: Write minimal implementation**

Implement final packaging validation:

- update `create-package.sh` so Free and PRO build outputs are explicit
- verify Free package excludes commercial add-on files
- verify public-facing readme language stays reviewer-safe
- define manual release checklist for compatibility checks

**Step 4: Run test to verify it passes**

Run:

```bash
sh -n "create-package.sh"
```

Expected: `create-package.sh` passes shell syntax validation, and the release strategy document explains how to verify Free and PRO package separation before shipping.

**Step 5: Commit**

```bash
git add create-package.sh readme.txt docs/plans/2026-03-12-review-safe-build-and-release-strategy.md
git commit -m "build: validate free and pro package separation"
```

## Verification Checklist for the Whole Plan

- Free still boots and exposes core backup/restore/schedule functionality without PRO.
- Phase 1 changes do **not** create two separate installable plugins yet.
- Phase 2 is the first point where `museder-restoreone-pro` becomes a real plugin package.
- Capability checks replace scattered direct PRO assumptions in major integration points.
- PRO package loading is version-checked and dependency-checked.
- Build strategy distinguishes WP.org-safe Free output from the commercial PRO output.

## Notes for the Implementer

- Prefer small commits after each task.
- If a shared core refactor exposes unclear boundaries, stop and update the compatibility policy before continuing.
- Do not promise WooCommerce-safe preservation logic during the split itself; that is a later product capability, not a prerequisite for packaging separation.
- Keep review-facing Free behavior simple and compliant throughout the migration.
