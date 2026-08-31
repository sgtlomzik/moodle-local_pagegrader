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
 * Tests for the event observers registered by local_pagegrader.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_pagegrader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pagegrader/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests that the observers in db/events.php are wired up and fire end to end.
 *
 * The other test class calls the callbacks directly; these tests go through the
 * real event system, so a broken db/events.php is caught as well.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_pagegrader_event_page_viewed
 * @covers     ::local_pagegrader_event_module_deleted
 */
final class observer_test extends \advanced_testcase {
    /**
     * Create a course with a graded page and an enrolled student.
     *
     * @param float $maxgrade Maximum grade to configure for the page.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} Course, page and student.
     */
    private function create_graded_page(float $maxgrade = 10.0): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Safety rules']);

        \local_pagegrader_coursemodule_edit_post_actions((object)[
            'modulename' => 'page',
            'coursemodule' => $page->cmid,
            'local_pagegrader_enable' => 1,
            'local_pagegrader_maxgrade' => $maxgrade,
            'name' => $page->name,
        ], $course);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        return [$course, $page, $student];
    }

    /**
     * Fetch the gradebook column the plugin owns for a page.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @return \grade_item|false
     */
    private function fetch_item(int $courseid, int $cmid) {
        return \grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $cmid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Both observers are registered for the events they handle.
     *
     * @param string $eventname Fully qualified event class name.
     * @param string $callback Expected observer callback.
     * @dataProvider observer_provider
     */
    public function test_the_observers_are_registered(string $eventname, string $callback): void {
        $this->resetAfterTest();

        $observers = \core\event\manager::get_all_observers();

        $this->assertArrayHasKey($eventname, $observers);

        $callbacks = array_map(static function ($observer) {
            return $observer->callable;
        }, $observers[$eventname]);

        $this->assertContains($callback, $callbacks);
    }

    /**
     * Data provider for {@see test_the_observers_are_registered()}.
     *
     * @return array[] Event name and the callback db/events.php maps it to.
     */
    public static function observer_provider(): array {
        return [
            'page viewed' => [
                '\mod_page\event\course_module_viewed',
                'local_pagegrader_event_page_viewed',
            ],
            'module deleted' => [
                '\core\event\course_module_deleted',
                'local_pagegrader_event_module_deleted',
            ],
        ];
    }

    /**
     * Triggering the real view event awards the grade through the observer.
     */
    public function test_triggering_the_view_event_awards_the_grade(): void {
        $this->resetAfterTest();

        [$course, $page, $student] = $this->create_graded_page(7.0);

        $this->setUser($student);
        \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ])->trigger();

        $item = $this->fetch_item($course->id, $page->cmid);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);

        $this->assertNotFalse($grade);
        $this->assertEquals(7.0, (float)$grade->finalgrade);
    }

    /**
     * Deleting the page removes its settings row and its gradebook column.
     */
    public function test_deleting_the_page_cleans_up(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $student] = $this->create_graded_page(5.0);

        $this->setUser($student);
        \mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ])->trigger();

        $this->assertNotFalse($this->fetch_item($course->id, $page->cmid));

        $this->setAdminUser();
        course_delete_module($page->cmid);

        $this->assertFalse($DB->record_exists('local_pagegrader', ['coursemoduleid' => $page->cmid]));
        $this->assertFalse($this->fetch_item($course->id, $page->cmid));
    }

    /**
     * Deleting another kind of activity leaves the page settings alone.
     */
    public function test_deleting_another_module_leaves_the_settings_alone(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_graded_page();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);

        \local_pagegrader_event_module_deleted(\core\event\course_module_deleted::create([
            'objectid' => $label->cmid,
            'context' => \context_course::instance($course->id),
            'courseid' => $course->id,
            'other' => [
                'modulename' => 'label',
                'instanceid' => $label->id,
                'name' => 'A label',
            ],
        ]));

        $this->assertTrue($DB->record_exists('local_pagegrader', ['coursemoduleid' => $page->cmid]));
        $this->assertNotFalse($this->fetch_item($course->id, $page->cmid));
    }

    /**
     * The guest account is never graded for viewing a page.
     */
    public function test_the_guest_user_is_not_graded(): void {
        $this->resetAfterTest();

        [$course, $page] = $this->create_graded_page();

        $this->setGuestUser();
        \local_pagegrader_event_page_viewed(\mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => \context_module::instance($page->cmid),
            'courseid' => $course->id,
        ]));

        $item = $this->fetch_item($course->id, $page->cmid);
        $this->assertFalse(\grade_grade::fetch(['itemid' => $item->id, 'userid' => guest_user()->id]));
    }

    /**
     * Someone who may not view the page is not graded for it either.
     */
    public function test_a_user_without_view_access_is_not_graded(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $page, $student] = $this->create_graded_page();
        $context = \context_module::instance($page->cmid);

        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/page:view', CAP_PROHIBIT, $studentroleid, $context->id, true);
        $this->assertFalse(has_capability('mod/page:view', $context, $student->id));

        $this->setUser($student);
        \local_pagegrader_event_page_viewed(\mod_page\event\course_module_viewed::create([
            'objectid' => $page->id,
            'context' => $context,
            'courseid' => $course->id,
        ]));

        $item = $this->fetch_item($course->id, $page->cmid);
        $this->assertFalse(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]));
    }
}
