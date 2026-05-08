# Implementation Plan: WordPress Day One Importer

## Overview

Build a standalone WordPress plugin that registers a **Day One** importer under **Tools → Import**. The importer accepts a Day One export ZIP, extracts it into a protected temporary location, finds Day One journal JSON files, parses entries, and creates one private WordPress post per Day One entry. It imports supported images into the Media Library, attaches them to their corresponding posts, maps Day One tags to WordPress post tags, and tracks Day One UUID/media/import-state metadata to avoid duplicates and resume incomplete entries on reruns.

The implementation should favor a clear synchronous first version suitable for the provided sample export and ordinary personal exports. It should be structured so larger/batched/background imports can be added later without rewriting parsing and import logic.

## Baseline assumptions

- Minimum WordPress: 6.4+ unless repository conventions dictate otherwise.
- Minimum PHP: 7.4+ or 8.0+ if project tooling allows. Use typed properties only if the selected PHP baseline supports them.
- No Composer dependency is required for the initial version.
- No external network services are used.
- Primary input is a `.zip` export from Day One.
- Sample export shape:
  - `sample/05-07-2026_1-48-PM.zip`
  - Extracted JSON: `Diario.json`
  - JSON has top-level `metadata` and `entries`.
  - Media files live under `photos/` and are named by MD5 plus extension, e.g. `{md5}.jpeg` or `{md5}.png`.

## Proposed plugin file structure

```text
day-one-importer.php
includes/
  class-day-one-importer-plugin.php
  class-day-one-importer-admin.php
  class-day-one-importer-runner.php
  class-day-one-importer-parser.php
  class-day-one-importer-media.php
  class-day-one-importer-content.php
  class-day-one-importer-results.php
  class-day-one-importer-cleanup.php
  functions.php
languages/
  day-one-importer.pot        # optional/generated later
README.md
tests/
  phpunit/
    test-parser.php
    test-content.php
    test-media-resolution.php
    test-idempotency.php       # if WP test bootstrap is available
```

### File responsibilities

- `day-one-importer.php`
  - Plugin header.
  - Constants such as version, file path, directory path, text domain.
  - Loads include files.
  - Boots the plugin on `plugins_loaded` or `init`.

- `includes/class-day-one-importer-plugin.php`
  - Central bootstrap class.
  - Loads text domain.
  - Registers admin/importer integration only in admin context.

- `includes/class-day-one-importer-admin.php`
  - Registers importer via `register_importer()`.
  - Renders upload/import screens.
  - Handles nonce and capability checks before dispatching an import.
  - Displays privacy-safe results and warnings.

- `includes/class-day-one-importer-runner.php`
  - Orchestrates one import run.
  - Validates and extracts ZIP.
  - Calls parser, post creation, media import, tag assignment, and cleanup.
  - Maintains counters in a result object.

- `includes/class-day-one-importer-parser.php`
  - Finds candidate journal JSON files.
  - Decodes JSON defensively.
  - Normalizes raw Day One entries into arrays/DTO-like structures.
  - Validates required fields such as `uuid`.

- `includes/class-day-one-importer-media.php`
  - Resolves Day One photo metadata to extracted source file paths.
  - Validates file types.
  - Imports/reuses attachments.
  - Stores Day One media meta on attachments.

- `includes/class-day-one-importer-content.php`
  - Converts Day One text into safe WordPress post content.
  - Derives post titles.
  - Builds appended image markup/gallery sections when inline placement is not reliable.

- `includes/class-day-one-importer-results.php`
  - Stores counts, warnings, and errors.
  - Provides privacy-safe formatting data for admin output.

- `includes/class-day-one-importer-cleanup.php`
  - Creates/removes temporary extraction directories.
  - Centralizes path safety checks.

- `README.md`
  - Explains Day One export steps, import steps, limitations, media privacy caveat, idempotency/resume behavior, temporary-file cleanup, and troubleshooting.

## Ordered implementation tasks

### 1. Repository/plugin bootstrap

1. Create `day-one-importer.php` with a valid plugin header:
   - Plugin Name: `Day One Importer`
   - Description: Import Day One exports as private WordPress posts.
   - Text Domain: `day-one-importer`
   - Requires at least / Requires PHP fields matching chosen baseline.
