=== Day One Importer ===
Contributors: automattic, cbravobernal
Tags: import, importer, day-one, journal, privacy
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Recommended PHP extensions: ZipArchive (for resumable batched imports)
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Day One JSON export ZIPs as private WordPress posts with journal categories, tags, dates, and supported photos.

== Description ==

Day One Importer is made by Automattic. It adds a WordPress admin importer for Day One JSON export ZIP files. It creates one private WordPress post for each Day One entry and attempts to preserve entry dates, journal categories, tags, text, and supported photos.

The importer is designed for local or private archive migration workflows:

* Imported posts are private by default.
* Large exports run as resumable jobs advanced by short browser requests with a WP-Cron fallback, reducing gateway timeout risk.
* Re-importing the same export skips completed entries using Day One UUID metadata.
* Interrupted, failed, or older-schema imports can be retried, continued, or refreshed in place without duplicating completed posts or media.
* Supported photos are imported into the Media Library and attached to their posts.
* New Day One media is stored outside the public uploads tree and served through a permission-checked WordPress endpoint.
* Generated image sub-sizes are skipped during import to reduce timeout risk on large exports.
* Result screens report counts, UUIDs, dates, filenames, and generic warnings rather than full journal content.

Privacy note: the importer stores new imported media outside the public uploads tree, preferring a location outside the WordPress web root when the host allows it. It writes best-effort server protection files as defense in depth and serves imported media only to logged-in users who can read the associated private post or attachment. If media privacy is critical, confirm your host does not expose the private filesystem directory through custom server configuration.

The plugin does not send journal content or media to external services.

For development and testing, the repository includes a wholly fictional sample Day One-style export. Real Day One exports should remain in ignored local paths such as `sample/` or outside the repository.

== Installation ==

1. Optionally confirm PHP has the ZipArchive extension enabled. Resumable batched imports require it; without it the importer falls back to a synchronous single-request import that works for smaller exports but may time out on very large or photo-heavy ones.
2. Upload the plugin files to the `/wp-content/plugins/day-one-importer/` directory, or install the plugin ZIP through the WordPress Plugins screen.
3. Activate the plugin through the Plugins screen in WordPress.
4. Go to Tools > Import and choose Day One.
5. Upload the original Day One JSON export ZIP and click Import Day One export.
6. Watch the import job panel for phase, progress, counters, warnings, and errors. If the browser connection is interrupted, refresh the page or click Retry / Continue.
7. Review the final import summary and spot-check the resulting private posts.

== Frequently Asked Questions ==

= What Day One export format is supported? =

Export your journal from Day One as JSON and keep the original ZIP intact. The importer expects a ZIP containing one or more journal JSON files with an `entries` array and, when photos are present, a `photos` directory.

= Are imported entries public? =

No. Imported entries are created as private WordPress posts by default. New Day One media is stored outside the public uploads tree and served through a permission-checked endpoint, but you should still confirm your host does not expose the private filesystem directory through custom server configuration.

= Can I rerun the same export? =

Yes. The importer stores Day One UUID metadata and skips entries that were already imported and marked complete. It can also resume incomplete imports, safely retry failed/interrupted batches, and refresh older importer-schema versions in place.

= What media types are imported? =

The importer initially supports common image formats such as JPEG/JPG and PNG, plus other image formats accepted safely by the target WordPress site. Unsupported or missing media generates warnings without stopping unrelated entries. New imported media is stored outside the public uploads tree and served through a permission-checked endpoint. To reduce timeout risk during large imports, generated image sub-sizes are skipped during import; regenerate thumbnails after import if you need those sizes later.

= Does the plugin contact external services? =

No. The plugin processes ZIP files, extracted content, and resumable job manifests locally in protected WordPress temporary locations. Completed or canceled jobs clean up temporary files when possible; failed jobs retain enough state to retry until canceled or stale.

== Changelog ==

= 0.2.1 =
* Move the upload submission dispatcher to `admin_init` so the post-upload `wp_safe_redirect()` runs before `admin-header.php` emits headers. Previously the redirect failed silently from inside the importer screen callback, leaving the page showing the prior canceled job instead of the freshly queued one. Also remove the redundant `#day-one-importer-status` notice under the form and suppress the upload panel's phase and "Progress will update as the job runs." sub-labels during the ZIP upload.

= 0.2.0 =
* Fix the blank importer screen after a successful upload by redirecting to `import.php` instead of `admin.php`, which is the dispatcher that `register_importer()` uses.
* Upload the export ZIP in the background and show real-time upload percentage in the job panel; the form, intro copy, and panel remain on screen for the entire upload instead of blanking during navigation.
* Always render the import job panel scaffold (hidden when no job exists yet) so the live upload progress and queuing state have a stable place to render before the first job is created.
* Collapse the job panel notice color to three states: canceled or failed runs are red, queued or running runs are blue, completed runs are green regardless of warnings.
* Recalibrate the import progress percentage so the bar tracks entries imported during the importing phase instead of jumping to roughly 65% once preflight, extract, and indexing finish. Resumed jobs paint at their already-imported ratio on first render, and failed or canceled mid-import jobs keep the computed value rather than snapping to 100%.
* Add an estimated import progress bar to the resumable job panel so paused, canceled, retried, or re-opened uploads visibly report their current status.
* Plugin Check compliance cleanup with no user-facing behavior change: private media writability checks now call `wp_is_writable()` directly (the prior `is_writable()` fallback is moved to the test bootstrap as a polyfill), the long-running-import `set_time_limit()` call is kept with a narrowly scoped, justified `phpcs:ignore`, and the media class direct-access guard is rewritten in the nested form already used elsewhere in the plugin.
* Additional Plugin Check compliance cleanup with no user-facing behavior change: add a `translators:` hint to the `%d%% complete` progress string in the resumable job panel, and annotate intentionally low-level lint findings with justified `phpcs:ignore` directives — the admin importer's pre-escaped progress-panel helpers, the indirect nonce check on the upload dispatch, the custom `sanitize_job_id()` reads from `$_GET`, the streaming JSON parser's `fclose()` calls paired with `fopen()`/`fopen('x')` handles, and the job store's atomic compare-and-swap and prefix-scan queries on `wp_options` that implement the distributed import lock.

= 0.1.0 =
* Initial release.
* Import Day One JSON export ZIP entries as private WordPress posts.
* Preserve dates, journal categories, tags, conservative text formatting, and supported photos.
* Support resumable batched import jobs with progress, Retry / Continue, cancellation, cron fallback, idempotent reruns, incomplete import resume behavior, and privacy-safe result summaries.

== Upgrade Notice ==

= 0.2.1 =
Fixes the post-upload screen still showing the previously canceled job instead of the new one by dispatching the upload on `admin_init` so the redirect actually runs.

= 0.2.0 =
Fixes the post-upload blank screen and adds a live upload progress percentage to the import job panel.

= 0.1.0 =
Initial release.
