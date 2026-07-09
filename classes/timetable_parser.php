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
 * Parses institutional timetable spreadsheets into normalized rows.
 *
 * Both known source formats (School of Business "one sheet per cohort" layout,
 * and PTTI "one sheet per campus with stacked programme blocks" layout) share the
 * same core column headings (UNIT CODE, UNIT NAME, LECTURER/UNIT LECTURER,
 * PROGRAM CODE, PROGRAM/PROGRAM NAME, ROOM TYPE, ROOM CODE, MODE OF STUDY/MODE).
 * Rather than hard-coding two separate parsers, this class locates the header
 * row on each worksheet by column name and reads through an alias map, which
 * also makes it resilient to minor header wording changes in future exports.
 *
 * The heavy lifting (parse_matrix) is deliberately decoupled from PhpSpreadsheet
 * so it can be unit tested against plain PHP arrays.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timetable_parser {

    /** @var int How many leading rows to scan for a header on each sheet. */
    const HEADER_SCAN_ROWS = 20;

    /**
     * @var string Rightmost column letter read from each sheet. Some source
     * workbooks report a phantom "highest column" far beyond their real data
     * (stray cell formatting extending to column XFD on an otherwise ~10-row
     * sheet) which would otherwise blow up memory when read as an array; no
     * real timetable header seen so far uses more than ~20 columns.
     */
    const MAX_COLUMN = 'AZ';

    /** @var array Canonical field => list of accepted header labels (already normalized). */
    const ALIASES = [
        'unitcode' => ['UNIT CODE'],
        'unitname' => ['UNIT NAME'],
        'lecturername' => ['LECTURER NAME', 'UNIT LECTURER', 'LECTURER'],
        'programcode' => ['PROGRAM CODE', 'PROGRAMME CODE'],
        'programname' => ['PROGRAM', 'PROGRAM NAME', 'PROGRAMME', 'PROGRAMME NAME'],
        'modeofstudy' => ['MODE OF STUDY', 'MODE'],
        'roomtype' => ['ROOM TYPE'],
        'roomcode' => ['ROOM CODE', 'VENUE'],
        'campus' => ['CAMPUS'],
        'trimester' => ['TRIMESTER', 'TRIM'],
        'dayofweek' => ['DAY OF WEEK', 'DAY'],
        'timeslot' => ['TIME SLOT', 'TIME'],
        // The two fields below are the master timetable's own authoritative School and calendar
        // term (e.g. "JAN-APRIL 2026") - see timetable_importer::import_files(), which prefers
        // these per-row values over the admin-typed term/filename-derived institution whenever
        // a file actually carries them.
        'school' => ['SCHOOL'],
        'period' => ['PERIOD'],
    ];

    /** @var string[] Campus tokens recognised in a sheet name, checked in this priority order. */
    const SHEETNAME_CAMPUS_TOKENS = ['KITENGELA', 'KISUMU', 'MAIN', 'TOWN', 'KITE', 'KSM'];

    /** @var string[] Mode-of-study tokens recognised in a sheet name; all matches are joined with '/'. */
    const SHEETNAME_MODE_TOKENS = ['FT', 'PT', 'WKD', 'DL'];

    /**
     * Normalizes a header cell value for comparison: uppercase, trimmed,
     * internal whitespace collapsed to single spaces.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalize_header($value): string {
        $value = (string) $value;
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return strtoupper($value);
    }

    /**
     * Normalizes a unit code for matching/dedup purposes: uppercase,
     * internal whitespace collapsed to a single space, trimmed.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalize_code($value): string {
        $value = (string) $value;
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return strtoupper($value);
    }

    /**
     * Normalizes a unit code for equality matching where whitespace placement
     * is unreliable (e.g. a timetable row stores "CUU 1103" but a course
     * shortname embeds it as "CUU1103"): uppercase with all whitespace removed.
     * Use normalize_code() instead when the spaced form should be preserved
     * for storage/display.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalize_matchkey($value): string {
        $value = (string) $value;
        $value = preg_replace('/\s+/u', '', $value);
        return strtoupper(trim($value));
    }

    /**
     * Locates the header row within a matrix of cell values and builds a
     * canonical-field => column-index map.
     *
     * @param array $matrix Zero-indexed array of rows, each a zero-indexed array of cell values.
     * @return array|null [rowindex, colmap] or null if no header row was found.
     */
    public static function find_header(array $matrix): ?array {
        $scanrows = min(count($matrix), self::HEADER_SCAN_ROWS);
        for ($r = 0; $r < $scanrows; $r++) {
            $row = $matrix[$r] ?? [];
            $normalized = [];
            foreach ($row as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $normalized[$c] = self::normalize_header($value);
            }
            if (in_array('UNIT CODE', $normalized, true) && in_array('UNIT NAME', $normalized, true)) {
                $colmap = [];
                foreach (self::ALIASES as $field => $labels) {
                    foreach ($normalized as $c => $headertext) {
                        if (in_array($headertext, $labels, true)) {
                            $colmap[$field] = $c;
                            break;
                        }
                    }
                }

                // A plain "code -> description" lookup table can also happen to have UNIT CODE/
                // UNIT NAME columns (e.g. a "Units & Host Departments" reference sheet); require
                // at least one scheduling-context column too, so those aren't mistaken for
                // actual scheduled classes.
                $hasschedulingcontext = isset($colmap['dayofweek']) || isset($colmap['timeslot'])
                    || isset($colmap['roomtype']) || isset($colmap['roomcode']);
                if (!$hasschedulingcontext) {
                    continue;
                }

                return [$r, $colmap];
            }
        }
        return null;
    }

    /**
     * Determines whether a data row (after the header) is actually a
     * "programme block" section-label row, e.g. a lone 'ACCA' or
     * 'ARTISAN SM TERMI' value in the first column with everything else blank.
     *
     * @param array $row
     * @return string|null The label text, or null if this isn't a label row.
     */
    public static function detect_block_label(array $row): ?string {
        $nonempty = [];
        foreach ($row as $c => $value) {
            if ($value !== null && $value !== '') {
                $nonempty[$c] = $value;
            }
        }
        if (count($nonempty) === 1 && array_key_exists(0, $nonempty) && is_string($nonempty[0])) {
            $label = trim($nonempty[0]);
            if ($label !== '') {
                return $label;
            }
        }
        return null;
    }

    /**
     * Strips a trailing intake/group suffix like " 1.1" or " 2" from a short
     * programme code, e.g. "MISM 1.1" -> "MISM", "BBIT 1.1" -> "BBIT". Several
     * source sheets repeat the same programme under multiple intake batches
     * (a fresh block label such as "MISM 1.2" partway down the same sheet) -
     * without this, each batch would need its own separate manual Programme
     * level classification instead of sharing one.
     *
     * @param string $label
     * @return string
     */
    public static function strip_intake_suffix(string $label): string {
        return trim((string) preg_replace('/\s+\d+(\.\d+)?$/', '', $label));
    }

    /**
     * Pulls a best-guess campus and mode-of-study out of a sheet name, for
     * source formats that don't have explicit CAMPUS/MODE OF STUDY columns
     * (e.g. "BIT KSM FT" -> campus KSM, mode FT; "BBIT MAIN PT & DL" -> campus
     * MAIN, mode PT/DL). Used only as a fallback when a row's own columns
     * don't supply these values.
     *
     * @param string $sheetname
     * @return array{campus: ?string, mode: ?string}
     */
    public static function extract_campus_mode_from_sheetname(string $sheetname): array {
        $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($sheetname), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            $tokens = [];
        }

        $campus = null;
        foreach (self::SHEETNAME_CAMPUS_TOKENS as $candidate) {
            if (in_array($candidate, $tokens, true)) {
                $campus = $candidate;
                break;
            }
        }

        $modes = [];
        foreach ($tokens as $token) {
            if (in_array($token, self::SHEETNAME_MODE_TOKENS, true) && !in_array($token, $modes, true)) {
                $modes[] = $token;
            }
        }

        return ['campus' => $campus, 'mode' => empty($modes) ? null : implode('/', $modes)];
    }

    /**
     * Scans the rows before a sheet's header for lone-label rows (the same
     * shape as an in-sheet block label - see detect_block_label()), used by
     * formats that put a descriptive programme title and/or a short code
     * above the header instead of in a PROGRAM CODE column, e.g.:
     *   Row 1: "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BIT FT MAIN)"
     *   Row 2: "BIT 1.1"
     *   Row 3: <header row>
     * Deliberately only looks directly above the header (not the whole
     * sheet), so it does nothing for formats like the single flat
     * consolidated sheet where the header is row 1 and there's nothing above
     * it to find - falling back to that sheet's own name would be actively
     * wrong there, since one such sheet legitimately covers many programmes.
     *
     * @param array $matrix
     * @param int $headerrow
     * @return array{title: ?string, code: ?string} title is the first (topmost, usually most
     *         descriptive) label found; code is the last (closest to the header, usually the
     *         short form) with any intake suffix stripped.
     */
    public static function scan_pre_header_labels(array $matrix, int $headerrow): array {
        $labels = [];
        for ($r = 0; $r < $headerrow; $r++) {
            $label = self::detect_block_label($matrix[$r] ?? []);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        if (empty($labels)) {
            return ['title' => null, 'code' => null];
        }

        return [
            'title' => $labels[0],
            'code' => self::strip_intake_suffix(end($labels)),
        ];
    }

    /**
     * Parses a single worksheet's cell matrix into normalized timetable rows.
     *
     * @param array $matrix Zero-indexed rows of zero-indexed cell values, as from Worksheet::toArray().
     * @param string $sheetname
     * @return array List of normalized row associative arrays.
     */
    public static function parse_matrix(array $matrix, string $sheetname): array {
        $header = self::find_header($matrix);
        if ($header === null) {
            return [];
        }
        [$headerrow, $colmap] = $header;

        if (!isset($colmap['unitcode']) || !isset($colmap['unitname'])) {
            return [];
        }

        $preheader = self::scan_pre_header_labels($matrix, $headerrow);
        $sheetcampusmode = self::extract_campus_mode_from_sheetname($sheetname);

        $rows = [];
        $currentblocklabel = null;
        $rowcount = count($matrix);

        for ($r = $headerrow + 1; $r < $rowcount; $r++) {
            $row = $matrix[$r] ?? [];

            $unitcoderaw = $row[$colmap['unitcode']] ?? null;

            if ($unitcoderaw === null || trim((string) $unitcoderaw) === '') {
                $label = self::detect_block_label($row);
                if ($label !== null) {
                    $currentblocklabel = $label;
                }
                continue;
            }

            if (self::normalize_header($unitcoderaw) === 'UNIT CODE') {
                // A repeated header row further down the sheet.
                continue;
            }

            $unitcode = self::normalize_code($unitcoderaw);

            $get = function (string $field) use ($row, $colmap): ?string {
                if (!isset($colmap[$field])) {
                    return null;
                }
                $value = $row[$colmap[$field]] ?? null;
                if ($value === null) {
                    return null;
                }
                $value = trim((string) $value);
                return $value === '' ? null : $value;
            };

            $blocklabelcode = $currentblocklabel !== null ? self::strip_intake_suffix($currentblocklabel) : null;

            $record = [
                'unitcode' => $unitcode,
                'unitname' => $get('unitname'),
                'lecturername' => $get('lecturername'),
                'programcode' => $get('programcode') ?? $blocklabelcode ?? $preheader['code'],
                'programname' => $get('programname') ?? $currentblocklabel ?? $preheader['title'],
                'modeofstudy' => $get('modeofstudy') ?? $sheetcampusmode['mode'],
                'roomtype' => $get('roomtype'),
                'roomcode' => $get('roomcode'),
                'campus' => $get('campus') ?? $sheetcampusmode['campus'],
                'trimester' => $get('trimester'),
                'school' => $get('school'),
                'period' => $get('period'),
                'sheetname' => $sheetname,
                'blocklabel' => $currentblocklabel,
                'rawrow' => array_values($row),
            ];

            $rows[] = $record;
        }

        return $rows;
    }

    /**
     * Parses every sheet in an uploaded xlsx file.
     *
     * @param string $filepath Absolute path to the .xlsx file.
     * @return array [
     *     'rows' => array of normalized row records (each includes 'sheetname'),
     *     'sheetsparsed' => array of sheet names that yielded a header + rows,
     *     'sheetsskipped' => array of sheet names with no usable header (e.g. index/summary tabs),
     * ]
     */
    public static function parse_file(string $filepath): array {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filepath);
        // Only cell values are needed - skipping styles/formatting avoids a large chunk of
        // work on workbooks with heavy formatting, and setReadDataOnly() cells still expose
        // any pre-computed formula result without invoking the calculation engine.
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filepath);

        $allrows = [];
        $parsed = [];
        $skipped = [];

        foreach ($spreadsheet->getSheetNames() as $sheetname) {
            $sheet = $spreadsheet->getSheetByName($sheetname);
            $range = 'A1:' . self::MAX_COLUMN . max($sheet->getHighestDataRow(), 1);
            // calculateFormulas=false: timetable cells are mostly plain text/values, and some
            // source workbooks have expensive/runaway formulas elsewhere on the sheet that make
            // the calculation engine blow past any reasonable memory limit for no benefit here.
            $matrix = $sheet->rangeToArray($range, null, false, false, false);
            // Some source workbooks DO derive a needed column via formula though (e.g. the
            // university's master timetable computes PROGRAM CODE with a VLOOKUP) - resolve just
            // those specific cells to the value Excel itself already computed and saved
            // (getOldCalculatedValue(), no recalculation risk), rather than forcing
            // calculateFormulas=true across the whole read above, which would re-trigger the
            // exact runaway-formula risk the comment above avoids.
            foreach ($matrix as $r => $row) {
                foreach ($row as $c => $value) {
                    if (is_string($value) && $value !== '' && $value[0] === '=') {
                        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . ($r + 1);
                        $matrix[$r][$c] = $sheet->getCell($coord)->getOldCalculatedValue();
                    }
                }
            }
            $rows = self::parse_matrix($matrix, $sheetname);
            if (empty($rows)) {
                $skipped[] = $sheetname;
                continue;
            }
            $parsed[] = $sheetname;
            foreach ($rows as $row) {
                $allrows[] = $row;
            }
        }

        return [
            'rows' => $allrows,
            'sheetsparsed' => $parsed,
            'sheetsskipped' => $skipped,
        ];
    }
}
