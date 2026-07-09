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
 * Human review/confirmation screen for programme code -> Programme level classification.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_courseaudit\program_mapper;
use local_courseaudit\form\programmap_form;
use local_courseaudit\task\refresh_audit_adhoc;

admin_externalpage_setup('local_courseaudit_programmap');

$PAGE->set_url(new moodle_url('/local/courseaudit/programmap.php'));
$PAGE->set_title(get_string('programmap', 'local_courseaudit'));
$PAGE->set_heading(get_string('programmap_heading', 'local_courseaudit'));

$dashboardurl = new moodle_url('/local/courseaudit/index.php');

// Every known programme code, not just unclassified ones - lets the QA team fix a
// mis-classified code later too, not just do the initial one-time review.
$rows = array_values(program_mapper::get_all());

$form = new programmap_form(null, ['rows' => $rows]);

if ($form->is_cancelled()) {
    redirect($dashboardurl);
}

if ($data = $form->get_data()) {
    $programcodetolevel = [];
    foreach ($rows as $row) {
        $levelfield = 'level_' . $row->id;
        $codefield = 'programcode_' . $row->id;
        if (isset($data->$levelfield) && isset($data->$codefield) && $data->$levelfield !== '') {
            $programcodetolevel[$data->$codefield] = $data->$levelfield;
        }
    }
    $updated = program_mapper::save_classifications($programcodetolevel, $USER->id);

    // Auto-queue a refresh so courses using these codes pick up their new Programme level
    // without a separate manual "Refresh from Moodle" click - same background-task mechanism
    // the dashboard button uses, so it's safe at any site size and just needs cron running.
    $refreshmessage = '';
    if ($updated > 0) {
        refresh_audit_adhoc::queue_fresh();
        $refreshmessage = ' ' . get_string('refreshqueued', 'local_courseaudit');
    }

    redirect(
        new moodle_url('/local/courseaudit/programmap.php'),
        get_string('classifysaved', 'local_courseaudit') . $refreshmessage,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('programmap_heading', 'local_courseaudit'));
echo html_writer::tag('p', get_string('programmap_help', 'local_courseaudit'));

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('noprogramcodes', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::link($dashboardurl, get_string('dashboard', 'local_courseaudit'), ['class' => 'btn btn-primary']);
} else {
    $unclassifiedcount = count(program_mapper::get_unclassified());
    if ($unclassifiedcount === 0) {
        echo $OUTPUT->notification(get_string('nounclassified', 'local_courseaudit'), \core\output\notification::NOTIFY_SUCCESS);
    }
    $form->display();
}

echo $OUTPUT->footer();
