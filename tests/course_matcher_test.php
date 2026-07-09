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
 * Unit tests for course_matcher's pure logic: unit code extraction,
 * offering dedup, and disambiguation scoring.
 *
 * The DB-backed find_for_course() needs a live Moodle $DB
 * and are exercised by Moodle-hosted advanced_testcase tests once installed.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\course_matcher
 */
final class course_matcher_test extends \basic_testcase {

    private const DEFAULT_PATTERN = '/([A-Za-z]{2,5}\s?\d{3,4})/';

    public function test_extract_unitcode_finds_code_anywhere_in_text(): void {
        $this->assertSame('ECO 8101', course_matcher::extract_unitcode('ECO 8101 Advanced Economic Theory', self::DEFAULT_PATTERN));
        // Shortname has no space between letters/digits; extraction preserves that -
        // matching this against the timetable's "CUU 1103" is handled via normalize_matchkey(),
        // not by extract_unitcode() itself. See test_normalize_matchkey_ignores_whitespace_differences().
        $this->assertSame('CUU1103', course_matcher::extract_unitcode('MAIN-CUU1103-2026', self::DEFAULT_PATTERN));
    }

    public function test_normalize_matchkey_ignores_whitespace_differences(): void {
        $this->assertSame(
            timetable_parser::normalize_matchkey('CUU 1103'),
            timetable_parser::normalize_matchkey('CUU1103')
        );
        $this->assertSame('CUU1103', timetable_parser::normalize_matchkey('  cuu   1103 '));
    }

    public function test_extract_unitcode_returns_null_when_no_match(): void {
        $this->assertNull(course_matcher::extract_unitcode('General Orientation Course', self::DEFAULT_PATTERN));
    }

    public function test_extract_unitcode_returns_null_for_invalid_pattern(): void {
        $this->assertNull(course_matcher::extract_unitcode('ECO 8101', '/(unterminated'));
    }

    public function test_extract_unitcode_returns_null_for_blank_pattern(): void {
        $this->assertNull(course_matcher::extract_unitcode('ECO 8101', ''));
    }

    /**
     * Real-world case found during parser verification: the same unit code is
     * taught as genuinely separate offerings across programmes/modes/campuses.
     */
    public function test_dedupe_offerings_keeps_distinct_program_mode_campus_combinations_separate(): void {
        $rows = [
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'SARAH MASESE',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'DL', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            // Same offering, second time slot in the week - must collapse into the FT row above.
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'TOWN'],
        ];

        $offerings = course_matcher::dedupe_offerings($rows);

        $this->assertCount(3, $offerings);
        $byoccurrence = array_column($offerings, 'occurrences');
        $this->assertSame(1, $byoccurrence[0]);
        $this->assertSame(2, $byoccurrence[1]); // FT MAIN, seen twice (two time slots).
        $this->assertSame(['EDWARD GURA'], $offerings[1]['lecturernames']);
    }

    public function test_disambiguate_returns_only_offering_unambiguously(): void {
        $offerings = course_matcher::dedupe_offerings([
            ['unitcode' => 'WEB 101', 'unitname' => 'WEB DEVELOPMENT', 'lecturername' => 'EVANS ONGULO',
                'programcode' => 'KCAUWEB', 'programname' => 'WEBDEV', 'modeofstudy' => 'FT', 'roomtype' => 'LAB',
                'roomcode' => 'TC 1-2', 'campus' => null],
        ]);

        $result = course_matcher::disambiguate($offerings, 'WEB101 Web Development');

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('KCAUWEB', $result['selected']['programcode']);
    }

    public function test_disambiguate_picks_offering_matching_mode_token_in_shortname(): void {
        $offerings = course_matcher::dedupe_offerings([
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'SARAH MASESE',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'DL', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
        ]);

        $result = course_matcher::disambiguate($offerings, 'BCOM-FT-CUU1103 Communication Skills (Main Campus)');

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('FT', $result['selected']['modeofstudy']);
    }

    public function test_disambiguate_flags_ambiguous_when_context_gives_no_signal(): void {
        $offerings = course_matcher::dedupe_offerings([
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'SARAH MASESE',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'DL', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
        ]);

        $result = course_matcher::disambiguate($offerings, 'CUU1103');

        $this->assertTrue($result['ambiguous']);
        $this->assertNull($result['selected']);
        $this->assertCount(2, $result['scores']);

        // Regression: a total tie at zero score is still zero *disambiguation* signal,
        // not zero information - both tied offerings must still be surfaced as winners
        // (e.g. so a caller can notice they happen to share the same programme code).
        $this->assertCount(2, $result['winners']);
        $programcodes = array_unique(array_column($result['winners'], 'programcode'));
        $this->assertSame(['KCABCOM'], $programcodes);
    }

    /**
     * The exact real-world case that used to be irreducibly ambiguous (no textual
     * signal in "CUU1103" distinguishes the DL offering from the FT one) is now
     * resolved directly by the course's own structured fullname.
     */
    public function test_disambiguate_resolves_previously_ambiguous_case_using_modeofstudy_hint(): void {
        $offerings = course_matcher::dedupe_offerings([
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'SARAH MASESE',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'DL', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
        ]);

        $result = course_matcher::disambiguate($offerings, 'CUU1103', ['modeofstudy' => 'DL']);

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('DL', $result['selected']['modeofstudy']);
        $this->assertSame('SARAH MASESE', $result['selected']['lecturernames'][0]);
    }

    public function test_disambiguate_resolves_using_lecturername_hint_when_mode_hint_absent(): void {
        $offerings = course_matcher::dedupe_offerings([
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'SARAH MASESE',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'DL', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
            ['unitcode' => 'CUU 1103', 'unitname' => 'COMMUNICATION SKILLS', 'lecturername' => 'EDWARD GURA',
                'programcode' => 'KCABCOM', 'programname' => 'BCOM', 'modeofstudy' => 'FT', 'roomtype' => 'VIRTUAL',
                'roomcode' => 'ZOOM', 'campus' => 'MAIN'],
        ]);

        $result = course_matcher::disambiguate($offerings, 'CUU1103', ['lecturername' => 'edward gura']);

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('EDWARD GURA', $result['selected']['lecturernames'][0]);
    }

    public function test_disambiguate_handles_no_offerings(): void {
        $result = course_matcher::disambiguate([], 'ANYTHING');
        $this->assertFalse($result['ambiguous']);
        $this->assertNull($result['selected']);
    }

    public function test_resolve_latest_term_picks_most_recently_imported_row(): void {
        $rows = [
            (object) ['term' => 'MAY-AUG-2026-SD-IS', 'timeimported' => 1000],
            (object) ['term' => 'MAY-AUG-2026-SEASS', 'timeimported' => 3000],
            (object) ['term' => 'MAY-AUG-2026-NAC', 'timeimported' => 2000],
        ];
        $this->assertSame('MAY-AUG-2026-SEASS', course_matcher::resolve_latest_term($rows));
    }

    public function test_resolve_latest_term_ignores_stale_term_for_this_unit_code(): void {
        // A unit code that only exists in an old import must not resolve to some
        // unrelated newer term that never actually mentioned it.
        $rows = [
            (object) ['term' => 'JAN-APR-2026', 'timeimported' => 500],
        ];
        $this->assertSame('JAN-APR-2026', course_matcher::resolve_latest_term($rows));
    }

    public function test_resolve_latest_term_returns_null_when_no_rows(): void {
        $this->assertNull(course_matcher::resolve_latest_term([]));
    }
}
