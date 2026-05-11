=== Day One Importer ===
Contributors: automattic, cbravobernal
Tags: import, importer, day-one, journal, privacy
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Day One JSON export ZIPs as private WordPress posts with tags, dates, and supported photos.

== Description ==

Day One Importer is made by Automattic. It adds a WordPress admin importer for Day One JSON export ZIP files. It creates one private WordPress post for each Day One entry and attempts to preserve entry dates, tags, text, and supported photos.

The importer is designed for local or private archive migration workflows:

* Imported posts are private by default.
* Re-importing the same export skips completed entries using Day One UUID metadata.
* Interrupted or older-schema imports can be resumed or refreshed in place.
* Supported photos are imported into the Media Library and attached to their posts.
* New Day One media is stored in an importer-private uploads directory and served through a permission-checked WordPress endpoint.
* Generated image sub-sizes are skipped during import to reduce timeout risk on large exports.
* Result screens report counts, UUIDs, dates, filenames, and generic warnings rather than full journal content.

Privacy note: the importer writes best-effort server protection files for its private media directory and serves imported media only to logged-in users who can read the associated private post or attachment. If media privacy is critical, confirm your host blocks direct requests to files under `wp-content/uploads/day-one-importer-private/`, because some Nginx or custom configurations may ignore `.htaccess` or `web.config` files.

The plugin does not send journal content or media to external services.

For development and testing, the repository includes a wholly fictional sample Day One-style export. Real Day One exports should remain in ignored local paths such as `sample/` or outside the repository.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/day-one-importer/` directory, or install the plugin ZIP through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Tools > Import and choose Day One.
4. Upload the original Day One JSON export ZIP.
5. Review the import summary and spot-check the resulting private posts.

== Frequently Asked Questions ==

= What Day One export format is supported? =

Export your journal from Day One as JSON and keep the original ZIP intact. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos are present, a `photos` directory.

= Are imported entries public? =

No. Imported entries are created as private WordPress posts by default. However, attached Media Library files may still be accessible by direct URL depending on your hosting and WordPress configuration.

= Can I rerun the same export? =

Yes. The importer stores Day One UUID metadata and skips entries that were already imported and marked complete. It can also resume incomplete imports and refresh older importer-schema versions in place.

= What media types are imported? =

The importer initially supports common image formats such as JPEG/JPG and PNG, plus other image formats accepted safely by the target WordPress site. Unsupported or missing media generates warnings without stopping unrelated entries. New imported media is stored under the importer-private uploads directory and served through a permission-checked endpoint. To reduce timeout risk during large imports, generated image sub-sizes are skipped during import; regenerate thumbnails after import if you need those sizes later.

= Does the plugin contact external services? =

No. The plugin processes ZIP files and extracted content locally in WordPress temporary locations and cleans them up when possible.

== Changelog ==

= 0.1.0 =
* Initial release.
* Import Day One JSON export ZIP entries as private WordPress posts.
* Preserve dates, tags, conservative text formatting, and supported photos.
* Support idempotent reruns, incomplete import resume behavior, and privacy-safe result summaries.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
