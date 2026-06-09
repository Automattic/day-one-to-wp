# Comparison: WordPress Importer Patterns

## References

- Human Made WordPress Importer rewrite: https://github.com/humanmade/WordPress-Importer
- Canonical WordPress importer: https://github.com/WordPress/wordpress-importer

## What They Do Better

- Human Made's rewrite separates parser, import controller, loggers, UI, and WP-CLI command surfaces. That makes the importer usable outside one admin request path and gives long imports a better operational interface.
- Human Made uses `XMLReader` for WXR parsing. That is a real streaming parser and is the right taste for large imports when the file API allows it.
- Human Made makes idempotency/remapping explicit with mapping tables for posts, users, terms, comments, URLs, and deferred remaps.
- The canonical WordPress importer has real WordPress test surfaces (`phpunit` and `e2e` folders) instead of a large fake-WordPress unit harness.
- Both importers treat attachments as a distinct import concern, with dedicated handling rather than blending media resolution into post creation.

## Where This Importer Is Stronger

- This importer is narrower: Day One ZIP/JSON/private posts/private media only. That makes stronger security defaults practical.
- ZIP preflight and extraction budgets are stricter than the general WXR importers.
- Async job state and checkpointing are more explicit than the legacy canonical importer.
- Imported private media is served through an authenticated endpoint instead of raw public attachment URLs.

## Architecture Gaps To Keep

- Add a WP-CLI import command. It would match Human Made's operational model and make large imports easier to run, retry, and observe.
- Keep the pure-helper harness removed. Regression confidence should come from WordPress integration tests and fixture imports.
- Add wp-env coverage for top-level `entries` parsing, ZIP budget rejection, private media authorization, and reruns.
- Consider a parser abstraction so the WP_Filesystem-backed JSON reader is isolated from import behavior. If a reviewer-safe streaming adapter becomes available later, only that adapter should change.
- Keep idempotency metadata centralized. Human Made's explicit mapping/remap state is easier to reason about than scattered post/meta checks.

## Current Tradeoff

For plugin-review conservatism, extracted Day One JSON and JSONL manifests now use WP_Filesystem only, even though this means whole-file reads and manifest rewrites. Private media responses still use narrow native stream exceptions because they are HTTP response streaming, not WordPress-owned filesystem mutation.
