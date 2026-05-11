# Implementation plan: Large import timeouts

1. Add a shared helper that prepares a long-running import request by asking WordPress for the admin memory limit and asking PHP to remove the script time limit when possible.
2. Call the helper at the start of `Day_One_Importer_Runner::run_upload()` and before each entry import so rerun/resume loops get the best available request budget.
3. Scope WordPress image-generation filters around Day One media sideloads:
   - return an empty intermediate image size list;
   - disable big-image scaling.
4. Keep filters in a `try/finally` block so they are removed even when sideloading fails.
5. Add pure helper coverage for the image-size filter callback.
6. Update README limitations/troubleshooting to explain skipped generated sub-sizes and remaining host timeout limitations.
7. Run PHP lint, pure helper tests, and focused ZIP/package checks are not needed for this issue.
