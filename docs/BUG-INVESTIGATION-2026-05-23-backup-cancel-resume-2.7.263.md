# Bug Investigation Report: 2.7.263 Backup Cancelled Job Resumes

## Summary

Museder RestoreOne Lite `2.7.263` can continue processing a backup job after the user cancels it. In the investigated case, the user cancelled a backup job around `2026-05-22 01:52` and went offline. The same job continued to run through WP-Cron/AJAX recovery throughout the day, then reappeared in the UI as an active backup progress bar after page refresh.

This is not a new backup being created automatically. It is the original job being resurrected/continued after cancellation.

Primary suspected code defect: an in-flight worker holds an older in-memory `$job` state and, after a long PclZip batch, overwrites the persisted `cancelled` state with `running/packing`, then schedules another batch.

No code has been changed as part of this investigation.

## Affected Context

- Site: `https://ciouyinghao.com/`
- Plugin version: `2.7.263`
- Build ID: `2.7.263-1`
- PHP: `8.3.30`
- Job ID: `38a3743a-2a07-4fe3-b9b4-4daec520c12a`
- Backup archive: `logs/150522-debug-2/ciouyinghao.com-20260522005323-YakglV.zip`
- Job state snapshot: `logs/150522-debug-2/38a3743a-2a07-4fe3-b9b4-4daec520c12a.json`
- Main backup log: `logs/150522-debug-2/backup-lite-2026-05-22 .log`
- PHP error log: `logs/150522-debug-2/error_log`
- UI screenshot: `logs/150522-debug-2/001.png`

## User-Visible Symptoms

1. Backup process reached about `95%` and exceeded normal duration.
2. User clicked cancel. UI displayed that backup was cancelled.
3. After the user came back later and opened/refreshed the backups page, the same backup progress bar appeared again.
4. Backup continued for more than three hours, showing about `94.9%`, and still could not complete.
5. User clicked cancel again. UI displayed cancellation, but the persistent job state still showed running in the collected JSON.

## Artifact Findings

### Job JSON

The job state file `38a3743a-2a07-4fe3-b9b4-4daec520c12a.json` shows:

```json
{
  "id": "38a3743a-2a07-4fe3-b9b4-4daec520c12a",
  "status": "running",
  "stage": "packing",
  "message": "Packing large files… (last step took 120.4 seconds). Please keep this tab open.",
  "created_at": "2026-05-22 00:53:23",
  "updated_at": "2026-05-22 18:12:57",
  "pointer": 3751,
  "processed_files": 3751,
  "total_files": 12884,
  "processed_bytes": 1579905781,
  "total_bytes": 1846891113,
  "pack_method": "pclzip",
  "finalize_step": "embed_meta",
  "repack_attempted": true
}
```

Interpretation:

- This is the same job created shortly after midnight.
- It was still considered active/running at `18:12:57`.
- It had not finalized.
- It was in PclZip repack mode after ZipArchive verification failed.

### ZIP Archive Inspection

The archive `ciouyinghao.com-20260522005323-YakglV.zip` is not a complete backup.

Observed central directory:

- ZIP size: `1,271,065,337` bytes
- Entries: `3753`
- Job JSON `added_files`: `3751`
- Required entries missing:
  - `package.json`
  - `manifest.ndjson`

This matches a partially packed archive: `3751` added files plus `database.ndjson` and `meta.json`.

Important large entries found in the archive:

```text
988 MB  wp-content/ai1wm-backups/ciouyinghao.com-20250331-180428-1ndz04.wpress
398 MB  wp-content/uploads/backwpup-317b50-backups/2019-03-11_16-14-29_ZYYXWUF601.zip
77 MB   wp-content/uploads/backwpup-436ba8-backups/2019-01-28_16-27-05_E5BWXKCO01.zip
12 MB   wp-content/uploads/wordpress-5.2.2-ZjdA3N.tmp
12 MB   wp-content/uploads/wordpress-5.1.1-oo66SH.tmp
```

These entries explain why PclZip batches became very slow and should be considered for exclusion guidance or Smart Exclude improvements.

## Timeline

All times are local site log time `+08:00`.

```text
00:48:17  Plugin 2.7.263 active.
00:53:23  Job created.
00:54:43  Manifest prepared: 12,884 files / 1.846 GB.
00:58:58  ZipArchive packing reached pointer 12,884.
00:59:17  ZipArchive verification failed.
00:59:17  Repack scheduled with PclZip.
01:00:50  PclZip repack begins showing time-budget warnings.
01:52:58  User cancellation logged: stage=packing status=running.
01:53:01  User cancellation logged again: stage=cancelled status=cancelled.
01:53:06  Same job continues packing and schedules next batch.
02:00-14:59 Same job continues sporadically through cron/admin traffic.
15:02+    PclZip batches become about 120 seconds each.
17:58:30  Same job still running, 10 files per batch, about 120 seconds per batch.
18:12:29  User cancellation logged again: stage=packing status=running.
18:12:31  User cancellation logged again: stage=cancelled status=cancelled.
18:12:57  Job JSON snapshot still shows status=running, stage=packing.
```

