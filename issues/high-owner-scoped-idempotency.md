# High: Scope imported UUID idempotency to the importing owner

Status: fixed locally

## Risk

Imported Day One UUID lookup was global across all imported posts. A different user with import privileges could import an entry with the same UUID and cause the importer to skip, resume, update, or permanently delete another user's private imported post.

## Local Fix

- Existing post lookup now requires the importing owner as `post_author`.
- Existing incomplete posts are only resumed when the current user can edit them.
- Trashed imported posts are only permanently deleted when the current user can delete them.
- The wp-env smoke test now imports the same ZIP as a second import-capable user and verifies the second user receives a separate post set instead of reusing the first user's private posts.

