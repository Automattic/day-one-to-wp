# Prompt: Build a WordPress Day One importer plugin

We work at Automattic and own Day One and WordPress.com. Build a WordPress plugin that lets a WordPress user import exported Day One journals into private WordPress posts, so users who want to delete their Day One account can still back up and preserve their journals on a private WordPress site.

The repository is initially empty except for sample Day One export data under `sample/` and prompt screenshots under `prompt-images/`. Use the sample export to understand the Day One JSON and media shape.

## Core goal

Create a WordPress plugin that adds a Day One importer to WordPress admin Tools / Importers and imports Day One journal entries as private posts.

## Functional expectations

- Register a Day One importer in WordPress admin importer tools.
- Accept a Day One export, likely a `.zip` containing journal JSON plus media folders, or extracted JSON/media where appropriate.
- Parse Day One journal JSON entries.
- Create one private WordPress post per Day One entry.
- Preserve the original journal entry date by setting the WordPress post date/date_gmt from the Day One entry creation date.
- Preserve content, including Markdown-like Day One text, in a useful WordPress post body.
- Upload/import photos and other supported phone media from the Day One export into the WordPress Media Library.
- Attach imported media to the corresponding post and include photos in the post content in the original entry context where feasible.
- Preserve/tag Day One tags as WordPress post tags.
- Keep imported posts private by default.
- Follow WordPress development best practices and coding standards throughout.

## Quality expectations

- Implement a normal WordPress plugin structure with a valid plugin header.
- Use WordPress APIs for admin pages, importers, file upload handling, media sideloading, post creation, taxonomy assignment, nonce/capability checks, escaping, sanitization, and internationalization.
- Avoid external service dependencies.
- Handle errors gracefully and report useful import results to the admin user.
- Be careful with privacy: do not expose imported journal content publicly.
- Make the importer reasonably resumable/idempotent, avoiding duplicate imports where possible by tracking Day One UUIDs.
- Include documentation explaining how to export from Day One and import into WordPress.
- Include tests or at least a clear verification strategy appropriate for a WordPress plugin repository.

## Artifact requirements

Produce a requirements/specification document for this task before implementation. The spec should describe acceptance criteria, out-of-scope items, WordPress-specific constraints, import data assumptions, media handling expectations, privacy requirements, and verification strategy.
