# Documentation summary

Updated documentation for large import behavior.

## README.md

- Explains that the importer asks for long-running admin request limits where the host allows it.
- Explains that generated image sub-sizes are skipped during Day One media sideloads to reduce timeout risk.
- Notes that imported posts use the original uploaded image file.
- Advises regenerating thumbnails after import if those sizes are needed later.
- Clarifies that very large exports may still hit host-enforced limits and can be resumed or split.

## readme.txt

- Adds the skipped generated sub-size behavior to the plugin description.
- Adds thumbnail regeneration guidance to the media FAQ.
