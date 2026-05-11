# Spec: Prevent imported media from being public or accessible

## Requirements

- Media imported from Day One exports should not be exposed as ordinary public uploads when the server honors WordPress-compatible protection files.
- Store newly imported Day One media under a dedicated importer-private uploads subdirectory rather than the normal year/month public uploads location.
- Add best-effort web server protection files to the private media directory and generated subdirectories.
- Replace public attachment URLs for Day One media with a WordPress-controlled endpoint.
- The media endpoint must require an authenticated user who can read the parent private post or the attachment.
- Existing imported post privacy, media metadata, idempotency, resume behavior, and image ordering must continue to work.
- Existing static server limitations must be documented honestly, especially for servers that ignore `.htaccess`/`web.config`.
- Avoid exposing journal text or filesystem paths in user-facing messages.

## Acceptance criteria

- New Day One media sideloads are directed to `uploads/day-one-importer-private/...`.
- The private media directory gets `index.html`, `.htaccess`, and `web.config` protection files.
- `wp_get_attachment_url()` for Day One-imported attachments returns a stable plugin endpoint URL instead of the raw uploads URL.
- The endpoint serves files only to users with permission to read the associated private post or attachment.
- Unauthenticated or unauthorized requests do not receive the media file.
- Existing generated sub-size suppression from issue #17 remains scoped to Day One imports.
- Pure helper tests and PHP lint pass.
- Documentation no longer presents public media exposure as unavoidable; it describes the new protection and remaining server caveats.

## Out of scope

- Retrofitting files imported by older plugin versions into the private directory.
- Providing guaranteed protection on every possible web server configuration.
- Building a full authenticated media proxy with range requests/caching controls beyond what is needed for images in private posts.
- Changing post privacy defaults or supported media types.
- Adding a background migration tool.

## Verification

- Run PHP syntax lint across repository PHP files.
- Run `php tests/pure-helper-tests.php`.
- In wp-env when available, import the fictional fixture and confirm imported attachment URLs use the plugin media endpoint.
- Confirm the private uploads directory contains protection files.
- Confirm a direct raw uploads path is not used in generated post image markup for newly imported Day One media.
