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
 * Unit tests for the timetable_parser pure-array parsing logic.
 *
 * Fixtures below mirror the real "School of Business" (single header row,
 * one sheet per cohort) and "PTTI" (title rows + stacked programme blocks)
 * timetable layouts, including the messy edge cases actually seen in
 * production exports: numeric programme codes, a block label re-using the
 * trimester column, and a repeated header row partway down a sheet.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\timetable_parser
 */
final class timetable_parser_test extends \basic_testcase {

    public function test_normalize_header_collapses_whitespace_and_case(): void {
        $this->assertSame('UNIT CODE', timetable_parser::normalize_header(" unit   code \n"));
        $this->assertSame('', timetable_parser::normalize_header(null));
    }

    public function test_normalize_code_collapses_whitespace(): void {
        $this->assertSame('ECO 8101', timetable_parser::normalize_code('  eco  8101 '));
    }

    public function test_find_header_locates_row_and_builds_colmap(): void {
        $matrix = [
            ['#DAY', 'DAY OF WEEK', 'TIME SLOT', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME',
                'LECTURER NAME', 'HOURS', 'SCHOOL', 'DPT', 'PROGRAM CODE', 'PROGRAM', 'MODE OF STUDY',
                'CAMPUS', 'TRIMESTER', 'OPTION'],
        ];
        [$row, $colmap] = timetable_parser::find_header($matrix);
        $this->assertSame(0, $row);
        $this->assertSame(5, $colmap['unitcode']);
        $this->assertSame(6, $colmap['unitname']);
        $this->assertSame(7, $colmap['lecturername']);
        $this->assertSame(11, $colmap['programcode']);
        $this->assertSame(13, $colmap['modeofstudy']);
        $this->assertSame(3, $colmap['roomtype']);
        $this->assertSame(9, $colmap['school']);
    }

    /**
     * Mirrors the university's master timetable format (one file per trimester, replacing the
     * old per-school files): every row already carries its own School and calendar-term Period,
     * which take priority over the admin-typed term/filename-derived institution - see
     * timetable_importer::import_files(). Header positions deliberately differ from other
     * fixtures in this file, matching how the real workbook's three trimester sheets don't
     * share identical column layouts with each other either.
     */
    public function test_parse_matrix_master_timetable_format_reads_school_and_period(): void {
        $matrix = [
            ['DAY#', 'DAY', 'TIME', 'ROOM TYPE', 'VENUE', 'UNIT CODE', 'UNIT NAME', 'STAFF NUMBER',
                'LECTURER NAME', 'PROGRAM', 'CAMPUS', 'HRS', 'SCHOOL', 'PROGRAM CODE', 'MODE OF STUDY',
                'TRIMESTER', 'PERIOD'],
            [1, 'WEDNESDAY', '1400-1700 HRS', 'VIRTUAL', 'ZOOM', 'ISS 2101', 'MANAGEMENT INFORMATION SYSTEMS',
                12345, 'JUSTUS MUTIA', 'BAC', 'MAIN', 3, 'SOT', 'KCABAC', 'FT', 'YEAR1TRIM3', 'JAN-APRIL 2026'],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'MASTER TT TRIM 1 2026');

        $this->assertCount(1, $rows);
        $this->assertSame('ISS 2101', $rows[0]['unitcode']);
        $this->assertSame('SOT', $rows[0]['school']);
        $this->assertSame('JAN-APRIL 2026', $rows[0]['period']);
        $this->assertSame('KCABAC', $rows[0]['programcode']);
        $this->assertSame('FT', $rows[0]['modeofstudy']);
    }

    public function test_find_header_returns_null_when_absent(): void {
        $matrix = [['Some', 'Random', 'Title', 'Row']];
        $this->assertNull(timetable_parser::find_header($matrix));
    }

    public function test_detect_block_label_only_matches_lone_first_column_string(): void {
        $this->assertSame('ACCA', timetable_parser::detect_block_label(['ACCA', null, null, null]));
        $this->assertNull(timetable_parser::detect_block_label([null, null, null]));
        $this->assertNull(timetable_parser::detect_block_label(['ACCA', 'extra', null]));
        $this->assertNull(timetable_parser::detect_block_label([null, 'not first column', null]));
    }

