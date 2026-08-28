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
 * English strings for local_pagegrader.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['enable_grading'] = 'Grade viewing of this page';
$string['enable_grading_help'] = 'When enabled, a gradebook column is created for this page and every learner who opens it is awarded the full number of points. The points are given once: opening the page again does not change the grade.

Turning this setting off again deletes the gradebook column and the grades stored in it.';
$string['error_maxgrade'] = 'Grade must be a number greater than zero.';
$string['max_grade'] = 'Points for viewing';
$string['pluginname'] = 'Page view grader';
$string['privacy:metadata'] = 'The Page view grader plugin stores only per-activity grading settings. The grades it awards are stored by the core gradebook.';
$string['settings_header'] = 'Automatic view grading';