2. Add constants:
   - `DAY_ONE_IMPORTER_VERSION`
   - `DAY_ONE_IMPORTER_FILE`
   - `DAY_ONE_IMPORTER_DIR`
   - `DAY_ONE_IMPORTER_TEXT_DOMAIN`
3. Add a simple autoload/include loader for classes in `includes/`.
4. Instantiate the plugin bootstrap class on `plugins_loaded`.
5. Load translations with `load_plugin_textdomain()`.

### 2. Register the WordPress importer

1. In admin context, hook into `admin_init`.
2. Require importer APIs robustly before registration:
   - If `register_importer()` is unavailable, load `ABSPATH . 'wp-admin/includes/import.php'`.
   - If it is still unavailable, fail gracefully on the admin screen rather than fatalling.
3. Call `register_importer()` with:
   - Importer slug: `day-one`
   - Display name: `Day One`
   - Description: imports Day One journal exports into private posts.
   - Callback: admin render/dispatch method.
4. Ensure callbacks check all capabilities before displaying or processing forms:
   - `import`
   - `upload_files`
   - `edit_posts`
5. Render a standard WordPress admin form with:
   - `method="post"`
   - `enctype="multipart/form-data"`
   - File input accepting `.zip`.
   - Nonce field, e.g. action `day_one_importer_import`.
   - Submit button.
6. Keep admin copy explicit that posts are imported as private and media URL privacy depends on hosting/WordPress media behavior.

### 3. Upload validation and ZIP extraction

1. On POST submit:
   - Verify nonce with `check_admin_referer()`.
   - Re-check capabilities.
   - Validate `$_FILES` has an uploaded file with no PHP upload errors.
2. Accept only ZIP uploads:
   - Use filename extension as a first-pass check.
   - Use `wp_check_filetype_and_ext()` where possible.
   - Reject unsupported file types with an escaped admin error.
3. Store raw uploaded ZIPs and extracted exports in the least-public temporary location feasible:
   - Prefer `get_temp_dir() . 'day-one-importer/{run-id}/'` when writable and compatible with WordPress filesystem operations.
   - Fall back to `wp_upload_dir()['basedir'] . '/day-one-importer/tmp/{run-id}/'` only when needed.
   - In any plugin-created temp directory, create defensive protection files where supported: `index.html`, and for upload-directory fallbacks also `.htaccess` (`Deny from all` / `Require all denied`) and/or `web.config` equivalents when safe to write.
4. Move upload using `wp_handle_upload()` with `test_form => false` after nonce/capability checks, then copy/move the ZIP into the protected run temp directory if WordPress placed it in public uploads.
5. Before extraction, pre-scan ZIP entries with `ZipArchive` when available:
   - Reject absolute Unix paths, Windows drive-letter paths, paths containing `..` components, empty names, NUL/control characters, or unsafe normalized filenames.
   - Reject symlink-like entries when detectable via external attributes, and reject entries whose normalized target would leave the extraction root.
   - If `ZipArchive` is unavailable, document and rely on WordPress extraction safeguards only as a fallback, and keep post-extraction validation mandatory.
6. Extract with WordPress-supported APIs:
   - Prefer `unzip_file( $zip_path, $extract_dir )`.
   - Initialize `WP_Filesystem` if required.
7. Protect against unsafe extraction paths with defense in depth:
   - After extraction, recursively inspect extracted files and directories.
   - Reject/delete if any `realpath()` falls outside the temp extraction root or if unexpected symlinks are present.
   - Ignore hidden system files like `__MACOSX` and `.DS_Store` during parsing, but do not allow them to bypass path validation.
8. Delete the uploaded ZIP immediately after successful extraction/pre-parse validation, and also during cleanup on extraction or validation failure.
9. Always attempt cleanup of uploaded ZIPs and extracted temp files after import completion or fatal validation failure, using `try/finally`-style orchestration where possible.

### 4. Discover and parse Day One JSON

1. Recursively scan extracted files for `.json` files.
2. For each candidate:
   - Decode JSON using `wp_json_file_decode()` if available or `json_decode()` with associative arrays.
   - Confirm top-level value has an `entries` array.
   - Treat files with `metadata` plus `entries` as strong candidates.
