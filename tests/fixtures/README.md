# Test fixtures

`day-one-fictional/` (the on-disk export tree) and `day-one-fictional.zip` (the lock-step archive) make up a single, wholly fictional Day One-style fixture used by every PHP and wp-env test in this repository. The fixture is curated, deterministic, dependency-free, and safe to publish:

- No real Day One private data is included. The fixture is **not** derived from any real journal, any real person, or the user's private export at `/Users/carlos/Downloads/3k-entries-5-journals-samplejournalexport/`. That sample is private and stays outside this repository.
- Every entry is reproducible from the JSON + media binaries committed under `day-one-fictional/`. Photos, videos, audios, and PDFs are tiny generated artifacts whose `md5_file()` hash equals the `md5` recorded in the JSON.
- The fixture grows by exactly one or two entries per issue. Each entry is dedicated to a specific block-type, parser path, or runner contract, and every test that asserts on it pins the entry by its UUID (`FICTIONAL-SAMPLE-ENTRY-####`).
- The plain-text `tests/fixtures/expected/` baselines pin the rendered post HTML for entries 0001-0017 (the #56 byte-parity range). Adding or moving entries in that range requires regenerating the baselines (see "How to extend").

The committed entries cover every block type the importer can emit today: `core/paragraph`, `core/heading`, `core/list` (+ `core/list-item`), `core/code`, `core/quote`, `core/image`, `core/gallery`, `core/video`, `core/audio`, and `core/file`. The wp-env smoke walks every imported post with `parse_blocks()` and asserts that this set is fully exercised across the fixture; missing any one fails the smoke and names the gap.

## Per-entry table

| Index | UUID suffix | Type / feature exercised | Source issue |
|------:|-------------|--------------------------|--------------|
| 0001  | `ENTRY-0001` | Legacy text path with one PNG photo (markdown heading + paragraph + attached image). | Original fixture (pre-#53). |
| 0002  | `ENTRY-0002` | Legacy text with a literal `[gallery]` shortcode-like substring and raw `<strong>` / `<script>` for content-safety / kses checks. | Original fixture (pre-#53). |
| 0003  | `ENTRY-0003` | Legacy text-only entry (no media); byte-for-byte regression baseline for `Day_One_Importer_Content::convert_text_to_content()`. | Original fixture (pre-#53). |
| 0004  | `ENTRY-0004` | `richText` arriving as a JSON-encoded string (Day One scaffold form 1). | #53 |
| 0005  | `ENTRY-0005` | `richText` arriving pre-decoded as an object, with an unresolved `embeddedObjects` item to exercise the dropped-embed path. | #53 |
| 0006  | `ENTRY-0006` | `richText` paragraph carrying an `embeddedObjects` array on the same item (precedence test). | #53 |
| 0007  | `ENTRY-0007` | `richText` paragraph + one attached photo, no inline embed (R14 case: photo attached but no `core/image`/`core/gallery`, R14 warning emitted). | #53 |
| 0008  | `ENTRY-0008` | `richText` inline formatting matrix: bold, italic, strikethrough, inline code, link, autolink, highlight, the canonical R18 multi-attribute combination, and negative paths (`javascript:` link, malformed color, autolink without URL, plain unstyled). | #54 |
| 0009  | `ENTRY-0009` | Line attribute `header`: levels 1-6, two adjacent header:2 (no inter-collapse), multi-line header:2 (`<br />` join), and a `header:0` paragraph fall-through. | #55 |
| 0010  | `ENTRY-0010` | Line attribute `listStyle:bulleted` with nested `indentLevel` (parent-child `core/list` / `core/list-item` shape). | #55 |
| 0011  | `ENTRY-0011` | Line attribute `listStyle:numbered` with explicit `listIndex:5` (outer list emits `start:5`) plus a nested numbered list (no `start` attr on nested). | #55 |
| 0012  | `ENTRY-0012` | Line attribute `listStyle:numbered` with first `listIndex:1` (outer list emits **no** `start` attr). | #55 |
| 0013  | `ENTRY-0013` | Line attribute `listStyle:checkbox` (`className:"task-list"`, `&#9745;`/`&#9744;` glyphs survive `wp_kses_post`). | #55 |
| 0014  | `ENTRY-0014` | Line attribute `codeBlock:true` (one `core/code` block; rejected inline attrs on a code item do not leak `<strong>`, `<a>`, or `<mark>`). | #55 |
| 0015  | `ENTRY-0015` | Line attribute `quote:true` with multi-paragraph quote (one `core/quote` wrapping two `core/paragraph` children; `indentLevel` ignored). | #55 |
| 0016  | `ENTRY-0016` | Mixed line types in one entry: lead paragraph, heading, bulleted list, numbered list, code block, quote, trailing paragraph. | #55 |
| 0017  | `ENTRY-0017` | Transparent-drop contract: empty-text bulleted item in the middle of a same-kind run keeps the run as ONE `core/list` with two `core/list-item`s. | #55 |
| 0018  | `ENTRY-0018` | Inline positioning: paragraph + embedded photo + paragraph emits `paragraph, image, paragraph` in top-level block order (no trailing gallery append). | #56 |
| 0019  | `ENTRY-0019` | Legacy placeholder regex: every observed `dayone-moment://`, `dayone-moment:/photo|video|audio|pdfAttachment/`, `dayone-photo://`, `dayone-video://`, `dayone-audio://`, `dayone-pdf://` form strips from both `post_content` and `post_title`. | #56 |
| 0020  | `ENTRY-0020` | Inline video embed (`core/video`); attachment carries `_day_one_media_kind=video`, `_day_one_video_duration` round-trips through `floatval()`, URL routes through the private-media endpoint. | #57 |
| 0021  | `ENTRY-0021` | Interleaved photo, video, photo embeds emit `core/image, core/video, core/image` in inline order (same photo identifier reused twice). | #57 |
| 0022  | `ENTRY-0022` | Inline audio embed (`core/audio`); `_day_one_audio_duration` round-trips, fixture `title` renders as a `<figcaption class="wp-element-caption">`. | #58 |
| 0023  | `ENTRY-0023` | Interleaved photo, video, audio embeds emit `core/image, core/video, core/audio` in inline order. | #58 |
| 0024  | `ENTRY-0024` | Inline PDF embed (`core/file`); attachment carries `_day_one_media_kind=pdf`, block carries `showDownloadButton:true`, fixture `pdfName` renders as the link text + `Download` button. | #59 |
| 0025  | `ENTRY-0025` | Interleaved photo, video, audio, PDF embeds emit `core/image, core/video, core/audio, core/file` in inline order. | #59 |
| 0026  | `ENTRY-0026` | Animated GIF photo (hand-crafted 2-frame 1x1 GIF89a); `core/image` block emits `sizeSlug:"full"`, figure carries `size-full`, attachment carries `_day_one_photo_format=gif`, on-disk file md5 round-trips to the export `photo.md5`. | #60 |
| 0027  | `ENTRY-0027` | Canonical Idaho Falls `location` subtree round-trips through eight `_day_one_location_*` post meta keys + `_day_one_location_raw` JSON snapshot; `day_one_importer_location_meta` filter mutates/skips. | #61 |
| 0028  | `ENTRY-0028` | Canonical `weather` subtree round-trips through twelve `_day_one_weather_*` post meta keys + `_day_one_weather_raw` JSON snapshot; preserves `0` / `0.0` values (relativeHumidity, windBearing, moonPhase); `day_one_importer_weather_meta` filter mutates/skips. | #62 |

## How to regenerate the ZIP

From the repository root:

```sh
php tools/build-fictional-sample-zip.php
```

The build tool validates the JSON shape, confirms every `photos[].md5`, `videos[].md5`, `audios[].md5`, and `pdfAttachments[].md5` matches the on-disk binary, enforces per-type byte-size caps (video <= 200 KB, audio <= 50 KB, PDF <= 5 KB), removes any previous `tests/fixtures/day-one-fictional.zip`, and writes a new ZIP whose only contents are the fixture export tree (top-level `*.json`, `photos/`, `videos/`, `audios/`, `pdfs/`).

The build tool refuses to commit `__MACOSX/` entries, dotfiles, or files that have escaped the source directory. If `md5_file()` disagrees with any record's `md5`, the build halts.

## How to extend

When a new issue needs a new fixture entry:

1. **Add a JSON entry** at the end of `tests/fixtures/day-one-fictional/Fictional Journal.json`. The next free index is `FICTIONAL-SAMPLE-ENTRY-0029`. Keep the `Etc/UTC` timezone for entries that do not exercise a real timezone, use the existing creation/modified date cadence (one entry per day), and follow the `"creationDeviceType": "Fictional Device"` / `"creationDeviceModel": "Sample Simulator"` convention so every entry remains obviously fictional. Re-use the existing `fictional` tag plus a feature-specific tag (for example `weather`, `video`, `pdf`) so the table above stays scannable.
2. **Add the media binary** (only if needed). Name files `<lowercase-md5>.<ext>` under:
   - `tests/fixtures/day-one-fictional/photos/<md5>.<png|jpeg|jpg|gif|heic>`
   - `tests/fixtures/day-one-fictional/videos/<md5>.<mov|mp4>` (<= 200 KB)
   - `tests/fixtures/day-one-fictional/audios/<md5>.<mp3|m4a|aac>` (<= 50 KB)
   - `tests/fixtures/day-one-fictional/pdfs/<md5>.pdf` (<= 5 KB)
   The regeneration commands for the video, audio, and PDF binaries are documented in the header of `tools/build-fictional-sample-zip.php`.
3. **Rebuild the ZIP** by running `php tools/build-fictional-sample-zip.php` from the repository root. Commit the regenerated `tests/fixtures/day-one-fictional.zip` alongside the JSON / media changes.
4. **Bump the six hard-coded count assertions** from `28` to the new total. They live at:
   - `tests/pure-helper-tests.php`: the four `=== count( $fixture_entries )`, `=== $fixture_results->get_count( 'entries_found' )`, `=== $batch_job['entries_total']`, and `=== $bounded_job['entries_total']` checks.
   - `tests/wp-env-import-sample.php`: the two assertions on `$entries_found` and `$created` in the `$using_default_zip` branch.
5. **If the new entry has a UUID in the #56 byte-parity range** (entries 0001-0017), regenerate the matching baseline under `tests/fixtures/expected/post-<UUID>.html` via `tools/capture-baseline.php` and commit the new baseline. Entries added at the end of the fixture (>= 0018) do not need a baseline.
6. **Add per-entry assertions in `tests/wp-env-import-sample.php`** under a `$using_default_zip` branch, looking the post up by UUID via `$day_one_importer_uuid_to_post_id[ 'FICTIONAL-SAMPLE-ENTRY-####' ]`. Follow the existing pattern: assert block counts via `parse_blocks()` + the local `day_one_importer_wp_env_collect_blocks_by_name()` helper, then any meta/figcaption/href checks.
7. **Document the new entry** by adding a row to the "Per-entry table" above.

## Coverage

Every block type the importer can emit today appears at least once in the fictional fixture:

- `core/paragraph` — entries 0001, 0003, 0004, 0006, 0007, 0008, 0009 (header:0 fall-through), 0016, 0018, 0019, 0020, 0022, 0024.
- `core/heading` — entries 0009 (levels 1-6, adjacent + multi-line), 0016 (mixed).
- `core/list` (+ `core/list-item`) — entries 0010 (bulleted), 0011 (numbered start:5 + nested), 0012 (numbered default), 0013 (checkbox / task-list), 0016 (mixed), 0017 (transparent drop).
- `core/code` — entries 0014, 0016.
- `core/quote` — entries 0015, 0016.
- `core/image` — entries 0001, 0007 (attached only, no inline), 0018 (inline), 0021 (interleaved), 0023 (interleaved), 0025 (interleaved), 0026 (animated GIF).
- `core/gallery` — exercised by the "post with multiple image attachments" branch in the smoke (any future fixture entry attaching >= 2 photos with inline embeds emits a `core/gallery`; the assertion at lines ~306-330 of `tests/wp-env-import-sample.php` covers ordering, IDs, and nested `core/image` children).
- `core/video` — entries 0020, 0021, 0023, 0025.
- `core/audio` — entries 0022, 0023, 0025.
- `core/file` — entries 0024, 0025.

The consolidated block-type regression assertion at the end of the wp-env smoke walks every imported post via `parse_blocks()`, collects the set of block names, and fails with a clear "missing block type" message if any of the ten types above is absent across the entire fixture.
