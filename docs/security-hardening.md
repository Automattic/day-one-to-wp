# Security hardening checklist

This checklist adapts the WordPress Security Hardening Guide to Day One Importer. It is a project-maintainer checklist, not a replacement for a site operator's broader WordPress/server hardening program.

Reference: https://github.com/dknauss/wp-security-hardening-guide

## Development controls

- Keep importer entry points behind WordPress capability checks.
- Verify nonces for state-changing admin, upload, AJAX, and cancel/retry actions.
- Escape all admin output at the point of rendering.
- Keep job status messages privacy-safe: no journal body text, raw JSON, local paths, or media previews.
- Treat Day One ZIP files, JSON values, filenames, UUIDs, and query parameters as untrusted.
- Prevent path traversal and unsafe extraction from ZIP archives.
- Prefer allowlists and WordPress sanitizers for file types, keys, and IDs.
- Keep private media outside the public uploads tree and serve it only after permission checks.
- Preserve idempotency for interrupted imports, retries, cancellation, cleanup, and re-imports.

## Dependency and release controls

- Review `package-lock.json` changes before merging dependency updates.
- Run automated lint/tests before release packaging.
- Do not include real Day One exports, private photos, generated local ZIPs, or prompt/reference images in commits.
- Verify release archives contain only intended plugin files.

## Manual verification additions

Before relying on a release for private journal imports, verify:

1. A user without import/upload/edit capabilities cannot access importer actions or AJAX job endpoints.
2. Invalid, non-ZIP, malformed, and path-traversal ZIP inputs fail safely.
3. Imported posts are private.
4. Imported media cannot be fetched anonymously and requires permission to read the parent private post or attachment.
5. Status panels, warnings, and errors expose counts, UUIDs, dates, and generic filenames only where needed, not journal content or server paths.
6. Canceling or retrying an import does not expose temporary files or create duplicate posts/media.
7. Temporary import files are removed after completion, cancellation, or stale-job cleanup where possible.

## Operator reminders

Site operators should also follow the broader WordPress hardening guidance for:

- WordPress core, plugin, and theme updates.
- Strong authentication and MFA for privileged accounts.
- Least-privilege roles for users running imports.
- TLS, secure cookies, and server-level access controls.
- Backups, logging, malware scanning, and incident response.
- Web server and PHP configuration outside this plugin's control.
