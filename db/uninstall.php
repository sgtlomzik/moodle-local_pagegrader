<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_pagegrader_uninstall() {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $items = $DB->get_records('grade_items', [
        'itemtype'   => 'local',
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