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
 * Uploads up to 10 trimester timetable exports in one go and imports them as
 * the site's single source of truth, replacing whatever was previously
 * imported - see timetable_importer::import_files().
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_courseaudit\program_mapper;
use local_courseaudit\timetable_importer;
use local_courseaudit\form\import_form;

admin_externalpage_setup('local_courseaudit_import');

$PAGE->set_url(new moodle_url('/local/courseaudit/import.php'));
$PAGE->set_title(get_string('importtimetable', 'local_courseaudit'));
$PAGE->set_heading(get_string('importtimetable_heading', 'local_courseaudit'));

$dashboardurl = new moodle_url('/local/courseaudit/index.php');
$programmapurl = new moodle_url('/local/courseaudit/programmap.php');

$form = new import_form();

if ($form->is_cancelled()) {
    redirect($dashboardurl);
}

$importresult = null;
$toplevelerror = null;

if ($data = $form->get_data()) {
    $usercontext = context_user::instance($USER->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data->timetablefiles, 'id', false);

    if (empty($files)) {
        $toplevelerror = get_string('importnofiles', 'local_courseaudit');
    } else {
        // Large multi-sheet workbooks can need considerably more than PHP's default
        // memory_limit, especially on top of what Moodle itself has already allocated by this
        // point - the university's real master timetable file (a 6.8MB, 17-sheet workbook)
        // needed several hundred MB above the default during testing.
        raise_memory_limit(MEMORY_HUGE);
        core_php_time_limit::raise();

        $importresult = timetable_importer::import_files($files, $data->term, $USER->id);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importtimetable_heading', 'local_courseaudit'));

if ($toplevelerror !== null) {
    echo $OUTPUT->notification($toplevelerror, \core\output\notification::NOTIFY_ERROR);
}

if ($importresult !== null) {
    foreach ($importresult['errors'] as $error) {
        echo $OUTPUT->notification(
            get_string('importerror', 'local_courseaudit', (object) $error),
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($importresult !== null && $importresult['totalrows'] > 0) {
    echo $OUTPUT->notification(
        get_string('importsuccess', 'local_courseaudit', (object) [
            'rows' => $importresult['totalrows'],
            'files' => count($importresult['filesummaries']),
            'sheets' => $importresult['totalsheets'],
            'skipped' => $importresult['totalskipped'],
            'terms' => implode(', ', $importresult['terms']),
        ]),
        \core\output\notification::NOTIFY_SUCCESS
    );

    echo html_writer::start_tag('ul');
    foreach ($importresult['filesummaries'] as $summary) {
        echo html_writer::tag('li', s(get_string('importfilesummary', 'local_courseaudit', (object) $summary)));
    }
    echo html_writer::end_tag('ul');

    if ($importresult['unclassifiedcount'] > 0) {
        echo $OUTPUT->notification(
            get_string('unclassifiedwarning', 'local_courseaudit', $importresult['unclassifiedcount']),
            \core\output\notification::NOTIFY_WARNING
        );
        echo html_writer::link($programmapurl, get_string('programmap', 'local_courseaudit'), ['class' => 'btn btn-primary']);
        echo ' ';
    }
    echo html_writer::link($dashboardurl, get_string('dashboard', 'local_courseaudit'), ['class' => 'btn btn-secondary']);
} else {
    echo html_writer::tag('p', get_string('importtimetable_help', 'local_courseaudit'));
    $form->display();
}

echo $OUTPUT->footer();
