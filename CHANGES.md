# Changelog

All notable changes to this plugin are documented in this file.

## [1.4.0] - 2026-08-28

### Added
- GPLv3 boilerplate, `@package`/`@copyright`/`@license` docblocks on every file and a `COPYING`
  file with the licence text.
- Function-level PHPDoc for all callbacks and event handlers.
- Help text for the "Grade viewing of this page" setting, spelling out that the points are
  awarded once and that switching grading off deletes the column.
- `db/upgrade.php` and Moodle plugin CI workflow.
- `$plugin->supported` declaration.

### Changed
- All source comments translated from Russian to English, as required for contributed plugins.
- Minimum requirement raised to Moodle 4.5 (LTS); older releases are out of support upstream.
- README rewritten to document the grading behaviour, including the effects of changing the
  maximum grade and of disabling grading.

### Fixed
- Removed four `error_log()` calls that wrote Russian-language messages — including a learner's
  user id on every graded page view — into the web server error log. The one case worth
  reporting (a settings row with no gradebook column) now goes through `debugging()`.
- Guests and requests with no user id are no longer considered for grading.
- `gradelib.php` is required once at the top of the post-save callback rather than in one branch,
  so `grade_item` is always defined when the callback deletes a column.
- `modulename` is read defensively in both the form and post-save callbacks, avoiding a PHP
  warning when a module form submits without it.
