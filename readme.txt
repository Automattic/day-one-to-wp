=== Day One Importer ===
Contributors: cbravobernal
Tags: import, importer, day-one, journal, privacy
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Recommended PHP extensions: ZipArchive (for resumable batched imports)
Stable tag: 0.2.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Day One JSON export ZIPs as private WordPress posts with journal categories, tags, dates, supported photos, supported videos, and supported audios.

== Description ==

Day One Importer is made by Automattic. It adds a WordPress admin importer for Day One JSON export ZIP files. It creates one private WordPress post for each Day One entry and attempts to preserve entry dates, journal categories, tags, text, supported photos, supported videos, and supported audios.

The importer is designed for local or private archive migration workflows:

* Imported posts are private by default.
* Large exports run as resumable jobs advanced by short browser requests with a WP-Cron fallback, reducing gateway timeout risk.
* Re-importing the same export skips completed entries using Day One UUID metadata.
* Interrupted, failed, or older-schema imports can be retried, continued, or refreshed in place without duplicating completed posts or media.
* Supported photos, videos, and audios are imported into the Media Library and attached to their posts. Videos render inline as `core/video` blocks and audios render inline as `core/audio` blocks (with the Day One audio `title` as the block caption when set) at their original `richText` position when present.
* New Day One media is stored in a protected uploads subfolder and served through a nonce- and permission-checked WordPress endpoint.
* Generated image sub-sizes are skipped during import to reduce timeout risk on large exports.
* Result screens report counts, UUIDs, dates, filenames, and generic warnings rather than full journal content.

Privacy note: the importer stores new imported media in a dedicated uploads subfolder with best-effort server protection files as defense in depth. It serves imported media through WordPress only to logged-in users with a valid media nonce who can read the associated private post or attachment. If media privacy is critical, confirm your host honors the protection files for that uploads subfolder.

The plugin does not send journal content or media to external services.

For development and testing, the repository includes a wholly fictional sample Day One-style export. Real Day One exports should remain in ignored local paths such as `sample/` or outside the repository.

== Installation ==

1. Optionally confirm PHP has the ZipArchive extension enabled. Resumable batched imports require it; without it the importer falls back to a synchronous single-request import that works for smaller exports but may time out on very large or photo-heavy ones.
2. Install the plugin ZIP through the WordPress Plugins screen, or upload the plugin files to your site's configured plugins directory.
3. Activate the plugin through the Plugins screen in WordPress.
4. Go to Tools > Import and choose Day One.
5. Upload the original Day One JSON export ZIP and click Import Day One export.
6. Watch the import job panel for phase, progress, counters, warnings, and errors. If the browser connection is interrupted, refresh the page or click Retry / Continue.
7. Review the final import summary and spot-check the resulting private posts.

== Frequently Asked Questions ==

= What Day One export format is supported? =

Export your journal from Day One as JSON and keep the original ZIP intact. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos, videos, or audios are present, `photos/`, `videos/`, and/or `audios/` directories.

= Are imported entries public? =

No. Imported entries are created as private WordPress posts by default. New Day One media is stored in a protected uploads subfolder and served through a nonce- and permission-checked endpoint, but you should still confirm your host honors the protection files for that directory.

= Can I rerun the same export? =

Yes. The importer stores Day One UUID metadata and skips entries that were already imported and marked complete. It can also resume incomplete imports, safely retry failed/interrupted batches, and refresh older importer-schema versions in place.

= What media types are imported? =

The importer initially supports common image formats such as JPEG/JPG and PNG, plus other image formats accepted safely by the target WordPress site. Day One videos (`.mov`, `.mp4`) and Day One audios (`.mp3`, `.m4a`, `.aac`) are imported when the site's MIME allowlist accepts them (`video/quicktime`, `video/mp4`, `audio/mpeg`, `audio/mp4`, and `audio/aac` are enabled by default for Administrator-role users on most WordPress sites). Embedded PDF references are recognized but not yet sideloaded. Unsupported or missing media generates warnings without stopping unrelated entries; video and audio embeds whose MIME the site refuses (most notably `.lpcm` linear-PCM audio on default WordPress allowlists) are dropped with a privacy-safe warning (no `core/file` fallback is emitted). To support additional video or audio MIMEs, extend the uploader allowlist via the standard `upload_mimes` filter. New imported media is stored in a protected uploads subfolder and served through a nonce- and permission-checked endpoint. To reduce timeout risk during large imports, generated image sub-sizes are skipped during import; regenerate thumbnails after import if you need those sizes later.