3. Support multiple journal JSON files in one export by merging normalized entries into one import run.
4. Validate each entry defensively:
   - `uuid` must be present and scalar/non-empty.
   - `creationDate` should be parsed if present; invalid dates fall back to current time with warning or skip based on severity.
   - `text` may be missing; create a private post with empty content plus media if UUID/date/media exist.
   - `tags` must be an array of strings; ignore malformed items.
   - `photos` must be an array of objects; ignore malformed photo entries with warnings.
5. Track duplicate UUIDs inside the same export:
   - Process the first occurrence.
   - Warn and skip later duplicates.
6. Do not log or show full journal text in warnings/errors.

### 5. Date and timezone handling

1. Parse Day One `creationDate` and `modifiedDate` as ISO-8601 strings, usually UTC with `Z` suffix.
2. Use `DateTimeImmutable` or WordPress date helpers to create timestamps.
3. Set:
   - `post_date_gmt` from the UTC creation date formatted as `Y-m-d H:i:s`.
   - `post_date` by converting to the site timezone using `get_date_from_gmt()`.
4. For `modifiedDate`, set `post_modified_gmt` and `post_modified` when valid.
5. Preserve original `timeZone` in post meta, e.g. `_day_one_time_zone`, but do not rely on it for core date conversion unless carefully validated.
6. If a date is invalid:
   - Create the post with WordPress default date or current time.
   - Record a privacy-safe warning referencing UUID only.

### 6. Idempotent and resumable post creation

1. Track import state on posts with explicit meta:
   - `_day_one_uuid` = source entry UUID.
   - `_day_one_source` = `day-one-export`.
   - `_day_one_import_version` = current plugin/import schema version.
   - `_day_one_import_complete` = `1` only after content, tags, media handling, and final post update have completed for that entry.
   - Optional `_day_one_import_started_at` / `_day_one_import_completed_at` timestamps for diagnostics.
2. Before inserting a post, query for existing imported posts by `_day_one_uuid`:
   - Include all statuses: `any`, including `trash` if query supports it.
   - Limit to one result, and warn if multiple existing posts are found.
3. If an existing post is found and `_day_one_import_complete` is truthy for the current import version:
   - Skip it by default.
   - Count as skipped existing complete.
   - Do not mutate it in the initial default mode.
4. If an existing post is found but it is incomplete or from an older/incompatible import version:
   - Treat the run as a resume for that entry rather than creating a duplicate.
   - Safely finish missing/incomplete work: regenerate sanitized content from source text, assign tags, import/reuse missing media for that same post, append image markup, and update safe Day One meta.
   - Never change post status away from a user-edited status except that newly created/import-owned posts remain `private`; if the post is in trash, skip/resume only if explicitly considered safe and report a warning.
   - Count as resumed/updated incomplete, not as newly created.
5. If not found, create a private post with `wp_insert_post()`:
   - `post_type => 'post'`
   - `post_status => 'private'`
   - `post_title => derived title`
   - `post_content => sanitized text content, initially without media or with appended media placeholders depending on implementation order`
   - `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt` where available.
6. Immediately after successful insertion, add `_day_one_uuid`, `_day_one_source`, `_day_one_import_version`, and `_day_one_import_complete = 0` before doing tags/media work, minimizing the duplicate window if a run crashes.
7. Add/update other post meta:
   - `_day_one_time_zone`
   - `_day_one_starred`
   - `_day_one_is_pinned`
   - Optional safe metadata: original JSON file basename, creation device type/model, weather/location as raw private meta only if sanitized and useful.
8. Assign tags after insertion/resume with `wp_set_post_tags()`.
9. If media is imported after post creation, update post content with appended image markup via `wp_update_post()`.
10. Set `_day_one_import_complete = 1` and `_day_one_import_completed_at` only after all required entry-level work has finished; if media/tag steps produce warnings but the post is otherwise usable, mark complete and store/report the warnings so reruns do not loop forever.

### 7. Content conversion strategy

Initial content conversion should be conservative and safe:

1. Treat Day One `text` as Markdown-like plain text.
2. Do not execute shortcodes from imported content. WordPress shortcodes are evaluated when rendering `post_content`, so this requires a concrete neutralization step before saving:
   - Escape imported journal text as text first (`esc_html()` or equivalent).
   - Neutralize shortcode delimiters in user text by encoding literal square brackets as HTML entities (`&#91;` and `&#93;`) or by using an equivalent helper before `wpautop()`/block wrapping.
   - Apply this only to Day One text; image markup generated by the importer may contain normal WordPress block comments/HTML produced by trusted code.
   - Add tests proving strings such as `[gallery]`, `[embed]`, and arbitrary plugin shortcodes remain visible text and do not render as shortcodes.
