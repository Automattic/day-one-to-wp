# Security Policy

Day One Importer handles private journal exports and imported media. Please report suspected security or privacy issues privately and avoid posting exploit details, real journal content, export ZIPs, or screenshots containing private entries in public issues.

## Supported versions

This repository is maintained from the `main` branch. Security fixes are made there first and are included in the next plugin release/package.

## Reporting a vulnerability

1. Use GitHub's private vulnerability reporting / Security Advisory flow for this repository when available.
2. If private GitHub reporting is unavailable, contact Automattic Security through https://automattic.com/security/ and include a link to this repository.
3. Include enough detail to reproduce the issue using fictional or redacted data only.

Helpful details include:

- WordPress, PHP, browser, and plugin versions.
- The affected importer screen, AJAX action, media endpoint, or file-processing path.
- Steps to reproduce with a minimal fictional Day One-style ZIP if possible.
- The expected impact, such as unauthorized file access, capability bypass, nonce bypass, unsafe output, or private media exposure.

## Project-specific security expectations

Security-sensitive changes should preserve these invariants:

- Import actions require the expected WordPress capabilities and nonces.
- Uploaded archives are treated as untrusted input and extracted only into protected temporary locations.
- ZIP paths, JSON fields, filenames, UUIDs, query parameters, and AJAX payloads are sanitized before use.
- Admin output and status responses do not expose private journal text, raw JSON, local filesystem paths, or media previews.
- Imported posts remain private by default.
- Imported Day One media remains outside the public uploads tree and is served only through permission-checked WordPress endpoints.
- Re-runs, retries, cancellation, and cleanup remain idempotent and do not create duplicate posts/media or orphan private files.
- Dependency updates are reviewed for supply-chain risk before release.

For additional context, see `docs/security-hardening.md` and the external WordPress Security Hardening Guide: https://github.com/dknauss/wp-security-hardening-guide
