# Prompt: Make Day One Importer ready for WordPress.org publish

Prepare the Day One Importer WordPress plugin repository for safe publication on GitHub and WordPress.org.

## Context

The plugin is implemented on branch `0-day-one-importer`. It imports Day One JSON export ZIP files as private WordPress posts, imports supported media, supports reruns/idempotency, and has wp-env smoke tests.

The owner explicitly requires a clean publishable Git history with **no private sample data**. Real Day One sample exports, photos, screenshots, and prompt images must not be tracked or present in publishable history. Local ignored sample data may exist in the working directory only and must not be read, copied, summarized, or committed.

## Goals

- Make the repository ready to publish on GitHub.
- Make the plugin package ready for WordPress.org plugin directory submission conventions.
- Keep the Git history clean and free of private sample data.
- Avoid publishing Radical Pipelines artifacts if they are not appropriate for the plugin distribution.
- Keep test tooling useful without requiring private sample data.

## Required checks and changes

- Verify no tracked files or reachable Git history objects include `sample/` or `prompt-images/` private data.
- Ensure `.gitignore` excludes local private sample data and other local-only files.
- Review whether `pipelines/` artifacts should remain in the public repo; if not, remove them from the publish branch/history or prepare a clean publish branch without them.
- Add or verify WordPress.org-required metadata:
  - valid plugin header,
  - stable version,
  - GPL-compatible license metadata,
  - `readme.txt` in WordPress.org format,
  - appropriate tested/requires tags,
  - no placeholder URLs like `example.com`.
- Ensure docs do not mention or expose private sample filenames/content where avoidable.
- Ensure wp-env tests can skip gracefully when local private sample ZIP is absent.
- Re-run verification: PHP lint, pure tests, wp-env smoke test if local sample exists, and static checks for private paths/history.

## Deliverables

- Implementation plan/release checklist artifact.
- Code/docs changes needed for publication.
- Verification artifact documenting exact commands and results.
- Final repository state suitable for GitHub and WordPress.org publication.
