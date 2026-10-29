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
 * Report: named list of students flagged "at risk of dropping out" by Moodle's Analytics model,
 * with the course, school, lecturer and risk score, filterable and downloadable as CSV/Excel.
 *
 * Access and scoping mirror index.php: local/courseaudit:view at system level sees everything,
 * a category-scoped holder sees only students in courses of their own categories.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_courseaudit\dashboard_filters;
use local_courseaudit\ml_insights;

require_login();
$systemcontext = context_system::instance();
$hassystemview = has_capability('local/courseaudit:view', $systemcontext);
$allowedcategories = $hassystemview ? null : \core_course_category::make_categories_list('local/courseaudit:view');
if (!$hassystemview && empty($allowedcategories)) {
    throw new \required_capability_exception($systemcontext, 'local/courseaudit:view', 'nopermissions', '');
}

$filters = dashboard_filters::from_request();
$minrisk = optional_param('minrisk', 0, PARAM_INT);
$minrisk = max(0, min(100, $minrisk));
$studentsearch = trim(optional_param('studentsearch', '', PARAM_TEXT));
$download = optional_param('download', '', PARAM_ALPHA);

$pageurlparams = [
    'categoryid' => $filters['categoryid'], 'school' => $filters['school'], 'programlevel' => $filters['programlevel'],
    'search' => $filters['search'], 'minrisk' => $minrisk, 'studentsearch' => $studentsearch,
];
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/courseaudit/atriskstudents.php', $pageurlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('atrisk_heading', 'local_courseaudit'));
$PAGE->set_heading(get_string('atrisk_heading', 'local_courseaudit'));

