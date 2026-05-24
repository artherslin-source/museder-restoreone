# Bug Report: Cancel During Preparing Stage Gets Overwritten (2.7.264)

## Summary

On the real production site `ciouyinghao.com`, cancelling a backup job during the **preparing** stage does not stick. The in-flight worker overwrites the cancel state, and the job continues running indefinitely.

## Affected Version

- Plugin: `2.7.264` (build `2.7.264-1`, commit `0d6b473`)
- Site: `https://ciouyinghao.com/`
- PHP: `8.3.30`
- memory_limit: `128M`
- max_execution_time: `30` (web)
- wp-content: `4.6 GB / 10,172 files`

## Reproduction

1. Start a backup on a large site (>10K files, >4GB)
2. Wait for the "preparing" stage to begin (manifest scanning — takes 13-40s on shared hosting)
3. Click "Cancel Backup" during this stage
4. Observe: cancel is confirmed in log and UI
5. Wait 10-15 seconds
6. Observe: job continues running, cancel_requested is overwritten to `false`

## Evidence (from server log)

```
[22:16:29] Job 39e22d8c created
[22:16:33] Backup job cancelled by user. {stage:preparing, status:running}
[22:16:35] Backup job cancelled by user. {stage:cancelled, status:cancelled}
[22:16:43] Time budget reached, scheduling next batch. {job_id:39e22d8c, elapsed:13.09}
[22:17:23] Time budget reached, scheduling next batch. {job_id:39e22d8c, elapsed:40.2}
[22:19:56] Backup Auto mode decision. {job:39e22d8c, files:13102}
[22:19:58] Backup job prepared. {job:39e22d8c, files:12932, bytes:44299197089}
[22:21:05] Time budget reached, scheduling next batch. {job_id:39e22d8c, elapsed:69.55}
```

## Job JSON State After Cancel

```json
{
  "cancel_requested": false,
  "cancel_requested_at": 0,
  "status": "running",
  "stage": "packing",
  "processing": true
}
```

Cancel flags were **overwritten by the stale in-memory state**.

## Root Cause

File: `includes/class-backup-jobs.php`, lines 349-373.

The preparing stage block does `save_job($job)` at line 371 **without first reloading the latest persisted state**. If the user cancelled during `run_preparing_stage()` (which takes 13-40s on shared hosting), the `save_job()` writes the old `cancel_requested=false` back to disk, overwriting the cancel.

```php
// Line 349-373 (BUGGY)
if ( isset( $job['stage'] ) && 'preparing' === $job['stage'] ) {
    $job = Museder_Restoreone_Backup::run_preparing_stage( $job );
    $batch_count++;
    // ... transition logic ...
    
    // BUG: No reload/check before save!
    $job['processing']    = true;
    $job['last_activity'] = time();
    $job['updated_at']    = current_time( 'mysql' );
    self::save_job( $job );  // ← OVERWRITES CANCEL
    
    continue;
}
```

In contrast, the **packing** stage has Gate A (line 400) that properly reloads and checks:

```php
// Line 400 (CORRECT - packing stage)
$latest_after_batch = self::load_latest_job_state( $job_id );
if ( self::is_terminal_or_cancel_requested( $latest_after_batch ) ) {
    $job = self::merge_with_latest_terminal_state( $job, $latest_after_batch );
    break;
}
```

## Why Docker Testing Didn't Catch This

Docker's fast I/O completes `run_preparing_stage()` in <1 second. The cancel window is too short to trigger during preparing. On shared hosting (128M RAM, slow disk), preparing takes 13-40 seconds — easily cancelled by users.

## Fix Required

Add the same Gate pattern after `run_preparing_stage()` returns, before `save_job()`:

```php
// Gate B: after preparing step, reload latest state to detect cancel.
$latest_after_prep = self::load_latest_job_state( $job_id );
if ( self::is_terminal_or_cancel_requested( $latest_after_prep ) ) {
    $job = self::merge_with_latest_terminal_state( $job, $latest_after_prep );
    $job_needs_finalize = false;
    $job_completed_in_loop = true;
    break;
}
```

## Related: Smart Exclude Threshold Issue (Design Limitation)

The site has only 13,102 files but 44.3 GB of data (including large AI1WM .wpress). The Auto threshold only checks file count (50,000), not data size. Smart Exclude does not trigger, so large third-party backup artifacts (~1GB .wpress) remain in the backup scope.

Suggestion: Add a bytes-based threshold (e.g., total_bytes > 2GB) as an alternative trigger for Smart Exclude auto mode.

## Severity

- **P0** for the cancel overwrite bug (makes cancel unreliable on shared hosting)
- **P2** for the Smart Exclude threshold design limitation

---

# Bug 2: Session Preservation Fix Fails in WP-Cron Context (2.7.264)

## Summary

The session preservation fix (Fix 1 from `docs/FIX-PLAN-RESTORE-SESSION-LOSS.md`) does NOT work on real production sites because `preserve_session_before_import()` calls `get_current_user_id()` which returns **0** when executed inside WP-Cron.

## Evidence

From production log (`backup-lite-2026-05-23.log`):

```
[23:22:49] Post-DB-import recovery: lock, active job, and cron re-established.  ← Fix 3 works
                                                                                   ← "Session tokens re-injected" is MISSING!
```

The `restore_session_after_import()` log entry is absent, meaning the session was NOT preserved.

## Root Cause

```php
// includes/class-restore.php — preserve_session_before_import()
private static function preserve_session_before_import() {
    $state = [
        'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
        //           ↑ Returns 0 in WP-Cron context! No user is "logged in" during cron.
```

**Execution flow on real sites:**
1. User clicks "Start Restore" → `execute()` runs (user IS authenticated, user_id > 0)
2. `execute()` schedules WP-Cron: `wp_schedule_single_event(time(), CRON_HOOK_PROCESS, [$job_id])`
3. WP-Cron fires → `cron_process_job()` → `process_job_slice()` → `stage_import_database()`
4. Inside `import_database_from_ndjson()`:
   - `preserve_session_before_import()` calls `get_current_user_id()` → **returns 0!**
   - `$state['session_tokens'] = []` → nothing is preserved
5. DB import replaces `wp_usermeta` → user session destroyed
6. `restore_session_after_import($state)` → `$user_id = 0` → skips session write

**Why Docker testing passed**: In Docker, `process_job_slice()` was called directly from PHP CLI with WP loaded in a context where user_id was implicitly available (or the same-site backup had matching tokens). On real sites, the cron pathway has no authenticated user.

## Fix Required

Save the authenticated user's ID and session tokens at `execute()` time (when user IS logged in), and store them in the job metadata. Then `preserve_session_before_import()` reads from job meta instead of relying on `get_current_user_id()`.

```php
// In execute():
$meta['restore_admin_user_id'] = get_current_user_id();
$manager = WP_Session_Tokens::get_instance( get_current_user_id() );
$meta['restore_admin_session_tokens'] = $manager->get_all();

// In preserve_session_before_import() — read from job meta:
$user_id = $meta['restore_admin_user_id'] ?? 0;
$session_tokens = $meta['restore_admin_session_tokens'] ?? [];
```

## Severity

**P0** — The primary advertised fix (session preservation) is completely non-functional on production WordPress sites that use WP-Cron for restore processing (which is ALL of them).
