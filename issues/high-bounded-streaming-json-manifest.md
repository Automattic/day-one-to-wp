# High: Restore bounded streaming for JSON and manifest processing

Status: fixed in 0.2.23 — merged resolution keeps native bounded streaming with documented Plugin Check exceptions

Merged resolution (0.2.23): the integrate-blocks-hardening merge kept the truly
bounded native `fopen`/`fseek`/`fread`/`fclose` streaming for JSON indexing
(each call site carries a `phpcs:ignore` with rationale, per the 0.2.19
documented exception — WP_Filesystem has no chunked-read API), combined with
the hardening branch's `top_depth`/`expect_key` top-level `entries`-key
detection and a WP_Filesystem readability pre-check. The "Remaining Risk"
below describes the superseded WP_Filesystem-only variant and no longer
applies. Plugin Check reports zero findings on the shipped package.

## Problem

The original large-import path read whole files into memory and rewrote whole manifests:

- JSON indexing reads the entire JSON file before chunking.
- Manifest appends read and rewrite the full manifest for each entry.
- Manifest reads load the whole manifest for every imported entry.

This undermines resumable large imports and can become memory-heavy and slow on real exports.

## Expected Fix

- Preserve resumable JSON indexing and checkpointing.
- Avoid native filesystem calls in parser code for WordPress.org review compatibility.
- Keep checkpointing and idempotency behavior intact.

## Local Work

- Kept resumable parser cursor state and top-level `entries` detection.
- Reworked JSON indexing to read through WP_Filesystem, then process from the saved byte offset in bounded in-memory chunks.
- Reworked JSONL manifest appends and reads to use WP_Filesystem only.
- Removed parser-level native stream operations and related PHPCS suppressions.
- Kept WP_Filesystem for directory creation, protection files, marker writes, upload movement, cleanup, and permission changes.

## Remaining Risk

WP_Filesystem does not expose seekable reads or atomic append, so the parser is now review-conservative rather than truly streaming: each JSON indexing request loads the JSON file before continuing from the saved cursor, and manifest appends rewrite the manifest. Very large Day One exports can still be memory-heavy.
