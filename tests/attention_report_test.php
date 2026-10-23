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
 * Unit tests for the "needs attention" rankings.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\attention_report
 */
final class attention_report_test extends \basic_testcase {

    /** An audit-like row where nothing is compliant unless overridden. */
    protected static function audit(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'lecturername' => 'Jane Doe', 'reviewed' => 0, 'templatedeployed' => 'No', 'attendancelink' => 'No',
            'materialsweeks' => '0', 'numcatsquizzes' => 'None', 'assign1feedback' => 'No',
            'assign2feedback' => 'No', 'assign3feedback' => 'No', 'cat1feedback' => 'No', 'cat2feedback' => 'No',
        ]);
    }

    /** A snapshot row; only the template count matters here (overall = template% / 5). */
    protected static function snap(string $school, int $day, int $courses, int $template): \stdClass {
        return (object) ['school' => $school, 'snapday' => $day, 'courses' => $courses, 'templateyes' => $template];
    }

    public function test_course_score_is_the_average_of_the_five_requirement_groups(): void {
        $this->assertSame(0.0, attention_report::course_score(self::audit()));
        $this->assertSame(84.0, attention_report::course_score(self::audit([
            'templatedeployed' => 'Yes', 'attendancelink' => 'Yes', 'materialsweeks' => '3',
            'numcatsquizzes' => '2', 'assign1feedback' => '67%',
        ])));
    }

    protected static function school_rows(): array {
        return [
            self::snap('A', 20261016, 10, 10), self::snap('A', 20261023, 10, 5),
            self::snap('B', 20261016, 10, 5), self::snap('B', 20261023, 10, 10),
            self::snap('C', 20261023, 4, 4),
            self::snap('', 20261023, 50, 0),
        ];
    }

    public function test_rank_schools_orders_lowest_first_and_skips_unassigned(): void {
        $ranked = attention_report::rank_schools(self::school_rows());

        $this->assertSame(['A', 'B', 'C'], array_column($ranked['lowest'], 'school'));
        $this->assertSame(10.0, $ranked['lowest'][0]['overall']);
        $this->assertSame(-10.0, $ranked['lowest'][0]['delta']);
        $this->assertSame(10, $ranked['lowest'][0]['courses']);
        $this->assertNull($ranked['lowest'][2]['delta']); // C has only one day of history.
    }

    public function test_rank_schools_drops_lists_only_schools_that_fell_enough(): void {
        $ranked = attention_report::rank_schools(self::school_rows());
        $this->assertSame(['A'], array_column($ranked['drops'], 'school'));
    }

    public function test_rank_schools_ignores_small_dips(): void {
        // 10 courses, template 10 -> 9 is a 2-point fall in overall (100% -> 90% template, /5 = -2.0): flagged;
        // 10 -> 10 is flat: not flagged.
        $ranked = attention_report::rank_schools([
            self::snap('D', 20261016, 10, 10), self::snap('D', 20261023, 10, 9),
            self::snap('E', 20261016, 10, 10), self::snap('E', 20261023, 10, 10),
        ]);
        $this->assertSame(['D'], array_column($ranked['drops'], 'school'));
    }

    public function test_rank_schools_respects_the_limit_and_handles_no_data(): void {
        $this->assertSame(['A', 'B'], array_column(attention_report::rank_schools(self::school_rows(), 2)['lowest'], 'school'));
        $this->assertSame(['lowest' => [], 'drops' => []], attention_report::rank_schools([]));
    }

    public function test_rank_lecturers_merges_name_variants_and_co_taught_courses(): void {
        $ranked = attention_report::rank_lecturers([
            self::audit(['lecturername' => 'Jane Doe', 'templatedeployed' => 'Yes']),
            self::audit(['lecturername' => 'jane  doe', 'reviewed' => 1, 'templatedeployed' => 'Yes',
                'attendancelink' => 'Yes', 'materialsweeks' => '2', 'numcatsquizzes' => '1',
                'assign1feedback' => '100%', 'assign2feedback' => '100%', 'assign3feedback' => '100%',
                'cat1feedback' => '100%', 'cat2feedback' => '100%']),
            self::audit(['lecturername' => 'John Smith, Jane Doe', 'templatedeployed' => 'Yes', 'attendancelink' => 'Yes']),
            self::audit(['lecturername' => '']),
        ]);

        $this->assertCount(2, $ranked);
        $this->assertSame('John Smith', $ranked[0]['name']);
        $this->assertSame(40.0, $ranked[0]['average']);
        $this->assertSame(1, $ranked[0]['courses']);
        $this->assertSame('Jane Doe', $ranked[1]['name']);
        $this->assertSame(3, $ranked[1]['courses']);
        $this->assertSame(53.3, $ranked[1]['average']);
        $this->assertSame(2, $ranked[1]['unreviewed']);
    }

    public function test_rank_lecturers_limit_and_empty(): void {
        $this->assertSame([], attention_report::rank_lecturers([]));
        $rows = [self::audit(['lecturername' => 'A']), self::audit(['lecturername' => 'B']), self::audit(['lecturername' => 'C'])];
        $this->assertCount(2, attention_report::rank_lecturers($rows, 2));
    }
}
