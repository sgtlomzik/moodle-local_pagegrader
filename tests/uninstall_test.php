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
 * Tests for the local_pagegrader uninstallation steps.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_pagegrader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pagegrader/lib.php');
require_once($CFG->dirroot . '/local/pagegrader/db/uninstall.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests that uninstalling takes the plugin's gradebook columns with it.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_pagegrader_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    /**
     * Create a course with a graded page.
     *
     * @param float $maxgrade Maximum grade to configure.
     * @return array{0:\stdClass,1:\stdClass} The course and the page.
     */
    private function create_graded_page(float $maxgrade = 10.0): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Graded page']);

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => $maxgrade,
            'name' => $page->name,
        ], $course);

        return [$course, $page];
    }

    /**
     * Uninstalling removes every gradebook column the plugin owns.
     */
    public function test_uninstall_removes_the_plugin_grade_items(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$courseone, $pageone] = $this->create_graded_page(8.0);
        [$coursetwo, $pagetwo] = $this->create_graded_page(4.0);

        $this->assertEquals(2, $DB->count_records('grade_items', [
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
        ]));

        $this->assertTrue(\xmldb_local_pagegrader_uninstall());

        $this->assertFalse(\grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $pageone->cmid,
            'courseid' => $courseone->id,
        ]));
        $this->assertFalse(\grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $pagetwo->cmid,
            'courseid' => $coursetwo->id,
        ]));
    }

    /**
     * Uninstalling leaves gradebook columns owned by other components alone.
     */
    public function test_uninstall_leaves_other_grade_items_alone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_graded_page();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $this->assertTrue(\xmldb_local_pagegrader_uninstall());

        $this->assertNotFalse(\grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $course->id,
        ]));
        $this->assertNotFalse(\grade_item::fetch_course_item($course->id));
    }

    /**
     * Uninstalling a site that never used the plugin is a no-op.
     */
    public function test_uninstall_without_any_grade_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_course();

        $this->assertTrue(\xmldb_local_pagegrader_uninstall());
    }
}