3. Escape raw text and convert structure rather than trusting embedded HTML.
4. Suggested initial conversion:
   - Normalize line endings.
   - Detect Markdown headings (`#`, `##`, etc.) and convert to heading blocks or safe heading HTML.
   - Preserve blank-line paragraph breaks.
   - Convert single newlines within paragraphs to line breaks only when useful.
   - Preserve bullet/numbered list text either as text or simple safe lists if implemented carefully.
   - Run final content through `wp_kses_post()` after shortcode neutralization and markup generation.
5. Alternative simpler first version:
   - Use `esc_html()` on text, then `wpautop()` for paragraphs.
   - Preserve Markdown characters visibly instead of full Markdown conversion.
6. Derive post title in this order:
   - First Markdown heading text.
   - First non-empty text line trimmed to a safe length, e.g. 80 characters.
   - Fallback: `Day One entry — {localized date}`.
7. Never include full imported text in admin notices, debug logs, test fixtures, or README examples.

### 8. Media parsing and import strategy

1. For each entry photo object, normalize fields:
   - `identifier`
   - `md5`
   - `type`
   - `filename`
   - `date`
   - `orderInEntry`
   - `width`, `height`
2. Sort photos by `orderInEntry` when numeric; preserve original array order as fallback.
3. Resolve source file path:
   - Locate `photos/` directories under extraction root.
   - Prefer `{md5}.{extension}` in any `photos/` directory.
   - Normalize extensions:
     - `jpeg` and `jpg` are interchangeable.
     - `png` supported.
     - Check other WordPress-supported image extensions if file exists and MIME validates.
   - If `filename` exists, try matching by basename as fallback.
4. Validate media before import:
   - Ensure resolved realpath remains under extraction root.
   - Ensure file is readable and not empty.
   - Use `wp_check_filetype_and_ext()` or `wp_check_filetype()` and `get_allowed_mime_types()`.
   - Skip executables/unknown MIME types.
5. Idempotency for media:
   - Query attachments by `_day_one_media_identifier` and/or `_day_one_media_md5`.
   - Prefer matching attachments already attached to the current post and with `_day_one_uuid`/import source metadata tying them to this entry/import.
   - Avoid reusing a same-MD5 attachment from an unrelated user, post, or prior non-Day-One upload, because that could cross-associate private media unexpectedly.
   - If a same-MD5 attachment exists but is not attached to the current post/import, import a new attachment or require an explicit future reuse mode.
   - Store identifier, MD5, entry UUID, and import source when available.
6. Import with WordPress media APIs:
   - Copy source to a temporary file with a safe filename.
   - Build a `$_FILES`-like array.
   - Call `media_handle_sideload( $file_array, $post_id, $desc )`.
   - If lower-level APIs are needed, use `wp_insert_attachment()`, `wp_generate_attachment_metadata()`, and `wp_update_attachment_metadata()`.
7. Attachment metadata to add:
   - `_day_one_media_identifier`
   - `_day_one_media_md5`
   - `_day_one_uuid` for the source entry when available
   - `_day_one_source` = `day-one-export`
   - `_day_one_media_date`
   - `_day_one_original_filename`
   - `_day_one_width`, `_day_one_height` if present.
8. Post content media placement:
   - Exact Day One inline placement is not reliably available from the required data.
   - Initial version should append an ordered image section after the converted text.
   - Use safe image markup, preferably WordPress image blocks or `wp_get_attachment_image()` output.
   - Include each imported image in `orderInEntry` order.
   - If no image is imported due to missing/unsupported files, leave text post intact and record warnings.
9. Supported initial media:
   - JPEG/JPG and PNG.
   - Other MIME types only if WordPress accepts them safely and implementation has tests.
10. Unsupported media:
   - Skip per item.
   - Count as skipped/failed media.
   - Show warning with UUID plus filename/identifier, not journal text.

### 9. Tag mapping

1. If Day One `tags` is present and an array, sanitize each tag string with `sanitize_text_field()` before use.
2. Remove empty strings and duplicates.
3. Use `wp_set_post_tags( $post_id, $tags, false )` to create/assign tags using taxonomy APIs.
4. If tag assignment fails, keep the post and record a warning.