    public function test_parse_matrix_sob_style_single_header(): void {
        $header = ['#DAY', 'DAY OF WEEK', 'TIME SLOT', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME',
            'LECTURER NAME', 'HOURS', 'SCHOOL', 'DPT', 'PROGRAM CODE', 'PROGRAM', 'MODE OF STUDY',
            'CAMPUS', 'TRIMESTER', 'OPTION'];
        $matrix = [
            $header,
            [1, 'MONDAY', '1700-2100 HRS', 'PG', 'ZOOM', 'ECO 8101', 'ADVANCED ECONOMIC THEORY',
                'PROF. CHRISTINE NANJALA', 4, 'SOB', 'ECOSTA', 'KCAPHDFIN', 'PHD FIN', 'PT',
                'MAIN', 'YEAR1TRIM1', null],
            [3, 'WEDNESDAY', '1730-2030 HRS', 'VIRTUAL', 'ZOOM', 'CUU 1103', 'COMMUNICATION SKILLS',
                'SARAH MASESE', 3, 'SOB', 'PAFMES', 'KCABCOM', 'BCOM', 'DL', 'MAIN', 'YEAR1TRIM1', null],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'PHD PT MAIN');

        $this->assertCount(2, $rows);
        $this->assertSame('ECO 8101', $rows[0]['unitcode']);
        $this->assertSame('ADVANCED ECONOMIC THEORY', $rows[0]['unitname']);
        $this->assertSame('PROF. CHRISTINE NANJALA', $rows[0]['lecturername']);
        $this->assertSame('KCAPHDFIN', $rows[0]['programcode']);
        $this->assertSame('PG', $rows[0]['roomtype']);
        $this->assertSame('ZOOM', $rows[0]['roomcode']);
        $this->assertNull($rows[0]['blocklabel']);
        $this->assertSame('KCABCOM', $rows[1]['programcode']);
        $this->assertSame('VIRTUAL', $rows[1]['roomtype']);
    }

    public function test_parse_matrix_ptti_style_title_rows_and_blocks(): void {
        $header = ['DAY#', 'DAY', 'TIME', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME',
            'UNIT LECTURER', 'PROGRAM CODE', 'PROGRAM NAME', 'MODE', 'TRIM', 'STREAM'];
        $matrix = [
            [null, null, 'PROFESSIONAL AND TECHNICAL TRAINING INSTITUTE - MAIN CAMPUS  MAY-AUG TT 2026 '],
            [null, null, null],
            $header,
            ['ACCA', null, null, null, null, null, null, null, null, null, null, null, null],
            [4, 'THURSDAY', '1100-1400 HRS', 'PHYSICAL', 'TC 3-9', 'APL 1102', 'MANAGEMENT ACCOUNTING',
                'VERILE OPILO', 'ACCA', 'ACCA', 'FT', 'MAY-AUG', 'A'],
            [3, 'WEDNESDAY', '1400-1700 HRS', 'PHYSICAL', 'TC 3-9', 'APL 1102', 'MANAGEMENT ACCOUNTING',
                'VERILE OPILO', 'ACCA', 'ACCA', 'FT', 'MAY-AUG', 'A'],
            ['ARTISAN SM TERMI', null, null, null, null, null, null, null, null, null, null, null, null],
            [2, 'TUESDAY', '1100-1400 HRS', 'PHYSICAL', 'TC 2-3', 'KASM 201', 'Salesmanship I',
                'ROSALIA KAGENI', 802, 'ACS MOD 1A', 'FT', 'ARTISAN SM TERMI', 'A'],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'PTTI MAIN CAMPUS-RUARAKA');

        $this->assertCount(3, $rows);
        $this->assertSame('APL 1102', $rows[0]['unitcode']);
        $this->assertSame('ACCA', $rows[0]['programcode']);
        $this->assertSame('ACCA', $rows[0]['blocklabel']);

        // Numeric programme code from a block that doesn't follow the alphanumeric convention.
        $this->assertSame('802', $rows[2]['unitcode'] === 'KASM 201' ? $rows[2]['programcode'] : null);
        $this->assertSame('KASM 201', $rows[2]['unitcode']);
        $this->assertSame('ARTISAN SM TERMI', $rows[2]['blocklabel']);
    }

