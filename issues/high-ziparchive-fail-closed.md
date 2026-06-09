# High: Fail closed when ZipArchive is unavailable

Status: fixed locally

## Risk

When PHP `ZipArchive` was unavailable, the importer fell back to a synchronous path where ZIP budget preflight returned success without inspecting the archive. That bypassed member count, uncompressed size, per-member size, compression ratio, unsafe path, and symlink checks before extraction.

## Local Fix

- Admin upload handling now returns a safe error instead of falling back to synchronous import without `ZipArchive`.
- ZIP preflight returns an error when `ZipArchive` is missing.
- README/readme copy now says `ZipArchive` is required for safe ZIP inspection before extraction.

