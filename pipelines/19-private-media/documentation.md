# Documentation summary

Documentation was updated to describe the new private media behavior.

## README.md

- Explains that new Day One media is stored under `day-one-importer-private`.
- Explains that the importer writes best-effort server protection files.
- Explains that imported media URLs use a WordPress endpoint that requires a logged-in user with read access to the associated post or attachment.
- Keeps the timeout/sub-size guidance from issue #17.
- Adds a limitation that private media blocking depends on server support for protection files.

## readme.txt

- Adds private uploads and permission-checked endpoint behavior to the description.
- Updates the privacy note with the direct-access caveat for hosts that ignore `.htaccess` or `web.config`.
- Updates the media FAQ to mention importer-private storage and endpoint serving.
