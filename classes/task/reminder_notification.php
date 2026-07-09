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

/**
 * Periodically reminds everyone holding local/courseaudit:manage how many audit rows still need
 * review, so a stalled audit cycle doesn't go unnoticed until someone happens to open the
 * dashboard. Disabled by default (see db/tasks.php) - enable once you've decided how often you
 * want this (a mid-cycle site probably wants it weekly, not daily).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminder_notification extends \core\task\scheduled_task {

    /**
     * @inheritDoc
     */
    public function get_name() {
        return get_string('task_reminder', 'local_courseaudit');
    }

    /**
     * @inheritDoc
     */
    public function execute() {
        global $DB;

        $unreviewedcount = $DB->count_records('local_courseaudit_audit', ['reviewed' => 0]);
        if ($unreviewedcount === 0) {
            mtrace('local_courseaudit: no unreviewed rows - skipping reminder.');
            return;
        }

        $byschool = $DB->get_records_sql(
            "SELECT COALESCE(school, :unspecified) AS school, COUNT(id) AS n
               FROM {local_courseaudit_audit}
              WHERE reviewed = 0
           GROUP BY school
           ORDER BY n DESC",
            ['unspecified' => get_string('notset', 'local_courseaudit')]
        );

        $lines = [];
        foreach ($byschool as $row) {
            $lines[] = "- {$row->school}: {$row->n}";
        }

        $dashboardurl = (new \moodle_url('/local/courseaudit/index.php'))->out(false);
        $fullmessage = get_string('remindermessage', 'local_courseaudit', (object) [
            'count' => $unreviewedcount,
            'breakdown' => implode("\n", $lines),
            'url' => $dashboardurl,
        ]);

        $recipients = get_users_by_capability(\context_system::instance(), 'local/courseaudit:manage', 'u.*', '', '', '',
            '', '', false);

        $sent = 0;
        foreach ($recipients as $recipient) {
            $message = new \core\message\message();
            $message->component = 'local_courseaudit';
            $message->name = 'reviewreminder';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $recipient;
            $message->notification = 1;
            $message->contexturl = $dashboardurl;
            $message->contexturlname = get_string('dashboard', 'local_courseaudit');
            $message->subject = get_string('remindersubject', 'local_courseaudit', $unreviewedcount);
            $message->fullmessage = $fullmessage;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = text_to_html($fullmessage);
            $message->smallmessage = get_string('remindersubject', 'local_courseaudit', $unreviewedcount);

            if (message_send($message)) {
                $sent++;
            }
        }

        mtrace("local_courseaudit: {$unreviewedcount} unreviewed row(s) - reminder sent to {$sent} recipient(s).");
    }
}
