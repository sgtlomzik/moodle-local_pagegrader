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
 * Event observer definitions for local_pagegrader.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The callbacks are plain functions in lib.php rather than autoloaded classes,
// so the file holding them has to be named explicitly.
$observers = [
    [
        'eventname' => '\mod_page\event\course_module_viewed',
        'callback' => 'local_pagegrader_event_page_viewed',
        'includefile' => '/local/pagegrader/lib.php',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => 'local_pagegrader_event_module_deleted',
        'includefile' => '/local/pagegrader/lib.php',
    ],
];
