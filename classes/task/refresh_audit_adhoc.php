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
 * Runs the dashboard's "Refresh from Moodle" as a background adhoc task
 * instead of inline in the web request that triggered it.
 *
 * A full refresh touches every course on the site and can involve tens of
 * thousands of database queries on a large install (thousands of courses) -
 * running that synchronously in an HTTP request risks the web server's own
 * timeout (independent of any PHP time_limit override) killing it partway
 * through. Queuing it here means cron does the work, which has no such
 * timeout, regardless of site size.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_audit_adhoc extends \core\task\adhoc_task {

    /**
     * Queues a fresh run, clearing out any existing not-currently-running row for this task
     * first. Without that, a row that failed in the past (e.g. on an older version of this
     * plugin, before some heuristic bug was fixed) sits in the queue with its retry pushed
     * further into the future each time it fails, via Moodle's exponential backoff -
     * queue_adhoc_task()'s duplicate check then sees that old, backed-off row as "already
     * queued" and silently declines to add a new one. Both call sites (the dashboard's
     * "Refresh from Moodle" button and programmap.php's auto-queue after saving
     * classifications) mean "run this now", not "wait for whatever an earlier failure
     * computed" - so only a row that's actually mid-execution (timestarted set) is left alone.
     *
     * @return void
     */
    public static function queue_fresh(): void {
        global $DB;

        // Moodle always stores/matches classnames with a leading backslash (see
        // \core\task\manager::get_canonical_class_name()) - self::class alone doesn't have one,
        // so comparing against it directly would silently match zero rows.
        $classname = \core\task\manager::get_canonical_class_name(self::class);
        $DB->delete_records_select(
            'task_adhoc',
            'classname = :classname AND timestarted IS NULL',
            ['classname' => $classname]
        );
        \core\task\manager::queue_adhoc_task(new self(), true);
    }

    /**
     * @inheritDoc
     */
    public function execute() {
        $counts = audit_assembler::refresh_all();
        mtrace(sprintf(
            'local_courseaudit: refresh queued from dashboard processed %d course(s) - %d created, %d updated, %d skipped (already reviewed), %d failed.',
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
