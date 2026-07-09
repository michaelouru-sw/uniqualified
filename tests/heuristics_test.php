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
 * Unit tests for the parts of heuristics that don't require a live Moodle
 * course (regex patterns and pure value-mapping helpers).
 *
 * The course_modinfo-driven detection methods (detect_template_deployed,
 * detect_attendance_link, detect_materials_count, detect_cats_assignments,
 * find_named_activity, batch_graded_percentages) need a real Moodle course/DB and are covered by
 * Moodle-hosted advanced_testcase tests using the course generator once the
 * plugin is installed; see tests/heuristics_db_test.php.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\heuristics
 */
final class heuristics_test extends \basic_testcase {

    /**
     * @dataProvider count_bucket_provider
     */
    public function test_count_to_dropdown_bucket(int $count, string $expected): void {
        $this->assertSame($expected, heuristics::count_to_dropdown_bucket($count));
    }

    public static function count_bucket_provider(): array {
        return [
            [0, 'None'],
            [1, '1'],
            [5, '5'],
            [6, 'more than 5'],
            [20, 'more than 5'],
        ];
    }

    public function test_course_policy_quiz_pattern_is_case_and_spacing_tolerant(): void {
        $this->assertSame(1, preg_match('/course\s*policy\s*quiz/i', 'COURSE POLICY QUIZ'));
        $this->assertSame(1, preg_match('/course\s*policy\s*quiz/i', 'Course PolicyQuiz'));
        $this->assertSame(0, preg_match('/course\s*policy\s*quiz/i', 'Course Introduction Quiz'));
    }

    public function test_week_section_pattern_matches_renamed_variants(): void {
        $pattern = '/\bweek\s*\d{1,2}\b/i';
        $this->assertSame(1, preg_match($pattern, 'Week 1'));
        $this->assertSame(1, preg_match($pattern, 'WEEK1: Introduction'));
        $this->assertSame(1, preg_match($pattern, 'Week 13'));
        $this->assertSame(0, preg_match($pattern, 'Weekly Announcements'));
    }

    public function test_attendance_url_hosts_cover_known_providers(): void {
        $this->assertContains('zoom.us', heuristics::ATTENDANCE_URL_HOSTS);
        $this->assertContains('meet.google.com', heuristics::ATTENDANCE_URL_HOSTS);
    }

    /**
     * @dataProvider cat_alias_provider
     */
    public function test_cat_pattern_matches_term_paper_and_tpaper_aliases(string $name, int $number, bool $shouldmatch): void {
        // Mirrors the pattern built inline in find_named_activity() for type='cat'; the
        // master template's own O/P column headers say "Cat 1/Term paper Feedback", confirming
        // "Term Paper"/"TPAPER" are meant as the same thing as "CAT" at some schools.
        $pattern = '/\b(?:CAT|TPAPER|TERM\s*PAPER)\s*' . $number . '\b/i';
        $this->assertSame($shouldmatch, (bool) preg_match($pattern, $name));
    }

    public static function cat_alias_provider(): array {
        return [
            ['CAT 1', 1, true],
            ['CAT1', 1, true],
            ['TPAPER 1', 1, true],
            ['TPAPER1', 1, true],
            ['Term Paper 1', 1, true],
            ['TermPaper1', 1, true],
            ['TERM PAPER 1', 1, true],
            ['CAT 2', 2, true],
            ['Term Paper 2', 2, true],
            ['TPAPER2', 2, true],
            ['Term Paper 1', 2, false], // Wrong number must not match.
            ['CAT 2', 1, false],
            ['Assignment 1', 1, false], // Not a CAT/Term Paper alias at all.
        ];
    }
}
