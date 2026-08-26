<?php
defined('MOODLE_INTERNAL') || die();

$observers = [[
        'eventname'   => '\mod_page\event\course_module_viewed',
        'callback'    => 'local_pagegrader_event_page_viewed',
        'includefile' => '/local/pagegrader/lib.php', // Обязательный параметр!
    ],[
        'eventname'   => '\core\event\course_module_deleted',
        'callback'    => 'local_pagegrader_event_module_deleted',
        'includefile' => '/local/pagegrader/lib.php', // Обязательный параметр!
    ],
];