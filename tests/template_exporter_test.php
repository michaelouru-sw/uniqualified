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
 * Unit tests for template_exporter's pure value-mapping logic.
 *
 * export() itself needs a live Moodle $DB and file storage and is covered by
 * Moodle-hosted advanced_testcase tests once installed; it was also verified
 * end-to-end against the real master template file during development (see
 * the plugin's deployment notes).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\template_exporter
 */
final class template_exporter_test extends \basic_testcase {

    public function test_map_cell_value_adds_trailing_space_quirk_for_attendance_yes(): void {
        $this->assertSame('Yes ', template_exporter::map_cell_value('attendancelink', 'Yes'));
        $this->assertSame('No', template_exporter::map_cell_value('attendancelink', 'No'));
        $this->assertSame('Not Applicable', template_exporter::map_cell_value('attendancelink', 'Not Applicable'));
    }

    public function test_map_cell_value_casts_numeric_catsquizzes_to_int(): void {
        $this->assertSame(3, template_exporter::map_cell_value('numcatsquizzes', '3'));
        $this->assertSame(3, template_exporter::map_cell_value('numcatsquizzes', 3));
        $this->assertSame('None', template_exporter::map_cell_value('numcatsquizzes', 'None'));
        $this->assertSame('more than 5', template_exporter::map_cell_value('numcatsquizzes', 'more than 5'));
    }

    public function test_map_cell_value_passes_through_other_fields_unchanged(): void {
        $this->assertSame('Bachelors', template_exporter::map_cell_value('programlevel', 'Bachelors'));
    }

    public function test_map_cell_value_casts_numeric_materialsweeks_to_int(): void {
        $this->assertSame(5, template_exporter::map_cell_value('materialsweeks', '5'));
        $this->assertSame(0, template_exporter::map_cell_value('materialsweeks', '0'));
        $this->assertSame(0, template_exporter::map_cell_value('materialsweeks', 0));
    }

    public function test_map_cell_value_returns_null_for_empty_input(): void {
        $this->assertNull(template_exporter::map_cell_value('programlevel', null));
        $this->assertNull(template_exporter::map_cell_value('programlevel', ''));
    }

    public function test_build_row_values_maps_every_expected_column(): void {
        $row = [
            'unitcode' => 'ECO 8101',
            'unitname' => 'ADVANCED ECONOMIC THEORY',
            'numstudents' => 25,
            'lecturername' => 'PROF. CHRISTINE NANJALA',
            'programlevel' => 'Phd',
            'modeoflearning' => 'PG',
            'templatedeployed' => 'Yes',
            'attendancelink' => 'Yes',
            'materialsweeks' => '6',
            'numcatsquizzes' => '2',
            'assign1feedback' => 'Yes',
            'assign2feedback' => 'No',
            'assign3feedback' => 'No',
            'cat1feedback' => 'Yes',
            'cat2feedback' => null,
            'comments' => 'Looks good.',
            'programme' => 'Bachelor of Commerce',
            'school' => 'SOB',
        ];

        $values = template_exporter::build_row_values($row);

        $this->assertSame('ECO 8101', $values['B']);
        $this->assertSame('ADVANCED ECONOMIC THEORY', $values['C']);
        $this->assertSame(25, $values['D']);
        $this->assertSame('PROF. CHRISTINE NANJALA', $values['E']);
        $this->assertSame('Phd', $values['F']);
        $this->assertSame('PG', $values['G']);
        $this->assertSame('Yes', $values['H']);
        $this->assertSame('Yes ', $values['I']); // Trailing-space quirk.
        $this->assertSame(6, $values['J']);
        $this->assertSame(2, $values['K']);
        $this->assertSame('Yes', $values['L']);
        $this->assertSame('No', $values['M']);
        $this->assertSame('No', $values['N']); // Assign 3 Feedback.
        $this->assertSame('Yes', $values['O']);
        $this->assertNull($values['P']);
        $this->assertSame('Looks good.', $values['Q']);
        $this->assertSame('Bachelor of Commerce', $values['R']);
        $this->assertSame('SOB', $values['S']);
    }

    public function test_column_field_map_has_no_gaps_between_b_and_s(): void {
        $letters = array_keys(template_exporter::COLUMN_FIELD_MAP);
        $this->assertSame(
            ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'],
            $letters
        );
    }
}
