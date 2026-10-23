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
 * Best-guess detection of the quality-audit columns that aren't directly
 * derivable from enrolment/timetable data, using each course's activity and
 * section structure.
 *
 * Every method here returns ['value' => ..., 'evidence' => ...] rather than
 * a bare value: the dashboard shows the evidence as a tooltip so a reviewer
 * can see *why* a guess was made without having to open the course, and
 * every column stays editable regardless of what the heuristic produced.
 * A null value means "couldn't tell, leave it for a human" - it is never
 * forced to Yes/No/None just to fill the cell.
 *
 * These heuristics necessarily encode institution-specific conventions
 * (the "Course Policy Quiz" signal, CAT/Assignment naming, Zoom vs Google
 * Meet resources) that were confirmed directly by the site owner; if the
 * convention drifts, update the constants/patterns below rather than the
 * detection flow.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heuristics {

    /** @var string[] Activity module types that are themselves a live-class/attendance integration. */
    const ATTENDANCE_MODNAMES = ['zoom', 'bigbluebuttonbn'];

    /**
     * @var string[] Providers whose links count as an attendance/class link. Informational only:
     * the actual matching (host + path shape) lives in meeting_link_detector, which no longer
     * treats a bare hostname substring as enough.
     */
    const ATTENDANCE_URL_HOSTS = ['zoom.us', 'meet.google.com', 'teams.microsoft.com'];

    /**
     * @var string[] Activity module types that count as "learning materials" (a PDF/Word/video
     * file, a folder of files, a content page, a forum) for the "Materials posted" heuristic -
     * deliberately excludes assessment types (quiz/assign), since posting a CAT or assignment
     * isn't the same thing as posting course materials.
     */
    const MATERIAL_MODNAMES = ['resource', 'folder', 'page', 'forum'];

    /** @var int Highest week number considered when scanning for "Week N" sections. */
    const MAX_WEEK = 13;

    /**
     * Runs every heuristic for one course and returns a flat result set
     * keyed the same way as the local_courseaudit_audit table columns.
     *
     * @param \stdClass $course Full course record (as from get_course()).
     * @param int[] $studentuserids Enrolled student user ids, for the graded-percentage feedback
     *        checks below - passed in rather than re-queried, since audit_assembler::build_row()
     *        already fetches this same list for the "No. of Students" column.
     * @return array
     */
    public static function evaluate_course(\stdClass $course, array $studentuserids = []): array {
        $modinfo = get_fast_modinfo($course);
        $format = course_get_format($course);

        $template = self::detect_template_deployed($modinfo, $format);
        $attendance = self::detect_attendance_link($modinfo);
        $materials = self::detect_materials_count($modinfo);
        $catsquizzes = self::detect_cats_assignments($modinfo);

        $targets = [
            'assign1feedback' => self::find_named_activity($modinfo, 1, 'assign'),
            'assign2feedback' => self::find_named_activity($modinfo, 2, 'assign'),
            'assign3feedback' => self::find_named_activity($modinfo, 3, 'assign'),
            'cat1feedback' => self::find_named_activity($modinfo, 1, 'cat'),
            'cat2feedback' => self::find_named_activity($modinfo, 2, 'cat'),
        ];
        // One batched pair of queries covers all five checks, regardless of how many of the
        // named activities actually exist in this course - not five separate round trips.
        $gradedpercentages = self::batch_graded_percentages((int) $course->id, $targets, $studentuserids);

        $assign1 = self::named_feedback_result($targets['assign1feedback'], $gradedpercentages['assign1feedback'] ?? null);
        $assign2 = self::named_feedback_result($targets['assign2feedback'], $gradedpercentages['assign2feedback'] ?? null);
        $assign3 = self::named_feedback_result($targets['assign3feedback'], $gradedpercentages['assign3feedback'] ?? null);
        $cat1 = self::named_feedback_result($targets['cat1feedback'], $gradedpercentages['cat1feedback'] ?? null);
        $cat2 = self::named_feedback_result($targets['cat2feedback'], $gradedpercentages['cat2feedback'] ?? null);

        return [
            'templatedeployed' => $template['value'],
            'attendancelink' => $attendance['value'],
            'materialsweeks' => $materials['value'],
            'numcatsquizzes' => $catsquizzes['value'],
            'assign1feedback' => $assign1['value'],
            'assign2feedback' => $assign2['value'],
            'assign3feedback' => $assign3['value'],
            'cat1feedback' => $cat1['value'],
            'cat2feedback' => $cat2['value'],
            'evidence' => [
                'templatedeployed' => $template['evidence'],
                'attendancelink' => $attendance['evidence'],
                'materialsweeks' => $materials['evidence'],
                'numcatsquizzes' => $catsquizzes['evidence'],
                'assign1feedback' => $assign1['evidence'],
                'assign2feedback' => $assign2['evidence'],
                'assign3feedback' => $assign3['evidence'],
                'cat1feedback' => $cat1['evidence'],
                'cat2feedback' => $cat2['evidence'],
            ],
        ];
    }

    /**
     * "Standard Template deployed": primarily signalled by a quiz named
     * "Course Policy Quiz"; a "Start Here" section plus a good spread of
     * Week-numbered sections is treated as weaker supporting evidence when
     * the quiz itself isn't found (e.g. it was renamed).
     *
     * @param \course_modinfo $modinfo
     * @param \core_courseformat\base $format
     * @return array
     */
    protected static function detect_template_deployed(\course_modinfo $modinfo, $format): array {
        $haspolicyquiz = false;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'quiz' && preg_match('/course\s*policy\s*quiz/i', $cm->name)) {
                $haspolicyquiz = true;
                break;
            }
        }

        $hasstarthere = false;
        $weeksectioncount = 0;
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if ((int) $sectioninfo->section === 0) {
                continue;
            }
            $name = $format->get_section_name($sectioninfo);
            if ($name === '' || $name === null) {
                continue;
            }
            if (preg_match('/^\s*start\s*here/i', $name)) {
                $hasstarthere = true;
            }
            if (preg_match('/\bweek\s*\d{1,2}\b/i', $name)) {
                $weeksectioncount++;
            }
        }

        $evidence = [
            'coursepolicyquiz_found' => $haspolicyquiz,
            'starthere_section_found' => $hasstarthere,
            'week_named_section_count' => $weeksectioncount,
        ];

        if ($haspolicyquiz) {
            return ['value' => 'Yes', 'evidence' => $evidence];
        }
        if ($hasstarthere && $weeksectioncount >= 8) {
            return ['value' => 'Yes', 'evidence' => $evidence];
        }
        return ['value' => 'No', 'evidence' => $evidence];
    }

    /**
     * "Is there a class attendance link?": a Zoom/BigBlueButton activity, or a genuine
     * Zoom/Google Meet/Teams meeting link (see meeting_link_detector for what counts) in a URL
     * resource, a label, a page, or a section summary.
     *
     * Only visible activities/sections count: a link students can't see isn't a working
     * attendance link. Hidden ones are listed in the evidence as "ignored" so a reviewer can see
     * why a course that "has a link" was still marked No.
     *
     * Each text source is fetched in a single query per module type (rather than one query per
     * cm) - matters on courses with many activities, and compounds across a full site refresh
     * with thousands of courses.
     *
     * @param \course_modinfo $modinfo
     * @return array
     */
    protected static function detect_attendance_link(\course_modinfo $modinfo): array {
        global $DB;

        // Module types whose link lives in a text/url column, and which column that is.
        $textcolumns = ['url' => 'externalurl', 'label' => 'intro', 'page' => 'content'];

        $found = [];
        $ignored = [];
        $idsbymod = array_fill_keys(array_keys($textcolumns), []);
        $cmsbymod = array_fill_keys(array_keys($textcolumns), []);

        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $visible = (bool) $cm->visible;

            if (in_array($cm->modname, self::ATTENDANCE_MODNAMES, true)) {
                if ($visible) {
                    $found[] = "{$cm->modname}: {$cm->name}";
                } else {
                    $ignored[] = "ignored (hidden) {$cm->modname}: {$cm->name}";
                }
            } else if (isset($textcolumns[$cm->modname])) {
                $idsbymod[$cm->modname][] = $cm->instance;
                $cmsbymod[$cm->modname][$cm->instance] = $cm;
            }
        }

        foreach ($textcolumns as $modname => $column) {
            if (empty($idsbymod[$modname])) {
                continue;
            }
            $records = $DB->get_records_list($modname, 'id', $idsbymod[$modname], '', "id, {$column}");
            foreach ($records as $record) {
                $cm = $cmsbymod[$modname][$record->id] ?? null;
                if ($cm === null) {
                    continue;
                }
                foreach (self::links_in_text($record->{$column}) as $hit) {
                    if ($cm->visible) {
                        $found[] = "{$modname} ({$hit['label']}): {$cm->name}";
                    } else {
                        $ignored[] = "ignored (hidden) {$modname} ({$hit['label']}): {$cm->name}";
                    }
                }
            }
        }

        // Section summaries are already loaded with modinfo - no query needed.
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            foreach (self::links_in_text($sectioninfo->summary ?? '') as $hit) {
                $sectionname = 'section ' . (int) $sectioninfo->section;
                if ($sectioninfo->visible) {
                    $found[] = "section summary ({$hit['label']}): {$sectionname}";
                } else {
                    $ignored[] = "ignored (hidden) section summary ({$hit['label']}): {$sectionname}";
                }
            }
        }

        return [
            'value' => empty($found) ? 'No' : 'Yes',
            'evidence' => array_values(array_unique(array_merge($found, $ignored))),
        ];
    }

    /**
     * Meeting links found in a text/HTML/URL value, with a display label per provider.
     *
     * @param string|null $text
     * @return array[] Each ['provider' => string, 'url' => string, 'label' => string].
     */
    protected static function links_in_text(?string $text): array {
        $hits = meeting_link_detector::find_in_text($text);
        foreach ($hits as &$hit) {
            $hit['label'] = meeting_link_detector::label($hit['provider']);
        }
        return $hits;
    }

    /**
     * "Learning materials posted": a plain count, across the whole course, of
     * actual material activities - file resources (PDF/Word/video/etc.),
     * folders, pages, and forums. Quizzes and assignments are deliberately
     * excluded (see numcatsquizzes/detect_named_feedback for those) - posting
     * an assessment isn't the same thing as posting course materials. The
     * auto-created "Announcements" forum (type='news') every course gets by
     * default is also excluded - it exists regardless of whether the
     * lecturer has posted anything, so counting it would inflate every
     * course's total for free.
     *
     * Fetches every forum's type in a single query (rather than one query per
     * forum cm) - matters on courses with several forums, and compounds
     * across a full site refresh with thousands of courses.
     *
     * @param \course_modinfo $modinfo
     * @return array value is the count as a string, e.g. '0', '5', '12'.
     */
    protected static function detect_materials_count(\course_modinfo $modinfo): array {
        global $DB;

        $foruminstanceids = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'forum') {
                $foruminstanceids[] = $cm->instance;
            }
        }
        $forumtypes = empty($foruminstanceids)
            ? []
            : $DB->get_records_list('forum', 'id', $foruminstanceids, '', 'id, type');

        $matches = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!in_array($cm->modname, self::MATERIAL_MODNAMES, true)) {
                continue;
            }
            if ($cm->modname === 'forum' && ($forumtypes[$cm->instance]->type ?? null) === 'news') {
                continue;
            }
            $matches[] = "{$cm->modname}: {$cm->name}";
        }

        return ['value' => (string) count($matches), 'evidence' => $matches];
    }

    /**
     * "No. of CATs and Quizzes posted": counts every quiz/assign activity by
     * type, regardless of naming - naming conventions vary too much across
     * lecturers to rely on (see detect_named_feedback() for the columns that
     * do still need to identify one specific numbered activity by name).
     * Mapped onto the Lists sheet's None/1/2/3/4/5/"more than 5" vocabulary.
     *
     * @param \course_modinfo $modinfo
     * @return array
     */
    protected static function detect_cats_assignments(\course_modinfo $modinfo): array {
        $matches = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (in_array($cm->modname, ['quiz', 'assign'], true)) {
                $matches[] = "{$cm->modname}: {$cm->name}";
            }
        }

        return ['value' => self::count_to_dropdown_bucket(count($matches)), 'evidence' => $matches];
    }

    /**
     * Maps a raw count onto the Lists sheet's None/1/2/3/4/5/"more than 5"
     * vocabulary for "No. of CATs and Quizzes posted". Pure/testable in
     * isolation from Moodle's course_modinfo.
     *
     * @param int $count
     * @return string
     */
    public static function count_to_dropdown_bucket(int $count): string {
        if ($count <= 0) {
            return 'None';
        }
        if ($count <= 5) {
            return (string) $count;
        }
        return 'more than 5';
    }

    /**
     * Finds the quiz/assign activity named for a specific CAT/Assignment number, without
     * touching the database - pure in-memory search against modinfo. "CAT" is also known as
     * "Term Paper"/"TPAPER" at some schools (the master template's own column headers say
     * "Cat 1/Term paper Feedback"), so both namings are matched.
     *
     * @param \course_modinfo $modinfo
     * @param int $number 1, 2, or 3.
     * @param string $type 'assign' or 'cat'.
     * @return \cm_info|null
     */
    protected static function find_named_activity(\course_modinfo $modinfo, int $number, string $type): ?\cm_info {
        $pattern = $type === 'cat'
            ? '/\b(?:CAT|TPAPER|TERM\s*PAPER)\s*' . $number . '\b/i'
            : '/\bASS(?:IGNMENT)?\s*' . $number . '\b/i';

        foreach ($modinfo->get_cms() as $cm) {
            if (!in_array($cm->modname, ['quiz', 'assign'], true)) {
                continue;
            }
            if (preg_match($pattern, $cm->name)) {
                return $cm;
            }
        }

        return null;
    }

    /**
     * The percentage of enrolled students who have actually been graded on each of the found
     * named activities (assign1/2/3feedback, cat1/2feedback), in one pair of queries covering
     * every activity found in this course - not one query per activity. Checks
     * {grade_grades}.finalgrade directly (the actual displayed result, after any grade item
     * exceptions/calculations) rather than calling grade_get_grades(), which can trigger a full
     * course regrade as a side effect if the course's grades are flagged dirty - too expensive
     * to risk once per course across a full site refresh.
     *
     * @param int $courseid
     * @param array $targets Keyed by audit column name, each value a \cm_info|null as returned
     *        by find_named_activity().
     * @param int[] $studentuserids
     * @return array Keyed by audit column name -> int percentage (0-100). A key is absent if
     *         its target was null, had no grade item, or there were no students to grade.
     */
    protected static function batch_graded_percentages(int $courseid, array $targets, array $studentuserids): array {
        global $DB;

        $percentages = [];
        $foundtargets = array_filter($targets);
        if (empty($studentuserids) || empty($foundtargets)) {
            return $percentages;
        }

        $griditems = $DB->get_records_select(
            'grade_items',
            "courseid = :courseid AND itemtype = 'mod' AND itemmodule IN ('assign', 'quiz')",
            ['courseid' => $courseid]
        );
        $itemidbymodinstance = [];
        foreach ($griditems as $griditem) {
            $itemidbymodinstance[$griditem->itemmodule . ':' . $griditem->iteminstance] = $griditem->id;
        }

        $keybyitemid = [];
        foreach ($foundtargets as $key => $target) {
            $itemid = $itemidbymodinstance[$target->modname . ':' . $target->instance] ?? null;
            if ($itemid !== null) {
                $keybyitemid[$itemid] = $key;
            }
        }
        if (empty($keybyitemid)) {
            return $percentages;
        }

        list($iteminsql, $itemparams) = $DB->get_in_or_equal(array_keys($keybyitemid), SQL_PARAMS_NAMED, 'item');
        list($userinsql, $userparams) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'user');
        $gradedcounts = $DB->get_records_sql(
            "SELECT itemid, COUNT(*) AS gradedcount
               FROM {grade_grades}
              WHERE itemid $iteminsql AND userid $userinsql AND finalgrade IS NOT NULL
           GROUP BY itemid",
            array_merge($itemparams, $userparams)
        );

        $totalstudents = count($studentuserids);
        foreach ($keybyitemid as $itemid => $key) {
            $gradedcount = isset($gradedcounts[$itemid]) ? (int) $gradedcounts[$itemid]->gradedcount : 0;
            $percentages[$key] = (int) round(($gradedcount / $totalstudents) * 100);
        }

        return $percentages;
    }

    /**
     * Combines a found (or not) named activity with its batch-computed graded percentage into
     * the final ['value' => ..., 'evidence' => ...] shape. The activity not existing at all is
     * still reported as 'No' (no evidence of this requirement whatsoever); once it exists, the
     * value becomes the actual graded percentage (e.g. '67%') rather than a bare Yes/No - a
     * lecturer having created the activity isn't the same as having graded anyone's work.
     *
     * @param \cm_info|null $target
     * @param int|null $percentage
     * @return array
     */
    protected static function named_feedback_result(?\cm_info $target, ?int $percentage): array {
        if ($target === null) {
            return ['value' => 'No', 'evidence' => ['activity' => null]];
        }
        if ($percentage === null) {
            // Activity exists, but either has no grade item yet or there are no students
            // enrolled to grade - nothing to report a percentage against.
            return ['value' => 'No', 'evidence' => ['activity' => "{$target->modname}: {$target->name}", 'gradedpercentage' => null]];
        }
        return [
            'value' => $percentage . '%',
            'evidence' => ['activity' => "{$target->modname}: {$target->name}", 'gradedpercentage' => $percentage],
        ];
    }
}
