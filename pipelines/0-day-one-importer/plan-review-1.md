# Implementation Plan Review 1

**Verdict: CHANGES REQUESTED**

The plan is thorough and mostly aligned with the prompt/spec: it covers importer registration, private posts, UUID idempotency, media sideloading, tags, cleanup, privacy-safe reporting, docs, and a reasonable test strategy. However, a few correctness/security/resumability gaps should be addressed before implementation.

## Required changes

### 1. Make resumability handle partially imported entries

The plan currently says that if an existing `_day_one_uuid` post is found, the initial version should skip it and avoid mutating it. That prevents duplicate posts, but it is not sufficiently resumable:

- If a run creates the post and then times out/fails before tags/media/content update, a rerun will skip the post forever.
- If media import fails due to a transient error, rerun cannot attach missing media.
- If a crash happens between `wp_insert_post()` and adding meta/tags/media, state may be incomplete.

Please add an explicit strategy, such as:

- Store an import completion marker/version, e.g. `_day_one_import_complete` and/or `_day_one_import_version`.
- On rerun, skip only entries that are complete by default.
- For existing incomplete imported posts, safely finish missing tags/media/content updates without creating duplicates.
- Alternatively add a documented “resume/update existing imported entries” mode and make it the behavior for incomplete posts.

This matters because the spec asks for a reasonably resumable importer, not just duplicate post avoidance.

### 2. Prevent shortcode execution from imported journal text

The plan says “Do not execute shortcodes from imported content,” but the conversion strategy does not fully guarantee that. In WordPress, shortcode-looking text saved in `post_content` can execute on render even if the text was originally escaped/sanitized, because shortcodes are processed from post content.

Please specify a concrete mitigation, for example:

- Escape/neutralize square brackets in user journal text before saving, or
- Wrap imported text in blocks/markup in a way that shortcode syntax is encoded as text, or
- Otherwise ensure `[gallery]`, `[embed]`, or plugin-provided shortcodes in Day One text cannot execute.

This is both a privacy and correctness issue because imported private journal text should not trigger embeds, galleries, or third-party plugin behavior.

### 3. Strengthen archive path traversal protections before extraction

The plan proposes inspecting extracted files after `unzip_file()`. Post-extraction validation is useful, but by itself it may be too late if an archive extraction implementation writes a traversal path before validation.

Please make the safe-extraction requirement explicit:

- Pre-scan ZIP entries with `ZipArchive` where available and reject absolute paths, `..` components, drive-letter paths, symlink-like entries if detectable, and unsafe filenames before calling extraction; and/or
- Clearly document reliance on a WordPress extraction API that normalizes/rejects unsafe paths, with a fallback pre-scan.
- Keep the existing post-extraction realpath validation and cleanup as defense in depth.

### 4. Reduce exposure of uploaded ZIP/extracted private content under public uploads

The plan uses `wp_handle_upload()` and extracts under `wp_upload_dir()['basedir']`. That is WordPress-conventional, but Day One exports contain highly private data and uploads directories are often web-accessible.

Please add implementation details to minimize the exposure window:

- Use a non-public temp location when feasible, e.g. `get_temp_dir()` or a protected subdirectory, while still respecting filesystem permissions.
- If using uploads, create protection files where supported (`index.html`, possibly `.htaccess`/web.config) and delete the uploaded ZIP immediately after extraction.
- Ensure cleanup runs on fatal validation failures and after successful imports.

The plan already mentions cleanup and media privacy caveats; this change is about temporary raw export files.

## Recommended clarifications

- Ensure `register_importer()` availability is handled robustly by loading `wp-admin/includes/import.php` if needed.
- Clarify whether multiple journal JSON files can produce duplicate UUIDs across files and that duplicates are detected across the merged run.
- Clarify attachment reuse queries: prefer attachment meta plus parent post match, but avoid reusing a same-MD5 attachment from an unrelated user/import if it could cross-associate private media unexpectedly.
- Add a test case for shortcode neutralization and incomplete-import rerun behavior.

## Summary

Approve after the above changes are incorporated. The plan is otherwise strong and implementation-ready, but the current skip-existing behavior and shortcode/archive/temp-file details leave important edge cases uncovered for a private journal importer.
