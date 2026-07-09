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

namespace local_courseaudit;

/**
 * Archives local_courseaudit_audit into local_courseaudit_history when a
 * cycle is cleared, and reads back past cycles for the "Audit history" page.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_history {

    /** @var string[] Columns copied verbatim from local_courseaudit_audit into local_courseaudit_history. */
    const COPIED_FIELDS = [
        'courseid', 'unitcode', 'unitname', 'school', 'programme', 'numstudents', 'lecturername',
        'programlevel', 'modeoflearning', 'templatedeployed', 'attendancelink', 'materialsweeks',
        'numcatsquizzes', 'assign1feedback', 'assign2feedback', 'assign3feedback', 'cat1feedback',
        'cat2feedback', 'comments', 'datechecked', 'reviewed', 'reviewedby', 'matchedterm',
    ];

    /**
     * A reasonable default label when the admin doesn't type one, so archiving never blocks on
     * a required field: date-based, not a bare number, so it's still meaningful on the history
     * list without needing to open a cycle to know what it was.
     *
     * @return string
     */
    public static function default_cycle_label(): string {
        return 'Audit cycle ' . userdate(time(), get_string('strftimedatefullshort', 'core_langconfig'));
    }

    /**
     * Copies every current local_courseaudit_audit row into local_courseaudit_history under the
     * given cycle label. Does not touch or clear local_courseaudit_audit itself - the caller
     * (index.php's "Clear all data" action) does that afterwards once archiving has succeeded.
     *
     * @param string $cyclelabel
     * @param int $userid
     * @return int Number of rows archived.
     */
    public static function archive_current(string $cyclelabel, int $userid): int {
        global $DB;

        $cyclelabel = trim($cyclelabel) !== '' ? trim($cyclelabel) : self::default_cycle_label();
        $rows = $DB->get_records('local_courseaudit_audit');
        if (empty($rows)) {
            return 0;
        }

        $now = time();
        $historyrecords = [];
        foreach ($rows as $row) {
            $record = ['cyclelabel' => $cyclelabel, 'archivedtime' => $now, 'archivedby' => $userid];
            foreach (self::COPIED_FIELDS as $field) {
                $record[$field] = $row->$field ?? null;
            }
            $historyrecords[] = (object) $record;
        }

        // Bulk insert, same reasoning as timetable_importer's use of insert_records() over a
        // per-row loop - measured ~5x faster on this driver, and this can be thousands of rows
        // on a full-size site.
        $DB->insert_records('local_courseaudit_history', $historyrecords);

        return count($historyrecords);
    }

    /**
     * Every distinct archived cycle, most recent first, with a row count and the archive
     * timestamp (rows within one cycle always share the same archivedtime - they're all written
     * in the single archive_current() call above).
     *
     * @return array List of ['cyclelabel' => string, 'coursecount' => int, 'archivedtime' => int]
     */
    public static function get_cycles(): array {
        global $DB;

        $sql = "SELECT cyclelabel, COUNT(id) AS coursecount, MAX(archivedtime) AS archivedtime
                  FROM {local_courseaudit_history}
              GROUP BY cyclelabel
              ORDER BY MAX(archivedtime) DESC";
        $records = $DB->get_records_sql($sql);

        $cycles = [];
        foreach ($records as $record) {
            $cycles[] = [
                'cyclelabel' => $record->cyclelabel,
                'coursecount' => (int) $record->coursecount,
                'archivedtime' => (int) $record->archivedtime,
            ];
        }
        return $cycles;
    }

    /**
     * Rows for a single archived cycle, paginated.
     *
     * @param string $cyclelabel
     * @param int $limitfrom
     * @param int $limitnum
     * @return array \stdClass rows.
     */
    public static function get_cycle_rows(string $cyclelabel, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;
        return array_values($DB->get_records('local_courseaudit_history', ['cyclelabel' => $cyclelabel],
            'unitcode ASC', '*', $limitfrom, $limitnum));
    }

    /**
     * @param string $cyclelabel
     * @return int
     */
    public static function count_cycle_rows(string $cyclelabel): int {
        global $DB;
        return $DB->count_records('local_courseaudit_history', ['cyclelabel' => $cyclelabel]);
    }
}
