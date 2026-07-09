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
 * Read-only view of archived audit cycles (see local_courseaudit\audit_history).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_courseaudit\audit_history;

require_login();
$context = context_system::instance();
require_capability('local/courseaudit:view', $context);

/** Rows per page when viewing a single archived cycle. */
const COURSEAUDIT_HISTORY_PERPAGE = 50;

$cyclelabel = optional_param('cyclelabel', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);

$dashboardurl = new moodle_url('/local/courseaudit/index.php');
$historyurl = new moodle_url('/local/courseaudit/history.php');

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url($historyurl, ['cyclelabel' => $cyclelabel, 'page' => $page]));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/courseaudit/styles.css');
$PAGE->set_title(get_string('audithistory', 'local_courseaudit'));
$PAGE->set_heading(get_string('audithistory_heading', 'local_courseaudit'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('audithistory_heading', 'local_courseaudit'));
echo html_writer::tag('p', html_writer::link($dashboardurl, get_string('back')));

if ($cyclelabel === '') {
    // Cycle list.
    $cycles = audit_history::get_cycles();
    if (empty($cycles)) {
        echo $OUTPUT->notification(get_string('nocycles', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
    } else {
        $table = new html_table();
        $table->head = [
            get_string('col_cyclelabel', 'local_courseaudit'),
            get_string('col_archivedtime', 'local_courseaudit'),
            get_string('col_coursecount', 'local_courseaudit'),
            '',
        ];
        $table->data = [];
        foreach ($cycles as $cycle) {
            $viewurl = new moodle_url($historyurl, ['cyclelabel' => $cycle['cyclelabel']]);
            $table->data[] = [
                s($cycle['cyclelabel']),
                userdate($cycle['archivedtime'], get_string('strftimedatetimeshort', 'core_langconfig')),
                $cycle['coursecount'],
                html_writer::link($viewurl, get_string('viewcycle', 'local_courseaudit')),
            ];
        }
        echo html_writer::table($table);
    }
} else {
    // Single cycle, read-only.
    echo $OUTPUT->heading(s($cyclelabel), 4);
    echo html_writer::tag('p', html_writer::link($historyurl, get_string('backtohistory', 'local_courseaudit')));

    $totalrows = audit_history::count_cycle_rows($cyclelabel);
    if ($totalrows === 0) {
        echo $OUTPUT->notification(get_string('nocourses', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
    } else {
        $rows = audit_history::get_cycle_rows($cyclelabel, $page * COURSEAUDIT_HISTORY_PERPAGE, COURSEAUDIT_HISTORY_PERPAGE);

        $table = new html_table();
        $table->head = [
            get_string('col_unitcode', 'local_courseaudit'),
            get_string('col_unitname', 'local_courseaudit'),
            get_string('col_school', 'local_courseaudit'),
            get_string('col_programme', 'local_courseaudit'),
            get_string('col_numstudents', 'local_courseaudit'),
            get_string('col_lecturername', 'local_courseaudit'),
            get_string('col_programlevel', 'local_courseaudit'),
            get_string('col_modeoflearning', 'local_courseaudit'),
            get_string('col_templatedeployed', 'local_courseaudit'),
            get_string('col_attendancelink', 'local_courseaudit'),
            get_string('col_materialsweeks', 'local_courseaudit'),
            get_string('col_numcatsquizzes', 'local_courseaudit'),
            get_string('col_assign1feedback', 'local_courseaudit'),
            get_string('col_assign2feedback', 'local_courseaudit'),
            get_string('col_assign3feedback', 'local_courseaudit'),
            get_string('col_cat1feedback', 'local_courseaudit'),
            get_string('col_cat2feedback', 'local_courseaudit'),
            get_string('col_comments', 'local_courseaudit'),
            get_string('col_reviewed', 'local_courseaudit'),
        ];
        $table->data = [];
        foreach ($rows as $row) {
            $commentstext = (string) ($row->comments ?? '');
            $commentscell = $commentstext === '' ? '-' : html_writer::tag('span', s(shorten_text($commentstext, 60)),
                ['title' => s($commentstext)]);
            $reviewedbadge = !empty($row->reviewed)
                ? html_writer::tag('span', get_string('reviewed', 'local_courseaudit'), ['class' => 'badge badge-success'])
                : html_writer::tag('span', get_string('notreviewed', 'local_courseaudit'), ['class' => 'badge badge-secondary']);

            $table->data[] = [
                s($row->unitcode ?: '-'),
                s($row->unitname ?: '-'),
                s($row->school ?: '-'),
                s($row->programme ?: '-'),
                $row->numstudents ?? '-',
                s($row->lecturername ?: '-'),
                s($row->programlevel ?: '-'),
                s($row->modeoflearning ?: '-'),
                s($row->templatedeployed ?: '-'),
                s($row->attendancelink ?: '-'),
                s($row->materialsweeks ?? '-'),
                s($row->numcatsquizzes ?: '-'),
                s($row->assign1feedback ?: '-'),
                s($row->assign2feedback ?: '-'),
                s($row->assign3feedback ?: '-'),
                s($row->cat1feedback ?: '-'),
                s($row->cat2feedback ?: '-'),
                $commentscell,
                $reviewedbadge,
            ];
        }

        echo html_writer::start_div('table-responsive');
        echo html_writer::table($table);
        echo html_writer::end_div();

        if ($totalrows > COURSEAUDIT_HISTORY_PERPAGE) {
            echo $OUTPUT->render(new \core\output\paging_bar($totalrows, $page, COURSEAUDIT_HISTORY_PERPAGE,
                new moodle_url($historyurl, ['cyclelabel' => $cyclelabel])));
        }
    }
}

echo $OUTPUT->footer();
