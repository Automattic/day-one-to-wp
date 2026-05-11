# Implementation plan: Private imported media

1. Add plugin hooks during bootstrap for Day One media URL filtering and an authenticated media-serving endpoint.
2. During Day One media sideloads, add a scoped `upload_dir` filter that redirects uploads to `uploads/day-one-importer-private/YYYY/MM`.
3. Protect the private media base directory and current dated directory with existing protection files.
4. Keep issue #17 image-size filters scoped around the same sideload operation.
5. Add a URL filter that maps attachments marked `_day_one_source=day-one-export` to `admin-ajax.php?action=day_one_importer_media&attachment_id=...`.
6. Add an AJAX handler that validates the attachment source, checks the current user's read permission on the parent post/attachment, and streams the file with a safe content type.
7. Add pure helper tests for private upload-dir rewriting and endpoint URL construction.
8. Update README/readme documentation to describe protected media behavior and server caveats.
9. Run PHP lint and pure helper tests, then open a PR.
