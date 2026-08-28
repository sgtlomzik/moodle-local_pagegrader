# Page view grader for Moodle

[![Moodle plugin CI](https://github.com/sgtlomzik/moodle-local_pagegrader/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/sgtlomzik/moodle-local_pagegrader/actions/workflows/moodle-ci.yml)

Awards a grade automatically when a learner opens a **Page** activity.

Moodle's Page activity has no grading of its own. This plugin adds a small section to the Page
settings form; once grading is switched on there, the plugin creates a gradebook column for that
page and gives every learner the full number of points the first time they view it. It is meant
for courses where simply reading a document is the thing being tracked — policies, instructions,
safety notices and similar material.

## Requirements

- Moodle 4.5 (LTS) or later.
- The core **Page** activity module (`mod_page`) enabled.

## Installation

### From the ZIP file

1. Download the ZIP of this repository.
2. Go to **Site administration → Plugins → Install plugins** and upload the ZIP.
3. Follow the on-screen upgrade steps.

### From Git

```bash
cd /path/to/moodle
git clone https://github.com/sgtlomzik/moodle-local_pagegrader.git local/pagegrader
```

Then visit **Site administration → Notifications** (or run `php admin/cli/upgrade.php`) to
complete the installation.

There are no site-wide settings; everything is configured per page.

## Usage

1. Add or edit a **Page** activity in a course.
2. Open the **Automatic view grading** section of the settings form.
3. Tick **Grade viewing of this page** and enter the number of points to award.
4. Save. A gradebook column named after the page appears in the course gradebook.

From then on, the first time a learner opens the page they receive the full number of points.
Viewing it again does not change the grade.

### Behaviour worth knowing

- **Teachers are not graded.** Anyone with `moodle/grade:edit` or
  `moodle/course:manageactivities` in the page's context is skipped, as are guests, so building
  and reviewing a course does not fill the gradebook with staff rows.
- **Changing the points re-writes existing grades.** Because every learner earns the same full
  amount, changing the maximum sets all previously awarded grades in that column to the new
  maximum instead of scaling them. Manual overrides in that column are cleared.
- **Turning grading off deletes the column.** Un-ticking the checkbox removes the gradebook
  column for that page, along with the grades stored in it. Deleting the page does the same.
- **Uninstalling the plugin** removes all gradebook columns it created.

## Privacy

The plugin's own database table stores per-activity configuration only — a course module id, an
on/off flag and the number of points. No personal data is stored by the plugin; the grades it
awards belong to, and are exported and deleted by, the core gradebook.

## Bug tracker

Please report issues at
<https://github.com/sgtlomzik/moodle-local_pagegrader/issues>.

## License

2026 SgtLomzik <lomzike@gmail.com>

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not,
see <https://www.gnu.org/licenses/>.
