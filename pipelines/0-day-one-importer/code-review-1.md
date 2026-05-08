# Code Review 1: WordPress Day One Importer

## Verdict: CHANGES REQUESTED

The implementation is well structured and passes PHP lint plus the included pure helper tests, but I found privacy/media-handling issues that should be addressed before approval.

## Checks run

- `find . -name '*.php' -print0 | xargs -0 -n1 php -l` — passed.
- `php tests/pure-helper-tests.php` — passed.
- Inspected the sample export structure without printing journal text: 47 entries, 60 photo metadata items, 58 resolvable files.

## Findings

### 1. High — ZIP export is temporarily placed in public uploads

`Day_One_Importer_Runner::handle_upload()` uses `wp_handle_upload()` first, then copies the uploaded ZIP into the protected run directory and deletes the public upload copy (`includes/class-day-one-importer-runner.php:132-154`). A Day One export ZIP contains private journal text and media, so placing it in the normal uploads tree, even briefly, is a privacy risk and weakens the requirement to keep uploaded/extracted private content in protected temporary storage.

**Request:** after nonce/capability and ZIP validation, move the PHP upload directly into the protected run directory (or force `wp_handle_upload()` into that directory via an `upload_dir` override) so the raw export is never stored under publicly served uploads.

### 2. High — Media sideload filename can use the original HEIC name for JPEG derivatives

`Day_One_Importer_Media::sideload_media()` prefers Day One `photo['filename']` for the sideload name (`includes/class-day-one-importer-media.php:353-364`). In the provided sample, many records have `filename` values ending in `.HEIC` while the export file resolved by MD5 is a `.jpeg` derivative. This risks WordPress rejecting the sideload or treating the file inconsistently, and it does not clearly satisfy the requirement to import the WordPress-compatible derivative when HEIC originals are present.

**Request:** use a safe upload filename whose extension matches the resolved source file/validated MIME (for example the MD5/source basename or original basename with the source extension), while preserving the Day One original filename separately in attachment meta. Add a test covering `filename=IMG_*.HEIC`, `type=jpeg`, source `{md5}.jpeg`.

### 3. Medium — Archives with entries but zero importable entries can be reported as success

After parsing, the runner only adds the “No importable Day One entries” fatal error when `empty( $entries ) && 0 === entries_failed` (`includes/class-day-one-importer-runner.php:78-80`). If every entry is malformed or missing a UUID, the admin notice can say the import completed successfully with zero posts created and failed-entry counts.

**Request:** treat `empty( $entries )` as an import-level error regardless of `entries_failed`, while still showing the per-entry warnings/counts.

## Additional notes

- The importer registration, nonce/capability checks, private post creation, UUID idempotency, shortcode neutralization, and bounded privacy-safe warnings are generally good.
- ZIP path preflight is mostly solid, but consider rejecting any `^[A-Za-z]:` archive name, not only `C:/...`, for Windows drive-relative hardening (`includes/class-day-one-importer-cleanup.php:123-126`).
- A live WordPress import was not run in this environment; manual verification should still cover importer registration, actual media sideload behavior, and rerun idempotency.
