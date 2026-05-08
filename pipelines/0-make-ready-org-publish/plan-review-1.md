# Plan review 1: release readiness for GitHub and WordPress.org

Verdict: APPROVED

## Review summary

The implementation plan is complete and safety-conscious for the stated publication task. It explicitly preserves the privacy rule, limits private-data verification to Git metadata/path checks, and covers the required WordPress.org metadata, readme/license, pipeline artifact, testing, and verification concerns.

## Checks performed during review

Path-only Git checks were run without reading ignored local private data:

- `git ls-files | grep -E '^(sample|prompt-images)/' || true` returned no tracked private paths.
- `git log --all --name-only --pretty=format: | grep -E '^(sample|prompt-images)/' | sort -u || true` returned no reachable private paths.
- `git rev-list --objects --all | grep -E '(^| )(sample|prompt-images)/' || true` returned no reachable private object names.
- `git ls-files | grep -E '^pipelines/' | head -20` confirmed tracked pipeline artifacts exist, matching the plan's finding that publishable history/branch handling is needed.

No files under `sample/` or `prompt-images/` were opened, read, copied, or summarized.

## Required coverage assessment

- Private-data history cleanup: covered. The plan includes tracked-file, reachable-history, object-name, and ignore checks, plus a stop-and-rewrite/clean-branch path if private paths are found.
- WordPress.org requirements: covered. The plan addresses plugin header URL/license/version fields, `readme.txt`, stable tag, requirements, tested-up-to, contributors/tags, GPL-compatible licensing, and placeholder URL removal.
- Pipeline artifact handling: covered. The plan recommends excluding `pipelines/` from the public publish branch and correctly warns that a simple deletion commit is not equivalent to clean public history.
- Test tooling without private samples: covered. The plan requires graceful skip behavior when no local sample ZIP exists, configurable/generic sample paths, and count/status-only output.
- Verification strategy: covered. The plan includes PHP linting, pure tests, wp-env importer registration and smoke checks, static scans, staged-file review, readme validation, and final package contents checks.
- Documentation safety: covered. The plan calls for removing placeholder URLs and hardcoded/private sample references while preserving generic privacy-safe guidance.

## Notes for implementation

- Keep the verification artifact outside the final WordPress.org distribution if the clean publish branch excludes `pipelines/`.
- Confirm the real public URLs, contributor slug(s), tested WordPress version, and final stable version before editing metadata.
- If any history rewrite or orphan publish branch is used, repeat the private-path and pipeline-path checks on the exact branch/commit that will be pushed publicly.