// Courses in scope: the same filter set as the dashboard (category / school / programme level /
// course search), restricted to the viewer's own categories when category-scoped.
$allowedcategoryids = $hassystemview ? [] : array_keys($allowedcategories);
list($wheresql, $params) = dashboard_filters::build_where($filters, $hassystemview, $allowedcategoryids);
$auditrows = $DB->get_records_sql(
    "SELECT a.courseid, a.unitcode, a.unitname, a.school, a.programme, a.lecturername,
            c.fullname AS coursefullname
       FROM {local_courseaudit_audit} a
       JOIN {course} c ON c.id = a.courseid
  LEFT JOIN {course_categories} cc ON cc.id = c.category
      WHERE $wheresql", $params);
$courseids = array_map('intval', array_keys($auditrows));

$summary = ml_insights::model_summary()[ml_insights::TARGET_STUDENT_DROPOUT] ?? null;
$modelready = $summary && $summary['enabled'] && $summary['trained'] && $summary['haspredictions'];

$flagged = $modelready ? ml_insights::student_dropout_list($courseids, $minrisk / 100) : [];

// Student details in one query, not one per row.
$users = [];
if ($flagged) {
    $userids = array_values(array_unique(array_column($flagged, 'userid')));
    $users = $DB->get_records_list('user', 'id', $userids, '',
        'id, ' . implode(',', \core_user\fields::get_name_fields()) . ', username, idnumber, email, deleted, suspended');
}

$report = [];
foreach ($flagged as $f) {
    $user = $users[$f['userid']] ?? null;
    if (!$user || $user->deleted) {
        continue;
    }
    $name = fullname($user);
    if ($studentsearch !== '' && stripos($name . ' ' . $user->username . ' ' . $user->idnumber . ' ' . $user->email,
            $studentsearch) === false) {
        continue;
    }
    $audit = $auditrows[$f['courseid']] ?? null;
    $report[] = (object) [
        'student' => $name,
        'username' => $user->username,
        'idnumber' => $user->idnumber,
        'email' => $user->email,
        'unitcode' => $audit->unitcode ?? '',
        'unitname' => $audit->unitname ?? ($audit->coursefullname ?? ''),
        'school' => $audit->school ?? '',
        'programme' => $audit->programme ?? '',
        'lecturer' => $audit->lecturername ?? '',
        'risk' => round($f['score'] * 100) . '%',
        'flagged' => userdate($f['timecreated'], get_string('strftimedatetimeshort')),
        'userid' => $f['userid'],
        'courseid' => $f['courseid'],
    ];
}

$columns = [
    'student' => get_string('atrisk_col_student', 'local_courseaudit'),
    'username' => get_string('username'),
    'idnumber' => get_string('idnumber'),
    'email' => get_string('email'),
    'unitcode' => get_string('col_unitcode', 'local_courseaudit'),
    'unitname' => get_string('col_unitname', 'local_courseaudit'),
    'school' => get_string('col_school', 'local_courseaudit'),
    'programme' => get_string('col_programme', 'local_courseaudit'),
    'lecturer' => get_string('atrisk_col_lecturer', 'local_courseaudit'),
    'risk' => get_string('atrisk_col_risk', 'local_courseaudit'),
    'flagged' => get_string('atrisk_col_flagged', 'local_courseaudit'),
];

if ($download !== '' && $modelready) {
    \core\dataformat::download_data('students-at-risk-' . date('Ymd-His'), $download === 'xlsx' ? 'excel' : 'csv',
        $columns, new ArrayIterator($report), function ($row) use ($columns) {
            return (object) array_intersect_key((array) $row, $columns);
        });
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('atrisk_heading', 'local_courseaudit'));
echo html_writer::tag('p', get_string('atrisk_help', 'local_courseaudit'), ['class' => 'text-muted']);

if (!$modelready) {
    echo $OUTPUT->notification(get_string('atrisk_notready', 'local_courseaudit'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

// Filters.
$categories = $hassystemview ? $DB->get_records_menu('course_categories', null, 'name ASC', 'id, name') : $allowedcategories;
$schools = $DB->get_fieldset_sql("SELECT DISTINCT school FROM {local_courseaudit_audit}
                                   WHERE school IS NOT NULL AND school <> '' ORDER BY school");
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3',
    'action' => (new moodle_url('/local/courseaudit/atriskstudents.php'))->out(false)]);
echo html_writer::select($categories, 'categoryid', $filters['categoryid'], [0 => get_string('allcategories', 'local_courseaudit')],
    ['class' => 'mr-2']);
echo html_writer::select(array_combine($schools, $schools), 'school', $filters['school'],
    ['' => get_string('allschools', 'local_courseaudit')], ['class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => $filters['search'],
    'placeholder' => get_string('atrisk_search_course', 'local_courseaudit'), 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'studentsearch', 'value' => $studentsearch,
    'placeholder' => get_string('atrisk_search_student', 'local_courseaudit'), 'class' => 'mr-2']);
echo html_writer::tag('label', get_string('atrisk_minrisk', 'local_courseaudit'), ['for' => 'id_minrisk', 'class' => 'mr-1']);
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'minrisk', 'id' => 'id_minrisk', 'value' => $minrisk,
    'min' => 0, 'max' => 100, 'style' => 'width:5em', 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
    'class' => 'btn btn-primary mr-2']);
echo html_writer::link(new moodle_url('/local/courseaudit/atriskstudents.php'), get_string('resetfilters', 'local_courseaudit'));
echo html_writer::end_tag('form');

echo html_writer::start_div('mb-3');
echo html_writer::tag('strong', get_string('atrisk_count', 'local_courseaudit', (object) [
    'students' => count(array_unique(array_column($report, 'userid'))), 'rows' => count($report)]));
if ($report) {
    echo ' ' . html_writer::link(new moodle_url($PAGE->url, ['download' => 'csv']), get_string('atrisk_downloadcsv', 'local_courseaudit'),
        ['class' => 'btn btn-secondary btn-sm ml-3 mr-2']);
    echo html_writer::link(new moodle_url($PAGE->url, ['download' => 'xlsx']), get_string('atrisk_downloadxlsx', 'local_courseaudit'),
        ['class' => 'btn btn-secondary btn-sm']);
}
echo html_writer::end_div();

if (empty($report)) {
    echo $OUTPUT->notification(get_string('atrisk_none', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = array_values($columns);
    foreach ($report as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = s($row->$key);
        }
        $line[0] = html_writer::link(new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $row->courseid]),
            s($row->student));
        $line[4] = html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), s($row->unitcode));
        $table->data[] = $line;
    }
    echo html_writer::table($table);
}

echo html_writer::link(new moodle_url('/local/courseaudit/index.php'), get_string('backtodashboard', 'local_courseaudit'),
    ['class' => 'btn btn-secondary']);
echo $OUTPUT->footer();