    public function test_parse_matrix_skips_repeated_header_row(): void {
        $header = ['DAY#', 'DAY', 'TIME', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME',
            'UNIT LECTURER', 'PROGRAM CODE', 'PROGRAM NAME', 'MODE', 'TRIM', 'STREAM'];
        $matrix = [
            $header,
            [1, 'MONDAY', '0800-1100 HRS', 'LAB', 'TC 1-4', 'QBK 101', 'QUICKBOOKS', 'FREDRICK OCHIENG',
                'KCAUQBKS', 'QUICK BOOKS', 'FT', 'MAY-AUG', 'A'],
            $header,
            [1, 'MONDAY', '1400-1700 HRS', 'LAB', 'TC 1-2', 'WEB 101', 'WEB DEVELOPMENT', 'EVANS ONGULO',
                'KCAUWEB', 'WEBDEV', 'FT', 'GROUP A', 'A'],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'PTTI MAIN CAMPUS-RUARAKA');

        $this->assertCount(2, $rows);
        $this->assertSame('QBK 101', $rows[0]['unitcode']);
        $this->assertSame('WEB 101', $rows[1]['unitcode']);
    }

    public function test_parse_matrix_returns_empty_when_no_header_found(): void {
        $matrix = [['nothing', 'useful', 'here']];
        $this->assertSame([], timetable_parser::parse_matrix($matrix, 'Empty Sheet'));
    }

    public function test_parse_matrix_same_unit_code_can_appear_under_multiple_programmes(): void {
        // CUU 1103 "Communication Skills" is genuinely taught as separate offerings
        // across several degree programmes/modes - the parser must not silently merge them.
        $header = ['#DAY', 'DAY OF WEEK', 'TIME SLOT', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME',
            'LECTURER NAME', 'HOURS', 'SCHOOL', 'DPT', 'PROGRAM CODE', 'PROGRAM', 'MODE OF STUDY',
            'CAMPUS', 'TRIMESTER', 'OPTION'];
        $matrix = [
            $header,
            [3, 'WEDNESDAY', '1730-2030 HRS', 'VIRTUAL', 'ZOOM', 'CUU 1103', 'COMMUNICATION SKILLS',
                'SARAH MASESE', 3, 'SOB', 'PAFMES', 'KCABCOM', 'BCOM', 'DL', 'MAIN', 'YEAR1TRIM1', null],
            [1, 'MONDAY', '1730-2030 HRS', 'VIRTUAL', 'ZOOM', 'CUU 1103', 'COMMUNICATION SKILLS',
                'EDWARD GURA', 3, 'SOB', 'PAFMES', 'KCABCOM', 'BCOM', 'FT', 'MAIN', 'YEAR1TRIM1', null],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'MIXED');

        $this->assertCount(2, $rows);
        $this->assertSame('SARAH MASESE', $rows[0]['lecturername']);
        $this->assertSame('EDWARD GURA', $rows[1]['lecturername']);
        $this->assertNotSame($rows[0]['modeofstudy'], $rows[1]['modeofstudy']);
    }

    public function test_strip_intake_suffix_removes_trailing_batch_number(): void {
        $this->assertSame('MISM', timetable_parser::strip_intake_suffix('MISM 1.1'));
        $this->assertSame('MISM', timetable_parser::strip_intake_suffix('MISM 1.2'));
        $this->assertSame('BBIT', timetable_parser::strip_intake_suffix('BBIT 1.1'));
        // No trailing bare number - PTTI-style labels must be left untouched.
        $this->assertSame('ARTISAN SM TERMI', timetable_parser::strip_intake_suffix('ARTISAN SM TERMI'));
        $this->assertSame('ACCA PT', timetable_parser::strip_intake_suffix('ACCA PT'));
    }

    public function test_extract_campus_mode_from_sheetname(): void {
        $this->assertSame(['campus' => 'MAIN', 'mode' => 'FT'],
            timetable_parser::extract_campus_mode_from_sheetname('BIT MAIN FT'));
        $this->assertSame(['campus' => 'KSM', 'mode' => 'FT'],
            timetable_parser::extract_campus_mode_from_sheetname('BIT KSM FT'));
        $this->assertSame(['campus' => 'MAIN', 'mode' => 'PT/DL'],
            timetable_parser::extract_campus_mode_from_sheetname('BBIT MAIN PT & DL'));
        $this->assertSame(['campus' => null, 'mode' => null],
            timetable_parser::extract_campus_mode_from_sheetname('MASTER TT TRIM 2 2026'));
    }

