# Test fixtures

The `day-one-fictional/` export and `day-one-fictional.zip` archive are wholly fictional Day One-style fixtures for tests and examples. They are safe to publish and are not derived from real Day One data, local private exports, or prompt/reference images.

The fixture contains three fictional entries: one with a generated PNG image, one with shortcode-like and raw HTML text for content-safety checks, and one text-only entry. The PNG is a tiny generated color tile, not a photograph.

## Rebuild the ZIP

From the repository root, run:

```sh
php tools/build-fictional-sample-zip.php
```

The helper validates the JSON shape, confirms photo metadata points to existing files with matching MD5 hashes, removes any previous `tests/fixtures/day-one-fictional.zip`, and creates a ZIP containing only the export contents, for example:

```text
Fictional Journal.json
photos/a36a701e0fe5e46e37fb460b40ccda7b.png
```

Do not place real Day One exports in this directory. Keep private local exports under ignored paths such as `sample/` or outside the repository.
