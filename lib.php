<?php
defined('MOODLE_INTERNAL') || die();

function local_pagegrader_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    if ($formwrapper->get_current()->modulename !== 'page') {
        return;
    }

    $mform->addElement('header', 'local_pagegrader_header',
        get_string('settings_header', 'local_pagegrader'));

    $mform->addElement('advcheckbox', 'local_pagegrader_enable',
        get_string('enable_grading', 'local_pagegrader'));
    $mform->setType('local_pagegrader_enable', PARAM_INT);

    $mform->addElement('text', 'local_pagegrader_maxgrade',
        get_string('max_grade', 'local_pagegrader'), ['size' => '5']);
    $mform->setType('local_pagegrader_maxgrade', PARAM_FLOAT);
    $mform->hideIf('local_pagegrader_maxgrade', 'local_pagegrader_enable', 'notchecked');

    $cm = $formwrapper->get_current();
    if (!empty($cm->coursemodule)) {
        $record = $DB->get_record('local_pagegrader',
            ['coursemoduleid' => $cm->coursemodule]);
        if ($record) {
            $mform->setDefault('local_pagegrader_enable', $record->enablegrading);
            $mform->setDefault('local_pagegrader_maxgrade', $record->maxgrade);
            return;
        }
    }

    $mform->setDefault('local_pagegrader_maxgrade', 10);
}

function local_pagegrader_coursemodule_validation($data, $files) {
    if (!is_array($data)) {
        return [];
    }
    $errors =[];
    if (!empty($data['local_pagegrader_enable'])) {
        $grade = isset($data['local_pagegrader_maxgrade'])
            ? (float)$data['local_pagegrader_maxgrade'] : 0;
        if ($grade <= 0) {
            $errors['local_pagegrader_maxgrade'] = get_string('error_maxgrade', 'local_pagegrader');
        }
    }
    return $errors;
}

function local_pagegrader_coursemodule_edit_post_actions($data, $course) {
    global $DB, $CFG;

    if ($data->modulename !== 'page') {
        return $data;
    }

    $cmid          = $data->coursemodule;
    $enablegrading = !empty($data->local_pagegrader_enable) ? 1 : 0;
    $maxgrade      = !empty($data->local_pagegrader_maxgrade)
        ? (float)$data->local_pagegrader_maxgrade : 0.0;

    $record = $DB->get_record('local_pagegrader', ['coursemoduleid' => $cmid]);
    $oldmaxgrade = $record ? (float)$record->maxgrade : null;

    if ($record) {
        $record->enablegrading = $enablegrading;
        $record->maxgrade      = $maxgrade;
        $DB->update_record('local_pagegrader', $record);
    } else {
        $record = new \stdClass();
        $record->coursemoduleid = $cmid;
        $record->enablegrading = $enablegrading;
        $record->maxgrade      = $maxgrade;
        $DB->insert_record('local_pagegrader', $record);
    }

    require_once($CFG->libdir . '/gradelib.php');

    if ($enablegrading) {
        $itemdetails =[
            'itemname'  => $data->name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => $maxgrade,
            'grademin'  => 0,
            'hidden'    => 0,
            'locked'    => 0,
            'display'   => 1,
            'overridelocked' => 1,
        ];
        
        // Создаем или обновляем колонку
        grade_update('local_pagegrader', $course->id, 'local', 'pagegrader', $cmid, 0, null, $itemdetails);

        if ($oldmaxgrade !== null && abs($oldmaxgrade - $maxgrade) > 0.00001) {
            local_pagegrader_sync_grades($course->id, $cmid, $maxgrade);
        }
    } else {
        // ЖЕЛЕЗОБЕТОННОЕ УДАЛЕНИЕ: если сняли галочку, находим колонку и убиваем её
        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'local',
            'itemmodule'   => 'pagegrader',
            'iteminstance' => $cmid,
            'courseid'     => $course->id,
        ]);
        if ($gradeitem) {
            $gradeitem->delete();
        }
    }

    return $data;
}

