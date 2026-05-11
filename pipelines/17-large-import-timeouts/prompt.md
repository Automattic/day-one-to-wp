# Prompt: Large imports can cause fatal errors

Source task: GitHub issue #17, "Large imports can cause fatal errors."
URL: https://github.com/Automattic/day-one-to-wp/issues/17

Investigate and fix large Day One imports that can trigger fatal PHP maximum execution time errors during media handling. The reported fatal is:

```text
Fatal error: Maximum execution time of 30 seconds exceeded in /var/www/html/wp-includes/class-wp-image-editor-imagick.php on line 525
```

The problem was reproduced with `wp-env` and Docker.

The implementation should make large imports safer in local/test WordPress environments and avoid request-ending fatal errors where possible. Prefer changes that keep import behavior privacy-safe, idempotent, and resumable. Update documentation if operators need to know about limits, timeouts, or batching expectations. Verify with existing tests and any focused tests that can run without a full WordPress environment.
