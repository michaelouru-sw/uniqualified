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
 * "School" isn't a managed entity anywhere else in the plugin - it's a free-text label copied
 * from whatever the timetable import parsed (see timetable_importer::import_files(), which
 * stores it as local_courseaudit_timetable.institution - local_courseaudit_audit's own column
 * for the same concept is instead called "school", see that table's install.xml comment), so two
 * imports can easily leave behind variants of the same school (e.g. "SOB" vs "School of
 * Business") that the dashboard's School filter then lists as if they were different schools.
 * This gives the QA team a way to see what's actually in use and merge/rename variants without
 * needing database access.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class school_manager {

    /**
     * Every distinct school label currently in use, with how many live audit rows and
     * timetable rows carry it - so a QA officer can spot a stray typo variant by its low count
     * next to the "real" one.
     *
     * @return array [school => ['auditcount' => int, 'timetablecount' => int]], school ASC.
     */
    public static function get_all(): array {
        global $DB;

        $schools = [];

        $auditcounts = $DB->get_records_sql(
            "SELECT school, COUNT(*) AS c
               FROM {local_courseaudit_audit}
              WHERE school IS NOT NULL AND school <> ''
           GROUP BY school"
        );
        foreach ($auditcounts as $row) {
            $schools[$row->school]['auditcount'] = (int) $row->c;
        }

        // local_courseaudit_timetable's equivalent column is called "institution", not "school".
        $timetablecounts = $DB->get_records_sql(
            "SELECT institution, COUNT(*) AS c
               FROM {local_courseaudit_timetable}
              WHERE institution IS NOT NULL AND institution <> ''
           GROUP BY institution"
        );
        foreach ($timetablecounts as $row) {
            $schools[$row->institution]['timetablecount'] = (int) $row->c;
        }

        foreach ($schools as $school => $counts) {
            $schools[$school] = [
                'auditcount' => $counts['auditcount'] ?? 0,
                'timetablecount' => $counts['timetablecount'] ?? 0,
            ];
        }

        ksort($schools, SORT_NATURAL | SORT_FLAG_CASE);
        return $schools;
    }

    /**
     * Renames (or merges, if $to already exists) a school label everywhere it's currently used:
     * the live audit table (school column) and the imported timetable rows (institution column).
     * Deliberately does NOT touch local_courseaudit_history - archived audit cycles are a frozen
     * snapshot of a finished cycle, not something a later rename should silently rewrite.
     *
     * @param string $from
     * @param string $to
     * @return int Total rows updated across both tables.
     */
    public static function rename(string $from, string $to): int {
        global $DB;

        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '' || $from === $to) {
            return 0;
        }

        $updated = 0;
        $updated += $DB->count_records('local_courseaudit_audit', ['school' => $from]);
        $DB->set_field('local_courseaudit_audit', 'school', $to, ['school' => $from]);
        $updated += $DB->count_records('local_courseaudit_timetable', ['institution' => $from]);
        $DB->set_field('local_courseaudit_timetable', 'institution', $to, ['institution' => $from]);

        return $updated;
    }
}
