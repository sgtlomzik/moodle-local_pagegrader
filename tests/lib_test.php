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
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for local_pagegrader callbacks in lib.php.
 *
 * @package   local_pagegrader
 * @copyright 2026 SgtLomzik <lomzike@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    ::local_pagegrader_coursemodule_standard_elements
 * @covers    ::local_pagegrader_coursemodule_validation
 * @covers    ::local_pagegrader_coursemodule_edit_post_actions
 * @covers    ::local_pagegrader_sync_grades
 * @covers    ::local_pagegrader_event_page_viewed
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

    /**
     * Build a stand-in for the activity settings form the callback is handed.
     *
     * @param \stdClass $current The module data the form is editing.
     * @return \moodleform_mod
     */
    private function make_form_wrapper(\stdClass $current): \moodleform_mod {
        $wrapper = $this->createStub(\moodleform_mod::class);
        $wrapper->method('get_current')->willReturn($current);

        return $wrapper;
    }

    /**
     * Validation rejects non positive grade when enabled.
     */
    public function test_validation_rejects_non_positive_grade_when_enabled(): void {
        $this->resetAfterTest();

        $errors = \local_pagegrader_coursemodule_validation([
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 0,
        ], []);

        $this->assertArrayHasKey('local_pagegrader_maxgrade', $errors);
    }

    /**
     * Validation accepts non array payload.
     */
    public function test_validation_accepts_non_array_payload(): void {
        $this->resetAfterTest();

        $errors = \local_pagegrader_coursemodule_validation('not-an-array', []);
        $this->assertSame([], $errors);
    }

    /**
     * Edit post actions creates and deletes grade item.
     */
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

    /**
     * View event awards grade to student.
     */
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

    /**
     * Changing maxgrade syncs existing finalgrade.
     */
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

    /**
     * Repeated view does not raise grade above max.
     */
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

    /**
     * Edit post actions ignores non page module.
     */
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

    /**
     * View event does not grade when disabled.
     */
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

    /**
     * View event does not grade editing teacher.
     */
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

    /**
     * View event skips when grade item missing.
     */
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

    /**
     * Validation accepts a positive grade when enabled.
     */
    public function test_validation_accepts_a_positive_grade_when_enabled(): void {
        $this->resetAfterTest();

        $this->assertSame([], \local_pagegrader_coursemodule_validation([
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 0.5,
        ], []));
    }

    /**
     * Validation ignores the grade while grading is switched off.
     *
     * @param array $data Submitted form data.
     * @dataProvider ignored_grade_provider
     */
    public function test_validation_ignores_the_grade_while_disabled(array $data): void {
        $this->resetAfterTest();

        $this->assertSame([], \local_pagegrader_coursemodule_validation($data, []));
    }

    /**
     * Data provider for {@see test_validation_ignores_the_grade_while_disabled()}.
     *
     * @return array[] Submissions where the maximum grade does not matter.
     */
    public static function ignored_grade_provider(): array {
        return [
            'unchecked with a zero grade' => [[
                'local_pagegrader_enable' => 0,
                'local_pagegrader_maxgrade' => 0,
            ]],
            'unchecked with a negative grade' => [[
                'local_pagegrader_enable' => 0,
                'local_pagegrader_maxgrade' => -3,
            ]],
            'no elements submitted' => [[]],
        ];
    }

    /**
     * Validation rejects a negative grade when enabled.
     */
    public function test_validation_rejects_a_negative_grade_when_enabled(): void {
        $this->resetAfterTest();

        $errors = \local_pagegrader_coursemodule_validation([
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => -1,
        ], []);

        $this->assertArrayHasKey('local_pagegrader_maxgrade', $errors);
        $this->assertSame(get_string('error_maxgrade', 'local_pagegrader'), $errors['local_pagegrader_maxgrade']);
    }

    /**
     * Standard elements add the grading settings to a page form.
     */
    public function test_standard_elements_are_added_to_a_page_form(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [, $page] = $this->create_page_fixture();

        // Creating the module already ran the edit callback, so clear the row to
        // get the state of a page that has never been through these settings.
        $DB->delete_records('local_pagegrader', ['coursemoduleid' => $page->cmid]);

        $mform = new \MoodleQuickForm('modedit', 'post', '');
        \local_pagegrader_coursemodule_standard_elements(
            $this->make_form_wrapper((object)['modulename' => 'page', 'coursemodule' => $page->cmid]),
            $mform
        );

        $this->assertTrue($mform->elementExists('local_pagegrader_header'));
        $this->assertTrue($mform->elementExists('local_pagegrader_enable'));
        $this->assertTrue($mform->elementExists('local_pagegrader_maxgrade'));

        // With no settings row yet, the documented default is offered.
        $this->assertEquals(
            LOCAL_PAGEGRADER_DEFAULT_MAXGRADE,
            $mform->getElementValue('local_pagegrader_maxgrade')
        );
    }

    /**
     * Standard elements leave other kinds of activity untouched.
     */
    public function test_standard_elements_are_not_added_to_other_modules(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new \MoodleQuickForm('modedit', 'post', '');
        \local_pagegrader_coursemodule_standard_elements(
            $this->make_form_wrapper((object)['modulename' => 'label', 'coursemodule' => 0]),
            $mform
        );

        $this->assertFalse($mform->elementExists('local_pagegrader_header'));
        $this->assertFalse($mform->elementExists('local_pagegrader_enable'));
    }

    /**
     * Standard elements show the settings already stored for a page.
     */
    public function test_standard_elements_show_the_stored_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 3.5,
            'name' => $page->name,
        ], $course);

        $mform = new \MoodleQuickForm('modedit', 'post', '');
        \local_pagegrader_coursemodule_standard_elements(
            $this->make_form_wrapper((object)['modulename' => 'page', 'coursemodule' => $page->cmid]),
            $mform
        );

        $this->assertEquals(1, $mform->getElementValue('local_pagegrader_enable'));
        $this->assertEquals(3.5, (float)$mform->getElementValue('local_pagegrader_maxgrade'));
    }

    /**
     * Edit post actions accept a page that has never been graded.
     */
    public function test_edit_post_actions_stores_a_disabled_page(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 0,
            'local_pagegrader_maxgrade' => 10,
            'name' => $page->name,
        ], $course);

        $record = $DB->get_record('local_pagegrader', ['coursemoduleid' => $page->cmid]);
        $this->assertNotFalse($record);
        $this->assertEquals(0, (int)$record->enablegrading);

        // Nothing is added to the gradebook for a page that is not graded.
        $this->assertFalse(\grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]));
    }

    /**
     * Sync grades does nothing when the page has no gradebook column.
     */
    public function test_sync_grades_without_a_grade_item(): void {
        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        // Must not raise anything: the page simply has no column to re-scale.
        \local_pagegrader_sync_grades($course->id, $page->cmid, 5.0);

        $this->assertFalse(\grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]));
    }

    /**
     * Renaming the page renames the gradebook column.
     */
    public function test_edit_post_actions_renames_the_grade_item(): void {
        $this->resetAfterTest();
        [$course, $page] = $this->create_page_fixture();

        $data = (object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => 10,
            'name' => $page->name,
        ];
        \local_pagegrader_coursemodule_edit_post_actions($data, $course);

        $data->name = 'Renamed page';
        \local_pagegrader_coursemodule_edit_post_actions($data, $course);

        $item = \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $page->cmid,
            'courseid' => $course->id,
        ]);
        $this->assertSame('Renamed page', $item->itemname);
    }
}
