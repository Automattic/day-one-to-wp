# Manual verification plan

1. Install the plugin on a local WordPress site with `WP_DEBUG` enabled.
2. Activate the plugin and confirm **Tools → Import → Day One** exists.
3. Import `sample/05-07-2026_1-48-PM.zip`.
4. Confirm the results screen is privacy-safe and reports counts for journal JSON files, entries, posts, tags, and media.
5. Inspect several imported posts:
   - Status is `private`.
   - Date matches the Day One `creationDate`.
   - Text is readable, headings/paragraphs are preserved sufficiently, and shortcode-like text remains literal.
   - Tags are assigned where the source entry has tags.
   - Photos are attached and appended in order when present.
6. Re-import the same ZIP and confirm no duplicate posts are created; complete entries are skipped.
7. Simulate an incomplete import by changing `_day_one_import_complete` to `0` on one imported post, then re-import and confirm it is resumed without a duplicate.
8. Test invalid inputs:
   - Non-ZIP upload.
   - ZIP with malformed JSON.
   - ZIP without a JSON file containing `entries`.
   - Entry missing `uuid`.
   - Entry with invalid date.
   - Entry with missing media file.
   - Entry with unsupported media extension.
9. Confirm temporary extraction files are removed after success and failure.
10. Confirm no full private journal text is displayed in admin notices or plugin-created logs.
11. Confirm frontend visibility: imported posts are visible only to users who can read private posts.
12. Review media URL exposure on the target hosting environment and communicate any direct-URL media privacy implications to the site owner.
