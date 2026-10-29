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
 * Parses this site's structured course fullname convention:
 *
 *   Unit Code:Unit Name:Mode of Study:Lecturer Name:Location:Stream
 *
 * e.g. "CSL 2202:BEHAVIOUR MODIFICATION:PTDL:MARGRET MURUGI NJIRU:ZOOM:Stream A"
 *
 * Confirmed (2026-07-02) to be used consistently across every real course on
 * the LMS, which makes it a far more reliable source for Unit Code, Unit
 * Name, Lecturer Name and location/mode than fuzzy-matching against an
 * imported timetable - the timetable is still needed for Programme Level,
 * since programme code isn't part of this string.
 *
 * Deliberately tolerant of a course that doesn't follow the convention (a
 * one-off manually created course, or one from before this convention was
 * adopted): parse_fullname() never throws, it just returns whichever fields
 * it could confidently extract and leaves the rest null.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_name_parser {

    /** @var int Expected number of colon-delimited segments (after any term prefix is removed). */
    const EXPECTED_SEGMENTS = 6;

    /** @var string Pattern a segment must match to be recognised as a unit code, e.g. "CSL 2202", "BBIT 04104". */
    const UNITCODE_PATTERN = '/^[A-Za-z]{2,6}\s?\d{3,5}$/';

    /** @var string[] Campus labels that may appear as an extra segment between Mode and Lecturer. */
    const CAMPUS_LABELS = ['MAIN', 'TOWN', 'KSM', 'KITENGELA', 'KISUMU', 'NAIROBI'];

    /**
     * Parses a course fullname into its component fields.
     *
     * @param string $fullname
     * Also tolerates the variants now seen on the LMS:
     *  - a leading term segment, e.g. "TRIM3 26:" or "01_TRIM3 26:" (returned as 'term' and
     *    otherwise ignored, so it can no longer be mistaken for the unit code);
     *  - an extra campus segment between Mode and Lecturer, e.g.
     *    "ACC 1201:FINANCIAL ACCOUNTING II:FT:MAIN:MARGARET NJERI KARUITHA:LT 2-4:Stream B"
     *    (returned as 'campus');
     *  - an empty Mode segment ("::").
     *
     * @return array{unitcode: ?string, unitname: ?string, modeofstudy: ?string,
     *               lecturername: ?string, location: ?string, stream: ?string,
     *               term: ?string, campus: ?string, matched: bool}
     *         'matched' is true only when all six segments were present; a partial
     *         match (e.g. missing stream) still returns whatever fields it found,
     *         with 'matched' false so callers know to treat it cautiously.
     */
    public static function parse_fullname(string $fullname): array {
        $result = [
            'unitcode' => null,
            'unitname' => null,
            'modeofstudy' => null,
            'lecturername' => null,
            'location' => null,
            'stream' => null,
            'term' => null,
            'campus' => null,
            'matched' => false,
        ];

        if (trim($fullname) === '' || strpos($fullname, ':') === false) {
            return $result;
        }

        $segments = explode(':', $fullname);

        // Leading term segment ("TRIM3 26", "01_TRIM3 26"): present when the first segment is not
        // itself a unit code but the second one is. Without this the whole record shifts by one.
        if (count($segments) > 1 && !self::looks_like_unitcode($segments[0])
                && self::looks_like_unitcode($segments[1])) {
            $result['term'] = trim($segments[0]) !== '' ? trim($segments[0]) : null;
            array_shift($segments);
        }

        // Optional campus segment straight after Mode of Study (unit code, unit name, mode, CAMPUS, ...).
        $hascampus = isset($segments[3]) && in_array(strtoupper(trim($segments[3])), self::CAMPUS_LABELS, true);
        $keys = $hascampus
            ? ['unitcode', 'unitname', 'modeofstudy', 'campus', 'lecturername', 'location', 'stream']
            : ['unitcode', 'unitname', 'modeofstudy', 'lecturername', 'location', 'stream'];

        // Cap the segment count so a stray colon in the trailing field (e.g. "Stream A: Evening")
        // lands entirely in 'stream' rather than shifting the rest of the record.
        $limit = count($keys);
        if (count($segments) > $limit) {
            $segments = array_merge(array_slice($segments, 0, $limit - 1),
                [implode(':', array_slice($segments, $limit - 1))]);
        }
        $segments = array_map('trim', $segments);

        foreach ($keys as $i => $key) {
            if (isset($segments[$i]) && $segments[$i] !== '') {
                $result[$key] = $segments[$i];
            }
        }

        if (!empty($result['unitcode'])) {
            $result['unitcode'] = timetable_parser::normalize_code($result['unitcode']);
        }

        $required = ['unitcode', 'unitname', 'modeofstudy', 'lecturername', 'location', 'stream'];
        $result['matched'] = count($segments) === $limit
            && count(array_filter($required, fn($k) => $result[$k] === null)) === 0;

        return $result;
    }

    /**
     * Whether a segment looks like a unit code ("CSL 2202", "MAT1102", "BBIT 04104").
     *
     * @param string $value
     * @return bool
     */
    protected static function looks_like_unitcode(string $value): bool {
        return preg_match(self::UNITCODE_PATTERN, trim($value)) === 1;
    }

    /**
     * Normalizes a mode-of-study value for comparison, collapsing the
     * different ways the same combined mode gets written (a course fullname
     * writes "PTDL" with no separator, a timetable's own MODE OF STUDY
     * column might say "PT/DL" or "PT & DL") down to one canonical key.
     *
     * @param string|null $value
     * @return string|null
     */
    public static function normalize_mode_of_study(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
        return $normalized === '' ? null : $normalized;
    }
}
