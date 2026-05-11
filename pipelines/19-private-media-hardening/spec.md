# Spec: Harden private media protection

## Requirements

- Newly imported Day One media must not be stored under the public WordPress uploads tree.
- Use a persistent plugin-private filesystem directory outside the web root (`ABSPATH`) when possible.
- If a private storage directory cannot be created or written, fail the media item safely instead of falling back to public uploads.
- Continue serving imported media through the existing permission-checked WordPress endpoint.
- Preserve idempotency/resume behavior and existing Day One media metadata.
- Do not expose journal content or private filesystem paths in user-facing messages.
- Update tests and documentation so server protection files are described as defense in depth, not the main privacy mechanism.

## Acceptance criteria

- New Day One media files are stored outside `wp-content/uploads`.
- `wp_get_attachment_url()` for Day One media still returns the authenticated endpoint URL.
- The endpoint can serve media from the private filesystem directory to authorized users.
- Direct public upload URLs are not created for newly imported media.
- Media import fails safely if the private directory cannot be prepared.
- PHP lint, pure helper tests, and wp-env smoke import pass.

## Out of scope

- Migrating media imported by prior plugin versions.
- Guaranteeing protection if a host maps directories outside `ABSPATH` into a public URL by custom server config.
- Adding background migration or cleanup tooling.
