# Manual verification plan

Use this checklist on a local or staging WordPress site before relying on the importer for a real Day One archive. For security-sensitive changes, also review `docs/security-hardening.md`.

## Environment setup

1. Install WordPress 6.4+ with PHP 7.4+ and the PHP `ZipArchive` extension enabled.
2. Enable `WP_DEBUG` and review PHP logs during testing.
3. Copy this plugin to `wp-content/plugins/day-one-importer/` and activate it.
4. Confirm the testing user can import, upload files, and edit posts.
5. In a test-only environment, optionally force small batches with filters returning `1` for `day_one_importer_batch_zip_limit`, `day_one_importer_batch_index_entry_limit`, `day_one_importer_batch_entry_limit`, and `day_one_importer_batch_media_limit`.

## Async upload and progress flow

1. Go to **Tools → Import → Day One**.
2. Confirm the page explains that a Day One JSON export ZIP is queued and then advanced by short resumable requests.
3. Upload `tests/fixtures/day-one-fictional.zip`.
4. Confirm the initial POST displays the current import panel quickly, updates the browser URL with a `day_one_importer_job` query arg, and does not wait for all entries/media to import before rendering the page.
5. With forced batch sizes of `1`, confirm multiple AJAX `day_one_importer_job_process` requests occur before completion and no single request performs the entire import.
6. In browser developer tools or server logs, confirm each processing request returns promptly rather than remaining open until all entries/media are finished.
7. Temporarily stop browser polling after a job is queued and trigger WP-Cron; confirm cron can advance or finish the job as a fallback.
8. Confirm the status panel shows phase, progress, counters, warnings/errors, final state, and Retry/Continue and Cancel controls.
9. Cancel the current job, upload the ZIP again from the same page, and confirm the current import panel and browser URL switch to the newly uploaded job rather than continuing to poll the canceled job.
10. Confirm the displayed `N% complete` is consistent with the "Imported X of Y entries. Current media: A of B." detail line during the `importing` phase — for a large export the percentage should track entries imported rather than jumping to roughly 65% once preflight, extract, and indexing finish.
11. Confirm status output does **not** include private journal text, raw JSON, local filesystem paths, or media previews.

## Resume/retry/interruption checks

1. Refresh the browser mid-import and confirm the same job continues. Confirm the progress percentage on first paint reflects the entries already imported rather than resetting to a low value.
2. Disable the network or stop polling mid-import, then restore it and click **Retry / Continue**; confirm only unfinished work resumes and counters continue from the last safe checkpoint.
3. Trigger overlapping AJAX/cron processing and confirm one request reports busy/safe status while the other owns the lock and no older status overwrites newer progress.
4. Simulate interruption after post creation by stopping a job with an incomplete post, then continue; confirm one post exists for the Day One UUID.
5. Simulate interruption after one media item, then continue; confirm one attachment exists per media item and content has no duplicate image/gallery blocks.
6. Re-run the same ZIP after completion and confirm completed current-schema posts are skipped and media is reused.
7. Change one imported post to an old `_day_one_import_version` and rerun; confirm it is resumed/upgraded without duplicates.
8. Trash one imported post and rerun; confirm it is recreated while other complete posts are skipped.

## Invalid input and authorization checks

Confirm clear, escaped, privacy-safe failures for:

1. Missing file upload.
2. Non-ZIP upload.
3. ZIP without a JSON file containing an `entries` array.
4. ZIP with malformed entries or entries missing UUIDs.
5. ZIP containing unsafe paths such as `../evil.php`, absolute paths, or symlink entries.
6. Missing or unsupported media files.
7. AJAX requests with a bad nonce.
8. AJAX requests by a user without import/upload/edit capabilities.
9. AJAX requests for another user’s job ID.

## Cleanup and final state checks

1. Confirm completed jobs remove the protected temporary ZIP/extraction directory while retaining final counts/status for refresh display, and that the bar reads `100% complete`.
2. Confirm failed jobs retain enough state/files to retry until canceled or stale, and that the progress bar keeps the percentage computed from the cursors at failure time rather than snapping to 100%.
3. Cancel a job and confirm temporary files are removed, status becomes canceled, and the bar keeps the percentage computed from the cursors at cancel time rather than snapping to 100%.
4. Force a stale job/lock past retention and run cleanup; confirm stale files/options/locks are removed without deleting an unexpired lock.
5. Confirm imported posts are private and imported Day One media is served only through the authenticated media endpoint to users who can read the parent post.

## Final acceptance

- Large imports advance through bounded requests rather than one long HTTP request.
- Browser refresh/network interruption can continue safely.
- Reruns do not duplicate posts or media.
- Progress and final summaries are understandable and privacy-safe.
- Invalid archives/uploads/permissions fail safely.