= Does the plugin contact external services? =

No. The plugin processes ZIP files, extracted content, and resumable job manifests locally in protected WordPress temporary locations. Completed or canceled jobs clean up temporary files when possible; failed jobs retain enough state to retry until canceled or stale.

== Changelog ==

= 0.2.12 =
* Import Day One `entry.pdfAttachments[]` from the JSON export as `core/file` blocks at their inline `embeddedObjects[]` positions. The streaming parser normalizes a new `pdfAttachments` field on each entry (with `identifier`, `md5`, `pdfName`, `orderInEntry`, and `fileSize`), and the JSONL manifest round-trips it alongside the existing `photos`, `videos`, and `audios` fields. Each PDF is sideloaded into the existing private uploads subfolder, deduped against any matching attachment on the same post via Day One UUID + identifier (or md5), and emitted as a `core/file` block at its inline position. An entry containing interleaved photo + video + audio + PDF embeds renders them in scan order. When the PDF record carries a non-empty `pdfName`, the block renders that name as the link text; otherwise the block falls back to the attachment basename (without extension) and finally to a literal `[PDF]` floor. The `core/file` block uses the standard `<div class="wp-block-file">` markup with a `Download` button (`wp-block-file__button`) and serves the file through the nonce-checked private endpoint, so PDFs remain readable only to users who can read the parent private post. New attachment markers (`_day_one_media_kind = pdf`, `_day_one_pdf_name`, plus the shared `_day_one_uuid` / `_day_one_media_identifier` / `_day_one_media_md5` / `_day_one_source` keys) are written to every PDF attachment so reruns deduplicate cleanly. Width, height, duration, and date are intentionally not persisted for PDF attachments (Day One ships zeros or omits them).
* Extend ZIP preflight to discover top-level `pdfs/` directories the same way `photos/`, `videos/`, and `audios/` directories are discovered. Both the per-archive candidate list and the resolved directory list are persisted on the import job (`zip_pdf_dirs`, `pdf_dirs`) so the async runner can resolve `pdfs/<md5>.pdf` paths without re-walking the archive on each batch.
* Extend the MIME allowlist gate (`Day_One_Importer_Media::validate_media_file()`) to accept `application/pdf` (in addition to the existing `image/*`, `video/*`, and `audio/*` paths). A PDF is sideloaded only when both `wp_check_filetype_and_ext()` reports `application/pdf` and that MIME appears in the site's `get_allowed_mime_types()` list. WordPress core's default allowlist already includes `application/pdf` for Administrator-role users, so the drop path is reserved for sites whose admins have explicitly stripped PDF from `upload_mimes`. PDFs the site refuses are dropped with a privacy-safe warning that does not leak identifier, UUID, filename, md5, or `pdfName`. **No `core/file` fallback is emitted** for rejected PDF MIMEs: this matches the video/audio precedent because `validate_media_file()` is the canonical codebase-wide MIME gate and `media_handle_sideload()` itself depends on `get_allowed_mime_types()`, so no attachment exists to render a block against. Unresolved PDF identifiers (referenced media file missing from the export, or rejected by the MIME gate) produce one warning per affected `embeddedObjects[]` record. To support additional PDF behavior, extend the uploader allowlist via the standard WordPress `upload_mimes` filter or your multisite Add to mime types setting.
* Bump the internal `IMPORT_SCHEMA_VERSION` from `8` to `9` so existing imported posts that contain PDF embeds are re-rendered when the same export is re-imported. Posts that do not reference any `embeddedObjects[].type === "pdfAttachment"` still re-render but the output is byte-identical to the previous version (modulo the same ID/nonce churn already documented for prior schema bumps). The reprocessing cost is the same shape as previous schema bumps; large existing imports pay it once on the next rerun.

