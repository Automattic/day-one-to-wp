# Day One Importer

Day One Importer is a WordPress admin importer for Day One JSON exports. It creates one **private** WordPress post per Day One entry and attempts to preserve dates, tags, text, and supported photos.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- A user account with permissions to import, upload files, and edit posts
- PHP ZIP support is recommended for safest ZIP preflight checks

## Install and activate

1. Copy this plugin directory to `wp-content/plugins/day-one-importer/` on your WordPress site.
2. In WordPress admin, go to **Plugins → Installed Plugins**.
3. Activate **Day One Importer**.
4. Go to **Tools → Import** and confirm **Day One** is listed.

## Export from Day One

1. Open Day One and export your journal as **JSON**.
2. Keep the original ZIP export intact. Do not manually edit the JSON before importing.
3. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos are present, a `photos/` directory.

The sample export used for development follows this shape: a journal JSON file such as `Diario.json` plus media files in `photos/`.

## Import into WordPress

1. In WordPress admin, go to **Tools → Import**.
2. Choose **Day One**.
3. Upload the Day One JSON export ZIP.
4. Run the import.
5. Review the summary counts and any warnings.
6. Spot-check the imported private posts before deleting or closing a Day One account.

The results screen is designed to be privacy-safe: it reports counts, UUIDs, dates, filenames, and generic warning text rather than full journal entry content.

## What is imported

- One WordPress `post` per Day One entry.
- Posts are created with `private` status by default.
- Day One `creationDate` is used for the WordPress post date when valid.
- Day One tags are assigned as WordPress post tags.
- Day One text is imported conservatively as safe HTML. Raw HTML is escaped and shortcode-like text such as `[gallery]` is neutralized so it remains visible text rather than executing.
- Supported photos are imported into the Media Library, attached to the imported post, and appended to the post content in Day One entry order when possible.

## Idempotency and resume behavior

The importer stores Day One UUID metadata on posts and media. Re-importing the same export skips entries that were already imported and marked complete. If an earlier import created a post but did not finish, the importer resumes that post instead of creating a duplicate.

This means you can normally rerun the same export after an interruption without creating duplicate posts for the same Day One UUIDs.

## Media behavior and privacy

Supported initial image types are JPEG/JPG, PNG, and other image formats that the target WordPress site accepts safely. HEIC, video, audio, PDF, and other attachment types are not guaranteed to import unless WordPress accepts and processes them in that environment.

Photos are attached to the corresponding private post and importer metadata is stored on the attachment to support reuse on reruns.

**Important media caveat:** WordPress Media Library files may be accessible by direct URL depending on your hosting and WordPress configuration, even when attached to private posts. If media privacy is critical, confirm your host or site configuration blocks public access to uploaded media URLs before relying on this importer as a private archive.

## Temporary files and external services

- The plugin does not send journal content or media to external services.
- ZIP files and extracted content are processed in a protected temporary location and cleaned up after the import when possible.
- Uploaded ZIPs are not intentionally left in the plugin directory.

## Limitations

- Day One rich text fidelity is not guaranteed; the importer uses the primary text field conservatively.
- Images are appended after entry text rather than placed at exact original inline positions.
- Unsupported or missing media produces warnings but does not stop the entry import.
- Very large exports may hit PHP upload, timeout, or memory limits. Increase server limits or split exports if needed.
- The importer does not sync with Day One, delete Day One accounts, or publish imported entries publicly.

## Troubleshooting

- **Importer is not listed:** confirm the plugin is activated and that the current user has import permissions.
- **ZIP upload fails:** check PHP upload size limits, WordPress upload permissions, and that the file is a Day One JSON export ZIP.
- **No entries found:** confirm the ZIP contains a journal JSON file with a top-level `entries` array.
- **Photos missing:** confirm the export includes a `photos/` directory and that the media type is accepted by WordPress.
- **Duplicate import concerns:** rerun the same ZIP; completed entries should be skipped because Day One UUID metadata is stored on imported posts.

## Verification

Run PHP linting:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run pure helper tests without WordPress:

```sh
php tests/pure-helper-tests.php
```

See `tests/manual-verification.md` for a WordPress manual verification checklist covering installation, import, privacy, idempotency, invalid inputs, media behavior, and cleanup.
