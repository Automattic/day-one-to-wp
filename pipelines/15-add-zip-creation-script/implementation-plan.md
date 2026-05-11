# Implementation plan: Add a ZIP creation script

1. Add npm project metadata with `@wordpress/scripts` as a development dependency.
2. Add `plugin-zip` and `build:zip` scripts that invoke `wp-scripts plugin-zip`.
3. Define the package `files` allowlist so the generated ZIP includes runtime plugin files and excludes development/private/generated files.
4. Commit `package-lock.json` for reproducible dependency installation.
5. Ignore the generated `day-one-importer.zip` artifact.
6. Document the ZIP build flow in `README.md`.
7. Verify by installing dependencies, building the ZIP, inspecting the archive contents, and running the existing PHP checks.
