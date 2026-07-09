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
 * Review/edit a single course's audit row.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_courseaudit\form\editrow_form;

require_login();
$context = context_system::instance();
require_capability('local/courseaudit:manage', $context);

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$dashboardurl = new moodle_url('/local/courseaudit/index.php');
$backurl = $returnurl !== '' ? new moodle_url($returnurl) : $dashboardurl;

$record = $DB->get_record('local_courseaudit_audit', ['id' => $id], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $record->courseid], '*', MUST_EXIST);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/courseaudit/edit.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_courseaudit') . ': ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

$formrecord = clone $record;
$formrecord->datechecked = $record->datechecked ?: 0;
foreach (['unitcode', 'unitname', 'school', 'programme', 'lecturername', 'programlevel', 'modeoflearning', 'templatedeployed',
        'attendancelink', 'materialsweeks', 'numcatsquizzes', 'assign1feedback', 'assign2feedback',
        'assign3feedback', 'cat1feedback', 'cat2feedback', 'comments'] as $field) {
    if ($formrecord->$field === null) {
        $formrecord->$field = '';
    }
}

$form = new editrow_form(null, ['record' => $formrecord, 'course' => $course, 'returnurl' => $returnurl]);

if ($form->is_cancelled()) {
    redirect($backurl);
}

if ($data = $form->get_data()) {
    $update = new stdClass();
    $update->id = $record->id;
    $update->unitcode = $data->unitcode;
    $update->unitname = $data->unitname;
    $update->school = $data->school ?: null;
    $update->programme = $data->programme ?: null;
    $update->numstudents = ($data->numstudents === '' || $data->numstudents === null) ? null : (int) $data->numstudents;
    $update->lecturername = $data->lecturername;
    $update->programlevel = $data->programlevel ?: null;
    $update->modeoflearning = $data->modeoflearning ?: null;
    $update->templatedeployed = $data->templatedeployed ?: null;
    $update->attendancelink = $data->attendancelink ?: null;
    // Always a count now (0 is a valid, meaningful value, not "unset") - PARAM_INT already
    // collapses a blank field to 0, so there's no separate "not entered" state to preserve here.
    $update->materialsweeks = (string) (int) $data->materialsweeks;
    $update->numcatsquizzes = $data->numcatsquizzes ?: null;
    $update->assign1feedback = $data->assign1feedback ?: null;
    $update->assign2feedback = $data->assign2feedback ?: null;
    $update->assign3feedback = $data->assign3feedback ?: null;
    $update->cat1feedback = $data->cat1feedback ?: null;
    $update->cat2feedback = $data->cat2feedback ?: null;
    $update->comments = $data->comments;
    $update->datechecked = !empty($data->datechecked) ? $data->datechecked : null;
    $update->reviewed = !empty($data->reviewed) ? 1 : 0;
    $update->reviewedby = !empty($data->reviewed) ? $USER->id : null;
    $update->timemodified = time();

    $DB->update_record('local_courseaudit_audit', $update);

    redirect($backurl, get_string('rowsaved', 'local_courseaudit'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($course->fullname));

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
echo html_writer::tag('p', html_writer::link($courseurl, get_string('view')) . ' | ' . html_writer::link($backurl, get_string('back')));

$form->display();

$evidence = json_decode((string) $record->evidence, true);
if (is_array($evidence)) {
    echo $OUTPUT->heading(get_string('col_evidence', 'local_courseaudit'), 4);
    echo html_writer::tag('pre', s(json_encode($evidence, JSON_PRETTY_PRINT)), ['class' => 'local_courseaudit-evidence']);
}

echo $OUTPUT->footer();
