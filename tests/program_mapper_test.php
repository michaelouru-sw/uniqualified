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
 * Unit tests for program_mapper's pure heuristic logic (suggest_level()).
 *
 * The DB-backed methods (register_seen, save_classifications, get_level) need
 * a live Moodle $DB and are covered separately by Moodle-hosted advanced_testcase
 * tests once the plugin is installed; see tests/program_mapper_db_test.php.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\program_mapper
 */
final class program_mapper_test extends \basic_testcase {

    /**
     * @dataProvider real_codes_provider
     */
    public function test_suggest_level_matches_real_programme_codes(string $code, ?string $name, ?string $expected): void {
        $this->assertSame($expected, program_mapper::suggest_level($code, $name));
    }

    public static function real_codes_provider(): array {
        return [
            'PHD Finance' => ['KCAPHDFIN', 'PHD FIN', 'Phd'],
            'PHD Business' => ['KCAPHDBM', null, 'Phd'],
            'MBA' => ['KCAMBA', 'MBA CM', 'Masters'],
            'MSc' => ['KCAMSCCOM', null, 'Masters'],
            'BCom' => ['KCABCOM', 'BCOM', 'Bachelors'],
            'BSc' => ['KCABSCFA', 'BSC FA', 'Bachelors'],
            'IBM undergrad' => ['KCABSIBM', 'IBM', 'Bachelors'],
            'ACCA professional' => ['ACCA', 'ACCA', 'Professional'],
            'CIFA professional' => ['CIFA', 'CIFA', 'Professional'],
            'CPA professional' => ['CPA', 'CPA FND I FT', 'Professional'],
            'ATD diploma' => ['ATD', 'ATD LEVEL I FT', 'Diploma'],
            'Craft diploma' => ['KCACIT', 'CRAFT ICT MOD I TERMI', 'Diploma'],
            'Artisan certificate' => ['802', 'ACS MOD 1A ARTISAN', 'Certificate'],
            'Unrecognisable numeric code with no hints' => ['999', 'MYSTERY', null],
            'Short course, no keyword match' => ['KCAUWEB', 'WEBDEV', null],
        ];
    }

    public function test_suggest_level_returns_null_for_empty_input(): void {
        $this->assertNull(program_mapper::suggest_level('', null));
    }
}