function local_pagegrader_sync_grades($courseid, $cmid, $newmaxgrade) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $gradeitem = \grade_item::fetch([
        'itemtype'     => 'local',
        'itemmodule'   => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid'     => $courseid,
    ]);
    
    if (!$gradeitem) {
        return;
    }

    // 1. Принудительно меняем макс. балл у самой колонки
    $gradeitem->grademax = $newmaxgrade;
    $gradeitem->update();

    // 2. Достаем все оценки из базы для этой страницы
    $grades = \grade_grade::fetch_all(['itemid' => $gradeitem->id]);
    if ($grades) {
        foreach ($grades as $g) {
            if (!is_null($g->finalgrade) || !is_null($g->rawgrade)) {
                // 3. ЖЕЛЕЗОБЕТОННАЯ ПЕРЕЗАПИСЬ (минуем запреты API Мудла)
                $g->rawgrade   = $newmaxgrade;
                $g->finalgrade = $newmaxgrade;
                $g->overridden = 0; // Насильно снимаем блокировки Moodle
                $g->update();       // Пишем напрямую в таблицу БД
            }
        }
    }

    // 4. Командуем Мудлу пересчитать все итоги курса (обновить интерфейс)
    grade_regrade_final_grades($courseid);
}

function local_pagegrader_event_page_viewed($event) {
    global $DB, $CFG;

    $cmid     = $event->contextinstanceid;
    $userid   = $event->userid;
    $courseid = $event->courseid;
    $modulecontext = $event->get_context();

    $settings = $DB->get_record('local_pagegrader',[
        'coursemoduleid' => $cmid,
        'enablegrading'  => 1,
    ]);
    if (!$settings) {
        return;
    }

    if (!has_capability('mod/page:view', $modulecontext, $userid)) {
        return;
    }

    if (has_any_capability([
        'moodle/grade:edit',
        'moodle/course:manageactivities'
    ], $modulecontext, $userid)) {
        return; // Это админ/препод, оценку не ставим
    }

    require_once($CFG->libdir . '/gradelib.php');

    $gradeitem = \grade_item::fetch([
        'itemtype'     => 'local',
        'itemmodule'   => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid'     => $courseid,
    ]);

    if (!$gradeitem) {
        error_log("PAGEGRADER: Grade item не найден для cmid=$cmid. Пересохраните настройки страницы.");
        return;
    }

    $current = \grade_grade::fetch([
        'itemid' => $gradeitem->id,
        'userid' => $userid,
    ]);

    $currentgrade = null;
    if ($current) {
        if (!is_null($current->finalgrade)) {
            $currentgrade = (float)$current->finalgrade;
        } else if (!is_null($current->rawgrade)) {
            $currentgrade = (float)$current->rawgrade;
        }
    }

    if (!is_null($currentgrade) && $currentgrade >= (float)$settings->maxgrade) {
        return; // Уже есть максимальная оценка
    }

    // Возвращаем твой надёжный низкоуровневый метод!
    $success = $gradeitem->update_final_grade(
        $userid,
        (float)$settings->maxgrade,
        'local_pagegrader' // Источник оценки
    );

    if ($success) {
        error_log("PAGEGRADER: Оценка " . $settings->maxgrade . " УСПЕШНО выставлена студенту $userid для cmid=$cmid");
    } else {
        error_log("PAGEGRADER: КРИТИЧЕСКАЯ ОШИБКА выставления оценки студенту $userid для cmid=$cmid");
    }
}

function local_pagegrader_event_module_deleted($event) {
    global $DB, $CFG;

    if (($event->other['modulename'] ?? '') !== 'page') {
        return;
    }

    $cmid = $event->objectid;
    $DB->delete_records('local_pagegrader',['coursemoduleid' => $cmid]);

    require_once($CFG->libdir . '/gradelib.php');
    
    // ЖЕЛЕЗОБЕТОННОЕ УДАЛЕНИЕ при удалении элемента из курса
    $gradeitem = \grade_item::fetch([
        'itemtype'     => 'local',
        'itemmodule'   => 'pagegrader',
        'iteminstance' => $cmid,
        'courseid'     => $event->courseid,
    ]);
    if ($gradeitem) {
        $gradeitem->delete();
    }
}
