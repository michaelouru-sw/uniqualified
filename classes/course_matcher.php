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
 * Matches a Moodle course to the timetable offering(s) that describe it.
 *
 * A single UNIT CODE can legitimately appear as several distinct offerings
 * in the timetable (same unit taught to different programmes/modes/campuses
 * by different lecturers - e.g. "CUU 1103 Communication Skills" is shared
 * across BCOM/BEBS/BPL/BPM/IBM in FT/PT/WKD/DL modes). Whether that maps to
 * one Moodle course or several depends on the institution's shortname
 * convention, which is why disambiguation is a best-effort scoring pass:
 * when it can't confidently pick a single offering, it reports all
 * candidates instead of guessing, so the dashboard can ask a human.
 *
 * The matching/scoring logic is pure (operates on plain arrays) so it can be
 * unit tested without a database; {@see self::find_for_course()} is the only
 * method that touches Moodle's $DB.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_matcher {

    /**
     * Extracts a unit code from free text (course shortname/fullname) using
     * an admin-configurable regular expression. The pattern must contain
     * exactly one capture group.
     *
     * @param string $text
     * @param string $pattern PCRE pattern, e.g. '/([A-Za-z]{2,5}\s?\d{3,4})/'.
     * @return string|null Normalized (uppercased, single-spaced) unit code, or null.
     */
    public static function extract_unitcode(string $text, string $pattern): ?string {
        if (trim($pattern) === '') {
            return null;
        }
        $matches = [];
        $result = @preg_match($pattern, $text, $matches);
        if ($result !== 1 || !isset($matches[1]) || trim($matches[1]) === '') {
            return null;
        }
        return timetable_parser::normalize_code($matches[1]);
    }

    /**
     * Groups raw timetable rows for a single unit code into distinct
     * "offerings" (one per programme code + mode of study + campus
     * combination), collapsing rows that only differ by day/time slot.
     *
     * @param array $rows Normalized row arrays as produced by timetable_parser::parse_matrix()
     *                     (or the DB-backed equivalent), all for the same unitcode.
     * @return array List of offering arrays: unitcode, unitname, lecturernames (array of
     *               distinct names seen), institution, programcode, programname, modeofstudy,
     *               roomtype, roomcode, campus, occurrences.
     */
    public static function dedupe_offerings(array $rows): array {
        $offerings = [];

        foreach ($rows as $row) {
            // Institution is part of the grouping key, not just carried along: the same unit
            // code can legitimately be taught by more than one school (cross-listed units), and
            // merging those into a single offering would wrongly blend two schools' lecturer
            // lists/programme codes together.
            $key = implode('|', [
                $row['institution'] ?? '',
                $row['programcode'] ?? '',
                $row['modeofstudy'] ?? '',
                $row['campus'] ?? '',
            ]);

            if (!isset($offerings[$key])) {
                $offerings[$key] = [
                    'unitcode' => $row['unitcode'] ?? null,
                    'unitname' => $row['unitname'] ?? null,
                    'lecturernames' => [],
                    'institution' => $row['institution'] ?? null,
                    'programcode' => $row['programcode'] ?? null,
                    'programname' => $row['programname'] ?? null,
                    'modeofstudy' => $row['modeofstudy'] ?? null,
                    'roomtype' => $row['roomtype'] ?? null,
                    'roomcode' => $row['roomcode'] ?? null,
                    'campus' => $row['campus'] ?? null,
                    'occurrences' => 0,
                ];
            }

            $offerings[$key]['occurrences']++;
            $lecturer = $row['lecturername'] ?? null;
            if ($lecturer !== null && $lecturer !== '' && !in_array($lecturer, $offerings[$key]['lecturernames'], true)) {
                $offerings[$key]['lecturernames'][] = $lecturer;
            }
            // Prefer a roomtype value if the first row we saw for this offering lacked one.
            if (empty($offerings[$key]['roomtype']) && !empty($row['roomtype'])) {
                $offerings[$key]['roomtype'] = $row['roomtype'];
            }
        }

        return array_values($offerings);
    }

    /**
     * Attempts to pick the single offering that matches a course's shortname/
     * fullname/category context. Scores each offering on independent signals
     * and only commits to a selection when there is a unique highest scorer
     * with a non-zero score; otherwise flags the result ambiguous.
     *
     * @param array $offerings As returned by dedupe_offerings().
     * @param string $contexttext Course shortname + fullname + category name, any case.
     * @param array $hints Optional high-confidence signals from course_name_parser::parse_fullname()
     *        (this site's structured course fullname convention), scored well above the generic
     *        text-matching signals below since they come straight from the course's own name
     *        rather than a fuzzy scan: 'modeofstudy' (already normalize_mode_of_study()'d) and/or
     *        'lecturername' (raw).
     * @return array ['selected' => offering|null, 'ambiguous' => bool, 'scores' => [offeringindex => score],
     *         'winners' => offering[] tied for the top score]
     */
    public static function disambiguate(array $offerings, string $contexttext, array $hints = []): array {
        if (count($offerings) <= 1) {
            return [
                'selected' => $offerings[0] ?? null,
                'ambiguous' => false,
                'scores' => count($offerings) === 1 ? [0 => 0] : [],
                'winners' => $offerings,
            ];
        }

        $haystack = strtoupper($contexttext);
        $hintedmode = $hints['modeofstudy'] ?? null;
        $hintedlecturer = isset($hints['lecturername']) ? strtoupper(trim($hints['lecturername'])) : null;
        $scores = [];

        foreach ($offerings as $i => $offering) {
            $score = 0;

            $programcode = strtoupper((string) ($offering['programcode'] ?? ''));
            if ($programcode !== '' && strpos($haystack, $programcode) !== false) {
                $score += 3;
            }

            $mode = strtoupper((string) ($offering['modeofstudy'] ?? ''));
            if ($mode !== '' && preg_match('/\b' . preg_quote($mode, '/') . '\b/', $haystack)) {
                $score += 2;
            }
            if ($hintedmode !== null && $mode !== ''
                    && course_name_parser::normalize_mode_of_study($mode) === $hintedmode) {
                $score += 6;
            }

            $campus = strtoupper((string) ($offering['campus'] ?? ''));
            if ($campus !== '' && preg_match('/\b' . preg_quote($campus, '/') . '\b/', $haystack)) {
                $score += 2;
            }

            $programname = strtoupper((string) ($offering['programname'] ?? ''));
            $tokens = array_filter(preg_split('/[^A-Z0-9]+/', $programname), fn($t) => strlen($t) >= 3);
            $nametokenscore = 0;
            foreach ($tokens as $token) {
                if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $haystack)) {
                    $nametokenscore += 1;
                }
            }
            $score += min($nametokenscore, 2);

            if ($hintedlecturer !== null && $hintedlecturer !== '') {
                foreach ($offering['lecturernames'] ?? [] as $lecturername) {
                    if (strtoupper(trim($lecturername)) === $hintedlecturer) {
                        $score += 4;
                        break;
                    }
                }
            }

            $scores[$i] = $score;
        }

        $maxscore = max($scores);
        $winnerindexes = array_keys($scores, $maxscore, true);
        $winners = array_map(fn($i) => $offerings[$i], $winnerindexes);

        if ($maxscore > 0 && count($winnerindexes) === 1) {
            return ['selected' => $winners[0], 'ambiguous' => false, 'scores' => $scores, 'winners' => $winners];
        }

        // Tied at the top - including a total tie at zero, where no signal matched
        // anything at all (e.g. the same lecturer teaching one unit to several degree
        // programmes' DL cohorts as one combined class - there's no way to tell which
        // programme *this* course belongs to). The specific offering stays unresolved,
        // but every tied candidate is still surfaced so a caller can use them for
        // anything they happen to agree on regardless of score (see
        // audit_assembler::build_row()'s Programme level fallback) - a zero
        // disambiguation score doesn't mean zero information: if literally every
        // real offering of this unit code shares one programme code, that's still
        // true independent of whether anything could score-differentiate between them.
        return ['selected' => null, 'ambiguous' => true, 'scores' => $scores, 'winners' => $winners];
    }

    /**
     * Given every timetable row that matches a unit code (regardless of
     * term), picks the term label of whichever row was imported most
     * recently. Deliberately resolved per unit code rather than globally,
     * since different schools may label the same period differently, or an
     * old term's rows might otherwise shadow a more recent one for units
     * that happen to only exist in the older import.
     *
     * @param array $rows Each element needs ->term and ->timeimported.
     * @return string|null
     */
    public static function resolve_latest_term(array $rows): ?string {
        if (empty($rows)) {
            return null;
        }

        $latest = null;
        foreach ($rows as $row) {
            if ($latest === null || (int) $row->timeimported > (int) $latest->timeimported) {
                $latest = $row;
            }
        }

        return $latest->term;
    }

    /**
     * Full lookup for a single Moodle course: extracts its unit code, fetches
     * matching timetable rows, dedupes into offerings, and attempts
     * disambiguation.
     *
     * @param \stdClass $course Must have shortname, fullname, and categoryname (already resolved) set.
     * @param string|null $term Restrict to this exact term label. When null, every term this unit
     *        code appears under is considered and whichever was imported most recently for *that*
     *        unit code is used - not a single global "latest term", since different schools/terms
     *        may use different labels for what covers the same period.
     * @return array [
     *     'unitcode' => string|null,
     *     'term' => string|null,
     *     'offerings' => array,
     *     'selected' => array|null,
     *     'ambiguous' => bool,
     *     'winners' => array The offering(s) tied for the top disambiguation score - a single
     *         element matching 'selected' when unambiguous, 2+ elements when ambiguous (useful
     *         for anything the tied candidates happen to agree on, e.g. Programme level).
     *     'namehints' => array As returned by course_name_parser::parse_fullname().
     * ]
     */
    public static function find_for_course(\stdClass $course, ?string $term = null): array {
        global $DB;

        $namehints = course_name_parser::parse_fullname($course->fullname ?? '');

        // This site's structured course fullname ("[Term:]Unit Code:Unit Name:Mode:Lecturer:Location:Stream")
        // is a far more direct signal than pattern-matching the shortname, so prefer it when present.
        // The shortname-derived code is kept as a fallback: if the fullname's code finds nothing in
        // the timetable (a mis-typed name, or a convention variant the parser doesn't know), the
        // shortname still gets a chance rather than the course silently going unmatched.
        $candidates = [];
        if (!empty($namehints['unitcode'])) {
            $candidates[] = $namehints['unitcode'];
        }
        $pattern = get_config('local_courseaudit', 'shortnameregex');
        $fromshortname = self::extract_unitcode($course->shortname ?? '', (string) $pattern);
        if ($fromshortname !== null && !in_array($fromshortname, $candidates, true)) {
            $candidates[] = $fromshortname;
        }

        if (empty($candidates)) {
            return ['unitcode' => null, 'term' => $term, 'offerings' => [], 'selected' => null, 'ambiguous' => false,
                'winners' => [], 'namehints' => $namehints];
        }

        $requestedterm = $term;
        $unitcode = $candidates[0];
        $dbrows = [];
        foreach ($candidates as $candidate) {
            $unitcodekey = timetable_parser::normalize_matchkey($candidate);
            $term = $requestedterm;

            if ($term !== null) {
                // Explicit term requested (e.g. a scoped refresh, or the dashboard's term filter) -
                // respect it exactly, even if nothing matches.
                $dbrows = $DB->get_records('local_courseaudit_timetable', ['unitcodekey' => $unitcodekey, 'term' => $term]);
            } else {
                // No term given: different schools' imports commonly use different term labels for
                // what is really the same period (or the admin might import a corrected file under a
                // fresh label rather than reusing the old one). Rather than assuming one single
                // globally-latest term string covers every course, resolve "latest" per unit code:
                // look at every term this unit code appears under, and use whichever one was
                // imported most recently for *this* unit code specifically.
                $allmatches = $DB->get_records('local_courseaudit_timetable', ['unitcodekey' => $unitcodekey]);
                $term = self::resolve_latest_term(array_values($allmatches));
                $dbrows = $term === null ? [] : array_filter($allmatches, fn($row) => $row->term === $term);
            }

            if (!empty($dbrows)) {
                $unitcode = $candidate;
                break;
            }
        }

        if (empty($dbrows)) {
            return ['unitcode' => $unitcode, 'term' => $term, 'offerings' => [], 'selected' => null, 'ambiguous' => false,
                'winners' => [], 'namehints' => $namehints];
        }

        $rows = [];
        foreach ($dbrows as $dbrow) {
            $rows[] = [
                'unitcode' => $dbrow->unitcode,
                'unitname' => $dbrow->unitname,
                'lecturername' => $dbrow->lecturername,
                'institution' => $dbrow->institution,
                'programcode' => $dbrow->programcode,
                'programname' => $dbrow->programname,
                'modeofstudy' => $dbrow->modeofstudy,
                'roomtype' => $dbrow->modelabel,
                'roomcode' => null,
                'campus' => $dbrow->campus,
            ];
        }

        $offerings = self::dedupe_offerings($rows);

        $contexttext = trim(($course->shortname ?? '') . ' ' . ($course->fullname ?? '') . ' ' . ($course->categoryname ?? ''));
        $hints = [];
        if (!empty($namehints['modeofstudy'])) {
            $hints['modeofstudy'] = course_name_parser::normalize_mode_of_study($namehints['modeofstudy']);
        }
        if (!empty($namehints['lecturername'])) {
            $hints['lecturername'] = $namehints['lecturername'];
        }
        $disambiguation = self::disambiguate($offerings, $contexttext, $hints);

        return [
            'unitcode' => $unitcode,
            'term' => $term,
            'offerings' => $offerings,
            'selected' => $disambiguation['selected'],
            'ambiguous' => $disambiguation['ambiguous'],
            'winners' => $disambiguation['winners'],
            'namehints' => $namehints,
        ];
    }
}
