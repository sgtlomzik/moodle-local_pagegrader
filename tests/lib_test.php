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

namespace local_pagegrader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pagegrader/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for local_pagegrader callbacks in lib.php.
 *
 * @package   local_pagegrader
 * @copyright 2026 SgtLomzik <lomzike@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {

    /**
     * Helper: create course, page module and enrolled student.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass}
     */
    private function create_page_fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Page grader test page',
        ]);

        $student = $generator->create_user();
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $generator->enrol_user($student->id, $course->id, $studentroleid);

        return [$course, $page, $student];
    }

    public function test_validation_rejects_non_positive_grade_when_enabled(): void {
        $this->resetAfterTest();

        $errors = \local_pagegrader_coursemodule_validation([
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 0,
        ], []);

        $this->assertArrayHasKey('local_pagegrader_maxgrade', $errors);
    }

    public function test_validation_accepts_non_array_payload(): void {
        $this->resetAfterTest();

        $errors = \local_pagegrader_coursemodule_validation('not-an-array', []);
        $this->assertSame([], $errors);
    }

    public function test_edit_post_actions_creates_and_deletes_grade_item(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        $data = (object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 12.5,
            'name' => $page->name,
        ];

        \local_pagegrader_coursemodule_edit_post_actions($data, $course);

        $record = $DB->get_record('local_pagegrader', ['coursemoduleid' => $page->cmid]);
        $this->assertNotFalse($record);
        $this->assertEquals(1, (int)$record->enablegrading);
        $this->assertEquals(12.5, (float)$record->maxgrade);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertNotEmpty($item);
        $this->assertEquals(12.5, (float)$item->grademax);

        $data->local_pagegrader_enable = 0;
        \local_pagegrader_coursemodule_edit_post_actions($data, $course);

        $deleteditem = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertFalse($deleteditem);
    }

    public function test_view_event_awards_grade_to_student(): void {
        $this->resetAfterTest();
        [$course, $page, $student] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 9,
            'name' => $page->name,
        ], $course);

        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertNotEmpty($item);

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertNotFalse($grade);
        $this->assertEquals(9.0, (float)$grade->finalgrade);
    }

    public function test_changing_maxgrade_syncs_existing_finalgrade(): void {
        $this->resetAfterTest();
        [$course, $page, $student] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 10,
            'name' => $page->name,
        ], $course);

        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 5,
            'name' => $page->name,
        ], $course);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);

        $this->assertEquals(5.0, (float)$item->grademax);
        $this->assertEquals(5.0, (float)$grade->finalgrade);
    }

    public function test_repeated_view_does_not_raise_grade_above_max(): void {
        $this->resetAfterTest();
        [$course, $page, $student] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 4,
            'name' => $page->name,
        ], $course);

        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);
        \local_pagegrader_event_page_viewed($event);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);

        $this->assertEquals(4.0, (float)$grade->finalgrade);
    }

    public function test_edit_post_actions_ignores_non_page_module(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        $before = $DB->get_record('local_pagegrader', ['coursemoduleid' => $page->cmid]);

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'label',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 7,
            'name' => $page->name,
        ], $course);

        $after = $DB->get_record('local_pagegrader', ['coursemoduleid' => $page->cmid]);
        if ($before === false) {
            $this->assertFalse($after);
        } else {
            $this->assertNotFalse($after);
            $this->assertEquals((int)$before->enablegrading, (int)$after->enablegrading);
            $this->assertEquals((float)$before->maxgrade, (float)$after->maxgrade);
        }
    }

    public function test_view_event_does_not_grade_when_disabled(): void {
        $this->resetAfterTest();
        [$course, $page, $student] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 0,
            'local_pagegrader_maxgrade' => 10,
            'name' => $page->name,
        ], $course);

        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertFalse($item);
    }

    public function test_view_event_does_not_grade_editing_teacher(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 6,
            'name' => $page->name,
        ], $course);

        $teacher = $this->getDataGenerator()->create_user();
        $teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $teacherroleid);

        $this->setUser($teacher);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertNotFalse($item);

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $teacher->id]);
        $this->assertFalse($grade);
    }

    public function test_view_event_skips_when_grade_item_missing(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $page, $student] = $this->create_page_fixture();

        $settings = $DB->get_record('local_pagegrader', ['coursemoduleid' => $page->cmid]);
        if ($settings) {
            $settings->enablegrading = 1;
            $settings->maxgrade = 8;
            $DB->update_record('local_pagegrader', $settings);
        } else {
            $DB->insert_record('local_pagegrader', (object)[
                'coursemoduleid' => $page->cmid,
                'enablegrading' => 1,
                'maxgrade' => 8,
            ]);
        }

        $existingitem = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        if ($existingitem) {
            $existingitem->delete();
        }

        $this->setUser($student);
        $event = \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]);
        \local_pagegrader_event_page_viewed($event);
        $this->assertDebuggingCalled();

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertFalse($item);
    }
}
