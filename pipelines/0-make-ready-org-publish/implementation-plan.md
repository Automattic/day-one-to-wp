# Implementation plan: release readiness for GitHub and WordPress.org

## Scope and current findings

This plan prepares the Day One Importer plugin for safe publication on GitHub and WordPress.org without implementing changes yet.

Observed from repository metadata and non-private files:

- Current branch: `0-day-one-importer`.
- Working tree was clean when checked.
- `git ls-files` does not list `sample/` or `prompt-images/`.
- `git log --all --name-only` does not list `sample/` or `prompt-images/` paths.
- `.gitignore` excludes `sample/` and `prompt-images/`.
- `pipelines/` artifacts are tracked and should be removed from the publishable branch unless the owner explicitly wants process artifacts public.
- WordPress.org readiness gaps include no `readme.txt`, no root `LICENSE`, and a placeholder `Plugin URI: https://example.com/day-one-importer`.
- Existing docs and tests reference a local sample ZIP path; replace with generic/private-sample guidance where practical before publication.

Privacy rule for all tasks below: do not open, read, extract, summarize, copy, or commit anything under `sample/` or `prompt-images/`. Only inspect Git metadata/path lists for those directories.

## Ordered implementation tasks

### 1. Freeze the release target and choose public metadata

1. Confirm the intended public repository URL, plugin homepage/support URL, author name, and initial release version.
2. Decide whether the WordPress.org slug will be `day-one-importer`.
3. Decide the initial stable version, likely `0.1.0` unless changing functionality before release.
4. Confirm license choice is GPL-2.0-or-later or another WordPress.org-compatible GPL license.

### 2. Clean private-data and repository-state checks

Run path-only checks, without reading ignored sample content:

```sh
git status --short
git ls-files | grep -E '^(sample|prompt-images)/' || true
git log --all --name-only --pretty=format: | grep -E '^(sample|prompt-images)/' | sort -u || true
git rev-list --objects --all | grep -E '(^| )(sample|prompt-images)/' || true
git check-ignore -v sample/ prompt-images/ || true
```

Acceptance criteria:

- No tracked private sample or prompt-image paths.
- No reachable history object names under those paths.
- The directories remain ignored for local use.
- If any private path appears in reachable history, stop normal work and create a clean publish branch/history using an approved history-rewrite process before publishing.

### 3. Decide and apply publishable repository contents

Recommended publication shape:

- Keep plugin source, tests, `.wp-env.json`, `README.md`, `readme.txt`, `.gitignore`, and license files.
- Remove `pipelines/` from the publishable branch because these are process artifacts, not plugin distribution assets, and may include unnecessary internal context.
- Do not remove `pipelines/` by simply deleting them in a later public commit if the goal is a clean public history. Instead, create a clean publish branch with only approved publishable files, or squash/filter the implementation history before pushing.

Suggested clean-branch approach:

1. Create a new branch from an empty/orphan state, e.g. `git switch --orphan publish/main`.
2. Add only approved files.
3. Verify no private or pipeline paths are staged.
4. Commit as the initial public release commit.
5. Push that branch to GitHub as the public default branch after review.

If retaining normal history is preferred, at minimum remove `pipelines/` before release and document why history still contains pipeline artifacts.

### 4. WordPress plugin header and metadata updates

Update `day-one-importer.php`:

- Replace placeholder `Plugin URI` with the real GitHub/plugin URL or remove it if no public URL is ready.
- Add license metadata, for example:
  - `License: GPL-2.0-or-later`
  - `License URI: https://www.gnu.org/licenses/gpl-2.0.html`
- Ensure `Version` and `DAY_ONE_IMPORTER_VERSION` match.
- Keep `Requires at least: 6.4` and `Requires PHP: 7.4` if those are the tested baselines.
- Consider adding `Update URI: false` only if intentionally preventing update conflicts outside WordPress.org; omit for WordPress.org submission.

### 5. Add WordPress.org `readme.txt`

Create a WordPress.org-format `readme.txt` with:

- Plugin name and contributors.
- Tags, e.g. `import, importer, day one, journal, privacy`.
- `Requires at least: 6.4`.
- `Tested up to:` the current tested WordPress version.
- `Requires PHP: 7.4`.
- `Stable tag: 0.1.0` or `trunk`, matching the release strategy.
- `License: GPLv2 or later` and `License URI`.
- Short description under 150 characters.
- Sections: Description, Installation, Frequently Asked Questions, Screenshots only if real non-private screenshots are available, Changelog, Upgrade Notice if needed.

