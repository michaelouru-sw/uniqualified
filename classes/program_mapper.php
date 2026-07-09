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
 * Tracks and classifies the programme codes seen in imported timetables.
 *
 * Every distinct PROGRAM CODE encountered in a timetable import (e.g.
 * KCAPHDFIN, KCABCOM, ACCA, 802) is registered here on first sighting with
 * a keyword-based best-guess Programme level. A human reviews/corrects the
 * guess once on the "Classify programme codes" admin screen; after that the
 * classification is reused for every future import and audit refresh.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_mapper {

    /** @var string[] Allowed values, matching the "Programme level" dropdown in the master audit template. */
    const LEVELS = [
        'Certificate',
        'Diploma',
        'Bachelors',
        'Postgraduate Diploma',
        'Masters',
        'Phd',
        'Professional',
    ];

    /**
     * Keyword => level, checked in order against the normalized programme code and name.
     * Order matters: more specific keywords must come before broader ones
     * (e.g. 'POSTGRADUATE DIPLOMA' before 'DIPLOMA', 'PHD' before generic degree keywords).
     *
     * @var array
     */
    const KEYWORD_RULES = [
        'PHD' => 'Phd',
        'DOCTOR' => 'Phd',
        'POSTGRADUATE DIPLOMA' => 'Postgraduate Diploma',
        'PGD' => 'Postgraduate Diploma',
        'MASTERS' => 'Masters',
        'MASTER' => 'Masters',
        'MBA' => 'Masters',
        'MSC' => 'Masters',
        'BACHELOR' => 'Bachelors',
        'BCOM' => 'Bachelors',
        'BSC' => 'Bachelors',
        'BPL' => 'Bachelors',
        'BPM' => 'Bachelors',
        'BEBS' => 'Bachelors',
        'IBM' => 'Bachelors',
        'BAS' => 'Bachelors',
        'ARTISAN' => 'Certificate',
        'CERTIFICATE' => 'Certificate',
        'CERT' => 'Certificate',
        'DIPLOMA' => 'Diploma',
        'DIP' => 'Diploma',
        'ATD' => 'Diploma',
        'CRAFT' => 'Diploma',
        'ACCA' => 'Professional',
        'CPA' => 'Professional',
        'CIFA' => 'Professional',
        'CHRP' => 'Professional',
        'CFFE' => 'Professional',
        'CPSP' => 'Professional',
        'CCNA' => 'Professional',
        'ICDL' => 'Professional',
        'CS' => 'Professional',
    ];

    /**
     * Registers a programme code as "seen" if it isn't already known. Existing
     * classifications are never overwritten; a blank programme name on an
     * existing row is filled in if a better one is now available.
     *
     * @param string $programcode
     * @param string|null $programname
     * @param int $userid
     * @return void
     */
    public static function register_seen(string $programcode, ?string $programname, int $userid): void {
        global $DB;

        $programcode = timetable_parser::normalize_code($programcode);
        if ($programcode === '') {
            return;
        }

        $existing = $DB->get_record('local_courseaudit_programmap', ['programcode' => $programcode]);
        $now = time();

        if (!$existing) {
            $record = (object) [
                'programcode' => $programcode,
                'programname' => $programname ?? '',
                'programlevel' => self::suggest_level($programcode, $programname),
                'confirmed' => 0,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_courseaudit_programmap', $record);
            return;
        }

        if (empty($existing->programname) && !empty($programname)) {
            $existing->programname = $programname;
            $existing->timemodified = $now;
            $DB->update_record('local_courseaudit_programmap', $existing);
        }
    }

    /**
     * Best-guess Programme level from keyword matching. Used to pre-fill the
     * classification form; always subject to human review.
     *
     * @param string $programcode
     * @param string|null $programname
     * @return string|null One of self::LEVELS, or null if no keyword matched.
     */
    public static function suggest_level(string $programcode, ?string $programname): ?string {
        $haystack = strtoupper(trim($programcode . ' ' . ($programname ?? '')));
        if ($haystack === '') {
            return null;
        }

        foreach (self::KEYWORD_RULES as $keyword => $level) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $haystack)) {
                return $level;
            }
        }

        // Fallback for concatenated codes with no spaces and no usable name
        // (e.g. 'KCAPHDBM'), where a word-boundary match can never succeed.
        // Plain substring match against the code only, same priority order.
        $codeonly = strtoupper(trim($programcode));
        foreach (self::KEYWORD_RULES as $keyword => $level) {
            if ($codeonly !== '' && strpos($codeonly, $keyword) !== false) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Returns every known programme code, keyed by code.
     *
     * @return array stdClass rows keyed by programcode.
     */
    public static function get_all(): array {
        global $DB;
        $records = $DB->get_records('local_courseaudit_programmap', null, 'programcode ASC');
        $bycode = [];
        foreach ($records as $record) {
            $bycode[$record->programcode] = $record;
        }
        return $bycode;
    }

    /**
     * Programme codes that have never been through the human review screen,
     * regardless of whether a heuristic guess already filled in a level.
     * These are what the "Classify programme codes" admin page lists.
     *
     * @return array stdClass rows.
     */
    public static function get_unclassified(): array {
        global $DB;
        return $DB->get_records('local_courseaudit_programmap', ['confirmed' => 0], 'programcode ASC');
    }

    /**
     * Saves a batch of human-confirmed classifications from the admin form.
     * Marks each row confirmed=1 even if the chosen level matches the prior
     * heuristic guess, since the point is recording that a human looked at it.
     *
     * @param array $programcodetolevel programcode => level.
     * @param int $userid
     * @return int Number of rows updated.
     */
    public static function save_classifications(array $programcodetolevel, int $userid): int {
        global $DB;

        $updated = 0;
        $now = time();

        foreach ($programcodetolevel as $programcode => $level) {
            $level = trim((string) $level);
            if ($level === '' || !in_array($level, self::LEVELS, true)) {
                continue;
            }
            $programcode = timetable_parser::normalize_code($programcode);
            $existing = $DB->get_record('local_courseaudit_programmap', ['programcode' => $programcode]);
            if (!$existing) {
                continue;
            }
            // Skip a no-op save (the "Manage programme codes" screen now always resubmits every
            // known code's current selection, not just newly-unclassified ones) - otherwise every
            // save would bump timemodified/usermodified on rows nobody actually touched.
            if ($existing->programlevel === $level && (int) $existing->confirmed === 1) {
                continue;
            }
            $existing->programlevel = $level;
            $existing->confirmed = 1;
            $existing->usermodified = $userid;
            $existing->timemodified = $now;
            $DB->update_record('local_courseaudit_programmap', $existing);
            $updated++;
        }

        return $updated;
    }

    /**
     * Looks up the classified level for a single programme code, and whether
     * that classification has been human-confirmed.
     *
     * @param string|null $programcode
     * @return array{level: ?string, confirmed: bool}
     */
    public static function get_level(?string $programcode): array {
        global $DB;
        if (empty($programcode)) {
            return ['level' => null, 'confirmed' => false];
        }
        $programcode = timetable_parser::normalize_code($programcode);
        $record = $DB->get_record('local_courseaudit_programmap', ['programcode' => $programcode]);
        if (!$record) {
            return ['level' => null, 'confirmed' => false];
        }
        return ['level' => $record->programlevel ?: null, 'confirmed' => (bool) $record->confirmed];
    }
}
