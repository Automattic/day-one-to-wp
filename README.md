# Day One Importer

Day One Importer is a WordPress admin importer for Day One JSON exports. It creates one **private** WordPress post per Day One entry and attempts to preserve dates, tags, text, and supported photos.

## Try in WordPress Playground

You can launch a temporary WordPress site with Day One Importer installed and activated:

[![Test in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Automattic/day-one-to-wp/main/blueprint.json)

Playground runs in your browser and is useful for checking the importer screens with fictional or disposable exports. Avoid uploading private Day One exports unless you are comfortable testing them in that browser session. Playground storage is temporary and browser-backed; large ZIP uploads, media processing, and generated attachment URLs may behave differently from a normal WordPress host.

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

A typical export follows this shape: a journal JSON file plus media files in `photos/`. Do not commit real Day One exports or photos to this repository; they can contain private journal content.

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

If importer behavior changes in a way that requires existing imported posts to be refreshed, rerunning the same export reprocesses older importer-schema versions in place instead of skipping them. This lets import-time fixes, such as cleaner Day One text/title conversion, apply by rerunning the import rather than manually editing posts.

If you move imported posts to Trash and rerun the import, the trashed imported copy is permanently removed and a fresh private post is created for that Day One UUID. This is useful when cleaning up a failed test import before retrying.

This means you can normally rerun the same export after an interruption without creating duplicate posts for the same Day One UUIDs.

## Media behavior and privacy

Supported initial image types are JPEG/JPG, PNG, and other image formats that the target WordPress site accepts safely. HEIC, video, audio, PDF, and other attachment types are not guaranteed to import unless WordPress accepts and processes them in that environment.

Photos are attached to the corresponding private post and importer metadata is stored on the attachment to support reuse on reruns.

To reduce timeout risk during large imports, the importer asks WordPress/PHP for long-running admin request limits where the host allows it and skips generated image sub-sizes during Day One media sideloads. Imported posts use the original uploaded image file. If you need WordPress thumbnail sizes for imported media later, regenerate thumbnails after the import using your preferred trusted maintenance tool.

**Important media caveat:** WordPress Media Library files may be accessible by direct URL depending on your hosting and WordPress configuration, even when attached to private posts. If media privacy is critical, confirm your host or site configuration blocks public access to uploaded media URLs before relying on this importer as a private archive.

## Temporary files and external services

- The plugin does not send journal content or media to external services.
- ZIP files and extracted content are processed in a protected temporary location and cleaned up after the import when possible.
- Uploaded ZIPs are not intentionally left in the plugin directory.

## Local private data

Local Day One exports, extracted photos, and prompt/reference images must not be committed. This repository ignores `sample/` and `prompt-images/` for local-only private data.

For tests and examples, the repository includes `tests/fixtures/day-one-fictional.zip`, a wholly fictional Day One-style export that is safe to publish and is used by default in automated smoke tests.

## License

Day One Importer is licensed under GPL-2.0-or-later. See `LICENSE` for details.

## Limitations

- Day One rich text fidelity is not guaranteed; the importer uses the primary text field conservatively.
- Images are appended after entry text rather than placed at exact original inline positions.
- Unsupported or missing media produces warnings but does not stop the entry import.
- Very large exports may still hit host-enforced upload, timeout, or memory limits even though the importer reduces image-processing work. Increase server limits, rerun to resume, or split exports if needed.
- WordPress Playground is useful for quick testing, but browser-backed uploads/media handling may differ from a normal WordPress host, especially for large Day One ZIP exports or photo-heavy imports.
- The importer does not sync with Day One, delete Day One accounts, or publish imported entries publicly.

## Troubleshooting

- **Importer is not listed:** confirm the plugin is activated and that the current user has import permissions.
- **ZIP upload fails:** check PHP upload size limits, WordPress upload permissions, and that the file is a Day One JSON export ZIP.
- **No entries found:** confirm the ZIP contains a journal JSON file with a top-level `entries` array.
- **Photos missing:** confirm the export includes a `photos/` directory and that the media type is accepted by WordPress.
- **Duplicate import concerns:** rerun the same ZIP; completed entries should be skipped because Day One UUID metadata is stored on imported posts.

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
php tests/pure-helper-tests.php
```

Run a local WordPress smoke test with `wp-env`:

```sh
wp-env start
wp-env run cli wp eval '$admin = new Day_One_Importer_Admin(); $admin->register_importer(); global $wp_importers; echo isset( $wp_importers["day-one"] ) ? "day-one importer registered\n" : "missing importer\n";'
PLUGIN_DIR=$(wp-env run cli wp eval 'echo basename( WP_PLUGIN_DIR . "/" . dirname( plugin_basename( DAY_ONE_IMPORTER_FILE ) ) );' 2>/dev/null | tail -n 1)
wp-env run cli wp eval-file "wp-content/plugins/${PLUGIN_DIR}/tests/wp-env-import-sample.php"
```

The wp-env smoke test imports the committed fictional fixture at `tests/fixtures/day-one-fictional.zip` by default. You can optionally set `DAY_ONE_IMPORTER_SAMPLE_ZIP` or pass a ZIP path as the first WP-CLI argument to test a developer-owned private export, such as an ignored `sample/local-day-one-export.zip`. The script does not print journal content. It verifies private posts/media are created, reruns the import, verifies completed entries are skipped, simulates an older importer-schema version and verifies it is reprocessed in place, moves one imported post to Trash, and verifies a later rerun recreates only that trashed entry. The committed default fixture is required; if it is missing, the script fails as a repository setup error.

WordPress Plugin Check is not required by the first CI workflow. It can be added later as a separate GitHub Actions job using the official Plugin Check action or a `wp-env`/WP-CLI job once that path is verified as stable and publish-safe.

See `tests/manual-verification.md` for a WordPress manual verification checklist covering installation, import, privacy, idempotency, invalid inputs, media behavior, and cleanup.
