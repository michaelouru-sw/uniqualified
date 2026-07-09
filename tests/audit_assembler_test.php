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
 * Unit tests for audit_assembler's pure logic (normalize_mode_of_learning()).
 *
 * build_row()/save_row()/refresh_all() need a live Moodle course and $DB and
 * are covered by Moodle-hosted advanced_testcase tests once installed.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\audit_assembler
 */
final class audit_assembler_test extends \basic_testcase {

    /**
     * @dataProvider roomtype_provider
     */
    public function test_normalize_mode_of_learning(?string $roomtype, ?string $expected): void {
        $this->assertSame($expected, audit_assembler::normalize_mode_of_learning($roomtype));
    }

    public static function roomtype_provider(): array {
        return [
            ['VIRTUAL', 'Virtual'],
            ['Physical', 'Physical'],
            ['lab', 'Lab'],
            ['PG', 'PG'],
            ['STUDIO', 'Studio'],
            ['PROJECT', 'Project'],
            ['ZOOM', 'Zoom'],
            [null, null],
            ['', null],
            ['CLASSROOM 4B', null], // Unrecognised value - left blank for manual entry, not guessed.
        ];
    }
}
