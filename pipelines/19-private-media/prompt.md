# Prompt: Prevent imported media from being public or accessible

Source task: GitHub issue #19, "Prevent Media from being public or accesible."
URL: https://github.com/Automattic/day-one-to-wp/issues/19

The importer currently warns that imported posts are private by default, but Media Library files may still be accessible by direct URL depending on WordPress and hosting configuration. Improve the plugin so media imported from Day One exports is protected from unauthenticated direct access as much as WordPress/server constraints allow.

The implementation should preserve the importer goals: privacy-safe behavior, private imported posts, idempotent/resumable imports, supported image import, and clear user-facing documentation. Prefer a practical server-side protection mechanism for the files imported by this plugin and avoid exposing journal content, private filenames beyond existing safe summaries, or filesystem paths.

Also avoid repeating prior phase-agent/login failures; produce complete Radical Pipelines artifacts and continue through implementation and PR creation.
