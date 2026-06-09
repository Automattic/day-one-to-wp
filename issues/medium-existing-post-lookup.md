# Medium: Optimize existing imported post lookup

Status: fixed locally

## Problem

Existing imported post lookup fetches all posts across all statuses and scans `_day_one_uuid` in PHP for every imported entry. Rerunning a large export on a large site can be very slow.

## Expected Fix

- Query by `_day_one_uuid` and `_day_one_source` with a bounded meta query.
- Preserve duplicate-warning behavior when more than one imported post exists for a UUID.

## Local Work

- Replaced the full post scan with a bounded two-result meta query for Day One UUID/source metadata.