= 0.2.11 =
* Import Day One `entry.audios[]` from the JSON export as `core/audio` blocks at their inline `embeddedObjects[]` positions. The streaming parser normalizes a new `audios` field on each entry (with `identifier`, `md5`, `format`, `duration`, `title`, `orderInEntry`, `date`, and `filename`), and the JSONL manifest round-trips it alongside the existing `photos` and `videos` fields. Each audio is sideloaded into the existing private uploads subfolder, deduped against any matching attachment on the same post via Day One UUID + identifier (or md5), and emitted as a `core/audio` block at its inline position. An entry containing interleaved photo + video + audio embeds renders them in scan order, with consecutive photos collapsing into a single `core/image` or `core/gallery` and each video / audio producing one block in between. When the audio record carries a non-empty `title`, the block renders that title as a `<figcaption class="wp-element-caption">` caption. The `core/audio` block uses the standard `<figure class="wp-block-audio">` markup and serves the file through the nonce-checked private endpoint, so audios remain readable only to users who can read the parent private post. New attachment markers (`_day_one_media_kind = audio`, `_day_one_audio_duration`, `_day_one_audio_title`, plus the shared `_day_one_uuid` / `_day_one_media_identifier` / `_day_one_media_md5` / `_day_one_source` / `_day_one_media_date` / `_day_one_original_filename` keys) are written to every audio attachment so reruns deduplicate cleanly. Width and height are intentionally not persisted for audio attachments (Day One ships zeros).
* Extend ZIP preflight to discover top-level `audios/` directories the same way `photos/` and `videos/` directories are discovered. Both the per-archive candidate list and the resolved directory list are persisted on the import job (`zip_audio_dirs`, `audio_dirs`) so the async runner can resolve `audios/<md5>.<format>` paths without re-walking the archive on each batch.
* Extend the MIME allowlist gate (`Day_One_Importer_Media::validate_media_file()`) to accept `audio/*` MIMEs (in addition to the existing `image/*` and `video/*` paths). An audio is sideloaded only when both `wp_check_filetype_and_ext()` reports an `audio/*` MIME and that MIME appears in the site's `get_allowed_mime_types()` list. Audios whose MIME the target site refuses (most notably `.lpcm` payloads — `audio/L16` / `audio/L24` are not in WordPress's default allowlist) are dropped with a privacy-safe warning that does not leak identifier, UUID, filename, or md5. **No `core/file` fallback is emitted** for rejected audio MIMEs: this matches the photo/video precedent, because `validate_media_file()` is the canonical codebase-wide MIME gate and `media_handle_sideload()` itself depends on `get_allowed_mime_types()`, so no attachment exists to render a `core/file` block against. Unresolved audio identifiers (referenced media file missing from the export, or rejected by the MIME gate) produce one warning per affected `embeddedObjects[]` record. To support additional audio MIME types, extend the uploader allowlist via the standard WordPress `upload_mimes` filter or your multisite Add to mime types setting.
* Bump the internal `IMPORT_SCHEMA_VERSION` from `7` to `8` so existing imported posts that contain audio embeds are re-rendered when the same export is re-imported. Posts that do not reference any `embeddedObjects[].type === "audio"` still re-render but the output is byte-identical to the previous version (modulo the same ID/nonce churn already documented for prior schema bumps). The reprocessing cost is the same shape as previous schema bumps; large existing imports pay it once on the next rerun.