### 10. Results, warnings, and admin UX

1. Maintain a result object with counters:
   - JSON files found.
   - Entries found.
   - Posts created.
   - Existing complete posts skipped.
   - Existing incomplete posts resumed/updated.
   - Entries failed.
   - Tags assigned or tag warnings.
   - Media found.
   - Media imported.
   - Media reused/skipped.
   - Media missing.
   - Media unsupported/failed.
2. Maintain bounded warning/error arrays to avoid enormous admin pages:
   - Store first N details, e.g. 50.
   - Add a final note if more warnings were suppressed.
3. Admin display:
   - Use WordPress notice classes: `notice notice-success`, `notice notice-warning`, `notice notice-error`.
   - Escape all dynamic output with `esc_html()`, `esc_attr()`, `esc_url()`.
   - Use `number_format_i18n()` for counts.
4. Warning detail format should include only:
   - UUID.
   - Date.
   - JSON basename.
   - Media identifier/filename.
   - Generic error cause.
5. Never display raw private entry content in notices.

### 11. Security and privacy checklist

Implementation must verify all of the following before acceptance:

- [ ] Import action requires valid nonce.
- [ ] Import action requires `import`, `upload_files`, and `edit_posts` capabilities.
- [ ] Uploaded file is validated as ZIP before extraction.
- [ ] Extraction happens in a protected temp directory, preferably `get_temp_dir()`, never the plugin directory.
- [ ] ZIP entries are pre-scanned for zip slip/path traversal before extraction when `ZipArchive` is available.
- [ ] Extracted paths are checked after extraction to prevent zip slip/path traversal defense in depth.
- [ ] Uploaded ZIPs are deleted immediately after extraction/validation.
- [ ] Temp upload and extraction directories are protected and removed after import/failure where possible.
- [ ] Posts are always created as `private` by default.
- [ ] Attachment import uses WordPress file/media APIs.
- [ ] Media file types are validated against allowed MIME types.
- [ ] Admin output is escaped.
- [ ] Imported text is sanitized before saving as post content.
- [ ] Shortcode-looking journal text is neutralized before saving so it cannot execute on render.
- [ ] `_day_one_import_complete`/version meta is used so reruns can finish incomplete imports without duplicating posts.
- [ ] Imported scalar metadata is sanitized before use in titles, meta, filenames, and warnings.
- [ ] The plugin makes no external HTTP/API calls.
- [ ] Documentation warns that Media Library files may be accessible by direct URL depending on hosting configuration.
- [ ] Errors/notices avoid exposing full private journal content.

### 12. Error handling strategy

1. Fatal import-level errors should stop before creating posts:
   - Invalid nonce/capability.
   - Upload failure.
   - Non-ZIP/unsupported upload.
   - ZIP extraction failure.
   - No candidate journal JSON files found.
   - No valid `entries` arrays found.
2. Entry-level errors should not stop the import:
   - Missing UUID: skip entry and warn.
   - Duplicate UUID in same export: skip later duplicate and warn.
   - Existing imported UUID with complete marker: skip and count.
   - Existing imported UUID without complete marker/current version: resume missing content/tags/media work and count as resumed.
   - Invalid date: create with fallback date and warn.
   - Post insertion failure: count failed and continue.
3. Media-level errors should not stop entry import:
   - Missing media file.
   - Unsupported MIME.
   - Failed sideload.
   - Failed metadata generation.
4. Cleanup should run in `finally`-style code where possible.
5. Convert `WP_Error` instances into privacy-safe warning/error messages.

### 13. Testing and verification strategy

#### Automated tests where project tooling permits

