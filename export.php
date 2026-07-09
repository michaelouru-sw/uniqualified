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
 * Exports the current audit dashboard into the site's master audit template.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_courseaudit\template_exporter;
use local_courseaudit\dashboard_filters;

require_login();
require_capability('local/courseaudit:manage', context_system::instance());

// Same filter params as the dashboard (see dashboard_filters::from_request()), carried through
// from whatever the "Export" link on index.php currently points at - so this always exports
// exactly the rows the reviewer was just looking at, not the whole site regardless of filters.
// Exporting always requires system-level :manage (see require_capability above), so there's no
// category-scoping to apply here the way index.php has to for view-only coordinators.
$filters = dashboard_filters::from_request();

// The Unit Mismatch report sheet can legitimately run into the thousands of rows on a large
// site now that the master timetable format is in play (a real ~19,000-row import produced
// over 10,000 timetable-only offering rows here) - same reasoning as import.php's memory raise.
raise_memory_limit(MEMORY_HUGE);
core_php_time_limit::raise();

try {
    $spreadsheet = template_exporter::export($filters);
} catch (\moodle_exception $e) {
    redirect(new moodle_url('/local/courseaudit/index.php'), $e->getMessage(), null,
        \core\output\notification::NOTIFY_ERROR);
}

$filename = 'course-quality-audit-' . (dashboard_filters::any_active($filters) ? 'filtered-' : '') . date('Ymd-His') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
