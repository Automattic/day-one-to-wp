=== Day One Importer ===
Contributors: cbravobernal
Tags: import, importer, day-one, journal, privacy
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Day One JSON export ZIPs as private WordPress posts with categories, tags, dates, photos, videos, audios, and PDF attachments.

== Description ==

Day One Importer is made by Automattic. It adds a WordPress admin importer for Day One JSON export ZIP files. It creates one private WordPress post for each Day One entry and attempts to preserve entry dates, journal categories, tags, text, supported photos, supported videos, supported audios, and supported PDF attachments. Entries import as private posts by default; the import screen also offers an optional Journal Entries custom post type, and the choice applies to newly imported entries only.

The importer is designed for local or private archive migration workflows:

* Imported entries are private by default and created as regular posts; the import form can optionally target a Journal Entries custom post type instead, affecting newly imported entries only.
* The PHP ZipArchive extension is required so ZIP exports can be inspected with safety budgets before extraction.
* Large exports run as resumable jobs advanced by short browser requests with a WP-Cron fallback, reducing gateway timeout risk.
* Re-importing the same export skips completed entries using Day One UUID metadata.
* Interrupted, failed, or older-schema imports can be retried, continued, or refreshed in place without duplicating completed posts or media.
* Supported photos, videos, audios, and PDFs are imported into the Media Library and attached to their posts. Videos render inline as `core/video` blocks, audios render inline as `core/audio` blocks (with the Day One audio `title` as the block caption when set), and PDFs render inline as `core/file` blocks (with the Day One `pdfName` as the link text when set, falling back to the basename without extension and finally to a literal `[PDF]` floor) at their original `richText` position when present.
* New Day One media is stored in a protected uploads subfolder and served through a nonce- and permission-checked WordPress endpoint.
* Generated image sub-sizes are skipped during import to reduce timeout risk on large exports.
* Result screens report counts, UUIDs, dates, filenames, and generic warnings rather than full journal content.

Privacy note: the importer stores new imported media in a dedicated uploads subfolder with best-effort server protection files as defense in depth. It serves imported media through WordPress only to logged-in users with a valid media nonce who can read the associated private post or attachment. If media privacy is critical, confirm your host honors the protection files for that uploads subfolder.

The plugin does not send journal content or media to external services.

For development and testing, the repository includes a wholly fictional sample Day One-style export. Real Day One exports should remain in ignored local paths such as `sample/` or outside the repository.

== Installation ==

1. Confirm PHP has the ZipArchive extension enabled. The importer requires it to inspect ZIP exports with safety budgets before extraction.
2. Install the plugin ZIP through the WordPress Plugins screen, or upload the plugin files to your site's configured plugins directory.
3. Activate the plugin through the Plugins screen in WordPress.
4. Go to Tools > Import and choose Day One.
5. Upload the original Day One JSON export ZIP and click Import Day One export.
6. Watch the import job panel for phase, progress, counters, warnings, and errors. If the browser connection is interrupted, refresh the page or click Retry / Continue.
7. Review the final import summary and spot-check the resulting private posts.

== Frequently Asked Questions ==

= What Day One export format is supported? =

Export your journal from Day One as JSON and keep the original ZIP intact. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos, videos, audios, or PDFs are present, `photos/`, `videos/`, `audios/`, and/or `pdfs/` directories.

= Are imported entries public? =

No. Imported entries are created as private WordPress posts by default. New Day One media is stored in a protected uploads subfolder and served through a nonce- and permission-checked endpoint, but you should still confirm your host honors the protection files for that directory.

= Can I rerun the same export? =

Yes. The importer stores Day One UUID metadata and skips entries that were already imported and marked complete. It can also resume incomplete imports, safely retry failed/interrupted batches, and refresh older importer-schema versions in place.

= What media types are imported? =

The importer initially supports common image formats such as JPEG/JPG and PNG, plus other image formats accepted safely by the target WordPress site. Day One videos (`.mov`, `.mp4`), Day One audios (`.mp3`, `.m4a`, `.aac`), and Day One PDFs (`application/pdf`) are imported when the site's MIME allowlist accepts them (`video/quicktime`, `video/mp4`, `audio/mpeg`, `audio/mp4`, `audio/aac`, and `application/pdf` are enabled by default for Administrator-role users on most WordPress sites). PDF preview / thumbnail rendering is intentionally out of scope: the emitted `core/file` block is link + Download button only. Unsupported or missing media generates warnings without stopping unrelated entries; video, audio, and PDF embeds whose MIME the site refuses (most notably `.lpcm` linear-PCM audio on default WordPress allowlists, or sites that have explicitly stripped `application/pdf` from `upload_mimes`) are dropped with a privacy-safe warning (no `core/file` fallback is emitted). To support additional video, audio, or PDF MIMEs, extend the uploader allowlist via the standard `upload_mimes` filter. New imported media is stored in a protected uploads subfolder and served through a nonce- and permission-checked endpoint. To reduce timeout risk during large imports, generated image sub-sizes are skipped during import; regenerate thumbnails after import if you need those sizes later.

= Can I import into a custom post type instead of posts? =

