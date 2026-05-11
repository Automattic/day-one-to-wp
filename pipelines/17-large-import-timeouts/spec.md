# Spec: Large imports can cause fatal errors

## Requirements

- Reduce the risk of PHP maximum-execution-time fatals during large Day One imports, especially while WordPress/Imagick processes imported photos.
- Keep imports synchronous for now, but make the current request more tolerant of long-running work where WordPress/PHP allows it.
- Avoid expensive generated image sub-size work during Day One media sideloads, because the importer appends full attachment images to private posts and does not require thumbnails for import correctness.
- Preserve idempotency and resume behavior: already imported posts/media should still be reused or skipped on rerun.
- Preserve privacy-safe result output; do not expose journal text or private paths in user-facing messages.
- Document the operational behavior/limitations for large imports.

## Acceptance criteria

- Starting an import attempts to raise WordPress/PHP resource limits for a long-running admin import.
- Day One image sideloads do not ask WordPress to create intermediate image sizes or scaled big-image derivatives during the import request.
- The media sideload behavior is scoped to this importer and does not globally disable image sizes outside the Day One import operation.
- Existing media validation, metadata storage, counters, and attachment reuse behavior continue to work.
- Existing pure helper tests and PHP syntax checks pass.
- Documentation explains that generated image sub-sizes are skipped to reduce timeout risk and that very large imports may still require reruns or higher host limits.

## Out of scope

- Replacing the importer with a background queue or AJAX/batched import workflow.
- Adding WP-Cron or Action Scheduler dependencies.
- Changing Day One parsing semantics or post privacy defaults.
- Guaranteeing success on hosts that enforce hard request timeouts outside PHP control.
- Importing unsupported media types.

## Verification

- Run PHP syntax lint across repository PHP files.
- Run `php tests/pure-helper-tests.php`.
- Run or reason through a wp-env smoke import of the fictional fixture.
- Inspect generated attachment behavior to ensure imported images remain attached and visible in post content while sub-size generation is suppressed for importer sideloads.
