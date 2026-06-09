# Medium: Avoid unbounded UUID maps in persisted job options

Status: fixed locally

## Problem

Async indexing stores `seen_uuids` in the serialized job option. Large exports can produce very large option payloads and expensive option writes. The manifest marker directory already exists and can carry duplicate detection/idempotency without bloating the job record.

## Expected Follow-Up

- Stop persisting the full UUID map in job options for large imports.
- Use marker files or another bounded index for duplicate detection.
- Preserve duplicate UUID warnings and idempotency behavior.

## Local Work

- Kept UUID duplicate detection in memory only for the current request.
- Relied on existing manifest marker files across resumptions.
- Cleared persisted `seen_uuids` state during async indexing checkpoints.
