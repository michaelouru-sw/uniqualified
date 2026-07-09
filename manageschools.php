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
 * View every distinct School label currently in use (from imported timetables), and
 * rename/merge variants of the same school - see school_manager for why this exists.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_courseaudit\school_manager;

admin_externalpage_setup('local_courseaudit_manageschools');

$PAGE->set_url(new moodle_url('/local/courseaudit/manageschools.php'));
$PAGE->set_title(get_string('manageschools', 'local_courseaudit'));
$PAGE->set_heading(get_string('manageschools_heading', 'local_courseaudit'));

$dashboardurl = new moodle_url('/local/courseaudit/index.php');
$thisurl = new moodle_url('/local/courseaudit/manageschools.php');

if (optional_param('action', '', PARAM_ALPHA) === 'rename' && confirm_sesskey()) {
    $from = trim(optional_param('from', '', PARAM_TEXT));
    $to = trim(optional_param('to', '', PARAM_TEXT));
    $updated = school_manager::rename($from, $to);
    redirect($thisurl, get_string('schoolrenamed', 'local_courseaudit', (object) [
        'from' => $from, 'to' => $to, 'count' => $updated,
    ]), null, \core\output\notification::NOTIFY_SUCCESS);
}

$schools = school_manager::get_all();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageschools_heading', 'local_courseaudit'));
echo html_writer::tag('p', get_string('manageschools_help', 'local_courseaudit'));

if (empty($schools)) {
    echo $OUTPUT->notification(get_string('noschools', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('col_school', 'local_courseaudit'),
        get_string('col_coursecount', 'local_courseaudit'),
        get_string('timetablerowcount', 'local_courseaudit'),
    ];
    $table->data = [];
    foreach ($schools as $school => $counts) {
        $table->data[] = [s($school), $counts['auditcount'], $counts['timetablecount']];
    }
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('renameschool', 'local_courseaudit'), 4);
    echo html_writer::tag('p', get_string('renameschool_help', 'local_courseaudit'), ['class' => 'text-muted small']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $thisurl->out_omit_querystring(), 'class' => 'form-inline mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rename']);

    $schooloptions = [];
    foreach (array_keys($schools) as $school) {
        $schooloptions[$school] = $school;
    }
    echo html_writer::tag('label', get_string('renamefrom', 'local_courseaudit'), ['class' => 'mr-1', 'for' => 'id_from']);
    echo html_writer::select($schooloptions, 'from', '', ['' => get_string('choosedots')], ['class' => 'mr-2', 'id' => 'id_from']);

    echo html_writer::tag('label', get_string('renameto', 'local_courseaudit'), ['class' => 'mr-1', 'for' => 'id_to']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'to', 'id' => 'id_to', 'class' => 'mr-2', 'size' => 30]);

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('renameschool', 'local_courseaudit'),
        'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

echo html_writer::link($dashboardurl, get_string('backtodashboard', 'local_courseaudit'), ['class' => 'btn btn-secondary']);

echo $OUTPUT->footer();
