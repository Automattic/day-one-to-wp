# Manual verification plan

Use this checklist on a local or staging WordPress site before relying on the importer for a real Day One archive.

## Environment setup

1. Install WordPress 6.4+ with PHP 7.4+ and the PHP `ZipArchive` extension enabled.
2. Enable `WP_DEBUG` and review PHP logs during testing.
3. Install this plugin in the site's configured plugins directory and activate it.
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
8. Confirm the status panel shows phase, progress, counters, warnings/errors, final state, and Retry/Continue and Cancel controls. Confirm Retry/Continue is disabled while the job is actively running and re-enabled only when a queued or failed job can be continued.
9. Cancel the current job, upload the ZIP again from the same page, and confirm the current import panel immediately resets to a queuing state, then the panel and browser URL switch to the newly uploaded job rather than continuing to poll the canceled job.
10. Confirm the displayed `N% complete` is consistent with the "Imported X of Y entries. Current media: A of B." detail line during the `importing` phase — for a large export the percentage should track entries imported rather than jumping to roughly 65% once preflight, extract, and indexing finish.
11. Confirm status output does **not** include private journal text, raw JSON, local filesystem paths, or media previews.

## Resume/retry/interruption checks

1. Refresh the browser mid-import and confirm the same job continues. Confirm the progress percentage on first paint reflects the entries already imported rather than resetting to a low value.
2. Disable the network or stop polling mid-import, then restore it and click **Retry / Continue**; confirm only unfinished work resumes and counters continue from the last safe checkpoint.
3. Trigger overlapping AJAX/cron processing and confirm one request reports busy/safe status while the other owns the lock and no older status overwrites newer progress.
4. Simulate interruption after entry creation by stopping a job with an incomplete entry, then continue; confirm one entry exists for the Day One UUID.
5. Simulate interruption after one media item, then continue; confirm one attachment exists per media item and content has no duplicate image/gallery blocks.
6. Re-run the same ZIP after completion and confirm completed current-schema entries are skipped and media is reused.
7. Change one imported entry to an old `_day_one_import_version` and rerun; confirm it is resumed/upgraded without duplicates.
8. Trash one imported entry and rerun with the same post-type choice; confirm it is recreated with that type while other complete entries are skipped. (For trash-replace across post-type choices, see the cross-type checks below.)

## Post type choice checks

The import form's **Import entries as** control selects the post type for entries created by that run: **Posts (default)** or **Journal entries (custom post type)** — the latter is the plugin's `day_one_entry` type, shown in wp-admin as **Journal Entries**. The checks below verify the control, both submission paths, and the behaviors that compose across the two types. The committed fixture `tests/fixtures/day-one-fictional.zip` imports 29 entries under the journal category "Fictional Journal".

### Form control and default behavior

1. Go to **Tools → Import → Day One** and locate the **Import entries as** row. Confirm it offers exactly two radio options — **Posts (default)** and **Journal entries (custom post type)** — with **Posts (default)** preselected, and a description stating that the choice applies only to entries created by this import and that previously imported entries keep their current post type.
2. Import the fixture without touching the control. Confirm the run behaves exactly as in the flows above and every imported entry is a private regular post under **Posts** — nothing appears under **Journal Entries**.
3. Repeat with **Journal entries (custom post type)** selected (on a site without a prior import of the fixture). Confirm the run completes through the same async panel flow and the entries appear under **Journal Entries** instead of **Posts**.
4. No-JS fallback: disable JavaScript in the browser, choose **Journal entries (custom post type)**, and submit. The form performs a normal POST that queues the job; trigger WP-Cron (as in the async flow checks) or re-enable JavaScript and refresh to finish it. Confirm the completed run created Journal Entries, identical to the JavaScript (XHR) path.
5. Crafted value: with browser developer tools, edit one radio input's `value` attribute to something outside the two permitted values (for example `page`), select it, and submit. Confirm the import runs normally and creates regular posts — any missing, empty, or unrecognized value falls back to the default, and no other post type is ever written.

### Custom post type import fidelity and wp-admin

Run a clean import with **Journal entries (custom post type)** selected, then:

