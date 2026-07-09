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
 * Cross-checks between the imported timetable and the current
 * local_courseaudit_audit table (built from live Moodle course/enrolment
 * data by the last refresh), surfaced as separate sheets in the exported
 * workbook - see template_exporter::export().
 *
 * Every method here reads from local_courseaudit_audit/local_courseaudit_timetable
 * rather than re-querying live Moodle data itself, since a refresh has
 * already done that work; running "Refresh from Moodle" before exporting
 * keeps these reports current.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mismatch_reports {

    /**
     * Units allocated in the timetable but never matched to any Moodle course, and Moodle
     * courses with an identifiable unit code that never matched any timetable row at all.
     * A course whose unit code couldn't even be identified (unitcode is null) is excluded from
     * the "on LMS" side - that's a different problem (naming convention not followed) rather
     * than "this specific known unit is missing from the timetable".
     *
     * Both sides report full detail (lecturer, school, programme, etc.), not just the bare unit
     * code - a unit code can legitimately have several distinct timetable offerings (different
     * lecturer/school/programme/campus/mode), so the timetable-only side reports one row per
     * distinct offering rather than collapsing them into one unhelpfully-merged row.
     *
     * @return array{timetableonly: \stdClass[], lmsonly: \stdClass[]}
     */
    public static function unit_mismatch(): array {
        global $DB;

        $timetablekeys = $DB->get_fieldset_sql('SELECT DISTINCT unitcodekey FROM {local_courseaudit_timetable}');

        $auditkeys = [];
        $auditunitcodes = $DB->get_fieldset_select(
            'local_courseaudit_audit', 'unitcode', "unitcode IS NOT NULL AND unitcode <> ''"
        );
        foreach ($auditunitcodes as $unitcode) {
            $auditkeys[timetable_parser::normalize_matchkey($unitcode)] = true;
        }

        $timetableonlykeys = array_values(array_filter($timetablekeys, fn($key) => !isset($auditkeys[$key])));

        $timetableonly = [];
        if (!empty($timetableonlykeys)) {
            list($insql, $params) = $DB->get_in_or_equal($timetableonlykeys, SQL_PARAMS_NAMED);
            // GROUP BY (not a naive SELECT DISTINCT keyed by unitcode) - get_records_sql() keys
            // its result by the first selected column, so a plain "DISTINCT unitcode, ..." query
            // would silently drop every row but the first whenever a unit code has more than one
            // distinct offering (different lecturer/school/etc for the same code) - the MIN(id)
            // here exists purely to give each grouped row a unique key.
            $timetableonly = array_values($DB->get_records_sql(
                "SELECT MIN(id) AS id, unitcode, unitname, institution AS school, programcode,
                        programname AS programme, lecturername, campus, modeofstudy, term
                   FROM {local_courseaudit_timetable}
                  WHERE unitcodekey $insql
               GROUP BY unitcode, institution, programcode, programname, lecturername, campus,
                        modeofstudy, term
               ORDER BY unitcode ASC",
                $params
            ));
        }

        $lmsonly = array_values($DB->get_records_sql(
            "SELECT a.courseid, a.unitcode, a.unitname, a.school, a.programme, a.lecturername,
                    a.numstudents, c.shortname AS courseshortname, c.fullname AS coursefullname
               FROM {local_courseaudit_audit} a
               JOIN {course} c ON c.id = a.courseid
              WHERE a.hastimetablematch = 0 AND a.unitcode IS NOT NULL AND a.unitcode <> ''
           ORDER BY a.unitcode ASC"
        ));

        return ['timetableonly' => $timetableonly, 'lmsonly' => $lmsonly];
    }

    /**
     * Courses where the timetable's scheduled lecturer and the actual Moodle-enrolled teacher
     * both exist but don't match - a case-insensitive, whitespace-trimmed comparison, since
     * these come from two independently-typed sources.
     *
     * @return \stdClass[]
     */
    public static function lecturer_mismatch(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.courseid, a.unitcode, a.unitname, a.timetablelecturer, a.lmslecturer,
                    c.shortname AS courseshortname, c.fullname AS coursefullname
               FROM {local_courseaudit_audit} a
               JOIN {course} c ON c.id = a.courseid
              WHERE a.timetablelecturer IS NOT NULL AND a.timetablelecturer <> ''
                    AND a.lmslecturer IS NOT NULL AND a.lmslecturer <> ''
           ORDER BY a.unitcode ASC"
        );

        return array_values(array_filter($rows, function ($row) {
            return strtoupper(trim($row->timetablelecturer)) !== strtoupper(trim($row->lmslecturer));
        }));
    }

    /**
     * Courses that are genuine duplicates - not just courses that happen to share a unit code.
     * Two courses with the same Unit Code and Unit Name but a **different lecturer, mode of
     * study, location, or stream** are legitimately distinct offerings (e.g. "Stream A" vs
     * "Stream B" of the same unit), not duplicates - only courses matching on every one of
     * those fields are flagged. Uses the course's own fullname (see course_name_parser, this
     * site's Unit Code:Unit Name:Mode of Study:Lecturer Name:Location:Stream convention) as the
     * authoritative source for these distinguishing fields, since that's what actually
     * identifies a specific offering - falling back to the audit row's own values for a course
     * that doesn't follow the naming convention.
     *
     * @return array<int, \stdClass[]> Each element is the group of 2+ courses that are genuine duplicates.
     */
    public static function duplicate_units(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.courseid, a.unitcode, a.unitname, c.shortname AS courseshortname,
                    c.fullname AS coursefullname
               FROM {local_courseaudit_audit} a
               JOIN {course} c ON c.id = a.courseid
              WHERE a.unitcode IS NOT NULL AND a.unitcode <> ''
           ORDER BY a.unitcode ASC"
        );

        $groups = [];
        foreach ($rows as $row) {
            $parsed = course_name_parser::parse_fullname((string) $row->coursefullname);
            $key = implode('|', [
                timetable_parser::normalize_matchkey($parsed['unitcode'] ?? $row->unitcode),
                self::normalize_component($parsed['unitname'] ?? $row->unitname),
                self::normalize_component($parsed['modeofstudy']),
                self::normalize_component($parsed['lecturername']),
                self::normalize_component($parsed['location']),
                self::normalize_component($parsed['stream']),
            ]);
            $groups[$key][] = $row;
        }

        return array_values(array_filter($groups, fn($group) => count($group) > 1));
    }

    /**
     * Uppercase/trim/whitespace-collapse for comparing course-fullname components - a null
     * component (e.g. a course whose fullname has no Stream segment at all) normalizes to an
     * empty string, so it's compared consistently rather than treated as "unknown, ignore".
     *
     * @param string|null $value
     * @return string
     */
    protected static function normalize_component(?string $value): string {
        if ($value === null) {
            return '';
        }
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    /**
     * Courses with no enrolled students at all, but a teacher is enrolled - worth flagging since
     * it usually means either the students haven't been enrolled yet or the course/cohort setup
     * is broken somewhere.
     *
     * @return \stdClass[]
     */
    public static function no_students_with_lecturer(): array {
        global $DB;

        return array_values($DB->get_records_sql(
            "SELECT a.courseid, a.unitcode, a.unitname, a.lecturername, a.numstudents,
                    c.shortname AS courseshortname, c.fullname AS coursefullname
               FROM {local_courseaudit_audit} a
               JOIN {course} c ON c.id = a.courseid
              WHERE (a.numstudents = 0 OR a.numstudents IS NULL)
                    AND a.lecturername IS NOT NULL AND a.lecturername <> ''
           ORDER BY a.unitcode ASC"
        ));
    }
}
