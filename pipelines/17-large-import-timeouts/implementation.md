# Implementation summary

Implemented issue #17 by reducing synchronous image-processing work during large imports.

## Changes

- Added `day_one_importer_prepare_long_running_import()` to request the admin memory limit and remove PHP's script time limit where the host permits it.
- Called the long-running import helper at import start and before each entry import.
- Scoped media sideload filters around Day One image sideloads:
  - `intermediate_image_sizes_advanced` returns an empty list;
  - `big_image_size_threshold` returns false.
- Added pure helper coverage for the importer image-size filter.
- Updated `README.md` and `readme.txt` to explain skipped generated image sub-sizes and remaining host timeout limitations.

## Verification

Passed:

```sh
find . -path './.git' -prune -o -path './sample' -prune -o -path './prompt-images' -prune -o -path './pipelines' -prune -o -path './vendor' -prune -o -path './node_modules' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/pure-helper-tests.php
```

Attempted wp-env smoke verification, but `wp-env start` could not bind port 8888 because another local service/container was already using it. This is an environment conflict rather than a test failure in the plugin code.