1. Confirm wp-admin shows a top-level **Journal Entries** menu entry with a book icon, linking to the type's list table (`edit.php?post_type=day_one_entry`).
2. In the list table, confirm every imported entry is listed as **Private**, the **Categories** column shows the Day One journal category (the fixture's "Fictional Journal"), and the **Tags** column shows each entry's Day One tags.
3. Open several entries and confirm the dates are the original Day One creation dates and the author is the user who ran the import.
4. Open an entry containing a photo. Confirm it opens in the block editor (not the classic editor) and the imported content renders as blocks — paragraphs, headings, images, galleries — rather than raw block markup or a plain HTML textarea. Confirm categories and tags are visible and editable in the editor sidebar.
5. Spot-check one Journal Entry's stored fields against a post imported from the same fixture (for example with `wp post meta list <ID>`): title, block content, dates, category, tags, and importer/location/weather meta keys and values match — only the post type differs.

### Per-run choice binding and legacy jobs

1. Start a fixture import with **Journal entries (custom post type)** selected and interrupt it mid-run (stop polling or disable the network, as in the resume checks above).
2. Click **Retry / Continue** and confirm entries created after the resume are still Journal Entries.
3. Interrupt again and let the WP-Cron fallback finish the job (as in the async flow checks). Confirm every entry of the completed run is a Journal Entry and none were created as regular posts — the choice is stored in the job record at submission and read back by every batch, so a later submission or page reload with a different selection never changes an in-flight or resumed run's type.
4. Expectation for legacy jobs (covered by the automated wp-env e2e rather than a manual step): import job records created by plugin versions before the choice existed carry no stored choice and resume and complete as default posts.

### Cross-type idempotency and trash-replace

1. Complete a fixture import as **Posts (default)** and note the imported entry count (29 for the committed fixture).
2. Re-import the same ZIP with **Journal entries (custom post type)** selected. Confirm the rerun completes, no duplicates are created, **Journal Entries** stays empty (every completed entry is recognized and skipped), and every existing entry remains a regular post — no type is changed and no migration occurs.
3. Re-import once more with the same choice as the original run and confirm entry counts are unchanged (idempotent rerun).
4. Move one imported post to Trash, then re-import with **Journal entries (custom post type)** selected. Confirm exactly one replacement is created for the trashed entry's Day One UUID — as a Journal Entry, the current run's choice — the trashed copy is gone, and every other entry is skipped untouched.
5. Mirror direction: starting from a completed Journal Entries import, trash one Journal Entry and re-import with **Posts (default)** selected; confirm a single replacement of type post is created and nothing else changes.

### Permalinks and rewrite-rules lifecycle

1. With a pretty permalink structure selected (Settings → Permalinks, any non-plain setting), open a Journal Entry's permalink (the **View** row action in the list table, or **View Journal Entry** from the editor) as a logged-in user authorized to read private content. Confirm the URL has the form `/day-one-entry/<entry-slug>/`, the entry renders without a 404, and Day One media embedded in the content loads through the authenticated endpoint (image URLs point at `admin-ajax.php?action=day_one_importer_media…`). Copy the permalink while logged in — WordPress shows a private entry's pretty permalink only to logged-in viewers.
2. Open the same URL in a private/incognito window (logged out). Confirm a "Page not found" (404) response with no title, content, or media leaked.
3. Deactivate the plugin: **Journal Entries** disappears from the admin menu and the permalink stops resolving. Reactivate and immediately load the permalink again (without visiting Settings → Permalinks). Confirm it renders without a 404 — activation flushes rewrite rules with the type registered.
4. Simulated update from a pre-CPT plugin version (plugin updates do not fire activation hooks): delete the plugin's rewrite-version marker with raw SQL, confirm it is gone, load the Journal Entry permalink once as an authorized user, and confirm the page renders without a 404 and the marker is restored:

    ```sh
    wp-env run cli wp db query "DELETE FROM wp_options WHERE option_name='day_one_importer_rewrite_version'"
    wp-env run cli wp db query "SELECT option_value FROM wp_options WHERE option_name='day_one_importer_rewrite_version'"   # no rows
    # load the Journal Entry permalink as a logged-in authorized user → renders, no 404
    wp-env run cli wp db query "SELECT option_value FROM wp_options WHERE option_name='day_one_importer_rewrite_version'"   # option_value: 1
    ```

    Use `wp db query` (raw SQL, which does not load WordPress) rather than `wp option delete` / `wp option get`: every regular WP-CLI command boots WordPress and fires `init`, so the plugin's one-time flush guard would restore the option during your verification command and you could not tell whether the page load or the command restored it.

5. Ordinary requests do not regenerate rewrite rules: after step 4, browse several front-end and admin pages, then re-run the `SELECT` and confirm the stored value is unchanged (`1`). The guard flushes only when the stored version is missing or stale — deactivation deletes it and a release that changes rewrite output bumps it; every other request short-circuits. (The wp-env e2e also asserts this short-circuit.)

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
5. Confirm imported entries — regular posts or Journal Entries, per the run's choice — are private, assigned to their Day One journal category, and imported Day One media is served only through the authenticated media endpoint to users who can read the parent entry.

## Plugin Check (PCP)

Run WordPress Plugin Check against the plugin before submitting any release to WordPress.org. This is gated behind an explicit helper so it does not run during the normal lint / test loop.

1. From the repository root, run the helper:

    ```sh
    composer plugin-check
    # or equivalently:
    ./tools/run-plugin-check.sh
    ```

2. The helper is idempotent. It:
    - Starts `wp-env` (`npx wp-env start --debug`) if it is not already running.
    - Installs and activates the `plugin-check` plugin only when it is not already active.
    - Runs `wp plugin check day-one-importer --checks=all --format=table --fields=check,file,line,column,type,code,message` inside the wp-env container.

3. Expected output on a clean release: PCP prints a "Checks complete" trailer (no table rows) and the helper exits `0` with the message `Plugin Check reported zero findings. Ready for submission review.`

4. On any error or warning the helper exits `1` and prints the offending rows. Address each finding (or document a deliberate exception in the changelog) before tagging the release.

5. Environment failures (Docker not running, port `8888`/`8889` conflict, wp-env unavailable) exit `2` with a hint pointing at the likely cause. Resolve the environment issue and rerun — the helper is safe to invoke repeatedly.

## Final acceptance

- Large imports advance through bounded requests rather than one long HTTP request.
- Browser refresh/network interruption can continue safely.
- Reruns do not duplicate entries or media, with either post-type choice and across choice changes.
- The **Import entries as** choice defaults to **Posts (default)**, binds to the run at submission, and Journal Entries match posts in privacy, fidelity, admin manageability, and permalink behavior.
- Progress and final summaries are understandable and privacy-safe.
- Invalid archives/uploads/permissions fail safely.
- Pre-submission only: `composer plugin-check` reports zero findings.
