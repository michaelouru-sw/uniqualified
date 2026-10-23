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
 * Records a daily snapshot of how many audited courses meet each requirement, so the dashboard
 * can chart compliance over time (the audit table itself is overwritten on every refresh, so on
 * its own it can only ever show "now").
 *
 * One row is stored per day x course category x school: small (a few hundred rows a day at most)
 * and still filterable by category/school - and, importantly, still permission-safe for
 * category-scoped viewers, who are only ever shown the categories they were granted.
 *
 * aggregate_rows() is pure (no DB, no Moodle API) so it can be unit tested in isolation.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trend_snapshot {

    /** @var string[] Count columns stored per snapshot row, besides courses/reviewed. */
    const METRIC_COLUMNS = [
        'templateyes', 'attendanceyes', 'materialsyes', 'catsyes',
        'assign1yes', 'assign2yes', 'assign3yes', 'cat1yes', 'cat2yes',
    ];

    /** @var string Audit columns read for each course, in the order the flags use them. */
    const AUDIT_COLUMNS = 'a.reviewed, a.templatedeployed, a.attendancelink, a.materialsweeks, a.numcatsquizzes, '
        . 'a.assign1feedback, a.assign2feedback, a.assign3feedback, a.cat1feedback, a.cat2feedback';

    /**
     * Which requirements one course currently meets. Same rules the compliance bar chart uses, so
     * the trend lines and the current-state chart always agree.
     *
     * @param \stdClass|array $row Audit (or archived audit) row.
     * @return int[] 0/1 per METRIC_COLUMNS key, plus 'reviewed'.
     */
    public static function row_flags($row): array {
        $row = (object) $row;

        $positivepercent = static function ($value): int {
            return is_string($value) && preg_match('/^(\d+)%$/', trim($value), $m) && (int) $m[1] > 0 ? 1 : 0;
        };
        $materials = trim((string) ($row->materialsweeks ?? ''));
        $cats = trim((string) ($row->numcatsquizzes ?? ''));

        return [
            'reviewed' => !empty($row->reviewed) ? 1 : 0,
            'templateyes' => ($row->templatedeployed ?? null) === 'Yes' ? 1 : 0,
            'attendanceyes' => ($row->attendancelink ?? null) === 'Yes' ? 1 : 0,
            'materialsyes' => ctype_digit($materials) && (int) $materials > 0 ? 1 : 0,
            'catsyes' => ($cats !== '' && strcasecmp($cats, 'None') !== 0) ? 1 : 0,
            'assign1yes' => $positivepercent($row->assign1feedback ?? null),
            'assign2yes' => $positivepercent($row->assign2feedback ?? null),
            'assign3yes' => $positivepercent($row->assign3feedback ?? null),
            'cat1yes' => $positivepercent($row->cat1feedback ?? null),
            'cat2yes' => $positivepercent($row->cat2feedback ?? null),
        ];
    }

    /**
     * Groups course rows into one totals row per category x school.
     *
     * @param iterable $rows Each with ->category (course category id), ->school and the audit columns.
     * @return array[] List of ['categoryid', 'school', 'courses', 'reviewed', ...METRIC_COLUMNS].
     */
    public static function aggregate_rows(iterable $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            $categoryid = (int) ($row->category ?? 0);
            $school = trim((string) ($row->school ?? ''));
            $key = $categoryid . '|' . $school;

            if (!isset($groups[$key])) {
                $groups[$key] = ['categoryid' => $categoryid, 'school' => $school, 'courses' => 0, 'reviewed' => 0]
                    + array_fill_keys(self::METRIC_COLUMNS, 0);
            }
            $groups[$key]['courses']++;
            foreach (self::row_flags($row) as $column => $flag) {
                $groups[$key][$column] += $flag;
            }
        }
        return array_values($groups);
    }

    /**
     * Calendar day key (YYYYMMDD) for a timestamp, in the site's timezone.
     *
     * @param int $timestamp
     * @return int
     */
    public static function day_key(int $timestamp): int {
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(\core_date::get_server_timezone_object());
        return (int) $date->format('Ymd');
    }

    /**
     * Snapshots the live audit table under today's date, replacing any earlier snapshot taken
     * the same day (so several refreshes in one day just keep the latest).
     *
     * Does nothing - and in particular does not wipe the day's existing snapshot - when the audit
     * table is empty, e.g. straight after "Clear all data".
     *
     * @param int|null $now Timestamp, for tests/backfills; defaults to now.
     * @return int Number of snapshot rows written.
     */
    public static function capture(?int $now = null): int {
        global $DB;

        $now = $now ?? time();
        $rows = $DB->get_records_sql(
            'SELECT a.id, c.category AS category, a.school, ' . self::AUDIT_COLUMNS . '
               FROM {local_courseaudit_audit} a
               JOIN {course} c ON c.id = a.courseid'
        );
        return self::store(self::aggregate_rows($rows), self::day_key($now), $now);
    }

    /**
     * One-off seeding on upgrade: one point per archived audit cycle (dated when it was archived),
     * then today's live state - so the trend charts have history from day one instead of starting
     * empty. Skipped if any snapshots already exist.
     *
     * @return void
     */
    public static function backfill(): void {
        global $DB;

        if ($DB->record_exists('local_courseaudit_trend', [])) {
            return;
        }

        $cycles = $DB->get_records_sql(
            'SELECT cyclelabel, MAX(archivedtime) AS archivedtime
               FROM {local_courseaudit_history}
           GROUP BY cyclelabel
           ORDER BY MAX(archivedtime) ASC'
        );
        foreach ($cycles as $cycle) {
            // Only the columns the flags read, and only courses that still exist (the category
            // comes from the live course record).
            $rows = $DB->get_records_sql(
                'SELECT h.id, c.category AS category, h.school, h.reviewed, h.templatedeployed, h.attendancelink,
                        h.materialsweeks, h.numcatsquizzes, h.assign1feedback, h.assign2feedback,
                        h.assign3feedback, h.cat1feedback, h.cat2feedback
                   FROM {local_courseaudit_history} h
                   JOIN {course} c ON c.id = h.courseid
                  WHERE h.cyclelabel = :cyclelabel',
                ['cyclelabel' => $cycle->cyclelabel]
            );
            $time = (int) $cycle->archivedtime;
            self::store(self::aggregate_rows($rows), self::day_key($time), $time);
        }

        self::capture();
    }

    /**
     * Replaces the given day's snapshot rows with $groups.
     *
     * @param array[] $groups As returned by aggregate_rows().
     * @param int $daykey
     * @param int $time
     * @return int Rows written.
     */
    protected static function store(array $groups, int $daykey, int $time): int {
        global $DB;

        if (empty($groups)) {
            return 0;
        }

        $records = [];
        foreach ($groups as $group) {
            $records[] = (object) array_merge($group, ['snapday' => $daykey, 'timecaptured' => $time]);
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_courseaudit_trend', ['snapday' => $daykey]);
        $DB->insert_records('local_courseaudit_trend', $records);
        $transaction->allow_commit();

        return count($records);
    }
}
