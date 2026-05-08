# Manual verification plan

Use this checklist on a local or staging WordPress site before relying on the importer for a real Day One archive.

## Environment setup

1. Install WordPress 6.4+ with PHP 7.4+.
2. Enable `WP_DEBUG` and review PHP logs during testing.
3. Copy this plugin to `wp-content/plugins/day-one-importer/`.
4. Activate **Day One Importer** in **Plugins → Installed Plugins**.
5. Confirm the testing user can import, upload files, and edit posts.

## Importer registration and upload flow

1. Go to **Tools → Import**.
2. Confirm **Day One** is listed.
3. Open the importer and verify the page explains:
   - A Day One JSON export ZIP is expected.
   - Imported posts are private by default.
   - Media URL privacy depends on WordPress/hosting configuration.
4. Confirm the upload form has a ZIP file input and submit button.

## Sample import

1. Place a local Day One JSON export ZIP in the ignored `sample/` directory, for example `sample/local-day-one-export.zip`, or choose another private local path.
2. Import that local sample ZIP through the Day One importer screen.
3. Confirm the results screen reports counts for journal JSON files, entries, created/skipped/resumed posts, tags, and media.
4. Confirm warnings, if any, use UUIDs/dates/filenames only and do **not** display full private journal text.
5. Confirm no plugin-created PHP warnings or logs include full journal entry text.

## Imported post checks

Inspect several imported posts in **Posts → All Posts**:

1. Status is `private`.
2. Post date matches the Day One `creationDate`.
3. Text is readable and paragraph breaks/headings are preserved sufficiently.
4. Raw HTML from Day One text is escaped rather than executed.
5. Shortcode-like text such as `[gallery]` remains visible text and does not render as a shortcode.
6. Day One tags are assigned as WordPress post tags when present.
7. Day One UUID/source/import metadata exists on the post.
8. Entries with no photos still import successfully.

## Media checks

For entries with photos:

1. Photos are imported into the Media Library.
2. Photos are attached to the corresponding imported post.
3. Photos appear/appended in the post content in entry order when possible.
4. Attachment metadata includes Day One media identifiers or MD5 values when available.
5. Reused/skipped media is counted correctly on reruns.
6. Review direct media URLs while logged out and document the hosting behavior for the site owner.

## Idempotency and resume checks

1. Re-import the same ZIP.
2. Confirm no duplicate posts are created for entries already marked complete.
3. Confirm already-complete entries are reported as skipped.
4. Simulate an incomplete import by changing `_day_one_import_complete` to `0` on one imported post.
5. Re-import the same ZIP.
6. Confirm the matching post is resumed/updated and no duplicate is created.
7. Confirm the post is marked complete again after the rerun.

## Invalid input and error handling checks

Test each scenario and confirm errors are clear, escaped, and privacy-safe:

1. Non-ZIP upload.
2. ZIP with malformed JSON.
3. ZIP without a JSON file containing `entries`.
4. Entry missing `uuid`.
5. Entry with an invalid date.
6. Entry with a missing media file.
7. Entry with an unsupported media extension.
8. ZIP containing unsafe paths such as `../evil.php` or absolute paths, if safely generated for testing.

Entry-level and media-level failures should not stop unrelated valid entries from importing.

## Cleanup and security checks

1. Confirm temporary ZIP and extraction directories are removed after successful import.
2. Confirm temporary files are also removed after fatal validation failures when possible.
3. Confirm imported posts remain private and are visible only to users who can read private posts.
4. Confirm no imported content is sent to external services.
5. Confirm admin output is escaped and no private entry text appears in notices.
6. Confirm unsupported media or missing files generate warnings rather than public content exposure.

## Final acceptance

Before considering the plugin verified, confirm all of the following:

- Day One importer appears under **Tools → Import**.
- Sample export imports without exposing private text in notices/logs.
- Posts are private and dated correctly.
- Tags and supported photos are preserved.
- Re-importing does not duplicate completed entries.
- Media direct-URL privacy behavior has been reviewed for the target hosting environment.
