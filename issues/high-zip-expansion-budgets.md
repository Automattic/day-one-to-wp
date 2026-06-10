# High: Add ZIP expansion budget checks before extraction

Status: fixed locally

## Problem

ZIP preflight rejects traversal and symlinks but does not cap uncompressed bytes, compression ratio, member count, per-file size, or extracted disk usage. A malicious or accidental ZIP bomb can fill disk or pin PHP during extraction.

## Expected Fix

- Track archive member count, total uncompressed size, largest member size, and compression ratio during preflight.
- Reject archives above configurable budgets before extraction proceeds.
- Enforce per-member extraction budgets during batch extraction as a second line of defense.
- Add tests for budget rejection.

## Local Work

- Added configurable member-count, total-uncompressed, per-member, and compression-ratio budgets.
- Persisted ZIP/extraction budget cursors on async jobs.
- Batch extraction enforces size budgets while extracting individual members.
- Removed the pure-helper harness; this still needs WordPress-level regression coverage for total-size and member-count rejection.
