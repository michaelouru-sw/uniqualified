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
 * Upgrade steps for local_courseaudit.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_courseaudit_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070201) {
        // numcatsquizzes was originally declared as an int, but the heuristic legitimately
        // produces text values ('None', '1'..'5', 'more than 5') matching the master
        // template's dropdown vocabulary - int could never hold those.
        $table = new xmldb_table('local_courseaudit_audit');
        $field = new xmldb_field('numcatsquizzes', XMLDB_TYPE_CHAR, '20', null, false, false, null, 'materialsweeks');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070201, 'local', 'courseaudit');
    }

    if ($oldversion < 2026070300) {
        // Column N on the Data sheet duplicates column L's "Assign 1 Feedback" header
        // verbatim - interpreted as a 3rd assignment slot (Assign1/2/3 then Cat1/2).
        $table = new xmldb_table('local_courseaudit_audit');
        $field = new xmldb_field('assign3feedback', XMLDB_TYPE_CHAR, '20', null, false, false, null, 'assign2feedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Widened so multi-file imports can auto-derive an institution label from each
        // uploaded filename rather than being limited to short fixed codes like 'SOB'.
        $timetabletable = new xmldb_table('local_courseaudit_timetable');
        $institutionfield = new xmldb_field('institution', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, false, null, 'term');
        if ($dbman->field_exists($timetabletable, $institutionfield)) {
            $dbman->change_field_precision($timetabletable, $institutionfield);
        }

        upgrade_plugin_savepoint(true, 2026070300, 'local', 'courseaudit');
    }

    if ($oldversion < 2026070400) {
        // Institution/school (e.g. SOB, PTTI, NAC) was already captured per timetable row but
        // dropped once course_matcher grouped rows into offerings - needed on the audit row
        // itself so the dashboard can filter by school.
        $table = new xmldb_table('local_courseaudit_audit');
        $schoolfield = new xmldb_field('school', XMLDB_TYPE_CHAR, '255', null, false, false, null, 'unitname');
        if (!$dbman->field_exists($table, $schoolfield)) {
            $dbman->add_field($table, $schoolfield);
        }

        $schoolindex = new xmldb_index('school', XMLDB_INDEX_NOTUNIQUE, ['school']);
        if (!$dbman->index_exists($table, $schoolindex)) {
            $dbman->add_index($table, $schoolindex);
        }

        $programlevelindex = new xmldb_index('programlevel', XMLDB_INDEX_NOTUNIQUE, ['programlevel']);
        if (!$dbman->index_exists($table, $programlevelindex)) {
            $dbman->add_index($table, $programlevelindex);
        }

        upgrade_plugin_savepoint(true, 2026070400, 'local', 'courseaudit');
    }

    if ($oldversion < 2026070500) {
        // "Clear all data" used to hard-delete the audit table with nothing kept behind. Archive
        // each cycle here instead, so past cycles remain viewable/comparable after the dashboard
        // is reset for a new one.
        $table = new xmldb_table('local_courseaudit_history');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('cyclelabel', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('unitcode', XMLDB_TYPE_CHAR, '100');
            $table->add_field('unitname', XMLDB_TYPE_CHAR, '255');
            $table->add_field('school', XMLDB_TYPE_CHAR, '255');
            $table->add_field('numstudents', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('lecturername', XMLDB_TYPE_CHAR, '255');
            $table->add_field('programlevel', XMLDB_TYPE_CHAR, '50');
            $table->add_field('modeoflearning', XMLDB_TYPE_CHAR, '50');
            $table->add_field('templatedeployed', XMLDB_TYPE_CHAR, '20');
            $table->add_field('attendancelink', XMLDB_TYPE_CHAR, '20');
            $table->add_field('materialsweeks', XMLDB_TYPE_CHAR, '50');
            $table->add_field('numcatsquizzes', XMLDB_TYPE_CHAR, '20');
            $table->add_field('assign1feedback', XMLDB_TYPE_CHAR, '20');
            $table->add_field('assign2feedback', XMLDB_TYPE_CHAR, '20');
            $table->add_field('assign3feedback', XMLDB_TYPE_CHAR, '20');
            $table->add_field('cat1feedback', XMLDB_TYPE_CHAR, '20');
            $table->add_field('cat2feedback', XMLDB_TYPE_CHAR, '20');
            $table->add_field('comments', XMLDB_TYPE_TEXT);
            $table->add_field('datechecked', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('reviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('matchedterm', XMLDB_TYPE_CHAR, '100');
            $table->add_field('archivedtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('archivedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('cyclelabel', XMLDB_INDEX_NOTUNIQUE, ['cyclelabel']);
            $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070500, 'local', 'courseaudit');
    }

    if ($oldversion < 2026070900) {
        // Programme (e.g. "Bachelor of Commerce") from the matched timetable offering -
        // distinct from programlevel, which is the classified Certificate/.../Professional level.
        $table = new xmldb_table('local_courseaudit_audit');
        $programmefield = new xmldb_field('programme', XMLDB_TYPE_CHAR, '255', null, false, false, null, 'school');
        if (!$dbman->field_exists($table, $programmefield)) {
            $dbman->add_field($table, $programmefield);
        }

        $historytable = new xmldb_table('local_courseaudit_history');
        $historyprogrammefield = new xmldb_field('programme', XMLDB_TYPE_CHAR, '255', null, false, false, null, 'school');
        if ($dbman->table_exists($historytable) && !$dbman->field_exists($historytable, $historyprogrammefield)) {
            $dbman->add_field($historytable, $historyprogrammefield);
        }

        upgrade_plugin_savepoint(true, 2026070900, 'local', 'courseaudit');
    }

    if ($oldversion < 2026071000) {
        // Raw timetable-lecturer / LMS-lecturer values and a timetable-match flag, needed for the
        // new exported Lecturer Mismatch and Unit Mismatch report sheets - see
        // classes/mismatch_reports.php.
        $table = new xmldb_table('local_courseaudit_audit');

        $timetablelecturerfield = new xmldb_field('timetablelecturer', XMLDB_TYPE_CHAR, '255', null, false, false, null, 'lecturername');
        if (!$dbman->field_exists($table, $timetablelecturerfield)) {
            $dbman->add_field($table, $timetablelecturerfield);
        }

        $lmslecturerfield = new xmldb_field('lmslecturer', XMLDB_TYPE_CHAR, '255', null, false, false, null, 'timetablelecturer');
        if (!$dbman->field_exists($table, $lmslecturerfield)) {
            $dbman->add_field($table, $lmslecturerfield);
        }

        $hastimetablematchfield = new xmldb_field('hastimetablematch', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lmslecturer');
        if (!$dbman->field_exists($table, $hastimetablematchfield)) {
            $dbman->add_field($table, $hastimetablematchfield);
        }

        $hastimetablematchindex = new xmldb_index('hastimetablematch', XMLDB_INDEX_NOTUNIQUE, ['hastimetablematch']);
        if (!$dbman->index_exists($table, $hastimetablematchindex)) {
            $dbman->add_index($table, $hastimetablematchindex);
        }

        upgrade_plugin_savepoint(true, 2026071000, 'local', 'courseaudit');
    }

    return true;
}