## Root Cause Hypothesis

### Primary Root Cause: Cancel State Can Be Overwritten by an In-Flight Worker

`Museder_Restoreone_Backup_Jobs::cancel_job()` correctly writes:

- `cancel_requested = true`
- `status = cancelled`
- `stage = cancelled`

However, immediate cleanup only happens if it can acquire the per-job option lock. If a PclZip worker is currently running, cancellation becomes cooperative.

The worker checks cancellation near the start of each loop iteration, but a single PclZip batch can run for 74 to 120+ seconds. After that batch returns, if the time budget has already expired, execution reaches the end-of-request scheduling/save path without reloading the latest persisted job state.

At the end of `process_job_immediately()`, the worker can use its stale in-memory `$job` and:

1. schedule the next batch because `$job['status']` is still `running`;
2. set `processing=false`;
3. save stale `running/packing` state back to disk.

This can overwrite the user cancellation that happened during the long PclZip batch.

Relevant area:

- `includes/class-backup-jobs.php`
- `Museder_Restoreone_Backup_Jobs::process_job_immediately()`
- Lines around the end-of-request terminal-state check, `schedule_next_batch()`, and final `save_job()`

### Secondary Root Cause: PclZip Batch Is Not Interruptible

`Museder_Restoreone_Backup::append_files_to_pclzip()` builds a manifest for the current batch and then calls:

```php
$archive = new PclZip( $archive_path );
$result  = $archive->add( $manifest );
```

Once inside `PclZip::add()`, the job cannot observe cancellation until that call returns. On this host, one batch can take about two minutes.

Relevant area:

- `includes/class-backup.php`
- `Museder_Restoreone_Backup::append_files_to_pclzip()`
- `Museder_Restoreone_Backup::process_job_batch()`

### Secondary Root Cause: UI Progress Is Misleading During Long Packing

The screenshot shows `94.9%`, but the job JSON shows only:

- `processed_files = 3751 / 12884` (`~29%`)
- `processed_bytes = 1579905781 / 1846891113` (`~85%`)

The frontend smoothing logic advances the displayed packing progress toward the stage cap (`95%`) when server progress stalls. This makes a long-running repack appear almost complete even when it is not.

Relevant area:

- `assets/js/admin.js`
- `backupProgressSmoother.capForStage()`
- `backupProgressSmoother.apply()`

### Environmental Contributor: WP-Cron Clear Failures

`error_log` contains:

```text
Cron 取消排程事件時發生錯誤，勾點: museder_restoreone_process_job
錯誤代碼: could_not_set
錯誤訊息: Cron 事件清單無法儲存。
```

This means calls to clear scheduled job events may be unreliable on this site. It increases the chance that stale scheduled events continue to fire. However, even with stale cron events, the worker should refuse to continue once the persisted job is cancelled.

## Code Areas to Inspect

### `includes/class-backup-jobs.php`

Primary functions:

- `create_job()`
- `get_active_job()`
- `enqueue_processing()`
- `cron_process_job()`
- `process_job_immediately()`
- `schedule_next_batch()`
- `cancel_job()`
- `clear_active_job()`
- `clear_scheduled_job()`

Likely fix points:

1. After every long batch, reload latest job state before scheduling/saving.
2. Before `schedule_next_batch()`, reload persisted job and abort if:
   - `status` is `cancelled`, `failed`, or `completed`;
   - `stage` is `cancelled`;
   - `cancel_requested` is true.
3. Before final `save_job()` at the end of `process_job_immediately()`, merge with persisted terminal state so stale running state cannot overwrite cancellation.
4. If cancellation is detected, clear active job and best-effort clear scheduled events.

### `includes/class-backup.php`

Primary functions:

- `finalize_async_job_after_close()`
- `process_job_batch()`
- `append_files_to_pclzip()`
- `append_files_to_zip()`
- `verify_archive_contains_wp_content()`

Likely fix points:

1. When `repack_attempted=true` and `pack_method=pclzip`, consider smaller, more responsive PclZip work units or cancellation checks immediately before and after PclZip add calls.
2. Exclude or warn about existing backup archives (`*.wpress`, old backup ZIPs, BackWPup folders, AI1WM backup folders) to avoid repacking huge backup artifacts.

### `assets/js/admin.js`

Primary functions:

- `backupProgressSmoother.capForStage()`
- `backupProgressSmoother.apply()`
- `cancelBackupJob()`
- `handleJobResponse()`
- `maybeNudgeBackupJob()`
- `maybeWatchdogNudge()`
- active job resume block around localized `activeJob`

Likely fix points:

