# Specification: WordPress Day One Importer

## Problem statement

Day One users who want to close or leave their Day One account need a private, durable way to preserve exported journals in WordPress. WordPress currently has no built-in importer for Day One exports. This plugin should add a WordPress admin importer that converts a Day One export into private WordPress posts while preserving journal text, dates, tags, and supported media as faithfully and safely as practical.

## Goals

- Add a Day One importer under WordPress admin **Tools → Import**.
- Accept a Day One journal export, preferably as the original `.zip`, and import its JSON and media assets.
- Create one private WordPress post per Day One entry.
- Preserve original entry creation dates, tags, text, and associated supported media.
- Keep imported journal content private by default and avoid accidental public exposure.
- Provide clear progress/results/errors to the importing administrator.
- Make imports reasonably resumable and idempotent by tracking Day One UUIDs and media identifiers.
- Follow WordPress plugin development best practices, coding standards, and internationalization conventions.

## User stories

- As a WordPress administrator, I can install and activate the plugin and see “Day One” listed in Tools → Import.
- As a Day One user, I can upload a Day One export zip and import my journal entries into WordPress.
- As a privacy-conscious user, I can trust that imported entries are private posts unless I explicitly change them later.
- As a user with photos in entries, I can see imported photos attached to the corresponding posts and included in the post content when feasible.
- As a user with tags in Day One, I can find imported posts by equivalent WordPress post tags.
- As an administrator rerunning a failed import, I do not get duplicate posts for entries that were already imported.
- As an administrator troubleshooting an import, I receive useful counts and error messages without exposing private journal content unnecessarily.

## Functional requirements

### Importer registration and admin flow

1. The plugin must include a valid WordPress plugin header and load only in compatible WordPress environments.
2. The plugin must register a Day One importer using WordPress importer APIs so it appears in **Tools → Import**.
3. The importer screen must:
   - Explain the expected Day One export format.
   - Provide a file upload form for `.zip` exports.
   - Optionally support a developer/admin fallback for an already-extracted export directory if appropriate for the environment.
   - Use nonces and capability checks.
   - Display import progress/results, including counts of entries created, skipped, failed, media imported, media skipped, and warnings.
4. The importer must require an administrator-level capability appropriate for importing and uploading content, e.g. `import`, `upload_files`, and `edit_posts`/`publish_posts` as needed.

### Accepted input

1. Primary input should be a Day One export `.zip` containing at least one journal JSON file and media folders.
2. The importer must validate that uploaded files are archives of an expected type and reject unsupported files gracefully.
3. The importer must extract uploads using WordPress filesystem/upload APIs and clean up temporary files after completion or failure.
4. The importer must detect one or more Day One journal JSON files in the export. The sample export contains `Diario.json` with top-level `metadata` and `entries` keys.
5. The importer must handle malformed JSON, missing `entries`, empty exports, and unsupported schema versions with clear errors or warnings.

### Entry parsing and post creation

For each valid Day One entry:

1. Create or reuse exactly one WordPress post for each Day One `uuid`.
2. Store the Day One entry UUID in post meta, e.g. `_day_one_uuid`.
3. Create posts with:
   - `post_type`: `post`.
   - `post_status`: `private`.
   - `post_date` and `post_date_gmt` derived from Day One `creationDate`.
   - `post_modified` and `post_modified_gmt` derived from `modifiedDate` where valid, or default WordPress behavior if unavailable.
   - `post_content` from Day One `text` converted/sanitized into useful WordPress content.
   - A reasonable `post_title`, derived from the first Markdown heading, first non-empty line, or localized fallback such as “Day One entry — {date}”.
4. Preserve Day One tags by assigning WordPress post tags. Tag names must be sanitized with taxonomy APIs.
5. Preserve useful non-sensitive metadata in post meta where appropriate, including Day One UUID, time zone, starred/pinned booleans, and possibly location/weather metadata if implemented. Location/weather display is not required for initial import unless included safely in post content or meta.
6. Continue processing remaining entries if one entry fails, and report per-entry failures by UUID/date rather than private text excerpts.

### Content handling

1. Day One `text` is the primary source for post content. The sample shows Markdown-like text and headings.
2. The importer should preserve paragraph breaks and common Markdown constructs in a WordPress-friendly way.
3. If Markdown conversion is implemented, it must use bundled code or WordPress-safe processing; no external service dependencies are allowed.
4. If rich text JSON (`richText`) is present, it may be ignored in the initial version unless used to improve fidelity. The importer must not fail solely because `richText` is absent or unparsable.
5. Imported content must be sanitized with WordPress post/content APIs, preserving safe markup and preventing unsafe HTML/script injection.

### Media import behavior

