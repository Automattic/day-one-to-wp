# Medium: Stream private media responses

Status: fixed locally

## Problem

The authenticated private media endpoint reads the full attachment file into a PHP string before sending it. Large images or concurrent requests can exhaust memory and make `admin-ajax.php` fragile.

## Expected Fix

- Serve files with bounded buffered reads.
- Preserve nonce, login, parent-post capability, MIME, and private-path checks.
- Keep PHPCS suppression narrow if low-level file streaming is needed.

## Local Work

- Replaced full-file private media reads with a bounded native read loop after nonce, capability, MIME, and private-path checks pass.
- Kept the streaming PHPCS suppressions narrow and attached to the specific native stream calls.

## Remaining Risk

The endpoint still runs through `admin-ajax.php`, so very large media responses consume PHP request workers. The memory profile is bounded now; offloading delivery outside PHP would require a larger architecture change.
