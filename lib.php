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
 * Course module form callbacks and event handlers for local_pagegrader.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Default number of points awarded for viewing a page.
 */
const LOCAL_PAGEGRADER_DEFAULT_MAXGRADE = 10;

/**
 * Add the view-grading settings to the page activity edit form.
 *
 * @param moodleform_mod $formwrapper The activity settings form.
 * @param MoodleQuickForm $mform The form the elements are added to.
 */
function local_pagegrader_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    $current = $formwrapper->get_current();

    if (($current->modulename ?? '') !== 'page') {
        return;
    }

    $mform->addElement('header', 'local_pagegrader_header',
        get_string('settings_header', 'local_pagegrader'));

    $mform->addElement('advcheckbox', 'local_pagegrader_enable',
        get_string('enable_grading', 'local_pagegrader'));
    $mform->setType('local_pagegrader_enable', PARAM_INT);
    $mform->addHelpButton('local_pagegrader_enable', 'enable_grading', 'local_pagegrader');

    $mform->addElement('text', 'local_pagegrader_maxgrade',
        get_string('max_grade', 'local_pagegrader'), ['size' => '5']);
    $mform->setType('local_pagegrader_maxgrade', PARAM_FLOAT);
    $mform->hideIf('local_pagegrader_maxgrade', 'local_pagegrader_enable', 'notchecked');

    if (!empty($current->coursemodule)) {
        $record = $DB->get_record('local_pagegrader',
            ['coursemoduleid' => $current->coursemodule]);
        if ($record) {
            $mform->setDefault('local_pagegrader_enable', $record->enablegrading);
            $mform->setDefault('local_pagegrader_maxgrade', $record->maxgrade);
            return;
        }
    }

    $mform->setDefault('local_pagegrader_maxgrade', LOCAL_PAGEGRADER_DEFAULT_MAXGRADE);
}

/**
 * Validate the view-grading settings submitted with the activity form.
 *
 * @param array $data Submitted form data.
 * @param array $files Submitted files.
 * @return array Validation errors, keyed by form element name.
 */
function local_pagegrader_coursemodule_validation($data, $files) {
    if (!is_array($data)) {
        return [];
    }

    $errors = [];

    if (!empty($data['local_pagegrader_enable'])) {
        $grade = isset($data['local_pagegrader_maxgrade']) ? (float)$data['local_pagegrader_maxgrade'] : 0;
        if ($grade <= 0) {
            $errors['local_pagegrader_maxgrade'] = get_string('error_maxgrade', 'local_pagegrader');
        }
    }

    return $errors;
}

/**
 * Store the settings and create, update or remove the gradebook column.
 *
 * @param stdClass $data Submitted module data.
 * @param stdClass $course The course the module belongs to.
 * @return stdClass The unmodified module data.
 */
function local_pagegrader_coursemodule_edit_post_actions($data, $course) {
    global $DB, $CFG;

    if (($data->modulename ?? '') !== 'page') {
        return $data;
    }

    require_once($CFG->libdir . '/gradelib.php');

    $cmid = $data->coursemodule;
    $enablegrading = !empty($data->local_pagegrader_enable) ? 1 : 0;
    $maxgrade = !empty($data->local_pagegrader_maxgrade) ? (float)$data->local_pagegrader_maxgrade : 0.0;

    $record = $DB->get_record('local_pagegrader', ['coursemoduleid' => $cmid]);
    $oldmaxgrade = $record ? (float)$record->maxgrade : null;

    if ($record) {
        $record->enablegrading = $enablegrading;
        $record->maxgrade = $maxgrade;
        $DB->update_record('local_pagegrader', $record);
    } else {
        $record = new stdClass();
        $record->coursemoduleid = $cmid;
        $record->enablegrading = $enablegrading;
        $record->maxgrade = $maxgrade;
        $DB->insert_record('local_pagegrader', $record);
    }

    if (!$enablegrading) {
        // Grading was switched off: drop the column so it stops showing in the gradebook.
        $gradeitem = grade_item::fetch([
            'itemtype' => 'local',
            'itemmodule' => 'pagegrader',
            'iteminstance' => $cmid,
            'courseid' => $course->id,
        ]);
        if ($gradeitem) {
            $gradeitem->delete();
        }

        return $data;
    }

    $itemdetails = [
        'itemname' => $data->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $maxgrade,
        'grademin' => 0,
        'hidden' => 0,
        'locked' => 0,
        'display' => 1,
        'overridelocked' => 1,
    ];

    grade_update('local_pagegrader', $course->id, 'local', 'pagegrader', $cmid, 0, null, $itemdetails);

    if ($oldmaxgrade !== null && abs($oldmaxgrade - $maxgrade) > 0.00001) {
        local_pagegrader_sync_grades($course->id, $cmid, $maxgrade);
    }

    return $data;
}