Yes. The upload form lets you choose, per import, between regular posts (the default) and a Journal Entries custom post type. The choice applies only to entries created by that import: previously imported entries keep their existing post type, and there is no migration between types.

= What happens to imported entries if I uninstall the plugin? =

Uninstalling retains all imported content, both regular posts and Journal Entries. After uninstall the custom post type is no longer registered, so Journal Entries disappear from wp-admin until a plugin registering the `day_one_entry` post type is active again. This is standard WordPress behavior; the content itself stays in the database.

= Does the plugin contact external services? =

No. The plugin processes ZIP files, extracted content, and resumable job manifests locally in protected WordPress temporary locations. Completed or canceled jobs clean up temporary files when possible; failed jobs retain enough state to retry until canceled or stale.

== Changelog ==

= 0.3.0 =
* Add an option on the import form to import entries as a Journal Entries custom post type. Regular posts remain the default, and previously imported entries keep their existing post type.

= 0.2.23 =
* Security hardening for WordPress.org review: the PHP ZipArchive extension is now required (imports fail closed without it), ZIP expansion budgets (member count, total uncompressed size, per-member size, compression ratio) reject hostile archives before and during extraction, and idempotency lookups are scoped to the import owner.
* Stored video, audio, and PDF block markup now uses the stable nonce-less private endpoint URL; fresh nonces are injected at render time, so imported posts never expire.
* Bounded streaming for journal JSON indexing with resumable offsets; ZIP budget cursors persist on async jobs.

= 0.2.22 =
* Add a Plugin Check helper script (`tools/run-plugin-check.sh`, `composer plugin-check`) for pre-submission verification.

= 0.2.21 =
* Harden private-media response headers: `Cache-Control: private, no-store`, `Referrer-Policy: same-origin`, and a sanitized inline `Content-Disposition` filename.

= 0.2.20 =
* Defer admin-only files on non-admin requests; cron processing and the private-media endpoint stay registered from the always-loaded plugin class.

= 0.2.19 =
* Document the streaming JSON I/O exception (native `fopen`/`fread`/`fclose`) with explicit `phpcs:ignore` rationale comments.

= 0.2.18 =
* Attachment dedupe N+1 fix: indexed `meta_query` lookups replace per-attachment meta scans during reruns.

= 0.2.17 =
* Existing-post lookup N+1 fix: a single `_day_one_uuid` meta query per entry replaces scanning every post.

= 0.2.16 =
* Document the fictional fixture journal and add a consolidated regression asserting every supported block type imports (paragraph, heading, list, code, quote, image, gallery, video, audio, file).

= 0.2.15 =
* Preserve Day One `entry.weather` as `_day_one_weather_*` post meta with a `day_one_importer_weather_meta` filter. Schema 10 → 11.

= 0.2.14 =
* Preserve Day One `entry.location` as `_day_one_location_*` post meta with a `day_one_importer_location_meta` filter. Schema 9 → 10.

= 0.2.13 =
* Preserve animated GIFs: GIF image blocks reference the original file with `sizeSlug: "full"` instead of the flattened `large` derivative.

= 0.2.12 =
* Import Day One `entry.pdfAttachments[]` as `core/file` blocks at their inline positions. Schema 8 → 9.

= 0.2.11 =
* Import Day One `entry.audios[]` as `core/audio` blocks at their inline positions, with `title` captions. Schema 7 → 8.

= 0.2.10 =
* Import Day One `entry.videos[]` as `core/video` blocks at their inline positions. Schema 6 → 7.

= 0.2.9 =
* Place richText inline photos at their original position (single embed → `core/image`, consecutive embeds → `core/gallery`).

= 0.2.8 =
* Map richText line attributes to Gutenberg blocks: headings, bulleted/numbered/checkbox lists, code blocks, and blockquotes.

= 0.2.7 =
* Render richText inline formatting: bold, italic, strikethrough, inline code, http(s) links, and highlight color.

= 0.2.6 =
* Parse Day One `richText` payloads (one block per text run) with a privacy-safe fallback to legacy `text` rendering.

= 0.2.5 =
* Address WordPress.org review feedback: nonces, upload sanitization, escaping, contributor metadata, private media directory, PHP time limits, and cron processing.

= 0.2.4 =
* Add a missing `translators:` comment and tighten the upload dispatcher screen-routing check.

= 0.2.3 =
* Fix the unresponsive upload submit handler caused by stale DOM references.

= 0.2.2 =
* Fix the Cancel import button so the job panel reflects the canceled status immediately.

= 0.2.1 =
* Dispatch the upload on `admin_init` so the post-upload redirect runs before headers are sent.

= 0.2.0 =
* Background ZIP upload with live progress, recalibrated progress bar, and Plugin Check compliance cleanup.

= 0.1.0 =
* Initial release: import Day One JSON export ZIPs as private posts with dates, categories, tags, text formatting, supported photos, and resumable batched jobs.

== Upgrade Notice ==

= 0.2.23 =
The PHP ZipArchive extension is now required; imports fail safely with a clear message when it is missing. ZIP safety budgets reject oversized or hostile archives before extraction. No schema bump.
