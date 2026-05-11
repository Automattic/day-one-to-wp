# Implementation plan

1. Change the Day One media upload directory filter to use a plugin-private directory outside `ABSPATH`, not `wp-content/uploads`.
2. Add helpers to resolve, prepare, and validate the private media base directory.
3. Keep `.htaccess`/`web.config` protection files as defense in depth in the private directory.
4. After sideloading, store the absolute private file path in `_wp_attached_file` so `get_attached_file()` works after upload filters are removed.
5. Update endpoint path validation to accept the private media directory.
6. Add pure helper tests for private path selection, upload dir rewriting, and path validation.
7. Update README/readme privacy docs.
8. Run PHP lint, pure helper tests, and wp-env smoke checks including file location and direct URL checks.
