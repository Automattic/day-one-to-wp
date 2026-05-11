# Prompt: Add a ZIP creation script

Source task: GitHub issue #15, "Add a ZIP creation script."
URL: https://github.com/Automattic/day-one-to-wp/issues/15

Add a project script that creates a WordPress plugin ZIP file for Day One Importer so maintainers can test the plugin locally. Consider using the existing `wp-scripts plugin-zip` command from `@wordpress/scripts`.

The result should make it straightforward for a developer to install dependencies and generate a ZIP from the repository root. The generated ZIP should contain the plugin files required for local WordPress installation and should avoid including development-only/private/generated files.

Update project documentation so developers know how to run the ZIP creation script. Verify the ZIP creation flow and existing checks before opening a pull request.