1. Do not smooth packing progress up to `94.9%` when the backend reports `pack_method=pclzip` and `repack_attempted=true`.
2. Display actual file count and mode, for example:
   - `Compatibility repack in progress: 3,751 / 12,884 files`
3. After cancel response succeeds, optionally verify active job is gone after a delay. If the backend still reports the same job, show a stronger warning instead of silently resetting UI.

## Proposed Minimal Fix Strategy

### Phase 1: Stop Cancelled Jobs from Resuming

Goal: cancellation must be terminal and must not be overwritten by stale workers.

Recommended changes:

1. Add a helper in `Museder_Restoreone_Backup_Jobs`, for example:
   - `load_latest_cancel_state( $job_id )`
   - `is_cancel_requested_or_terminal( $job )`
2. In `process_job_immediately()`:
   - reload the job after `process_job_batch()` returns;
   - reload again immediately before scheduling next batch;
   - reload/merge before the final `save_job()`.
3. If latest state is cancelled:
   - do not schedule next batch;
   - clear active job;
   - clear scheduled job;
   - cleanup archive/temp/manifest if safe;
   - save final cancelled state only, not stale running state.
4. In `schedule_next_batch()`, add a defensive latest-state check before `wp_schedule_single_event()`.

Acceptance criteria:

- Cancelling while a long PclZip batch is running should result in a terminal cancelled job after the current batch returns.
- No new `Scheduled next batch with short interval` log should appear after cancellation is persisted.
- Refreshing the backups page should not show the cancelled job as active.

### Phase 2: Improve PclZip Repack UX and Safety

Goal: user should understand that repack is slow and progress should not imply near completion.

Recommended changes:

1. Include `repack_attempted` and/or `finalize_step` in `format_job_payload()` if not already present.
2. Adjust frontend smoothing:
   - use lower cap or disable smoothing for PclZip repack;
   - show actual `processed_files / total_files`.
3. Add admin-facing warning when backup includes large backup artifacts.

Acceptance criteria:

- UI does not show `94.9%` when only about `29%` of files have been processed.
- UI clearly states compatibility repack mode when `pack_method=pclzip`.

### Phase 3: Exclude Known Backup Artifacts

Goal: avoid backing up old backup archives by default when safe.

Candidate patterns observed:

```text
wp-content/ai1wm-backups/*.wpress
wp-content/uploads/backwpup-*-backups/*.zip
wp-content/uploads/wordpress-*.tmp
```

This should be handled carefully for WordPress.org Lite compliance:

- Do not silently remove user content.
- Prefer Smart Exclude, warning, or opt-in/clearly documented exclusion behavior.
- Keep paths under WordPress APIs and sanitize all configured exclude patterns.

## Regression Tests / Verification Plan

### Unit or Integration-Level Test Ideas

1. Simulate a job with `status=running`, `stage=packing`.
2. Start `process_job_immediately()`.
3. During simulated long batch, persist a newer job state with:
   - `cancel_requested=true`
   - `status=cancelled`
   - `stage=cancelled`
4. Ensure worker does not overwrite this newer state.
5. Ensure `schedule_next_batch()` is not called after cancellation.
6. Ensure `get_active_job()` returns null for cancelled job.

### Manual QA

1. Start a backup on a site with large archives or force PclZip.
2. Wait until packing starts.
3. Click cancel while one batch is running.
4. Wait at least one batch duration.
5. Refresh the backups page.
6. Confirm:
   - no active progress bar appears;
   - job JSON remains cancelled;
   - no new scheduled events for `museder_restoreone_process_job` with that job ID;
   - half-built archive is removed or clearly marked incomplete.

### Log Expectations After Fix

Expected good log shape:

```text
Backup job cancelled by user.
Cancellation detected after batch; stopping job.
Cleared active job.
Cleared scheduled backup job events.
```

Unexpected/bad log shape:

```text
Backup job cancelled by user.
Scheduled next batch with short interval. stage=packing
```

## Immediate Site Cleanup Recommendation

For the investigated site, before running another backup:

1. Remove active job option if it still points to `38a3743a-2a07-4fe3-b9b4-4daec520c12a`.
2. Clear scheduled `museder_restoreone_process_job` events for that job ID.
3. Delete the partial archive:
   - `ciouyinghao.com-20260522005323-YakglV.zip`
4. Delete related temp/job/manifest files if still present.
5. Exclude or remove old backup artifacts from:
   - `wp-content/ai1wm-backups/`
   - `wp-content/uploads/backwpup-*-backups/`
   - `wp-content/uploads/wordpress-*.tmp`

## Developer Notes

- Keep Lite package WordPress.org compliant.
- Do not add license gates, quota gates, trial behavior, or PRO-only local logic.
- Sensitive AJAX endpoints must keep capability and nonce checks.
- Generated runtime data must remain under `wp_upload_dir()/museder-restoreone`.
- Any fix touching backup job state should preserve existing archive verification guards. Those guards correctly prevented a bad ZipArchive backup from being marked completed.

