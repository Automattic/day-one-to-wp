# Manual verification plan

Use this checklist on a local or staging WordPress site before relying on the importer for a real Day One archive.

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
4. Confirm the initial POST redirects back quickly with a `day_one_importer_job` query arg rather than waiting for all entries/media to import.
5. With forced batch sizes of `1`, confirm multiple AJAX `day_one_importer_job_process` requests occur before completion.
6. Confirm the status panel shows phase, progress, counters, warnings/errors, final state, and Retry/Continue and Cancel controls.
7. Confirm status output does **not** include private journal text, raw JSON, local filesystem paths, or media previews.

## Resume/retry/interruption checks

1. Refresh the browser mid-import and confirm the same job continues.
2. Disable the network or stop polling mid-import, then restore it and click **Retry / Continue**; confirm only unfinished work resumes.
3. Trigger overlapping AJAX/cron processing and confirm one request reports busy/safe status while the other owns the lock.
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

1. Confirm completed jobs remove the protected temporary ZIP/extraction directory while retaining final counts/status for refresh display.
2. Confirm failed jobs retain enough state/files to retry until canceled or stale.
3. Cancel a job and confirm temporary files are removed and status becomes canceled.
4. Force a stale job/lock past retention and run cleanup; confirm stale files/options/locks are removed without deleting an unexpired lock.
5. Confirm imported posts are private and imported Day One media is served only through the authenticated media endpoint to users who can read the parent post.

## Final acceptance

- Large imports advance through bounded requests rather than one long HTTP request.
- Browser refresh/network interruption can continue safely.
- Reruns do not duplicate posts or media.
- Progress and final summaries are understandable and privacy-safe.
- Invalid archives/uploads/permissions fail safely.