/**
 * Re-scale the grades already awarded for a page after its maximum was changed.
 *
 * Everyone who viewed the page earned the full amount, so every existing grade is
 * rewritten to the new maximum rather than being scaled proportionally. Any manual
 * override in the gradebook is cleared in the process, because the plugin owns the
 * column and would otherwise leave stale values behind.
 *
 * @param int $courseid Course ID.
 * @param int $cmid Course module ID of the page.
 * @param float $newmaxgrade The new maximum grade.
 */
function local_pagegrader_sync_grades($courseid, $cmid, $newmaxgrade) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $gradeitem = grade_item::fetch([
        'itemtype' => 'local',
        'itemmodule' => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid' => $courseid,
    ]);

    if (!$gradeitem) {
        return;
    }

    $gradeitem->grademax = $newmaxgrade;
    $gradeitem->update();

    $grades = grade_grade::fetch_all(['itemid' => $gradeitem->id]);
    if ($grades) {
        foreach ($grades as $grade) {
            if (is_null($grade->finalgrade) && is_null($grade->rawgrade)) {
                continue;
            }
            $grade->rawgrade = $newmaxgrade;
            $grade->finalgrade = $newmaxgrade;
            $grade->overridden = 0;
            $grade->update();
        }
    }

    grade_regrade_final_grades($courseid);
}

/**
 * Award the configured points the first time a learner views a graded page.
 *
 * @param \mod_page\event\course_module_viewed $event The view event.
 */
function local_pagegrader_event_page_viewed($event) {
    global $DB, $CFG;

    $cmid = $event->contextinstanceid;
    $userid = $event->userid;
    $courseid = $event->courseid;
    $modulecontext = $event->get_context();

    $settings = $DB->get_record('local_pagegrader', [
        'coursemoduleid' => $cmid,
        'enablegrading' => 1,
    ]);
    if (!$settings) {
        return;
    }

    if (empty($userid) || isguestuser($userid)) {
        return;
    }

    if (!has_capability('mod/page:view', $modulecontext, $userid)) {
        return;
    }

    // Teachers and managers browse the page while building the course; they are
    // not the audience for the grade.
    if (has_any_capability(['moodle/grade:edit', 'moodle/course:manageactivities'], $modulecontext, $userid)) {
        return;
    }

    require_once($CFG->libdir . '/gradelib.php');

    $gradeitem = grade_item::fetch([
        'itemtype' => 'local',
        'itemmodule' => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid' => $courseid,
    ]);

    if (!$gradeitem) {
        // The settings row exists but the column does not, which normally means the
        // page was restored without its gradebook item. Re-saving the page settings
        // recreates it.
        debugging("local_pagegrader: no grade item for course module {$cmid}; re-save the page settings.",
            DEBUG_DEVELOPER);
        return;
    }

    $current = grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);

    $currentgrade = null;
    if ($current) {
        if (!is_null($current->finalgrade)) {
            $currentgrade = (float)$current->finalgrade;
        } else if (!is_null($current->rawgrade)) {
            $currentgrade = (float)$current->rawgrade;
        }
    }

    if (!is_null($currentgrade) && $currentgrade >= (float)$settings->maxgrade) {
        // Already at the maximum: repeated views must not raise the grade further.
        return;
    }

    $gradeitem->update_final_grade($userid, (float)$settings->maxgrade, 'local_pagegrader');
}

/**
 * Clean up settings and the gradebook column when a page is deleted from a course.
 *
 * @param \core\event\course_module_deleted $event The deletion event.
 */
function local_pagegrader_event_module_deleted($event) {
    global $DB, $CFG;

    if (($event->other['modulename'] ?? '') !== 'page') {
        return;
    }

    $cmid = $event->objectid;
    $DB->delete_records('local_pagegrader', ['coursemoduleid' => $cmid]);

    require_once($CFG->libdir . '/gradelib.php');

    $gradeitem = grade_item::fetch([
        'itemtype' => 'local',
        'itemmodule' => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid' => $event->courseid,
    ]);
    if ($gradeitem) {
        $gradeitem->delete();
    }
}