1. The importer must support Day One `photos` arrays and media files in an export `photos/` folder.
2. The sample export stores photo metadata in entries with fields such as `identifier`, `md5`, `type`, `filename`, `date`, `orderInEntry`, `width`, and `height`; exported files are named by MD5 with extensions such as `.jpeg` and `.png`.
3. For each supported photo:
   - Locate the source file by metadata, preferring `photos/{md5}.{type-or-extension}` and allowing common extension normalization (`jpeg`/`jpg`, `png`, etc.).
   - Import the file into the WordPress Media Library using WordPress media/file APIs.
   - Attach the media item to the corresponding imported post.
   - Store Day One media identifiers and/or MD5 in attachment meta to avoid duplicate media imports.
   - Include image markup or blocks in `post_content` in `orderInEntry` order where feasible. If exact inline placement cannot be determined, append a gallery or ordered image section after the text.
4. The importer should preserve attachment metadata where useful and safe, such as original filename, Day One media identifier, capture date, and dimensions.
5. Supported initial media types should include common image formats exported by Day One and accepted by WordPress (`jpeg`, `jpg`, `png`, and other WordPress-supported image MIME types). Unsupported media types should be skipped with warnings rather than causing the entire import to fail.
6. If Day One exports HEIC originals but provides JPEG/PNG derivatives, import the WordPress-compatible derivative. Native HEIC support is not required unless the WordPress environment supports it.
7. The importer should be structured so future support for videos, audio, PDFs, or other attachments can be added, but those types are not required for the initial acceptance unless they are present and WordPress accepts them safely.
8. Missing media files must not block entry import; the post should be created with a warning that media was missing.

## WordPress-specific requirements, APIs, and constraints

