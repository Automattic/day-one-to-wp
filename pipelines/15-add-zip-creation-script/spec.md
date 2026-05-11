# Spec: Add a ZIP creation script

## Requirements

- Add a Node/npm-based script that creates an installable WordPress plugin ZIP for Day One Importer from the repository root.
- Use `@wordpress/scripts` and its `wp-scripts plugin-zip` command for ZIP creation.
- Include only files needed to install and run the plugin locally, including the main plugin file, PHP includes, admin assets, readme files, and license.
- Exclude private, test-only, local-development, dependency, pipeline, and generated files from the ZIP.
- Ensure the generated ZIP artifact is not committed to git.
- Document the install/build commands for developers.
- Preserve the existing PHP lint/test verification flow.

## Acceptance criteria

- `npm install` installs the package dependencies from a committed lockfile.
- `npm run plugin-zip` creates `day-one-importer.zip` in the repository root.
- The ZIP expands into a top-level `day-one-importer/` folder.
- The ZIP contains runtime plugin files, including `assets/admin-import-status.js`.
- The ZIP does not contain `tests/`, `tools/`, `sample/`, `prompt-images/`, `pipelines/`, `.git/`, `node_modules/`, or the generated ZIP itself.
- `README.md` explains how to build the ZIP.
- Existing PHP syntax checks and pure helper tests continue to pass.

## Out of scope

- Publishing a release to GitHub, WordPress.org, or any package registry.
- Adding CI release automation.
- Changing plugin runtime behavior.
- Adding JavaScript build/bundling beyond the ZIP command.
- Resolving unrelated npm audit findings from transitive development dependencies.

## Verification

- Run `npm install` or `npm ci` from a clean checkout/worktree.
- Run `npm run plugin-zip` and inspect `unzip -l day-one-importer.zip`.
- Run the repository PHP lint command documented in `README.md`.
- Run `php tests/pure-helper-tests.php`.
