# Day One Importer

Day One Importer is a WordPress admin importer for Day One JSON exports. It creates one **private** WordPress entry per Day One entry — as a regular post by default, or optionally as the plugin's **Journal Entries** custom post type (`day_one_entry`), chosen per import on the upload form — and attempts to preserve dates, journal categories, tags, text, supported photos, supported videos, supported audios, supported PDF attachments, per-entry location metadata, and per-entry weather metadata.

## Try in WordPress Playground

You can launch a temporary WordPress site with Day One Importer installed and activated:

[Test in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Automattic/day-one-to-wp/main/blueprint.json)

Playground runs in your browser and is useful for checking the importer screens with fictional or disposable exports. Avoid uploading private Day One exports unless you are comfortable testing them in that browser session. Playground storage is temporary and browser-backed; large ZIP uploads, media processing, and generated attachment URLs may behave differently from a normal WordPress host.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- A user account with permissions to import, upload files, and edit posts
- PHP `ZipArchive` extension is required so ZIP exports can be inspected with safety budgets before extraction

## Install and activate

1. Confirm PHP has the `ZipArchive` extension enabled. The importer requires it to inspect ZIP exports with safety budgets before extraction.
2. Install the plugin ZIP through the WordPress Plugins screen, or copy this plugin directory into your site's configured plugins directory.
3. In WordPress admin, go to **Plugins → Installed Plugins**.
4. Activate **Day One Importer**.
5. Go to **Tools → Import** and confirm **Day One** is listed.

## Export from Day One

1. Open Day One and export your journal as **JSON**.
2. Keep the original ZIP export intact. Do not manually edit the JSON before importing.
3. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos, videos, audios, or PDFs are present, a `photos/`, `videos/`, `audios/`, and/or `pdfs/` directory.

A typical export follows this shape: a journal JSON file plus media files in `photos/` (and `videos/` / `audios/` / `pdfs/` when the export contains video, audio, or PDF moments). Do not commit real Day One exports, photos, videos, audios, or PDFs to this repository; they can contain private journal content.

## Import into WordPress

1. In WordPress admin, go to **Tools → Import**.
2. Choose **Day One**.
3. Upload the Day One JSON export ZIP.
4. Under **Import entries as**, choose how entries are created: **Posts (default)** or **Journal entries (custom post type)**. **Posts (default)** is preselected, so if you want regular posts you can skip this step entirely. The choice applies only to entries created by this import; previously imported entries keep their current post type.
5. Click **Import Day One export**. The upload is queued quickly, then the browser advances the import in short resumable requests with a WP-Cron fallback.
6. Keep the importer page open when possible and watch the job panel for phase, progress, counters, warnings, and errors. You may refresh the page or use **Retry / Continue** after a network interruption.
7. When the job is complete, review the summary counts and any warnings.
8. Spot-check the imported private entries — under **Posts**, or under **Journal Entries** in the admin menu if you chose the custom post type — before deleting or closing a Day One account.

The results/status screen is designed to be privacy-safe: it reports counts, UUIDs, dates, filenames, and generic warning text rather than full journal entry content.

## What is imported

