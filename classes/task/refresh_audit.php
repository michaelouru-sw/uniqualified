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

namespace local_courseaudit\task;

use local_courseaudit\audit_assembler;

/**
 * Scheduled task equivalent of the dashboard's "Refresh from Moodle" button.
 * Disabled by default (see db/tasks.php) - enable it once the heuristics
 * and programme classifications have been reviewed and trusted for
 * unattended runs.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_audit extends \core\task\scheduled_task {

    /**
     * @inheritDoc
     */
    public function get_name() {
        return get_string('task_refreshaudit', 'local_courseaudit');
    }

    /**
     * @inheritDoc
     */
    public function execute() {
        $counts = audit_assembler::refresh_all();
        mtrace(sprintf(
            'local_courseaudit: processed %d course(s) - %d created, %d updated, %d skipped (already reviewed), %d failed.',
            $counts['processed'],
            $counts['created'],
            $counts['updated'],
            $counts['skippedreviewed'],
            $counts['failed']
        ));
        foreach ($counts['failures'] as $failure) {
            mtrace("  FAILED course {$failure['courseid']} ({$failure['shortname']}): {$failure['message']}");
        }
    }
}
