# Prompt: Harden private media protection

Source task: reopened GitHub issue #19, "Prevent Media from being public or accesible."
URL: https://github.com/Automattic/day-one-to-wp/issues/19

The previous implementation stored Day One media under `wp-content/uploads/day-one-importer-private/`, added server protection files, and replaced attachment URLs with a permission-checked WordPress endpoint. The issue was reopened because imported media is still accessible directly in at least one tested environment, likely when the web server ignores `.htaccess`/`web.config` or when the raw uploads path is known.

Harden the implementation so newly imported Day One media is not stored under a public uploads URL at all. Store imported media files in a persistent plugin-private directory outside WordPress' public uploads tree when possible, continue serving them only through a permission-checked WordPress endpoint, and preserve import idempotency/resume behavior. Update tests and documentation to reflect that server protection files are no longer the primary privacy mechanism.