- One WordPress entry per Day One entry: a regular `post` by default, or a `day_one_entry` (the plugin's **Journal Entries** custom post type) when that option is chosen on the import form. Both types receive identical content, status, dates, taxonomies, and importer metadata; only the post type differs.
- Entries are created with `private` status regardless of the chosen post type.
- The **Journal Entries** custom post type is always registered while the plugin is active and is manageable in wp-admin: it has its own **Journal Entries** admin menu entry, its list table shows the standard Categories and Tags columns, and entries open in the block editor with the imported content rendering as blocks.
- Day One `creationDate` is used for the WordPress post date when valid.
- Day One tags are assigned as WordPress post tags. The custom post type uses the same standard tags taxonomy as regular posts.
- Each Day One journal is assigned as a WordPress category, using the journal JSON filename when the export does not provide an explicit journal name. The custom post type uses the same standard categories taxonomy as regular posts.
- Day One text is imported conservatively as safe HTML. Raw HTML is escaped and shortcode-like text such as `[gallery]` is neutralized so it remains visible text rather than executing.
- When a Day One entry ships a `richText` payload (newer exports), the importer reads it and emits one block per text run. Plain paragraph runs become paragraph blocks, and runs beginning with `# `, `- `, or `*` still become the corresponding markdown-derived heading or list block when no explicit line attribute is set — matching the way the same content rendered for legacy `text`-only entries. Supported run-level inline formatting is honored: `bold`, `italic`, `strikethrough`, `inlineCode`, `linkURL` (http(s) only), `autolink` (when paired with a `linkURL`), and `highlightedColor` (Day One's `0xRRGGBB` form) are wrapped in `<strong>`, `<em>`, `<s>`, `<code>`, `<a href="…">`, and `<mark style="background-color:#RRGGBB">` respectively. Non-http(s) links and malformed highlight colors are dropped with a privacy-safe warning (the run's text still renders). Legacy entries that ship only the plain `text` field continue to import exactly as before.
- Day One richText `attributes.line` block-level structures are mapped to native Gutenberg blocks:
    - `header` values 1–6 become `core/heading` blocks at the matching level (intra-text newlines render as `<br />`). Adjacent headings stay as separate blocks. `header` values outside 1–6 fall through to the paragraph path.
    - `listStyle: bulleted` and `listStyle: numbered` become `core/list` blocks (with `core/list-item` children). Numbered lists honor the first item's `listIndex` as the `start` attribute when it is an integer (or numeric string) `>= 2`. Nested levels (`indentLevel`) render as Gutenberg-native parent-child nested `core/list` blocks inside the parent list item.
    - `listStyle: checkbox` becomes a `core/list` with the `task-list` className. Each item is prefixed with a Unicode ballot-box glyph — `&#9745;` (☑, U+2611) when `checked: true`, `&#9744;` (☐, U+2610) otherwise — followed by a single space. The checkbox is purely visual: Day One captures the checked state at export time and `core/list-item` does not accept `<input>` content inside the editor, so the glyph approach survives both `wp_kses_post` and Gutenberg block validation while keeping the recognizable rendering.
    - `codeBlock: true` runs collapse into one `core/code` block, joined with single `\n` separators. Inline-formatting wrappers are intentionally not applied inside code (no `<strong>`/`<em>`/`<a>` inside `<code>`), so any `linkURL` or `highlightedColor` attribute on a code item is ignored without recording a warning.
    - `quote: true` runs collapse into one `core/quote` block with one child paragraph per item. Quote `indentLevel` is intentionally ignored (Day One uses it as typographic spacing); all quote items render as flat siblings inside the same `<blockquote>`.
  Inline formatting wrappers still apply inside headings, list items, and quote paragraphs.
- Supported photos are imported into the Media Library, attached to the imported entry, and placed in the entry content. When a Day One entry ships a `richText` payload that lists photos via `embeddedObjects`, each photo block renders at the position its embed appears in the text stream — a single photo becomes a `core/image` block between the surrounding paragraphs, and consecutive embeds collapse into a single `core/gallery` block. Legacy `text`-only entries continue to append photos after the entry text in Day One entry order. If a `richText` entry has imported photos but no inline `embeddedObjects` references for them, the photos still attach to the entry and remain visible in the Media Library, but they are not rendered in the body and a privacy-safe warning is recorded.
- Every imported photo carries a `_day_one_photo_format` attachment meta marker holding the lowercased Day One `photo.type` value (for example `gif`, `jpeg`, `png`, `heic`). The marker is uniform across formats and is the signal the content layer uses to decide how to render a given photo block. Animated GIF photos are preserved: the `core/image` block emitted for a GIF photo references the original full-size file URL returned by `wp_get_attachment_url()` and uses `sizeSlug: "full"` with a `wp-block-image size-full` figure class, so the GIF stays animated in the entry body instead of being replaced by a flattened first-frame `large` derivative. Non-GIF photos keep the existing `large` derivative ladder unchanged, so entries with JPEG and PNG photos render identically to prior releases. Mixed galleries (for example a GIF alongside a JPEG in the same entry) emit per-image correct `sizeSlug` values automatically because the gallery serializer delegates to the per-image block builder. The `_day_one_photo_format` marker is written only for newly imported attachments; previously imported GIFs do not have the marker and continue to render through the `large` derivative path until they are re-imported. Hosts running image-optimization plugins that transcode GIFs post-upload (Jetpack Image CDN, EWWW Image Optimizer, similar) may still flatten animation after the file lands in `wp-content/uploads/`; that path is outside this plugin's control.
- Day One `entry.videos[]` are imported alongside photos. ZIP preflight discovers any top-level `videos/` directory next to the existing `photos/` discovery, so a Day One export that ships both is recognized in a single pass. Each video is sideloaded into the same protected `day-one-importer-private` uploads subfolder used for photos, served through the same nonce- and permission-checked WordPress media endpoint, and attached to the imported entry. Importer markers (`_day_one_uuid`, `_day_one_media_identifier`, `_day_one_media_md5`, `_day_one_source = day-one-export`, `_day_one_media_kind = video`, plus video-specific `_day_one_video_duration`, `_day_one_width`, `_day_one_height`, `_day_one_media_date`, and `_day_one_original_filename` when present) are written to the attachment so reruns deduplicate by UUID + identifier (or md5) without creating duplicate attachments. When a `richText` payload lists a video via `embeddedObjects[].type === "video"`, the video renders inline at the position the embed appears in the text stream as a `core/video` block (`<figure class="wp-block-video"><video controls src="…"></video></figure>`). Interleaved photo + video sequences emit blocks in scan order — consecutive photos still collapse into a single `core/image` or `core/gallery`, and each video produces one `core/video` block in between, splitting a run of photos when a video is interposed.
- The importer's MIME allowlist gate is extended to accept `video/*` (in addition to the existing `image/*` path). A video is sideloaded only when both `wp_check_filetype_and_ext()` reports a `video/*` MIME and that MIME appears in the site's `get_allowed_mime_types()` list. On a default WordPress install Administrator-role users have `video/quicktime` and `video/mp4` enabled, so the typical `.mov` and `.mp4` files from a Day One export are accepted. If a site explicitly removes those MIMEs (via the `upload_mimes` filter or multisite Add to mime types settings), the affected embed is **dropped** with a privacy-safe warning ("Skipping embedded video in Day One entry: referenced media file is unsupported or missing."). No attachment is created, no `core/file` fallback is emitted, and the warning contains no identifier, UUID, filename, md5, or path. To support additional video MIME types on your site, extend the uploader allowlist via the standard WordPress `upload_mimes` filter (or your multisite Add to mime types settings).
- Day One `entry.audios[]` are imported alongside photos and videos. ZIP preflight discovers any top-level `audios/` directory next to the existing `photos/` and `videos/` discovery, so a Day One export that ships audio is recognized in the same preflight pass. Each audio file is sideloaded into the same protected `day-one-importer-private` uploads subfolder used for photos and videos, served through the same nonce- and permission-checked WordPress media endpoint, and attached to the imported entry. Importer markers (`_day_one_uuid`, `_day_one_media_identifier`, `_day_one_media_md5`, `_day_one_source = day-one-export`, `_day_one_media_kind = audio`, plus audio-specific `_day_one_audio_duration` (when the source provides a positive numeric value), `_day_one_audio_title` (when the Day One record carries a non-empty title), `_day_one_media_date`, and `_day_one_original_filename` when present) are written to the attachment so reruns deduplicate by UUID + identifier (or md5) without creating duplicate attachments. Width and height are intentionally not persisted on audio attachments (Day One ships zeros for both). When a `richText` payload lists an audio via `embeddedObjects[].type === "audio"`, the audio renders inline at the position the embed appears in the text stream as a `core/audio` block (`<figure class="wp-block-audio"><audio controls src="…"></audio></figure>`). When the Day One record's `title` is non-empty, the block also renders a `<figcaption class="wp-element-caption">` with that title underneath the player. Interleaved photo + video + audio sequences emit blocks in scan order: consecutive photos still collapse into a single `core/image` or `core/gallery`, and each video and each audio produces one `core/video` or `core/audio` block in between, splitting a run of photos when a video or audio is interposed.
- The MIME allowlist gate (`Day_One_Importer_Media::validate_media_file()`) now also accepts `audio/*` (in addition to the existing `image/*` and `video/*` paths). An audio is sideloaded only when both `wp_check_filetype_and_ext()` reports an `audio/*` MIME and that MIME appears in the site's `get_allowed_mime_types()` list. On a default WordPress install, Administrator-role users have `audio/mpeg`, `audio/mp4`, and `audio/aac` enabled, so typical `.mp3`, `.m4a`, and `.aac` files from a Day One export are accepted. Day One records shipped as `.lpcm` (linear PCM — `audio/L16` / `audio/L24`) are reliably **not** in WordPress's default allowlist and are **dropped** with a privacy-safe warning ("Skipping embedded audio in Day One entry: referenced media file is unsupported or missing."). Any audio whose MIME the site refuses follows the same drop path. No attachment is created, no `core/file` fallback is emitted, and the warning contains no identifier, UUID, filename, md5, or path. To support additional audio MIME types on your site, extend the uploader allowlist via the standard WordPress `upload_mimes` filter (or your multisite Add to mime types settings).
- Day One `entry.pdfAttachments[]` are imported alongside photos, videos, and audios. ZIP preflight discovers any top-level `pdfs/` directory next to the existing `photos/`, `videos/`, and `audios/` discovery, so a Day One export that ships PDFs is recognized in the same preflight pass. Each PDF file is sideloaded into the same protected `day-one-importer-private` uploads subfolder used for photos, videos, and audios, served through the same nonce- and permission-checked WordPress media endpoint, and attached to the imported entry. Importer markers (`_day_one_uuid`, `_day_one_media_identifier`, `_day_one_media_md5`, `_day_one_source = day-one-export`, `_day_one_media_kind = pdf`, plus the pdf-specific `_day_one_pdf_name` when the Day One record carries a non-empty `pdfName`) are written to the attachment so reruns deduplicate by UUID + identifier (or md5) without creating duplicate attachments. Width, height, duration, and date are intentionally not persisted on PDF attachments (Day One ships zeros for the first three and omits the date). When a `richText` payload lists a PDF via `embeddedObjects[].type === "pdfAttachment"`, the PDF renders inline at the position the embed appears in the text stream as a `core/file` block (`<div class="wp-block-file"><a href="…">…</a><a … class="wp-block-file__button …" download>Download</a></div>`) with `showDownloadButton: true`. The Day One record's `pdfName` is used as the block link text when present; absent names fall back to the attachment basename without extension, and finally to a literal `[PDF]` floor. Interleaved photo + video + audio + PDF sequences emit blocks in scan order: consecutive photos still collapse into a single `core/image` or `core/gallery`, and each video, audio, and PDF produces one `core/video`, `core/audio`, or `core/file` block in between, splitting a run of photos when any of them is interposed.
- The MIME allowlist gate (`Day_One_Importer_Media::validate_media_file()`) now also accepts `application/pdf` (in addition to the existing `image/*`, `video/*`, and `audio/*` paths). A PDF is sideloaded only when both `wp_check_filetype_and_ext()` reports `application/pdf` and that MIME appears in the site's `get_allowed_mime_types()` list. On a default WordPress install, Administrator-role users have `application/pdf` enabled, so the typical PDFs from a Day One export are accepted. Sites whose admins have explicitly stripped `application/pdf` from `upload_mimes` will see the affected PDFs **dropped** with a privacy-safe warning ("Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing."). No attachment is created, no `core/file` fallback block is emitted, and the warning contains no identifier, UUID, filename, md5, `pdfName`, or path. To support additional PDF behavior on your site, extend the uploader allowlist via the standard WordPress `upload_mimes` filter (or your multisite Add to mime types settings). PDF preview / thumbnail rendering is intentionally out of scope: the emitted `core/file` block is link + Download button only.
- Day One `entry.location` is preserved as sanitized post meta on the imported entry. The streaming parser extracts the location subtree when present and the runner writes up to eight typed `_day_one_location_*` meta keys during `finalize_imported_entry()`, plus one raw JSON snapshot key. Each typed key is written only when its source field is present after sanitization, except `latitude` / `longitude` which are gated on `isset()` so the equator (`0.0`) and prime meridian (`0.0`) are preserved rather than dropped as falsy. The full key set:
    - `_day_one_location_latitude` — `(string)(float)` of Day One `latitude`.
    - `_day_one_location_longitude` — `(string)(float)` of Day One `longitude`.
    - `_day_one_location_place_name` — sanitized Day One `placeName`.
    - `_day_one_location_locality` — sanitized Day One `localityName`.
    - `_day_one_location_administrative_area` — sanitized Day One `administrativeArea`.
    - `_day_one_location_country` — sanitized Day One `country`.
    - `_day_one_location_timezone` — sanitized Day One `timeZoneName`.
    - `_day_one_location_raw` — `wp_json_encode()` of the original `location` subtree (with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`). This is where Day One's `region` center/identifier/radius subtree round-trips; it is intentionally not split into separate meta fields.
  Entries whose source JSON omits `location` (or ships an empty object) write zero `_day_one_location_*` rows. A non-array `location` value emits one privacy-safe warning naming the entry UUID and the entry continues importing without location meta. All writes use `update_post_meta` so reruns are idempotent and produce identical rows and values. No block-level rendering, map embed, or geocoding is added in this release; the data is meta-only and is meant for downstream code (theme code, custom block, REST consumer) that wants to render or query against it.
- The location meta map is exposed through a `day_one_importer_location_meta` filter before any rows are written, so downstream code can mutate the eight typed values, add related keys (the prefix is not enforced), or skip writes entirely:

    ```php
    /**
     * Filter the location meta key/value map before writing to the post.
     *
     * @param array<string,string> $meta     Meta key => value map, ready to write.
     * @param array<string,mixed>  $location Normalized location array (latitude, longitude, placeName, localityName, administrativeArea, country, timeZoneName, raw).
     * @param int                  $post_id  Imported post ID.
     * @param array<string,mixed>  $entry    Full normalized entry.
     */
    apply_filters( 'day_one_importer_location_meta', $meta, $location, $post_id, $entry );
    ```

    Filter return contract:
    - Returning an `array` writes its `key => value` pairs via `update_post_meta` (callbacks may add, remove, or replace keys; unprefixed keys are allowed).
    - Returning `null` or `false` skips ALL writes for this entry (no rows touched).
    - Returning any other value (scalar truthy, object, resource) is treated as a no-op and emits one privacy-safe warning naming the filter; no rows are written.

    The filter fires only on entries that have a normalized location. Entries without `location` do not invoke the filter at all.
- Day One `entry.weather` is preserved as sanitized post meta on the imported entry. The streaming parser extracts the weather subtree when present and the runner writes up to twelve typed `_day_one_weather_*` meta keys during `finalize_imported_entry()`, plus one raw JSON snapshot key. Each typed key is gated on `isset()` in the source array (after sanitization) rather than truthiness, so numeric `0` / `0.0` values are preserved: `relativeHumidity: 0` (matching one of Day One's sample payloads), `windBearing: 0` (due north), and `moonPhase: 0` (new moon) all round-trip as the string `"0"` instead of being dropped as falsy. The full key set:
    - `_day_one_weather_temperature_celsius` — `(string)(float)` of Day One `temperatureCelsius`.
    - `_day_one_weather_humidity` — `(string)(float)` of Day One `relativeHumidity`.
    - `_day_one_weather_pressure_mb` — `(string)(float)` of Day One `pressureMB`.
    - `_day_one_weather_wind_kph` — `(string)(float)` of Day One `windSpeedKPH`.
    - `_day_one_weather_wind_bearing` — `(string)(int)` of Day One `windBearing`.
    - `_day_one_weather_visibility_km` — `(string)(float)` of Day One `visibilityKM`.
    - `_day_one_weather_moon_phase` — `(string)(float)` of Day One `moonPhase`.
    - `_day_one_weather_moon_phase_code` — sanitized Day One `moonPhaseCode`.
    - `_day_one_weather_code` — sanitized Day One `weatherCode`.
    - `_day_one_weather_conditions` — sanitized Day One `conditionsDescription`.
    - `_day_one_weather_service` — sanitized Day One `weatherServiceName`.
    - `_day_one_weather_raw` — `wp_json_encode()` of the original `weather` subtree (with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`). This is where any additional fields Day One ships under `weather` (sunrise/sunset timestamps, icon hints, future additions) round-trip; they are intentionally not split into separate meta fields.
  Entries whose source JSON omits `weather` (or ships an empty object) write zero `_day_one_weather_*` rows. A non-array `weather` value emits one privacy-safe warning naming the entry UUID and the entry continues importing without weather meta. All writes use `update_post_meta` so reruns are idempotent and produce identical rows and values. No block-level rendering, icon mapping, unit conversion (Celsius → Fahrenheit, KPH → MPH), or external weather re-fetch is added in this release; the data is meta-only and is meant for downstream code (theme code, custom block, REST consumer) that wants to render or query against it. Floats are stored verbatim from source — note that Day One ships full IEEE-754 double precision (`29.909999847412109`) and the `(string)(float)` cast keeps that precision intact.
- The weather meta map is exposed through a `day_one_importer_weather_meta` filter before any rows are written, so downstream code can mutate the twelve typed values, add related keys (the prefix is not enforced), or skip writes entirely:

    ```php
    /**
     * Filter the weather meta key/value map before writing to the post.
     *
     * @param array<string,string> $meta    Meta key => value map, ready to write.
     * @param array<string,mixed>  $weather Normalized weather array (temperatureCelsius, relativeHumidity, pressureMB, windSpeedKPH, windBearing, visibilityKM, moonPhase, moonPhaseCode, weatherCode, conditionsDescription, weatherServiceName, raw).
     * @param int                  $post_id Imported post ID.
     * @param array<string,mixed>  $entry   Full normalized entry.
     */
    apply_filters( 'day_one_importer_weather_meta', $meta, $weather, $post_id, $entry );
    ```

    Filter return contract:
    - Returning an `array` writes its `key => value` pairs via `update_post_meta` (callbacks may add, remove, or replace keys; unprefixed keys are allowed).
    - Returning `null` or `false` skips ALL writes for this entry (no rows touched).
    - Returning any other value (scalar truthy, object, resource) is treated as a no-op and emits one privacy-safe warning naming the filter; no rows are written.

    The filter fires only on entries that have a normalized weather array. Entries without `weather` do not invoke the filter at all.

## Batched jobs, idempotency, and resume behavior

Large exports are processed as persisted import jobs instead of one long admin request. Each processing request handles a bounded amount of ZIP preflight/extraction, JSON indexing, entry creation, or media import work and returns status before typical proxy/PHP timeout limits are reached. Browser polling normally advances the job; WP-Cron can also continue queued work as a fallback. The importer screen shows the current phase, counts, warnings/errors, and an estimated progress bar so a paused, canceled, retried, or re-opened job is visible instead of leaving a blank page. The percentage on the progress bar is dominated by entries imported during the `importing` phase, so it tracks the "Imported X of Y entries" detail line rather than jumping ahead while pre-import phases finish. A resumed job paints at the percentage matching its already-imported entries on first render, and a failed or canceled mid-import job keeps the computed percentage rather than snapping to 100%.

The importer stores Day One UUID metadata on imported entries — whichever post type they use — and media. Re-importing the same export, refreshing the browser, retrying a failed batch, or continuing after a temporary interruption skips entries that were already imported and marked complete. If an earlier import created an entry but did not finish, the importer resumes that entry instead of creating a duplicate.

The **Import entries as** choice is fixed for an import run at the moment it is submitted: the job record stores the chosen post type, and every subsequent batch reads the choice back from that record — whether the batch is advanced by browser polling, by **Retry / Continue** after an interruption, or by the WP-Cron fallback running with no user request context. Submitting another import with a different choice, or reloading the page, never changes the post type of an in-flight or resumed run. Import job records created by plugin versions before the choice existed carry no stored choice; they resume and complete as default posts.

Reruns recognize previously imported entries in **either** post type: the existing-entry lookup matches by Day One UUID (scoped to the importer's source marker and the owning user) across both regular posts and Journal Entries, so re-importing the same export with the other choice selected never duplicates entries. Existing entries keep the post type they were created with: complete entries at the current importer-schema version are skipped untouched, incomplete entries are resumed, and outdated-schema entries are reprocessed in place — in every case without changing their type. There is no automatic conversion or migration between the two types; the choice affects only entries the current run creates.

If importer behavior changes in a way that requires existing imported entries to be refreshed, rerunning the same export reprocesses older importer-schema versions in place instead of skipping them. This lets import-time fixes, such as cleaner Day One text/title conversion, apply by rerunning the import rather than manually editing entries. The refresh updates content and metadata but, like every resume path, never changes an entry's post type.

If you move imported entries to Trash and rerun the import, the trashed imported copy is permanently removed and a fresh private entry is created for that Day One UUID. Because the replacement is newly created, it takes the **current run's** chosen post type — the one exception to type preservation. A trashed regular post rerun with **Journal entries (custom post type)** selected comes back as a Journal Entry, and a trashed Journal Entry rerun with **Posts (default)** selected comes back as a regular post; either way exactly one replacement is created. This is useful when cleaning up a failed test import before retrying.

This means you can normally click **Retry / Continue** or rerun the same export after an interruption — with either post-type choice — without creating duplicate entries or attachments for the same Day One UUIDs/media items. Use **Cancel import** only when you want to abandon the queued job and remove its temporary files.

## Media behavior and privacy

Supported initial image types are JPEG/JPG, PNG, GIF, and other image formats that the target WordPress site accepts safely. Animated GIF photos are preserved end-to-end: the original file bytes are written to disk untouched by the sideload step, and the emitted `core/image` block points at the original file URL (`sizeSlug: "full"`) instead of WordPress's `large` derivative, so the saved attachment file md5 matches the export's `photo.md5` and the GIF stays animated in the entry body. WebP, AVIF, and HEIC files pass through the importer unmodified — there is no GIF-style original-URL emission for those formats yet; if the site's MIME allowlist accepts them they import as ordinary `core/image` blocks against the `large` derivative, otherwise the embed is dropped with a privacy-safe warning the same way unsupported video, audio, and PDF embeds are. Day One videos (`.mov`, `.mp4`), Day One audios (`.mp3`, `.m4a`, `.aac`), and Day One PDFs (`application/pdf`) are imported when the site's MIME allowlist accepts them (the default on most WordPress sites for Administrator-role users); embeds whose MIME the site refuses (most notably `.lpcm` audio on default WordPress allowlists, or PDFs on sites that have explicitly stripped `application/pdf` from `upload_mimes`) are dropped with a privacy-safe warning rather than failing the entry. HEIC and other attachment types are not guaranteed to import unless WordPress accepts and processes them in that environment.

Photos, videos, audios, and PDFs are attached to the corresponding private entry — the same way for both entry post types — and importer metadata is stored on the attachment (including the `_day_one_media_kind` marker that distinguishes photo, video, audio, and pdf attachments) to support reuse on reruns.

New Day One media is stored in a protected `day-one-importer-private` subfolder of the WordPress uploads directory. The importer uses a nonce- and permission-checked WordPress media endpoint instead of raw upload URLs for imported attachments. The endpoint only serves a Day One media file to a logged-in user who can read the associated private entry or attachment; the check behaves identically whether the parent entry is a post or a custom-post-type journal entry.

To reduce timeout risk during large imports, the importer uses resumable batches, asks WordPress for the admin memory limit where the host allows it, and skips generated image sub-sizes during Day One media sideloads. Imported entries use the original uploaded image file. If you need WordPress thumbnail sizes for imported media later, regenerate thumbnails after the import using your preferred trusted maintenance tool.

**Important media caveat:** The private media directory is a protected subfolder of WordPress uploads. The importer writes `.htaccess` and `web.config` files as defense in depth, but privacy ultimately depends on the host honoring those protection files. Advanced operators can customize the directory with the `day_one_importer_private_media_dir` filter.

## Temporary files and external services

- The plugin does not send journal content or media to external services.
- ZIP files, extracted content, and job manifests are processed in a protected temporary location and cleaned up after the import completes or is canceled when possible.
- Failed/interrupted jobs retain enough temporary state to retry until they are canceled or expire as stale.
- Uploaded ZIPs are not intentionally left in the plugin directory.

## Local private data

Local Day One exports, extracted photos, and prompt/reference images must not be committed. This repository ignores `sample/` and `prompt-images/` for local-only private data.

For tests and examples, the repository includes `tests/fixtures/day-one-fictional.zip`, a wholly fictional Day One-style export that is safe to publish and is used by default in automated smoke tests.

## License

Day One Importer is licensed under GPL-2.0-or-later. See `LICENSE` for details.

## Limitations

- Day One rich text fidelity is not guaranteed. The importer reads the `richText` payload when present and maps run-level inline formatting (bold, italic, strikethrough, inline code, http(s) links, highlight color) and block-level line attributes (headings 1–6, bulleted/numbered/checkbox lists with nesting, code blocks, blockquotes) to the matching Gutenberg blocks. The highlight `<mark style="background-color:#RRGGBB">` wrapper depends on the site's `wp_kses_post` allowlist accepting `<mark>` with that style attribute; if a host or filter tightens the allowlist, the text still survives and only the highlight color is dropped. Imported checkbox list items are visual-only (Unicode ballot-box glyphs) and cannot be re-toggled in the editor without re-importing. Nested `<blockquote>` rendering for Day One quote `indentLevel` is not emitted (quote items render as flat siblings).
- Inline-positioned **photos** inside `richText` `embeddedObjects` render at their original position in the text stream (single embed → `core/image`, consecutive embeds → `core/gallery`). Legacy `text`-only entries still append photos after the entry text.
- **Animated GIF preservation** applies only to newly imported attachments. The `core/image` block emitted for a GIF photo now references the original full-size file URL (`wp_get_attachment_url()`) with `sizeSlug: "full"` instead of WordPress's flattened first-frame `large` derivative, and every photo attachment carries a `_day_one_photo_format` meta marker the renderer keys off. Previously imported GIFs (from 0.2.12 or earlier) do not have the marker and continue to render through the `large` derivative path until they are re-imported — there is no automatic backfill. Hosts running image-optimization plugins that transcode GIFs post-upload (Jetpack Image CDN, EWWW Image Optimizer, similar) may still flatten animation after the file lands in `wp-content/uploads/`; that path is outside this plugin's control. **WebP, AVIF, and HEIC photos pass through unmodified:** they receive the `_day_one_photo_format` marker (`webp`, `avif`, `heic`) but the renderer does not apply GIF-style original-URL emission to them, so they import as ordinary `core/image` blocks against the `large` derivative when the site's MIME allowlist accepts them, or are dropped with a privacy-safe warning when it does not. GIF-to-MP4 conversion and `loading="lazy"` toggling for GIFs are intentionally out of scope.
- Inline-positioned **videos** inside `richText` `embeddedObjects` are sideloaded and rendered as `core/video` blocks at their original position. Video sideload depends on the site's MIME allowlist accepting the file's `video/*` MIME; embeds whose MIME the site rejects are dropped with a privacy-safe warning rather than emitted as a `core/file` fallback, and the `<video>` element relies on Gutenberg's defaults (no `poster` thumbnail, no transcoding, no `width`/`height` block attributes — the HTML element auto-detects dimensions from the source). Videos play only for users who can read the parent private entry, since the `src` is served through the same nonce-checked private endpoint as photos. The "imported photos but no inline embed" privacy-safe warning is photo-specific by design and does not fire for videos (extending it to videos may land as a follow-up).
- Inline-positioned **audios** inside `richText` `embeddedObjects` are sideloaded and rendered as `core/audio` blocks at their original position. Audio sideload depends on the site's MIME allowlist accepting the file's `audio/*` MIME; embeds whose MIME the site rejects (most notably `.lpcm` linear-PCM payloads on default WordPress allowlists) are dropped with a privacy-safe warning rather than emitted as a `core/file` fallback, and the `<audio>` element relies on Gutenberg's defaults (no transcoding, no waveform thumbnail, no `width`/`height` block attributes — Day One ships zeros for audio width/height and the importer does not persist them). When the Day One record's `title` is non-empty, the block renders that title as a `<figcaption class="wp-element-caption">` caption under the player; an empty title produces a player without a caption. Audios play only for users who can read the parent private entry, since the `src` is served through the same nonce-checked private endpoint as photos and videos. The "imported photos but no inline embed" privacy-safe warning is photo-specific by design and does not fire for audios (same rationale as videos).
- Inline-positioned **PDFs** inside `richText` `embeddedObjects` are sideloaded and rendered as `core/file` blocks at their original position. PDF sideload depends on the site's MIME allowlist accepting `application/pdf`; embeds whose MIME the site rejects are dropped with a privacy-safe warning rather than emitted as a `core/file` fallback against a missing attachment. The emitted block is link + Download button only: PDF preview / thumbnail rendering is intentionally out of scope (Gutenberg has no native PDF preview block, and emitting an `<embed>` / `<iframe>` was rejected as outside the scope of this release). The `pdfName` value from the Day One record renders as the block link text when present; absent names fall back to the attachment basename without extension, and finally to a literal `[PDF]` floor. Browser-side behavior for clicking the link (open inline in a PDF viewer vs. force-download) is decided by the viewer's browser based on the response headers the host serves; the block emits the standard Gutenberg `download` attribute and does not override server-side `Content-Disposition`. PDFs are readable only to users who can read the parent private entry, since the `href` is served through the same nonce-checked private endpoint as photos, videos, and audios. The "imported photos but no inline embed" privacy-safe warning is photo-specific by design and does not fire for PDFs (same rationale as videos and audios).
- Private media storage depends on the host allowing WordPress to create and protect a dedicated uploads subfolder. Media import fails safely if that directory cannot be prepared.
- Unsupported or missing media produces warnings but does not stop unrelated entries from importing.
- Very large exports may still hit host-enforced upload-size or memory limits before a job can be queued, and exceptionally slow hosts may require using **Retry / Continue**. Increase upload/memory limits, rerun to resume, or split exports if needed.
- WordPress Playground is useful for quick testing, but browser-backed uploads/media handling may differ from a normal WordPress host, especially for large Day One ZIP exports or photo-heavy imports.
- The importer does not sync with Day One, delete Day One accounts, or publish imported entries publicly.

## Troubleshooting

- **Importer is not listed:** confirm the plugin is activated and that the current user has import permissions.
- **ZIP upload fails:** check PHP upload size limits, WordPress upload permissions, and that the file is a Day One JSON export ZIP.
- **No entries found:** confirm the ZIP contains a journal JSON file with a top-level `entries` array.
- **Import appears paused:** keep the importer screen open, refresh it, or click **Retry / Continue**. Continuing is safe and resumes unfinished work.
- **Photos, videos, audios, or PDFs missing:** confirm the export includes the relevant `photos/`, `videos/`, `audios/`, and/or `pdfs/` directory and that the media type is accepted by your site's MIME allowlist (`get_allowed_mime_types()` / the `upload_mimes` filter). Video, audio, and PDF embeds whose MIME the site refuses (notably `.lpcm` linear-PCM audio on default WordPress allowlists, or sites that have explicitly stripped `application/pdf` from `upload_mimes`) are dropped with a privacy-safe warning instead of imported as an attachment.
- **Duplicate import concerns:** click **Retry / Continue** or rerun the same ZIP; completed entries should be skipped because Day One UUID metadata is stored on imported entries (of either post type) and media.

## Build a plugin ZIP

Install Node dependencies and create a local plugin ZIP from the repository root:

```sh
npm install
npm run plugin-zip
```

The ZIP is generated in the repository root. Included files are controlled by the `files` list in `package.json` so development-only files are left out.

## Verification

GitHub Actions runs the required CI checks on pull requests and pushes to `main`.

Run the same required checks locally from the repository root:

```sh
find . -path './.git' -prune -o -path './sample' -prune -o -path './prompt-images' -prune -o -path './pipelines' -prune -o -path './vendor' -prune -o -path './node_modules' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
composer run lint:php
```

Run a local WordPress smoke test with `wp-env`:

```sh
wp-env start
wp-env run cli wp eval '$admin = new Day_One_Importer_Admin(); $admin->register_importer(); global $wp_importers; echo isset( $wp_importers["day-one"] ) ? "day-one importer registered\n" : "missing importer\n";'
PLUGIN_DIR=$(wp-env run cli wp eval 'echo dirname( DAY_ONE_IMPORTER_FILE );' 2>/dev/null | tail -n 1)
wp-env run cli wp eval-file "${PLUGIN_DIR}/tests/wp-env-import-sample.php"
```

The wp-env smoke test imports the committed fictional fixture at `tests/fixtures/day-one-fictional.zip` by default. You can optionally set `DAY_ONE_IMPORTER_SAMPLE_ZIP` or pass a ZIP path as the first WP-CLI argument to test a developer-owned private export, such as an ignored `sample/local-day-one-export.zip`. The script does not print journal content. It verifies private posts/media are created, reruns the import, verifies completed entries are skipped, simulates an older importer-schema version and verifies it is reprocessed in place, moves one imported post to Trash, and verifies a later rerun recreates only that trashed entry. The committed default fixture is required; if it is missing, the script fails as a repository setup error.

WordPress Plugin Check is not required by the first CI workflow. It can be added later as a separate GitHub Actions job using the official Plugin Check action or a `wp-env`/WP-CLI job once that path is verified as stable and publish-safe.

See `tests/manual-verification.md` for a WordPress manual verification checklist covering installation, import, privacy, idempotency, invalid inputs, media behavior, and cleanup.

## Pre-submission verification (WordPress Plugin Check)

Before tagging a release for submission to WordPress.org, run Plugin Check (PCP) against the plugin. This step is intentionally gated behind an explicit helper so it does **not** run during the normal lint / test loop:

```sh
composer plugin-check
# or equivalently:
./tools/run-plugin-check.sh
```

The helper is idempotent: it starts `wp-env` only if it is not already running, installs and activates the `plugin-check` plugin only when needed, then runs `wp plugin check day-one-importer --checks=all` and prints a tabular report. Zero findings exits `0`; any error or warning exits `1`; environment problems (Docker not running, port conflict, wp-env unavailable) exit `2` with a hint. A clean release should print `Plugin Check reported zero findings. Ready for submission review.` See the `## Plugin Check (PCP)` section in `tests/manual-verification.md` for the full pre-submission checklist.