Ensure `readme.txt` does not include placeholder URLs, private sample filenames, journal excerpts, screenshots, or any private-data-derived content.

### 6. Add/verify license and documentation files

1. Add a root `LICENSE` file matching the plugin header and WordPress.org readme.
2. Review `README.md` for GitHub users:
   - Replace hardcoded private sample ZIP path with generic language such as `sample/local-day-one-export.zip` or an environment variable.
   - Keep privacy warnings about not committing exports/photos.
   - Remove or replace any placeholder URLs.
3. Review `tests/manual-verification.md`:
   - Replace hardcoded sample ZIP names with generic local sample references.
   - Keep verification instructions privacy-safe.
4. Ensure all docs state that local Day One exports/photos are ignored and must not be committed.

### 7. Test tooling without private samples

Preserve useful tests without requiring private data:

1. Confirm `tests/wp-env-import-sample.php` exits successfully with a skipped status when no local sample ZIP exists.
2. Consider changing the sample ZIP path to be configurable by environment variable or WP-CLI argument, with a generic default under ignored `sample/`.
3. Ensure the script prints only counts/statuses and not journal content.
4. Keep pure helper tests independent of private files.

### 8. Optional static quality pass

Before final release, run lightweight checks and fix any publication blockers:

```sh
find . -path './sample' -prune -o -path './prompt-images' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/pure-helper-tests.php
rg -n --glob '!sample/**' --glob '!prompt-images/**' 'example\.com|TODO|FIXME|05-07-2026|prompt-images|sample/' . || true
```

Treat results as follows:

- `example.com` must be removed or replaced.
- Hardcoded private sample filenames should be removed from public docs/tests where avoidable.
- Generic mentions of ignored `sample/` are acceptable when documenting local-only test data.

### 9. wp-env verification

Run these after metadata/docs changes:

```sh
wp-env start
wp-env run cli wp eval '$admin = new Day_One_Importer_Admin(); $admin->register_importer(); global $wp_importers; echo isset( $wp_importers["day-one"] ) ? "day-one importer registered\n" : "missing importer\n";'
wp-env run cli wp eval-file wp-content/plugins/day-one-importer/tests/wp-env-import-sample.php
```

Acceptance criteria:

- Importer registers under Tools > Import.
- If no local sample is present, smoke test exits `0` with `status: skipped`.
- If the owner provides a local ignored sample, smoke test exits `0` with counts only and verifies private posts, media import, idempotency, schema rerun, and trash recreation.

### 10. Final publish packaging checks

For GitHub/public branch:

```sh
git status --short
git ls-files | grep -E '^(sample|prompt-images|pipelines)/' || true
git rev-list --objects --all | grep -E '(^| )(sample|prompt-images)/' || true
rg -n --glob '!sample/**' --glob '!prompt-images/**' 'example\.com' . || true
```

For WordPress.org ZIP/SVN contents:

- Include only plugin files needed at runtime plus accepted docs/assets.
- Exclude `sample/`, `prompt-images/`, `pipelines/`, local caches, and development-only artifacts not needed in the plugin directory.
- Confirm `readme.txt` validates with the WordPress.org readme validator.
- Confirm version values match across plugin header, constant, readme stable tag, and changelog.

## Commit strategy

Recommended commits for a clean public release branch:

1. `Prepare WordPress.org plugin metadata`
   - Plugin header URL/license/version updates.
   - Add `readme.txt` and `LICENSE`.
2. `Sanitize public documentation and test sample references`
   - Remove placeholder/private sample filenames from public docs/tests.
   - Keep skipped test behavior for absent local samples.
3. `Remove non-distribution pipeline artifacts`
   - Exclude `pipelines/` from the publishable branch, preferably through an orphan/squashed initial public commit rather than a public deletion commit.
4. `Verify release readiness`
   - Add/update a verification note if desired, containing commands and pass/fail results only, with no private sample content.

Before pushing publicly, review the exact staged set:

```sh
git diff --cached --name-status
git diff --cached --check
git ls-files | sort
```

Do not commit local ignored sample data, prompt images, generated private screenshots, or extracted Day One export contents.
