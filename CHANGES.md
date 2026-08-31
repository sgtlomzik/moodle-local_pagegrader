# Changelog

All notable changes to this plugin are documented in this file.

## [1.4.1] - 2026-08-31

### Added
- Privacy provider tests confirming the plugin is a compliant null provider and
  that its own table carries configuration only, with no user column.
- Observer tests that go through the real event system rather than calling the
  callbacks directly, so a broken `db/events.php` is caught: both observers are
  registered, a page view awards the grade, deleting the page removes its
  settings row and gradebook column, and neither the guest account nor a user
  without `mod/page:view` is graded.
- Tests for `xmldb_local_pagegrader_uninstall`, covering the removal of every
  gradebook column the plugin owns while leaving other components' columns
  alone.
- Tests for `local_pagegrader_coursemodule_standard_elements`, the default and
  stored maximum grade, the validation of the maximum grade, storing a page with
  grading switched off, re-scaling a page that has no gradebook column, and
  renaming the gradebook column with the page.

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
