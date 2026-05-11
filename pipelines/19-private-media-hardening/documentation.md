# Documentation summary

Updated README.md and readme.txt for the reopened issue #19 hardening.

## Updates

- Clarified that new imported media is stored outside the public WordPress uploads tree.
- Clarified that the importer prefers a filesystem location outside the WordPress web root when the host allows it.
- Kept protection files as defense in depth rather than the main privacy mechanism.
- Documented the `day_one_importer_private_media_dir` filter for advanced operators who need to choose a private media directory.
- Clarified that media import fails safely instead of falling back to public uploads if private storage cannot be prepared.
