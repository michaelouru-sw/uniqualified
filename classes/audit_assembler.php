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
 * Builds and persists one local_courseaudit_audit row per Moodle course,
 * combining enrolment data, the matched timetable offering, and the
 * heuristic activity-structure checks.
 *
 * A row a human has already marked "Reviewed" has its QA judgement (the compliance heuristic
 * fields, comments, the reviewed flag itself) left completely untouched by a refresh - but its
 * reference fields (School, Programme, matched lecturer, Programme level, student count - see
 * LIVE_REFERENCE_FIELDS) are still kept in sync with whatever the current timetable/enrolment
 * data says, since those are facts, not something a reviewer signed off on. See save_row().
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_assembler {

    /**
     * @var string[] Fields kept live even on a reviewed row - facts about the course's current
     * timetable match and Moodle enrolment, not a QA judgement call, so they must never freeze at
     * whatever happened to be true when the row was reviewed. A course's School/Programme/matched
     * lecturer are facts about whichever timetable is currently imported, not a permanent property
     * of the course - see timetable_importer::import_files(), which wipes these to null the moment
     * a new timetable replaces the old one. Everything else (the compliance heuristics, comments,
     * reviewed status itself) is the actual QA judgement call and stays frozen once reviewed=1, as
     * always.
     */
    const LIVE_REFERENCE_FIELDS = [
        'unitcode', 'unitname', 'school', 'programme', 'numstudents', 'lecturername',
        'timetablelecturer', 'lmslecturer', 'hastimetablematch', 'programlevel', 'modeoflearning',
        'needsclassification', 'matchedterm',
    ];

    /** @var string[] Role shortnames counted as the "Lecturer" for a course, in priority order. */
    const LECTURER_ROLES = ['editingteacher', 'teacher'];

    /** @var string[] Role shortnames counted for "No. of Students". */
    const STUDENT_ROLES = ['student'];

    /**
     * Maps a timetable ROOM TYPE value onto the Lists sheet's Mode of
     * learning vocabulary (Virtual/Lab/Physical/PG/PCP/Studio/Project/Zoom).
     *
     * @param string|null $roomtype
     * @return string|null
     */
    public static function normalize_mode_of_learning(?string $roomtype): ?string {
        if (empty($roomtype)) {
            return null;
        }
        $known = ['Virtual', 'Lab', 'Physical', 'PG', 'PCP', 'Studio', 'Project', 'Zoom'];
        $upper = strtoupper(trim($roomtype));
        foreach ($known as $value) {
            if (strtoupper($value) === $upper) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Refreshes audit rows for every course on the site (excluding the front
     * page). Intended for both the dashboard's "Refresh from Moodle" button
     * (queued as an adhoc task, see refresh_audit_adhoc) and the periodic
     * scheduled task. Records its own completion time/counts as plugin
     * config (local_courseaudit/lastrefreshcompleted,
     * local_courseaudit/lastrefreshcounts) so the dashboard can show when
     * the last refresh actually finished.
     *
     * Each course is processed independently: if one course throws (a
     * corrupted section/module structure, a database hiccup, anything
     * unexpected), that failure is logged and counted, but the loop
     * continues - without this, a single bad course partway through a
     * large site's course list would silently abort the whole batch, and
     * every course after it in iteration order would simply never get an
     * audit row that run.
     *
     * @param string|null $term Restrict timetable matching to this term; defaults to the latest import.
     * @return array{processed:int, created:int, updated:int, skippedreviewed:int, failed:int, failures:array}
     */
    public static function refresh_all(?string $term = null): array {
        global $DB;

        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $counts = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skippedreviewed' => 0, 'failed' => 0];
        $failures = [];

        $courses = $DB->get_records_select('course', 'id <> :site', ['site' => SITEID]);
        foreach ($courses as $course) {
            try {
                $action = self::build_and_save($course, $term);
            } catch (\Throwable $e) {
                $counts['failed']++;
                $message = "local_courseaudit: failed to refresh course {$course->id} ({$course->shortname}): " . $e->getMessage();
                debugging($message, DEBUG_NORMAL);
                mtrace($message);
                if (count($failures) < 20) {
                    $failures[] = ['courseid' => $course->id, 'shortname' => $course->shortname, 'message' => $e->getMessage()];
                }
                continue;
            }

            $counts['processed']++;
            if ($action === 'created') {
                $counts['created']++;
            } else if ($action === 'updated') {
                $counts['updated']++;
            } else {
                $counts['skippedreviewed']++;
            }
        }

        // Recorded regardless of how this was triggered (dashboard button's adhoc task, the
        // periodic scheduled task, or a direct call) so the dashboard can show "last refreshed
        // at X" - otherwise, now that a refresh runs in the background, there's no way to tell
        // from the UI whether cron has actually picked up a queued refresh yet.
        set_config('lastrefreshcompleted', time(), 'local_courseaudit');
        set_config('lastrefreshcounts', json_encode($counts), 'local_courseaudit');
        set_config('lastrefreshfailures', json_encode($failures), 'local_courseaudit');

        $counts['failures'] = $failures;
        return $counts;
    }

    /**
     * Builds the audit row for a single course and saves it.
     *
     * @param \stdClass $course Full course record.
     * @param string|null $term
     * @return string 'created', 'updated', or 'skippedreviewed'.
     */
    public static function build_and_save(\stdClass $course, ?string $term = null): string {
        $data = self::build_row($course, $term);
        return self::save_row((int) $course->id, $data);
    }

    /**
     * Assembles the full set of audit column values for one course, without
     * saving anything.
     *
     * @param \stdClass $course
     * @param string|null $term
     * @return array
     */
    public static function build_row(\stdClass $course, ?string $term = null): array {
        global $DB;

        $context = \context_course::instance($course->id);
        $course->categoryname = $DB->get_field('course_categories', 'name', ['id' => $course->category]) ?: '';

        $matcherresult = course_matcher::find_for_course($course, $term);
        $offering = $matcherresult['selected'];
        $namehints = $matcherresult['namehints'] ?? [];

        $studentuserids = array_keys(self::get_role_users($context, self::STUDENT_ROLES));
        $numstudents = count($studentuserids);
        $teachernames = self::role_user_names($context, self::LECTURER_ROLES);
        // Priority: who's actually enrolled as teacher in Moodle right now (ground truth for
        // "is the right lecturer here") > the lecturer this site's course-naming convention
        // claims > the timetable's generically-scheduled lecturer for that slot.
        $lecturername = !empty($teachernames)
            ? implode(', ', $teachernames)
            : ($namehints['lecturername'] ?? (!empty($offering['lecturernames']) ? implode(', ', $offering['lecturernames']) : null));

        $lecturermismatch = !empty($teachernames) && !empty($namehints['lecturername'])
            && !in_array(strtoupper(trim($namehints['lecturername'])), array_map(fn($n) => strtoupper(trim($n)), $teachernames), true);

        // Raw, unmerged values for the exported Lecturer Mismatch report (see
        // mismatch_reports::lecturer_mismatch()) - deliberately kept separate from the
        // above $lecturername, which already prioritises/falls back between sources for
        // display; the mismatch report needs the timetable's and Moodle's own values
        // untouched so it can compare them directly against each other.
        $timetablelecturer = !empty($offering['lecturernames']) ? implode(', ', $offering['lecturernames']) : null;
        $lmslecturer = !empty($teachernames) ? implode(', ', $teachernames) : null;

        $programcode = $offering['programcode'] ?? null;
        $levelinfo = program_mapper::get_level($programcode);

        $heuristicresult = heuristics::evaluate_course($course, $studentuserids);

        $needsclassification = false;
        if ($matcherresult['ambiguous']) {
            // Can't pick a single offering, but if every tied candidate agrees on
            // Programme level anyway (e.g. one lecturer teaching one DL class shared
            // across BCOM/BPL/BPM's cohorts as a single combined session), that shared
            // answer is still usable even though which specific offering applies isn't.
            $unanimouslevel = self::resolve_unanimous_level($matcherresult['winners'] ?? []);
            if ($unanimouslevel !== null) {
                $levelinfo = ['level' => $unanimouslevel, 'confirmed' => true];
            } else {
                $needsclassification = true;
            }
        } else if ($programcode !== null && !$levelinfo['confirmed']) {
            $needsclassification = true;
        }

        $unitcode = $matcherresult['unitcode'] ?? $course->shortname;
        $unitname = $namehints['unitname'] ?? ($offering['unitname'] ?? $course->fullname);

        // The course's own claimed location (e.g. "ZOOM") is course-specific ground truth,
        // more reliable than the timetable's generic per-slot room type.
        $modeoflearning = self::normalize_mode_of_learning($namehints['location'] ?? null)
            ?? self::normalize_mode_of_learning($offering['roomtype'] ?? null);

        return [
            'unitcode' => $unitcode,
            'unitname' => $unitname,
            'school' => $offering['institution'] ?? null,
            // The programme itself (e.g. "Bachelor of Commerce"), not the classified level -
            // falls back to the bare programme code if the timetable didn't carry a full name.
            'programme' => $offering['programname'] ?? $offering['programcode'] ?? null,
            'numstudents' => $numstudents,
            'lecturername' => $lecturername,
            'timetablelecturer' => $timetablelecturer,
            'lmslecturer' => $lmslecturer,
            'hastimetablematch' => !empty($matcherresult['offerings']) ? 1 : 0,
            'programlevel' => $levelinfo['level'],
            'modeoflearning' => $modeoflearning,
            'templatedeployed' => $heuristicresult['templatedeployed'],
            'attendancelink' => $heuristicresult['attendancelink'],
            'materialsweeks' => $heuristicresult['materialsweeks'],
            'numcatsquizzes' => $heuristicresult['numcatsquizzes'],
            'assign1feedback' => $heuristicresult['assign1feedback'],
            'assign2feedback' => $heuristicresult['assign2feedback'],
            'assign3feedback' => $heuristicresult['assign3feedback'],
            'cat1feedback' => $heuristicresult['cat1feedback'],
            'cat2feedback' => $heuristicresult['cat2feedback'],
            'needsclassification' => $needsclassification ? 1 : 0,
            'matchedterm' => $matcherresult['term'],
            // Reflects when this row was last (re)checked, automated or not. save_row() never
            // reaches this for a reviewed row (those are skipped outright), so a human's own
            // review date is never clobbered by a later automated refresh.
            'datechecked' => time(),
            'evidence' => json_encode([
                'matcher' => [
                    'unitcode' => $matcherresult['unitcode'],
                    'ambiguous' => $matcherresult['ambiguous'],
                    'offeringscount' => count($matcherresult['offerings']),
                    'offerings' => $matcherresult['offerings'],
                ],
                'namehints' => $namehints,
                'lecturermismatch' => $lecturermismatch,
                'heuristics' => $heuristicresult['evidence'],
            ]),
        ];
    }

    /**
     * Inserts or updates the local_courseaudit_audit row for a course. A row already marked
     * reviewed=1 never has its QA judgement (compliance fields, comments) overwritten, but its
     * LIVE_REFERENCE_FIELDS are still refreshed - see this class's docblock for why.
     *
     * @param int $courseid
     * @param array $data As returned by build_row().
     * @return string 'created', 'updated', or 'skippedreviewed'.
     */
    public static function save_row(int $courseid, array $data): string {
        global $DB;

        $existing = $DB->get_record('local_courseaudit_audit', ['courseid' => $courseid]);
        $now = time();

        if ($existing && !empty($existing->reviewed)) {
            $record = (object) ['id' => $existing->id];
            foreach (self::LIVE_REFERENCE_FIELDS as $field) {
                $record->$field = $data[$field];
            }
            $record->timemodified = $now;
            $DB->update_record('local_courseaudit_audit', $record);
            return 'skippedreviewed';
        }

        if ($existing) {
            $record = (object) array_merge((array) $existing, $data);
            $record->id = $existing->id;
            $record->courseid = $courseid;
            $record->timemodified = $now;
            $DB->update_record('local_courseaudit_audit', $record);
            return 'updated';
        }

        $record = (object) $data;
        $record->courseid = $courseid;
        $record->reviewed = 0;
        $record->reviewedby = null;
        $record->comments = '';
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_courseaudit_audit', $record);
        return 'created';
    }

    /**
     * When course_matcher couldn't pick a single offering, checks whether every
     * tied candidate at least agrees on a confirmed Programme level anyway.
     *
     * @param array $winners Tied offerings, as returned by course_matcher::find_for_course()['winners'].
     * @return string|null The shared level, or null if there's no confirmed level or they disagree.
     */
    protected static function resolve_unanimous_level(array $winners): ?string {
        if (empty($winners)) {
            return null;
        }

        $level = null;
        foreach ($winners as $winner) {
            $info = program_mapper::get_level($winner['programcode'] ?? null);
            if (!$info['confirmed'] || $info['level'] === null) {
                return null;
            }
            if ($level === null) {
                $level = $info['level'];
            } else if ($level !== $info['level']) {
                return null;
            }
        }

        return $level;
    }

    /**
     * Returns the display names of users holding any of the given role
     * shortnames in a course context (deduplicated).
     *
     * @param \context $context
     * @param string[] $roleshortnames
     * @return string[]
     */
    protected static function role_user_names(\context $context, array $roleshortnames): array {
        $names = [];
        foreach (self::get_role_users($context, $roleshortnames) as $user) {
            $name = fullname($user);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Fetches the deduplicated set of users holding any of the given role
     * shortnames in a course context.
     *
     * @param \context $context
     * @param string[] $roleshortnames
     * @return array Keyed by userid.
     */
    protected static function get_role_users(\context $context, array $roleshortnames): array {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($roleshortnames, SQL_PARAMS_NAMED);
        $roleids = $DB->get_fieldset_select('role', 'id', "shortname $insql", $params);

        $users = [];
        foreach ($roleids as $roleid) {
            $roleusers = get_role_users($roleid, $context, false, 'u.id, u.firstname, u.lastname, u.firstnamephonetic, ' .
                'u.lastnamephonetic, u.middlename, u.alternatename');
            foreach ($roleusers as $user) {
                $users[$user->id] = $user;
            }
        }
        return $users;
    }
}