- Use `register_importer()` for Tools → Import integration.
- Use WordPress admin form helpers and nonce verification (`wp_nonce_field()`, `check_admin_referer()` or equivalent).
- Use capability checks such as `current_user_can()` before rendering upload/import actions.
- Use WordPress upload and filesystem APIs, such as `wp_handle_upload()`, `wp_upload_dir()`, `WP_Filesystem`, and archive helpers where applicable.
- Use `wp_insert_post()`/`wp_update_post()` for post creation and updates.
- Use `wp_set_post_tags()` or taxonomy APIs for tags.
- Use media APIs such as `media_handle_sideload()`, `wp_insert_attachment()`, `wp_generate_attachment_metadata()`, and `wp_update_attachment_metadata()` as appropriate.
- Use `wp_check_filetype_and_ext()`/MIME validation for uploaded/imported files.
- Escape all admin output with `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, etc.
- Sanitize all imported scalar data before use in titles, meta, tags, paths, and admin messages.
- Prefix functions/classes/hooks/meta keys to avoid collisions, e.g. `day_one_importer_` or a namespaced class structure.
- Wrap user-facing strings in internationalization functions with a plugin text domain.
- Avoid network calls and external service dependencies.
- Be compatible with a supported baseline WordPress/PHP version defined by the implementation plan/readme.

## Day One export assumptions

Based on the provided sample export:

- The export may be a zip containing a top-level folder with a journal JSON file and a `photos/` directory.
- A journal JSON file has top-level `metadata` and `entries` keys.
- `metadata.version` may be present.
- Each entry may include keys such as `uuid`, `creationDate`, `modifiedDate`, `timeZone`, `text`, `richText`, `tags`, `photos`, `location`, `weather`, `starred`, `isPinned`, and device/activity fields.
- Entry dates are ISO-8601 UTC strings such as `2024-10-29T12:34:30Z`.
- `tags` is an array of strings.
- `photos` is an array of objects; files can be resolved from `md5` plus media type/extension in the `photos/` folder.
- Entries may not all contain media.
- Some optional fields may be absent; parsing must be defensive.
- Private journal text from samples must not be hard-coded in tests, docs, or logs.

## Privacy and security requirements

1. Imported posts must be `private` by default.
2. Attachments imported for private posts should be attached to those posts. The plugin must document WordPress media privacy limitations: uploaded media files may be accessible by direct URL depending on hosting configuration, even when attached to private posts.
3. The importer must not send journal content or media to external services.
4. Admin notices/logs should avoid displaying full journal content. Use UUIDs, dates, filenames, and counts for diagnostics.
5. Temporary extracted files must be stored in an appropriate uploads/temp location and removed after import where possible.
6. Uploaded zip files and extracted private content must not be left in publicly browsable plugin directories.
7. Validate archive extraction paths to prevent zip slip/path traversal.
8. Verify file types before importing media and reject executable or unexpected files.
9. Use nonces and capability checks on every import action.
10. Avoid increasing visibility of imported content via public post status, public custom post types, or automatic sharing integrations.

## Idempotency and duplicate handling

- Store `_day_one_uuid` on imported posts and query by it before creating a post.
- If a post with the same Day One UUID already exists, skip it by default and count it as skipped.
- Optionally provide an “update existing imported entries” mode, but default behavior should avoid duplicates.
- Store media-level metadata, e.g. `_day_one_media_identifier` and `_day_one_media_md5`, on attachments.
- Before importing a media file for a post, check for an existing attachment with the same media identifier/MD5 associated with the same Day One import/post and reuse it if found.
- Idempotency checks should include posts regardless of status to avoid duplicates if an imported private post was later edited or trashed; trashed handling may be reported and skipped unless update/restore behavior is explicitly implemented.

## Error handling and reporting

- Fail early with a clear admin error when the upload is invalid, the archive cannot be extracted, or no journal JSON is found.
- For per-entry or per-media errors, continue importing the rest of the export when safe.
- Report a final summary with at least:
  - Entries found.
  - Posts created.
  - Existing posts skipped.
  - Entries failed.
  - Media imported.
  - Media reused/skipped.
  - Media failed/missing.
- Include detailed but privacy-safe warnings for unsupported media, invalid dates, missing UUIDs, duplicate UUIDs in the same export, or malformed entry data.
- Ensure cleanup runs after both successful and failed imports when possible.

## Accessibility and internationalization

- Admin screens must use semantic markup, labels associated with form controls, and WordPress admin UI patterns.
- Results and errors should be announced through standard WordPress admin notice markup.
- Do not rely solely on color to communicate status.
- User-facing strings must be translatable using the plugin text domain.
- Date/time output in admin messages should use WordPress date/time formatting functions where practical.

## Acceptance criteria

1. Activating the plugin adds a Day One importer to **Tools → Import**.
2. An administrator can upload the provided sample Day One export zip and start an import.
3. The importer reads `Diario.json`-style journal JSON and processes all entries without exposing sample private text in logs/notices.
4. Each imported Day One entry creates one WordPress `post` with `private` status.
5. Imported posts preserve the Day One `creationDate` in `post_date_gmt` and an appropriate local `post_date`.
6. Imported posts contain useful converted content from the Day One `text` field.
7. WordPress post tags are assigned from Day One `tags`.
8. Supported photos from the sample export are imported into the Media Library, attached to the corresponding post, and represented in the post content or appended gallery/section.
9. Missing or unsupported media generates warnings but does not stop the whole import.
10. Re-running the same import does not create duplicate posts for the same Day One UUIDs and should not duplicate already-imported media.
11. Admin upload/import actions are protected by nonces and capability checks.
12. Admin output is escaped, imported data is sanitized, and file/archive handling prevents unsafe paths or file types.
13. The plugin includes documentation explaining how to export from Day One and import into WordPress, including media privacy caveats.
14. The repository includes tests or a documented manual verification plan suitable for a WordPress plugin.

## Out of scope for initial version

- Bi-directional sync with Day One.
- Importing directly from a Day One account/API.
- Deleting a user’s Day One account.
- Public publishing workflows or automatic sharing.
- Perfect fidelity for Day One rich text, templates, prompts, weather, location maps, activity, or device metadata.
- Guaranteed privacy for raw media URLs beyond WordPress/hosting capabilities.
- Full support for every possible Day One attachment type if not supported by WordPress media handling.
- Multisite/network-specific management beyond normal plugin compatibility.
- Background queue processing for very large imports unless needed by implementation constraints.

## Verification strategy

### Automated checks

- Run PHP linting for plugin files.
- Run WordPress coding standards where configured.
- Add unit tests for pure parsing/resolution functions where possible:
  - Detecting journal JSON files in an extracted export.
  - Parsing valid and invalid Day One JSON.
  - Resolving photo file paths from `md5`, `type`, and folder structure.
  - Deriving titles from entry text.
  - Mapping tags and dates.
- Add WordPress integration tests where feasible for:
  - Creating private posts with `_day_one_uuid` meta.
  - Skipping duplicate UUIDs.
  - Assigning tags.
  - Importing/reusing media attachments.

### Manual verification

1. Install and activate the plugin on a local WordPress site with debugging enabled.
2. Confirm the Day One importer appears under Tools → Import.
3. Import the sample zip from `sample/05-07-2026_1-48-PM.zip`.
4. Verify the result summary counts match the sample structure: all entries detected, media processed, and any warnings are privacy-safe.
5. Inspect several imported posts:
   - Status is private.
   - Date matches the Day One creation date.
   - Content is readable and preserves headings/paragraphs.
   - Tags are present.
   - Photos are attached and displayed/linked appropriately.
6. Re-import the same zip and verify posts/media are skipped or reused rather than duplicated.
7. Test invalid inputs: non-zip file, malformed JSON, missing media folder, missing UUID, invalid date, and unsupported media.
8. Confirm temporary extraction files are cleaned up.
9. Review admin screens with keyboard navigation and screen-reader-friendly labels/notices.
10. Review generated posts and attachments to ensure no imported content is public unless deliberately changed by an administrator.
