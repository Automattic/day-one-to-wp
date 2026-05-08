# Day One Importer

Day One Importer is a WordPress admin importer for Day One JSON exports. It creates one **private** WordPress post per Day One entry and attempts to preserve dates, tags, text, and supported photos.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- Administrator capabilities to import, upload files, and edit posts

## How to export from Day One

1. In Day One, export the journal as JSON.
2. Keep the original ZIP export intact. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos are present, a `photos/` directory.

## How to import

1. Install and activate this plugin.
2. Go to **Tools → Import**.
3. Choose **Day One**.
4. Upload the Day One export ZIP and run the import.
5. Review the summary counts and any privacy-safe warnings.

## What is imported

- One WordPress `post` per Day One entry.
- Posts are created with `private` status by default.
- Day One `creationDate` is used for the post date when valid.
- Day One tags are assigned as WordPress post tags.
- Day One text is imported conservatively as safe HTML. Raw HTML is escaped and shortcode-like text such as `[gallery]` is neutralized so it remains visible text rather than executing.
- Supported photos (JPEG/JPG/PNG and other image types accepted by WordPress) are imported into the Media Library, attached to the imported post, and appended to the post content.

## Idempotency and resume behavior

The importer stores Day One UUID metadata on posts and media. Re-importing the same export skips entries that were already imported and marked complete. If an earlier import created a post but did not finish, the importer resumes that post instead of creating a duplicate.

## Privacy notes

- Imported posts are private by default.
- The plugin does not send journal content or media to external services.
- Admin notices avoid displaying full journal text.
- Temporary extracted files are created in a protected temporary directory and removed after the import when possible.
- **Important media caveat:** WordPress Media Library files may be accessible by direct URL depending on your hosting and WordPress configuration, even when attached to private posts.

## Limitations

- Day One rich text fidelity is not guaranteed; the first version imports the primary text field conservatively.
- Images are appended after entry text rather than placed at exact original inline positions.
- HEIC, video, audio, and PDF support is not guaranteed unless WordPress accepts the files safely.
- Very large exports may hit PHP upload, timeout, or memory limits. Increase server limits or split exports if needed.

## Verification

Run PHP linting:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run pure helper tests without WordPress:

```sh
php tests/pure-helper-tests.php
```

See `tests/manual-verification.md` for a WordPress manual verification checklist.
