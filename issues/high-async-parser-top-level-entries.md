# High: Async parser can index nested entries arrays

Status: fixed locally

## Problem

The async JSON parser scans for the first key named `entries` anywhere in the JSON stream. A nested object with an `entries` key before the real top-level Day One `entries` array can cause the importer to index and import the wrong objects.

## Expected Fix

- Only accept a top-level `entries` array in async indexing.
- Ignore nested keys named `entries`.
- Add regression coverage with a nested decoy `entries` array before the real top-level array.

## Local Work

- Updated the async parser to track top-level JSON object state while searching for the `entries` key.
- Removed the pure-helper harness; this still needs WordPress-level regression coverage with a nested decoy `entries` array before the real top-level array.
