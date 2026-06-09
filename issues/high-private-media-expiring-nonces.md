# High: Do not store expiring private media nonces in post content

Status: fixed locally

## Risk

Imported image block markup stored `admin-ajax.php` media URLs with a user/time-bound nonce. Those saved URLs expire and can fail for other authorized users, making imported private media disappear even when permissions are valid.

## Local Fix

- Imported Day One image blocks now store stable nonce-less private media endpoint URLs.
- Rendered post content injects a fresh nonce for each Day One image immediately before output.
- The private media endpoint still requires a valid nonce and a parent-post/attachment capability check.
- The wp-env smoke test now asserts stored content omits `nonce=` while rendered content receives fresh media nonces.

