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
 * Fills the site's uploaded master audit template with the current
 * dashboard rows, rather than building a workbook from scratch - this way
 * the template's own formatting, column widths, and Lists-sheet dropdown
 * validations on the Data sheet are preserved untouched.
 *
 * The source template has one known quirk worth documenting: its Data sheet
 * header row has two columns both literally labelled "Assign 1 Feedback"
 * (columns L and N - N appears to be a copy/paste mistake in the original
 * spreadsheet). Given the column order Assign1(L)/Assign2(M)/?(N)/Cat1(O)/
 * Cat2(P), and that N shares the exact same Yes/No dropdown validation as
 * its neighbours, it's mapped here as a 3rd assignment feedback slot
 * ("assign3feedback"). Relabel the template's N1 cell to match if it should
 * say something clearer than the inherited duplicate text.
 *
 * Columns L/M/N/O/P now hold a graded percentage (e.g. '67%') rather than
 * plain Yes/No when the named activity exists - only a bare 'No' when it
 * doesn't. Like materialsweeks below, this doesn't match any option in the
 * Lists sheet's Yes/No dropdown validation for these columns, but the cell
 * still displays the percentage text correctly; clicking the dropdown arrow
 * just won't highlight a matching preset option.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_exporter {

    /** @var string Name of the sheet in the master template that holds the audit rows. */
    const DATA_SHEET_NAME = 'Data';

    /** @var int First row (1-indexed) that audit data is written to; row 1 is the header. */
    const FIRST_DATA_ROW = 2;

    /**
     * Data sheet column letter => local_courseaudit_audit field name.
     * Column A (Date Checked) is handled separately since it needs Excel
     * date serialization rather than a plain value.
     *
     * @var array<string, string|null>
     */
    const COLUMN_FIELD_MAP = [
        'B' => 'unitcode',
        'C' => 'unitname',
        'D' => 'numstudents',
        'E' => 'lecturername',
        'F' => 'programlevel',
        'G' => 'modeoflearning',
        'H' => 'templatedeployed',
        'I' => 'attendancelink',
        'J' => 'materialsweeks',
        'K' => 'numcatsquizzes',
        'L' => 'assign1feedback',
        'M' => 'assign2feedback',
        'N' => 'assign3feedback',
        'O' => 'cat1feedback',
        'P' => 'cat2feedback',
        'Q' => 'comments',
        // R/S extend beyond the original template's own column layout - see EXTRA_HEADERS and
        // export(), which write these two header labels in since the uploaded template has no
        // header text of its own for them.
        'R' => 'programme',
        'S' => 'school',
    ];

    /**
     * Header labels for the columns appended beyond the master template's own original layout
     * (see COLUMN_FIELD_MAP's R/S entries). Written into row 1 on every export, since the
     * uploaded template itself has no header text for these - they don't exist in the
     * institution's original approved spreadsheet.
     *
     * @var array<string, string>
     */
    const EXTRA_HEADERS = [
        'R' => 'Programme',
        'S' => 'School',
    ];

    /**
     * Converts a canonical audit-row field value into the exact string/number
     * expected by the template's dropdown Lists sheet. Pure and testable
     * independently of any spreadsheet library or database.
     *
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    public static function map_cell_value(string $field, $value) {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'attendancelink' && $value === 'Yes') {
            // The source Lists sheet's "Zoom link" option is literally 'Yes ' (trailing space).
            return 'Yes ';
        }

        if (($field === 'numcatsquizzes' || $field === 'materialsweeks') && is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Builds the column-letter => value map for one audit row (columns B-Q;
     * column A/date is handled by the caller). Pure/testable.
     *
     * @param array $row Associative array keyed by local_courseaudit_audit field names.
     * @return array<string, mixed> Column letter => value to write (null entries should be skipped by the caller).
     */
    public static function build_row_values(array $row): array {
        $values = [];
        foreach (self::COLUMN_FIELD_MAP as $column => $field) {
            if ($field === null) {
                $values[$column] = null;
                continue;
            }
            $values[$column] = self::map_cell_value($field, $row[$field] ?? null);
        }
        return $values;
    }

    /**
     * Loads the site's uploaded master template and fills its Data sheet
     * with the current audit rows.
     *
     * @param array|null $filters As returned by dashboard_filters::from_request(); null/empty
     *        exports every row, same as the dashboard shows with no filters applied.
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws \moodle_exception If no master template has been uploaded, or it has no Data sheet.
     */
    public static function export(?array $filters = null): \PhpOffice\PhpSpreadsheet\Spreadsheet {
        global $DB;

        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_courseaudit', 'mastertemplate', 0, 'itemid', false);
        if (empty($files)) {
            throw new \moodle_exception('exportmissingtemplate', 'local_courseaudit');
        }
        $file = reset($files);

        $tmpdir = make_request_directory();
        $templatepath = $tmpdir . '/master-template.xlsx';
        $file->copy_content_to($templatepath);

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatepath);
        $datasheet = $spreadsheet->getSheetByName(self::DATA_SHEET_NAME);
        if ($datasheet === null) {
            throw new \moodle_exception('exportmissingdatasheet', 'local_courseaudit');
        }

        foreach (self::EXTRA_HEADERS as $column => $label) {
            $datasheet->setCellValue($column . '1', $label);
        }

        list($wheresql, $params) = dashboard_filters::build_where($filters ?? [], true);

        $sql = "SELECT a.*, c.fullname AS coursefullname, cc.name AS categoryname
                  FROM {local_courseaudit_audit} a
                  JOIN {course} c ON c.id = a.courseid
             LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE $wheresql
              ORDER BY cc.name ASC, c.fullname ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $rownum = self::FIRST_DATA_ROW;
        foreach ($rows as $row) {
            if (!empty($row->datechecked)) {
                $cell = $datasheet->getCell('A' . $rownum);
                $cell->setValue(\PhpOffice\PhpSpreadsheet\Shared\Date::timestampToExcel((int) $row->datechecked));
                $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }

            foreach (self::build_row_values((array) $row) as $column => $value) {
                if ($value === null) {
                    continue;
                }
                $datasheet->setCellValue($column . $rownum, $value);
            }

            $rownum++;
        }

        self::add_report_sheets($spreadsheet);

        return $spreadsheet;
    }

    /**
     * Appends the four cross-check report sheets (see mismatch_reports) as extra tabs on the
     * exported workbook, alongside the master template's own Data/Lists sheets. These are
     * built fresh from whatever the last "Refresh from Moodle" left in local_courseaudit_audit -
     * run a refresh before exporting if the timetable or Moodle data has changed since.
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @return void
     */
    protected static function add_report_sheets(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void {
        $unitmismatch = mismatch_reports::unit_mismatch();
        $unitsheet = self::add_sheet($spreadsheet, 'Unit Mismatch');
        $timetableonlyheaders = ['Unit Code', 'Unit Name', 'School', 'Programme', 'Lecturer', 'Campus', 'Mode of Study', 'Term'];
        $lmsonlyheaders = ['Unit Code', 'Unit Name', 'School', 'Programme', 'Lecturer', 'No. of Students', 'Course'];
        $lastcolumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(count($timetableonlyheaders), count($lmsonlyheaders)));

        $unitrow = 1;
        $unitsheet->setCellValue('A' . $unitrow, 'In Timetable, Not on LMS');
        $unitsheet->getStyle('A' . $unitrow)->getFont()->setBold(true);
        $unitrow++;
        foreach ($timetableonlyheaders as $i => $header) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $unitsheet->setCellValue($letter . $unitrow, $header);
            $unitsheet->getStyle($letter . $unitrow)->getFont()->setBold(true);
        }
        $unitrow++;
        foreach ($unitmismatch['timetableonly'] as $row) {
            $values = [$row->unitcode, $row->unitname, $row->school, $row->programme, $row->lecturername,
                $row->campus, $row->modeofstudy, $row->term];
            foreach ($values as $i => $value) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $unitsheet->setCellValue($letter . $unitrow, $value);
            }
            $unitrow++;
        }

        $unitrow += 2;
        $unitsheet->setCellValue('A' . $unitrow, 'On LMS, Not in Timetable');
        $unitsheet->getStyle('A' . $unitrow)->getFont()->setBold(true);
        $unitrow++;
        foreach ($lmsonlyheaders as $i => $header) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $unitsheet->setCellValue($letter . $unitrow, $header);
            $unitsheet->getStyle($letter . $unitrow)->getFont()->setBold(true);
        }
        $unitrow++;
        foreach ($unitmismatch['lmsonly'] as $row) {
            $values = [$row->unitcode, $row->unitname, $row->school, $row->programme, $row->lecturername,
                (int) $row->numstudents, $row->coursefullname];
            foreach ($values as $i => $value) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $unitsheet->setCellValue($letter . $unitrow, $value);
            }
            $unitrow++;
        }
        self::autosize($unitsheet, range('A', $lastcolumn));

        $lecturersheet = self::add_sheet($spreadsheet, 'Lecturer Mismatch');
        self::write_table($lecturersheet, ['Unit Code', 'Unit Name', 'Timetable Lecturer', 'LMS Lecturer', 'Course'],
            array_map(fn($row) => [$row->unitcode, $row->unitname, $row->timetablelecturer, $row->lmslecturer, $row->coursefullname],
                mismatch_reports::lecturer_mismatch()));

        $duplicatesheet = self::add_sheet($spreadsheet, 'Duplicate Units');
        $duplicaterows = [];
        foreach (mismatch_reports::duplicate_units() as $group) {
            foreach ($group as $row) {
                $duplicaterows[] = [$row->unitcode, $row->unitname, $row->courseshortname, $row->coursefullname, count($group)];
            }
        }
        self::write_table($duplicatesheet,
            ['Unit Code', 'Unit Name', 'Course Shortname', 'Course Fullname', 'No. of Courses Sharing This Exact Unit'],
            $duplicaterows);

        $nostudentssheet = self::add_sheet($spreadsheet, 'No Students With Lecturer');
        self::write_table($nostudentssheet, ['Unit Code', 'Unit Name', 'Lecturer', 'No. of Students', 'Course'],
            array_map(fn($row) => [$row->unitcode, $row->unitname, $row->lecturername, (int) $row->numstudents, $row->coursefullname],
                mismatch_reports::no_students_with_lecturer()));
    }

    /**
     * Adds a new, empty worksheet to the workbook with the given title.
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param string $title
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    protected static function add_sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $title): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        return $sheet;
    }

    /**
     * Writes a bold header row followed by data rows onto a sheet, starting at A1.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string[] $headers
     * @param array $rows Each element an indexed array matching $headers' column order.
     * @return void
     */
    protected static function write_table(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $rows): void {
        foreach ($headers as $col => $header) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
            $sheet->setCellValue($letter . '1', $header);
            $sheet->getStyle($letter . '1')->getFont()->setBold(true);
        }
        foreach ($rows as $rowindex => $row) {
            foreach (array_values($row) as $col => $value) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                $sheet->setCellValue($letter . ($rowindex + 2), $value);
            }
        }
        $lastcolumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        self::autosize($sheet, range('A', $lastcolumn));
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string[] $columns
     * @return void
     */
    protected static function autosize(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $columns): void {
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
