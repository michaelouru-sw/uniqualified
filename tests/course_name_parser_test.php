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
 * Unit tests for course_name_parser, covering the confirmed real course
 * fullname convention: "Unit Code:Unit Name:Mode of Study:Lecturer Name:Location:Stream".
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\course_name_parser
 */
final class course_name_parser_test extends \basic_testcase {

    public function test_parse_fullname_real_example(): void {
        $result = course_name_parser::parse_fullname(
            'CSL 2202:BEHAVIOUR MODIFICATION:PTDL:MARGRET MURUGI NJIRU:ZOOM:Stream A'
        );

        $this->assertTrue($result['matched']);
        $this->assertSame('CSL 2202', $result['unitcode']);
        $this->assertSame('BEHAVIOUR MODIFICATION', $result['unitname']);
        $this->assertSame('PTDL', $result['modeofstudy']);
        $this->assertSame('MARGRET MURUGI NJIRU', $result['lecturername']);
        $this->assertSame('ZOOM', $result['location']);
        $this->assertSame('Stream A', $result['stream']);
    }

    public function test_parse_fullname_normalizes_unitcode_spacing(): void {
        $result = course_name_parser::parse_fullname('MAT1102:DISCRETE MATHEMATICS:FT:JOHN DOE:PHYSICAL:Stream B');
        $this->assertSame('MAT1102', $result['unitcode']);

        $result2 = course_name_parser::parse_fullname('MAT   1102:DISCRETE MATHEMATICS:FT:JOHN DOE:PHYSICAL:Stream B');
        $this->assertSame('MAT 1102', $result2['unitcode']);
    }

    public function test_parse_fullname_returns_unmatched_for_plain_course_name(): void {
        $result = course_name_parser::parse_fullname('Project Management');
        $this->assertFalse($result['matched']);
        $this->assertNull($result['unitcode']);
        $this->assertNull($result['lecturername']);
    }

    public function test_parse_fullname_handles_extra_colons_in_stream_field(): void {
        // explode() is capped at 6 segments, so a stray colon in the trailing
        // field (e.g. "Stream A: Evening") lands entirely in 'stream' rather
        // than shifting/truncating the rest of the record.
        $result = course_name_parser::parse_fullname(
            'CSL 2202:BEHAVIOUR MODIFICATION:PTDL:MARGRET MURUGI NJIRU:ZOOM:Stream A: Evening'
        );
        $this->assertSame('ZOOM', $result['location']);
        $this->assertSame('Stream A: Evening', $result['stream']);
    }

    public function test_parse_fullname_partial_record_is_not_matched(): void {
        // Missing lecturer/location/stream - still returns what it found.
        $result = course_name_parser::parse_fullname('CSL 2202:BEHAVIOUR MODIFICATION:PTDL');
        $this->assertFalse($result['matched']);
        $this->assertSame('CSL 2202', $result['unitcode']);
        $this->assertSame('PTDL', $result['modeofstudy']);
        $this->assertNull($result['lecturername']);
    }

    public function test_parse_fullname_with_term_prefix(): void {
        $result = course_name_parser::parse_fullname(
            'TRIM3 26:CSL 4102:COUNSELING IN THE WORKPLACE:PTDL:VIVIAN ARANGO ACHIENG:ZOOM:Stream A'
        );
        $this->assertTrue($result['matched']);
        $this->assertSame('TRIM3 26', $result['term']);
        $this->assertSame('CSL 4102', $result['unitcode']);
        $this->assertSame('COUNSELING IN THE WORKPLACE', $result['unitname']);
        $this->assertSame('PTDL', $result['modeofstudy']);
        $this->assertSame('VIVIAN ARANGO ACHIENG', $result['lecturername']);
        $this->assertSame('ZOOM', $result['location']);
        $this->assertSame('Stream A', $result['stream']);
    }

    public function test_parse_fullname_with_numbered_term_prefix_and_campus(): void {
        $result = course_name_parser::parse_fullname(
            '01_TRIM3 26:MAT 106:BUSINESS MATHEMATICS:FT:MAIN:GEORGE WANJANGI WAMAI:4-1:Stream A'
        );
        $this->assertSame('01_TRIM3 26', $result['term']);
        $this->assertSame('MAT 106', $result['unitcode']);
        $this->assertSame('FT', $result['modeofstudy']);
        $this->assertSame('MAIN', $result['campus']);
        $this->assertSame('GEORGE WANJANGI WAMAI', $result['lecturername']);
        $this->assertSame('4-1', $result['location']);
        $this->assertSame('Stream A', $result['stream']);
    }

    public function test_parse_fullname_with_empty_mode(): void {
        $result = course_name_parser::parse_fullname(
            'TRIM3 26:CSL 2102:COUNSELING CHILDREN, YOUTH AND ADULTS::VIVIAN ARANGO ACHIENG:ZOOM:Stream A'
        );
        $this->assertSame('CSL 2102', $result['unitcode']);
        $this->assertNull($result['modeofstudy']);
        $this->assertSame('VIVIAN ARANGO ACHIENG', $result['lecturername']);
        $this->assertSame('ZOOM', $result['location']);
        $this->assertFalse($result['matched']);
    }

    public function test_parse_fullname_without_prefix_has_no_term(): void {
        $result = course_name_parser::parse_fullname('CSL 2202:BEHAVIOUR MODIFICATION:PTDL:MARGRET MURUGI NJIRU:ZOOM:Stream A');
        $this->assertNull($result['term']);
        $this->assertNull($result['campus']);
    }

    public function test_parse_fullname_empty_string(): void {
        $result = course_name_parser::parse_fullname('');
        $this->assertFalse($result['matched']);
        $this->assertNull($result['unitcode']);
    }

    /**
     * @dataProvider mode_of_study_equivalence_provider
     */
    public function test_normalize_mode_of_study_treats_equivalent_forms_as_equal(string $a, string $b): void {
        $this->assertSame(
            course_name_parser::normalize_mode_of_study($a),
            course_name_parser::normalize_mode_of_study($b)
        );
    }

    public static function mode_of_study_equivalence_provider(): array {
        return [
            'fused vs slash' => ['PTDL', 'PT/DL'],
            'fused vs ampersand with spaces' => ['PTDL', 'PT & DL'],
            'fused vs space separated' => ['PTDL', 'PT DL'],
            'case insensitive' => ['ptdl', 'PTDL'],
        ];
    }

    public function test_normalize_mode_of_study_distinguishes_different_modes(): void {
        $this->assertNotSame(
            course_name_parser::normalize_mode_of_study('FT'),
            course_name_parser::normalize_mode_of_study('PTDL')
        );
    }

    public function test_normalize_mode_of_study_null_passthrough(): void {
        $this->assertNull(course_name_parser::normalize_mode_of_study(null));
        $this->assertNull(course_name_parser::normalize_mode_of_study(''));
    }
}