    public function test_scan_pre_header_labels_finds_title_and_code_above_header(): void {
        $matrix = [
            ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY  (BIT FT MAIN)'],
            ['BIT 1.1'],
            ['DAY NO', 'DAY', 'TIME', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME', 'TRIMESTER'],
        ];
        $result = timetable_parser::scan_pre_header_labels($matrix, 2);
        $this->assertSame('BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY  (BIT FT MAIN)', $result['title']);
        $this->assertSame('BIT', $result['code']);
    }

    public function test_scan_pre_header_labels_returns_nulls_when_header_is_first_row(): void {
        // The "one flat sheet covering many programmes" case: there's nothing above
        // the header to find, and falling back to the sheet name would be wrong.
        $matrix = [
            ['TH', 'DAY OF WEEK', 'TIME SLOT', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME'],
        ];
        $result = timetable_parser::scan_pre_header_labels($matrix, 0);
        $this->assertNull($result['title']);
        $this->assertNull($result['code']);
    }

    /**
     * Real-world case: SD-IS/NAC/DSAI-style sheets have no PROGRAM CODE, MODE OF
     * STUDY, or CAMPUS columns at all - a descriptive title and short code sit
     * above the header instead, and campus/mode live only in the sheet name.
     */
    public function test_parse_matrix_derives_program_and_mode_from_title_rows_and_sheetname(): void {
        $matrix = [
            ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY  (BIT FT MAIN)'],
            ['BIT 1.1'],
            ['DAY NO', 'DAY', 'TIME', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME', 'TRIMESTER'],
            [1, 'MONDAY', '1400-1700 HRS', 'PHYSICAL', 'LT 1-1', 'MAT 1102', 'DISCRETE MATHEMATICS', 'YEAR1TRIM1'],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'BIT MAIN FT');

        $this->assertCount(1, $rows);
        $this->assertSame('MAT 1102', $rows[0]['unitcode']);
        $this->assertNull($rows[0]['lecturername']);
        $this->assertSame('BIT', $rows[0]['programcode']);
        $this->assertSame('BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY  (BIT FT MAIN)', $rows[0]['programname']);
        $this->assertSame('FT', $rows[0]['modeofstudy']);
        $this->assertSame('MAIN', $rows[0]['campus']);
        $this->assertSame('PHYSICAL', $rows[0]['roomtype']);
    }

    /**
     * A repeated batch/intake block label partway down a title-fallback sheet
     * (e.g. "MISM 1.2") should collapse to the same programme code as the
     * pre-header fallback ("MISM 1.1" -> "MISM"), not create a second one.
     */
    public function test_parse_matrix_mid_sheet_intake_block_matches_preheader_fallback_code(): void {
        $matrix = [
            ['MASTERS OF INFORMATION SYSTEMS MANAGEMENT'],
            ['MISM 1.1'],
            ['DAY NO_', 'DAY OF WEEK', 'TIME SLOT', 'ROOM TYPE', 'ROOM CODE', 'UNIT CODE', 'UNIT NAME', 'TRIMESTER'],
            [4, 'THURSDAY', '1730-2030 HRS', 'PG', 'ZOOM', 'CPP 6101', 'OBJECT-ORIENTED TECHNOLOGIES', 'YEAR1TRIM1'],
            ['MISM 1.2'],
            [4, 'THURSDAY', '1730-2030 HRS', 'PG', 'ZOOM', 'DSA 6104', 'ADVANCED DATABASE SYSTEMS', 'YEAR1TRIM1'],
        ];

        $rows = timetable_parser::parse_matrix($matrix, 'MISM MAIN PT');

        $this->assertCount(2, $rows);
        $this->assertSame('MISM', $rows[0]['programcode']);
        $this->assertSame('MISM', $rows[1]['programcode']);
    }

    /**
     * Real-world case: a plain "code -> description" reference sheet (e.g. "Units
     * & Host Departments") can coincidentally have UNIT CODE/UNIT NAME columns
     * without being an actual timetable - it must not be mistaken for one.
     */
    public function test_parse_matrix_rejects_lookup_table_lacking_scheduling_columns(): void {
        $matrix = [
            ['Unit Code', 'UNIT NAME', 'UNIT HOST DEPARTMENT', 'LEN'],
            ['PSY 2102', 'Issues in Adolescent Psychology', 'SS', 8],
            ['PSY 2105', 'Motivation and Emotions', 'SS', 8],
        ];

        $this->assertSame([], timetable_parser::parse_matrix($matrix, 'UNITS & HOST DPTS'));
    }
}