= 0.2.10 =
* Import Day One `entry.videos[]` from the JSON export as `core/video` blocks at their inline `embeddedObjects[]` positions. The streaming parser normalizes a new `videos` field on each entry (with `identifier`, `md5`, `type`, `width`, `height`, `duration`, `orderInEntry`, `date`, and `filename`), and the JSONL manifest round-trips it alongside the existing `photos` field. Each video is sideloaded into the existing private uploads subfolder, deduped against any matching attachment on the same post via Day One UUID + identifier (or md5), and emitted via the existing inline media routing introduced in 0.2.9. An entry containing interleaved photo and video embeds renders them in scan order, with consecutive photos collapsing into a single `core/image` or `core/gallery` and each video producing one `core/video` block in between. The `core/video` block uses the standard `<figure class="wp-block-video">` markup and serves the file through the nonce-checked private endpoint, so videos remain readable only to users who can read the parent private post. New attachment markers (`_day_one_media_kind = video`, `_day_one_video_duration`, `_day_one_width`, `_day_one_height`, plus the shared `_day_one_uuid` / `_day_one_media_identifier` / `_day_one_media_md5` / `_day_one_source` / `_day_one_media_date` / `_day_one_original_filename` keys) are written to every video attachment so reruns deduplicate cleanly.
* Extend ZIP preflight to discover top-level `videos/` directories the same way `photos/` directories are discovered. Both directory lists are persisted on the import job (`zip_video_dirs`, `video_dirs`) so the async runner can resolve `videos/<md5>.<ext>` paths without re-walking the archive on each batch.
* Extend the MIME allowlist gate (`Day_One_Importer_Media::validate_media_file()`) to accept `video/*` MIMEs (in addition to the existing `image/*` path). A video is sideloaded only when both `wp_check_filetype_and_ext()` reports a `video/*` MIME and that MIME appears in the site's `get_allowed_mime_types()` list. Videos whose MIME the target site refuses (for example because `video/quicktime` or `video/mp4` is not in `get_allowed_mime_types()`) are dropped with a privacy-safe warning that does not leak identifier, UUID, filename, or md5. **No `core/file` fallback is emitted** for rejected video MIMEs: this is a deliberate departure from the original prompt's "fall back to `core/file` and warn" wording, documented in the spec, because `validate_media_file()` is the canonical codebase-wide MIME gate and `media_handle_sideload()` itself depends on `get_allowed_mime_types()`, so no attachment exists to render a `core/file` block against. Unresolved video identifiers (referenced media file missing from the export, or rejected by the MIME gate) produce one warning per affected `embeddedObjects[]` record. To support additional video MIME types, extend the uploader allowlist via the standard WordPress `upload_mimes` filter or your multisite Add to mime types setting.
* Bump the internal `IMPORT_SCHEMA_VERSION` from `6` to `7` so existing imported posts that contain video embeds are re-rendered when the same export is re-imported. Posts that do not reference any `embeddedObjects[].type === "video"` still re-render but the output is byte-identical to the previous version (modulo the same ID/nonce churn already documented for 0.2.6 and 0.2.9). The reprocessing cost is the same shape as previous schema bumps; large existing imports pay it once on the next rerun.
* Scope the 0.2.9 "imported media but no inline embed" privacy-safe warning (`finalize_imported_entry()`'s richText-has-no-photo-embeds branch) to photos only. The warning was a workaround for a Day One quirk where the user's photo lives at the entry root rather than inline; videos do not exhibit the same pattern in observed exports, so the warning is intentionally not extended to videos in this release. Extending it (or adding a parallel video variant) may land as a follow-up if observed exports show a similar shape for videos.

= 0.2.9 =
* Place Day One `richText` inline photos at their original position in the text stream. When a `richText` payload lists photos via `contents[].embeddedObjects[]`, each `type: photo` embed resolves against the imported attachment for the same identifier and renders inline: a single embed becomes a `core/image` block between the surrounding paragraphs, and two or more consecutive embeds (including embeds separated only by transparent empty items) collapse into a single `core/gallery` block. Legacy `text`-only entries continue to append photos after the entry text in entry order.
* Broaden the legacy-text placeholder regex so the importer strips every observed `dayone-moment` variant from `text`-only entries. The regex now accepts all nine forms seen in real exports: `dayone-moment://UUID`, `dayone-moment:/photo/UUID`, `dayone-moment:/video/UUID`, `dayone-moment:/audio/UUID`, `dayone-moment:/pdfAttachment/UUID`, `dayone-photo://UUID`, `dayone-video://UUID`, `dayone-audio://UUID`, and `dayone-pdf://UUID`. The match remains anchored to a fully-trimmed line wrapped in `![...](...)`, so a sentence that merely mentions a `dayone-` URL inline is still preserved.
* Recognize embedded video, audio, and PDF references inside `richText` without sideloading them yet. Each unsupported embed type emits exactly one privacy-safe warning per entry per type and renders no block. The warning text contains no identifier, UUID, file path, md5, or internal issue number. Full support is tracked as separate follow-ups (video #57, audio #58, PDF #59).
* Document the `richText` + photos + no-`embeddedObjects` trade-off. When a `richText` entry imports photos but its `contents[]` contains zero `type: photo` embeds, the photos still attach to the post (and remain visible in the Media Library), but the body no longer receives an appended gallery. The runner emits one privacy-safe warning per such entry so the dropped placement is not silent. This avoids reintroducing the duplicate-gallery shape that motivated #56 for the common case.
* Move `richText` body rendering to entry finalize so the `identifier → attachment_id` map populated during media import is available when the body is materialized. The initial `private` draft inserts a deterministic placeholder body (the legacy `text` for legacy entries, an empty string for `richText` entries) and `finalize_imported_entry()` becomes the sole producer of `post_content`. Imports remain idempotent and resumable; the change is not user-visible.
* `IMPORT_SCHEMA_VERSION` is unchanged in this release: the persisted manifest shape is unchanged because inline positioning is derived at render time from the already-persisted `richText` payload and from per-job media metadata. Existing imported posts keep their current layout; re-importing the same export will re-render in the new inline shape.

= 0.2.8 =
* Map Day One `richText` `attributes.line` block-level structures to native Gutenberg blocks. `header` values 1–6 render as `core/heading` blocks at the matching level (intra-text newlines render as `<br />`; adjacent headings stay as separate blocks; values outside 1–6 fall through to the paragraph path). `listStyle: bulleted` and `listStyle: numbered` render as `core/list` blocks with `core/list-item` children, honoring the first item's `listIndex` as the ordered-list `start` attribute when it is an integer (or numeric string) `>= 2`, and nesting deeper `indentLevel` items as parent-child nested `core/list` blocks inside the parent list item.
* Render `listStyle: checkbox` runs as `core/list` blocks with the `task-list` className. Each item is prefixed with a Unicode ballot-box glyph: `&#9745;` (☑) for `checked: true`, `&#9744;` (☐) otherwise, followed by a single space. The checkbox is purely visual because Day One captures the checked state at export time and `core/list-item` does not accept `<input>` content; the glyph approach survives both `wp_kses_post` and the Gutenberg block validator.
* Collapse consecutive `codeBlock: true` runs into a single `core/code` block, joining per-item text with single `\n` separators (no trailing newline). Inline-formatting wrappers are intentionally not applied inside `<code>`, so `linkURL` or `highlightedColor` on a code item is ignored without recording a warning.
* Collapse consecutive `quote: true` runs into a single `core/quote` block with one child paragraph per item. Inline-formatting wrappers still apply inside headings, list items, and quote paragraphs. Quote `indentLevel` is intentionally ignored: all quote items render as flat siblings inside the same `<blockquote>` (Day One uses quote indent as typographic spacing rather than semantic nesting).
* Empty-text items between two items of the same kind are treated as transparent drops: the surrounding run is not closed, so a single empty richText item inside a list, code block, or quote does not split it into multiple blocks.
* `IMPORT_SCHEMA_VERSION` is unchanged in this release; the persisted manifest shape is unchanged because line attributes are read from the already-persisted richText payload.

= 0.2.7 =
* Render Day One `richText` run-level inline attributes when present: `bold`, `italic`, `strikethrough`, and `inlineCode` map to `<strong>`, `<em>`, `<s>`, and `<code>`; `linkURL` (with optional `autolink`) maps to `<a href="…">`; and `highlightedColor` (Day One's `0xRRGGBB` form) maps to `<mark style="background-color:#RRGGBB">`. Run text is escaped before wrappers are applied, so existing shortcode/HTML neutralization is preserved.
* Validate `linkURL` strictly: only http(s) URLs that survive `esc_url()` produce an anchor. Non-http(s) or otherwise-invalid links are dropped (the run text still renders) and a privacy-safe warning is recorded that does not include the rejected URL value verbatim.
* Validate `highlightedColor` strictly against the `0xRRGGBB` pattern (case-insensitive `0x` prefix, exactly six hex digits). Malformed values are dropped (the run text still renders) and a privacy-safe warning is recorded that does not include the rejected color value verbatim.
* Note: the `<mark style="background-color:#RRGGBB">` wrapper depends on the target site's `wp_kses_post` allowlist accepting `<mark>` with that style attribute. On the plugin's supported WordPress range this passes unchanged; if a host or filter tightens the allowlist, only the highlight color is dropped silently and the run's text still survives. The `IMPORT_SCHEMA_VERSION` is unchanged in this release, so re-importing the same export does not refresh existing posts solely for inline formatting.

= 0.2.6 =
* Read Day One `richText` payloads when present (both JSON-encoded string and pre-decoded object forms) and route each text run through the existing Day One text-to-block conversion (typically a paragraph block; runs that begin with a markdown sigil such as `# `, `- `, or `*` still produce the matching heading or list block, matching legacy `text`-only rendering). Legacy `text`-only entries import unchanged. Inline formatting (bold/italic/links/etc.), explicit richText line attributes (proper code blocks, blockquotes, checklists, nested lists, header levels), and inline-positioned media are intentionally not interpreted from richText in this release; they are tracked as separate follow-ups.
* Bump the internal `IMPORT_SCHEMA_VERSION` so existing imported posts are re-rendered when the same export is re-imported. For legacy `text`-only entries the re-rendered output is byte-identical to the previous version; entries that ship `richText` switch from a single legacy markdown-rendered body to one block per richText run (preserving the same per-run escape and markdown semantics).
* Malformed `richText` payloads no longer drop the entry: the importer falls back to the legacy `text` rendering and records a privacy-safe warning naming the affected entry UUID.

= 0.2.5 =
* Address WordPress.org review feedback: remove the extra contributor, add nonce verification for admin job/media URLs, sanitize and validate request/upload values before processing, escape generated job-panel markup with an allow-list, store private media in a protected uploads subfolder, stop changing PHP time limits, and avoid switching the current user during cron processing.

= 0.2.4 =
* Add a missing `translators:` comment to the `%d%% complete` localized progress format used by the resumable job panel's JavaScript so Plugin Check no longer flags the call as `WordPress.WP.I18n.MissingTranslatorsComment`.
* Tighten the upload dispatcher screen-routing check while keeping upload nonce verification in the submission handler.

= 0.2.3 =
* Fix the Import Day One Export upload submission. The submit handler still referenced the removed inline `#day-one-importer-status` notice (its `statusRegion`, `statusMessage`, and `spinner` lookups), which threw a `ReferenceError` under strict mode right after `event.preventDefault()` and prevented the XHR upload from running, so the form looked unresponsive and the upload percentage never appeared. The stale references are now removed.

= 0.2.2 =
* Fix the Cancel import button so the job panel reflects the canceled status immediately. The poll loop's in-flight `day_one_importer_job_process` response was returning after the cancel completed and overwriting the panel back to the running state; the polling callbacks now bail when the `stopped` flag has been set by Cancel.

= 0.2.1 =
* Move the upload submission dispatcher to `admin_init` so the post-upload `wp_safe_redirect()` runs before `admin-header.php` emits headers. Previously the redirect failed silently from inside the importer screen callback, leaving the page showing the prior canceled job instead of the freshly queued one. Also remove the redundant `#day-one-importer-status` notice under the form and suppress the upload panel's phase and "Progress will update as the job runs." sub-labels during the ZIP upload.

= 0.2.0 =
* Fix the blank importer screen after a successful upload by redirecting to `import.php` instead of `admin.php`, which is the dispatcher that `register_importer()` uses.
* Upload the export ZIP in the background and show real-time upload percentage in the job panel; the form, intro copy, and panel remain on screen for the entire upload instead of blanking during navigation.
* Always render the import job panel scaffold (hidden when no job exists yet) so the live upload progress and queuing state have a stable place to render before the first job is created.
* Collapse the job panel notice color to three states: canceled or failed runs are red, queued or running runs are blue, completed runs are green regardless of warnings.
* Recalibrate the import progress percentage so the bar tracks entries imported during the importing phase instead of jumping to roughly 65% once preflight, extract, and indexing finish. Resumed jobs paint at their already-imported ratio on first render, and failed or canceled mid-import jobs keep the computed value rather than snapping to 100%.
* Add an estimated import progress bar to the resumable job panel so paused, canceled, retried, or re-opened uploads visibly report their current status.
* Plugin Check compliance cleanup with no user-facing behavior change: private media writability checks now call `wp_is_writable()` directly (the prior `is_writable()` fallback is moved to the test bootstrap as a polyfill), long-running imports use the resumable job flow rather than changing the PHP time limit, and the media class direct-access guard is rewritten in the nested form already used elsewhere in the plugin.
* Additional Plugin Check compliance cleanup with no user-facing behavior change: add a `translators:` hint to the `%d%% complete` progress string in the resumable job panel and document intentionally low-level file/option operations used by the streaming parser and distributed import lock.

= 0.1.0 =
* Initial release.
* Import Day One JSON export ZIP entries as private WordPress posts.
* Preserve dates, journal categories, tags, conservative text formatting, and supported photos.
* Support resumable batched import jobs with progress, Retry / Continue, cancellation, cron fallback, idempotent reruns, incomplete import resume behavior, and privacy-safe result summaries.

== Upgrade Notice ==

= 0.2.12 =
Imports Day One `entry.pdfAttachments[]` into the existing private uploads subfolder and renders them as `core/file` blocks at their inline `embeddedObjects[]` positions, including interleaved photo + video + audio + PDF sequences. The PDF's `pdfName` is rendered as the block link text with a `Download` button per Gutenberg defaults; absent names fall back to the attachment basename and then to a `[PDF]` floor. ZIP preflight now discovers `pdfs/` directories alongside `photos/`, `videos/`, and `audios/`. The MIME gate is extended to `application/pdf`; PDFs whose MIME the site refuses (rare — `application/pdf` ships in WordPress core's default allowlist for admins) are dropped with a privacy-safe warning (no identifier leak and no `core/file` fallback) — extend `upload_mimes` to allow them. Bumps the internal import schema (`8` → `9`) so re-imports refresh existing posts that contain PDF embeds.

= 0.2.11 =
Imports Day One `entry.audios[]` into the existing private uploads subfolder and renders them as `core/audio` blocks at their inline `embeddedObjects[]` positions, including interleaved photo + video + audio sequences. When the audio record carries a non-empty `title`, the block renders that title as a `<figcaption class="wp-element-caption">` caption. ZIP preflight now discovers `audios/` directories alongside `photos/` and `videos/`. The MIME gate is extended to `audio/*`; audios whose MIME the site refuses (most notably `.lpcm` payloads on default WordPress allowlists) are dropped with a privacy-safe warning (no identifier leak and no `core/file` fallback) — extend `upload_mimes` to allow them. Bumps the internal import schema (`7` → `8`) so re-imports refresh existing posts that contain audio embeds.

= 0.2.10 =
Imports Day One `entry.videos[]` into the existing private uploads subfolder and renders them as `core/video` blocks at their inline `embeddedObjects[]` positions, including interleaved photo + video sequences. ZIP preflight now discovers `videos/` directories alongside `photos/`. The MIME gate is extended to `video/*`; videos whose MIME the site refuses are dropped with a privacy-safe warning (no identifier leak and no `core/file` fallback) — extend `upload_mimes` to allow them. The 0.2.9 "imported media but no inline embed" warning remains photo-specific. Bumps the internal import schema (`6` → `7`) so re-imports refresh existing posts that contain video embeds.

= 0.2.9 =
Places Day One richText inline photos at their original position in the text stream (single embed → `core/image`, consecutive embeds → `core/gallery`) and broadens the legacy-text placeholder regex to strip all nine observed `dayone-moment` variants. Embedded video, audio, and PDF references are recognized but not yet sideloaded — each logs one privacy-safe warning per entry per type until #57/#58/#59 land. `richText` entries that import photos without any inline `embeddedObjects` keep their photos attached to the post but no longer receive an appended gallery, and the runner records a privacy-safe warning so the dropped placement is not silent.

= 0.2.8 =
Maps Day One richText line attributes to native Gutenberg blocks: headings 1–6, bulleted/numbered lists (with `start` and nested levels), checkbox lists (Unicode ballot-box glyphs; visual-only because `core/list-item` rejects `<input>`), code blocks (consecutive runs collapsed; no inline wrappers), and blockquotes (flat siblings; `indentLevel` ignored). Inline wrappers still apply inside headings, list items, and quote paragraphs.

= 0.2.7 =
Adds richText inline formatting: bold, italic, strikethrough, inline code, http(s) links, and highlight color are now rendered when present in `richText` payloads. Non-http(s) links and malformed highlight colors are dropped with privacy-safe warnings; `<mark style="background-color:#RRGGBB">` survives only if the site's `wp_kses_post` allowlist accepts it.

= 0.2.6 =
Adds initial Day One `richText` parsing (one block per text run, delegating to the legacy markdown helper), bumps the import schema version so re-imports refresh existing posts, and falls back to legacy `text` with a privacy-safe warning if a `richText` payload cannot be decoded.

= 0.2.5 =
Addresses WordPress.org review feedback for nonces, upload sanitization, escaping, contributor metadata, private media directory selection, PHP time limits, and cron processing.

= 0.2.4 =
Adds a missing `translators:` comment for the `%d%% complete` progress format.

= 0.2.3 =
Fixes the unresponsive Import Day One Export button and the missing upload percentage caused by stale DOM references in the submit handler.

= 0.2.2 =
Cancel import now updates the job panel status immediately instead of flickering back to the running state.

= 0.2.1 =
Fixes the post-upload screen still showing the previously canceled job instead of the new one by dispatching the upload on `admin_init` so the redirect actually runs.

= 0.2.0 =
Fixes the post-upload blank screen and adds a live upload progress percentage to the import job panel.

= 0.1.0 =
Initial release.
