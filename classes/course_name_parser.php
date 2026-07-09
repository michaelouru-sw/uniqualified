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

    /** @var int Expected number of colon-delimited segments. */
    const EXPECTED_SEGMENTS = 6;

    /**
     * Parses a course fullname into its component fields.
     *
     * @param string $fullname
     * @return array{unitcode: ?string, unitname: ?string, modeofstudy: ?string,
     *               lecturername: ?string, location: ?string, stream: ?string,
     *               matched: bool}
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
            'matched' => false,
        ];

        if (trim($fullname) === '' || strpos($fullname, ':') === false) {
            return $result;
        }

        $segments = array_map('trim', explode(':', $fullname, self::EXPECTED_SEGMENTS));
        $keys = ['unitcode', 'unitname', 'modeofstudy', 'lecturername', 'location', 'stream'];

        foreach ($keys as $i => $key) {
            if (isset($segments[$i]) && $segments[$i] !== '') {
                $result[$key] = $segments[$i];
            }
        }

        if (!empty($result['unitcode'])) {
            $result['unitcode'] = timetable_parser::normalize_code($result['unitcode']);
        }

        $result['matched'] = count($segments) === self::EXPECTED_SEGMENTS && !in_array('', $segments, true);

        return $result;
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
