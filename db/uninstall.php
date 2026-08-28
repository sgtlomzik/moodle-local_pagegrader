<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Uninstallation steps for local_pagegrader.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Remove the gradebook columns owned by this plugin.
 *
 * @return bool Always true.
 */
function xmldb_local_pagegrader_uninstall() {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $items = $DB->get_records('grade_items', [
        'itemtype' => 'local',
        'itemmodule' => 'pagegrader',
    ]);

    foreach ($items as $item) {
        grade_update(
            'local_pagegrader', $item->courseid,
            'local', 'pagegrader', $item->iteminstance,
            0, null, ['deleted' => 1]
        );
    }

    return true;
}
