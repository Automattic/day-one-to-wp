# Medium: Strengthen private media storage boundary

Status: follow-up

## Problem

Imported Day One media is stored under the public uploads tree and protected with `.htaccess` and `web.config`. Hosts that do not honor those files may still expose raw private media directly.

## Expected Follow-Up

- Prefer a non-public default private media directory when feasible, or surface an admin-visible hard warning when storage falls back to public uploads.
- Keep the authenticated media endpoint as the intended URL path.
- Preserve the `day_one_importer_private_media_dir` operator filter.