1. PHP lint:
   - `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
2. WordPress Coding Standards if configured:
   - `phpcs --standard=WordPress .`
3. Unit tests for parser/content functions:
   - Detect `Diario.json`-style files.
   - Ignore JSON files without `entries`.
   - Handle malformed JSON.
   - Normalize entries with missing optional fields.
   - Skip entries missing UUID.
   - Parse ISO-8601 dates and return GMT/local values.
   - Derive titles from heading, first line, fallback.
   - Convert text without allowing script tags.
   - Neutralize shortcode-looking text (`[gallery]`, `[embed]`, custom shortcodes) so it remains visible text and is not executed by WordPress.
   - Normalize tags.
   - Resolve photo paths from MD5/type and extension aliases.
   - Reject unsafe ZIP entry names before extraction in the `ZipArchive` pre-scan helper where that helper can be tested without WordPress bootstrap.
4. WordPress integration tests if test bootstrap exists:
   - Insert private post with `_day_one_uuid`.
   - Re-run same UUID and verify no duplicate.
   - Simulate a partial import (post has `_day_one_uuid` but `_day_one_import_complete` is missing/false) and verify a rerun resumes tags/media/content and then marks complete.
   - Assign tags.
   - Sideload/reuse media attachment with Day One meta.

#### Manual verification

1. Install plugin on a local WordPress site with `WP_DEBUG` enabled.
2. Activate plugin and confirm **Tools → Import → Day One** exists.
3. Upload `sample/05-07-2026_1-48-PM.zip`.
4. Confirm result summary is privacy-safe and shows expected counts for entries/media.
5. Inspect imported posts:
   - `post_status` is `private`.
   - Date matches Day One `creationDate`.
   - Content is readable and preserves paragraph breaks/headings sufficiently.
   - Tags are present where sample entries have tags.
   - Photos are attached and displayed/appended in expected order.
6. Re-import the same ZIP:
   - No duplicate posts are created.
   - Existing complete posts are counted as skipped.
   - Media is not duplicated for posts that are skipped.
7. Simulate/resume an incomplete import by removing `_day_one_import_complete` from an imported post or interrupting a local run:
   - Re-import the same ZIP.
   - Confirm the importer updates only the matching existing post, restores missing content/tags/media, and marks it complete without creating duplicates.
8. Try invalid inputs:
   - Non-ZIP upload.
   - Malformed JSON inside ZIP.
   - ZIP with no JSON entries.
   - Entry missing UUID.
   - Entry with invalid date.
   - Missing `photos/` folder.
   - Unsupported media extension.
9. Confirm temporary ZIP and extraction directories are removed and, if uploads fallback was used, protected while present.
10. Confirm no raw private journal content appears in notices, PHP error logs created by the plugin, README examples, or tests.
11. Confirm frontend only shows imported entries to users who can read private posts, and shortcode-like journal text displays literally rather than executing.

## Suggested commit sequence

1. **Add plugin bootstrap and importer registration**
   - `day-one-importer.php`
   - Bootstrap/admin classes
   - Basic Tools → Import screen with nonce/capability-protected upload form

2. **Add upload validation and extraction helpers**
   - ZIP upload handling
   - Temporary directory creation
   - Safe extraction and cleanup
   - Fatal error reporting

3. **Add Day One JSON discovery and parser**
   - Find JSON files
   - Decode/validate `entries`
   - Normalize entry fields
   - Parser unit tests where available

4. **Add private post creation, idempotency, and resumability**
   - UUID meta tracking
   - Import version and completion marker meta
   - Date/title/content basics
   - Private posts
   - Existing complete UUID skip behavior
   - Existing incomplete UUID resume behavior

5. **Add tags and safe content conversion**
   - Tag assignment
   - Heading/title derivation
   - Paragraph preservation and sanitization
   - Shortcode neutralization before saving imported text
   - Tests for title/content helpers and shortcode-looking text

6. **Add media resolution and import**
   - Locate photos by MD5/type/filename
   - Validate MIME
   - Sideload attachments
   - Attachment meta idempotency
   - Append ordered images to post content

7. **Improve reporting, cleanup, and privacy hardening**
   - Final counters
   - Bounded warnings
   - Escaped admin notices
   - Temp cleanup on failures
   - Review security checklist

8. **Add documentation and verification notes**
   - README with export/import steps
   - Media privacy caveat
   - Manual verification plan
   - Troubleshooting and known limitations

9. **Add tests/tooling refinements**
   - PHP lint instructions/scripts
   - Parser/content/media tests
   - Optional WordPress integration tests
   - ZIP pre-scan/path traversal tests

## Initial version limitations to document

- Posts are private, but uploaded media files may still be accessible by direct URL depending on WordPress/hosting configuration.
- Rich Day One formatting may not be perfectly preserved; primary `text` is imported conservatively.
- Images may be appended after entry text rather than placed at exact original inline positions.
- HEIC/video/audio/PDF support is not guaranteed in the first version unless WordPress accepts and processes those files safely.
- Very large exports may hit PHP timeout or memory limits; future work can add background/batched imports.
