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
 * Imports a batch of up to 10 uploaded timetable files (any mix of source
 * layouts) into local_courseaudit_timetable as the site's single source of
 * truth: every import clears whatever was there before (any term, any
 * school) rather than accumulating alongside it. Term and institution are
 * taken from the file's own SCHOOL/PERIOD columns when it carries them (the
 * university's master timetable format does); the admin-typed term and the
 * filename-derived institution are only a fallback for older per-school
 * files that don't. Kept separate from import.php so the same logic is
 * exercised by tests as by the real upload form.
 *
 * A course's School/Programme/matched lecturer binding is a fact about
 * whichever timetable is currently imported, not a permanent property of the
 * course - a timetable doesn't equal a school. So every import also wipes
 * those fields on every local_courseaudit_audit row (even reviewed ones) and
 * auto-queues a refresh to repopulate them from the new data - see
 * import_files() and audit_assembler::LIVE_REFERENCE_FIELDS.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timetable_importer {

    /**
     * @param \stored_file[] $files
     * @param string $term Fallback term, used only for rows/files whose own PERIOD column is
     *        blank or missing. May be blank if every imported file carries its own PERIOD.
     * @param int $userid
     * @return array{
     *     totalrows: int, totalsheets: int, totalskipped: int,
     *     filesummaries: array<array{filename: string, rows: int, sheets: int, skipped: int}>,
     *     errors: array<array{filename: string, message: string}>,
     *     unclassifiedcount: int,
     *     terms: string[]
     * }
     */
    public static function import_files(array $files, string $term, int $userid): array {
        global $DB;

        $term = trim($term);
        $now = time();
        $tmpdir = make_request_directory();

        // Single source of truth: a new import replaces everything previously imported, not just
        // whatever happens to share the same term label - see the plugin's README for why
        // (the institution moved away from accumulating per-school files toward one master
        // timetable file per trimester, each wholly superseding the last).
        $DB->delete_records('local_courseaudit_timetable');

        // A course's School/Programme/matched lecturer/Programme level are facts about whichever
        // timetable is currently imported, not a permanent property of the course - a timetable
        // doesn't equal a school. The moment the old timetable is gone, every dashboard row's
        // binding to it is stale and must be wiped immediately (not left showing data from an
        // import that no longer exists), even on rows a QA officer has already reviewed and even
        // if they'd manually corrected the value by hand - see audit_assembler::save_row(), which
        // repopulates these fields correctly (including for reviewed rows) once the refresh this
        // triggers below runs against the new timetable.
        $DB->execute(
            "UPDATE {local_courseaudit_audit}
                SET school = NULL, programme = NULL, timetablelecturer = NULL,
                    hastimetablematch = 0, programlevel = NULL, matchedterm = NULL,
                    needsclassification = 0"
        );

        $totalrows = 0;
        $totalsheets = 0;
        $totalskipped = 0;
        $filesummaries = [];
        $errors = [];
        $seenprograms = [];
        $allrecords = [];
        $termsfound = [];

        foreach ($files as $file) {
            $filename = $file->get_filename();
            $tmpfilepath = $tmpdir . '/' . $file->get_id() . '-' . $filename;
            $file->copy_content_to($tmpfilepath);

            try {
                $parsed = timetable_parser::parse_file($tmpfilepath);
            } catch (\Throwable $e) {
                $errors[] = ['filename' => $filename, 'message' => $e->getMessage()];
                continue;
            }

            if (empty($parsed['rows'])) {
                $errors[] = ['filename' => $filename, 'message' => get_string('importnorows', 'local_courseaudit')];
                continue;
            }

            $filenameinstitution = pathinfo($filename, PATHINFO_FILENAME);

            foreach ($parsed['rows'] as $row) {
                // Every record needs identical keys in identical order - $DB->insert_records()
                // requires it (it batches all of them into single multi-row INSERT statements).
                $programcode = $row['programcode'] !== null ? timetable_parser::normalize_code($row['programcode']) : null;

                // The file's own SCHOOL/PERIOD columns are authoritative when present (the
                // master timetable format always carries them); fall back to the filename/the
                // admin-typed term for older per-school files that don't.
                $effectiveterm = $row['period'] ?? ($term !== '' ? $term : self::default_term());
                $effectiveinstitution = $row['school'] ?? $filenameinstitution;
                $termsfound[$effectiveterm] = true;

                $allrecords[] = [
                    'term' => $effectiveterm,
                    'institution' => $effectiveinstitution,
                    'sourceformat' => 'auto',
                    'programcode' => $programcode,
                    'programname' => $row['programname'],
                    'unitcode' => $row['unitcode'],
                    'unitcodekey' => timetable_parser::normalize_matchkey($row['unitcode']),
                    'unitname' => $row['unitname'],
                    'lecturername' => $row['lecturername'],
                    'studentcount' => null,
                    'modelabel' => $row['roomtype'],
                    'modeofstudy' => $row['modeofstudy'],
                    'campus' => $row['campus'],
                    'sheetname' => $row['sheetname'],
                    'rawrow' => json_encode($row['rawrow']),
                    'importbatchid' => $now,
                    'timeimported' => $now,
                    'importedby' => $userid,
                ];

                if (!empty($programcode) && !isset($seenprograms[$programcode])) {
                    $seenprograms[$programcode] = $row['programname'];
                }
            }

            $totalrows += count($parsed['rows']);
            $totalsheets += count($parsed['sheetsparsed']);
            $totalskipped += count($parsed['sheetsskipped']);
            $filesummaries[] = [
                'filename' => $filename,
                'rows' => count($parsed['rows']),
                'sheets' => count($parsed['sheetsparsed']),
                'skipped' => count($parsed['sheetsskipped']),
            ];
        }

        // One batched bulk insert for the whole upload rather than one insert_record() call
        // per row - matters once a term's worth of timetable data reaches thousands of rows.
        if (!empty($allrecords)) {
            $DB->insert_records('local_courseaudit_timetable', $allrecords);
        }

        foreach ($seenprograms as $programcode => $programname) {
            program_mapper::register_seen($programcode, $programname, $userid);
        }

        // Auto-queue a refresh so the reference fields just wiped above get repopulated from the
        // new timetable straight away, without a separate manual "Refresh from Moodle" click -
        // same background-task mechanism the dashboard button and programmap.php's save use.
        \local_courseaudit\task\refresh_audit_adhoc::queue_fresh();

        return [
            'totalrows' => $totalrows,
            'totalsheets' => $totalsheets,
            'totalskipped' => $totalskipped,
            'filesummaries' => $filesummaries,
            'errors' => $errors,
            'unclassifiedcount' => count(program_mapper::get_unclassified()),
            'terms' => array_keys($termsfound),
        ];
    }

    /**
     * A reasonable term label when a row has no PERIOD column and the admin left the term field
     * blank - date-based, not a bare number, so it's still meaningful on the dashboard's Term
     * filter without needing to open a row to know what it was. Mirrors
     * audit_history::default_cycle_label()'s reasoning.
     *
     * @return string
     */
    protected static function default_term(): string {
        return 'Imported ' . userdate(time(), get_string('strftimedatefullshort', 'core_langconfig'));
    }
}
