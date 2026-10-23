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
 * Ranks who needs attention first: the lowest-compliance schools, the schools slipping fastest
 * week on week, and the lecturers whose courses have the most gaps.
 *
 * Schools come from the daily trend snapshots (so they carry a week-on-week change); lecturers
 * come from the live audit rows. Both are pure (no DB, no Moodle API) so they are unit tested
 * directly.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attention_report {

    /** @var float A school losing at least this many points week on week is flagged as dropping. */
    const DROP_THRESHOLD = 2.0;

    /**
     * Schools ranked by overall compliance, and by how fast they are falling.
     *
     * @param iterable $snapshotrows Rows from trend_report::fetch_rows(), for every school.
     * @param int $limit Max entries per list.
     * @return array ['lowest' => array[], 'drops' => array[]], each entry
     *         ['school' => string, 'courses' => int, 'overall' => float, 'delta' => float|null].
     */
    public static function rank_schools(iterable $snapshotrows, int $limit = 5): array {
        $byschool = [];
        foreach ($snapshotrows as $row) {
            $row = (object) $row;
            $school = trim((string) ($row->school ?? ''));
            if ($school === '') {
                continue; // Unassigned courses have nobody to chase.
            }
            $byschool[$school][] = $row;
        }

        $entries = [];
        foreach ($byschool as $school => $rows) {
            $kpis = trend_report::kpis(trend_report::daily_totals($rows));
            $overall = $kpis['metrics']['overall']['value'] ?? null;
            if ($kpis === null || $overall === null) {
                continue;
            }
            $entries[] = [
                'school' => (string) $school,
                'courses' => $kpis['courses'],
                'overall' => $overall,
                'delta' => $kpis['metrics']['overall']['delta'],
            ];
        }

        $lowest = $entries;
        usort($lowest, static function (array $a, array $b): int {
            return [$a['overall'], -$a['courses'], $a['school']] <=> [$b['overall'], -$b['courses'], $b['school']];
        });

        $drops = array_values(array_filter($entries, static function (array $entry): bool {
            return $entry['delta'] !== null && $entry['delta'] <= -self::DROP_THRESHOLD;
        }));
        usort($drops, static function (array $a, array $b): int {
            return [$a['delta'], $a['school']] <=> [$b['delta'], $b['school']];
        });

        return ['lowest' => array_slice($lowest, 0, $limit), 'drops' => array_slice($drops, 0, $limit)];
    }

    /**
     * A single course's compliance score, 0-100: the average of template, attendance link,
     * materials, CATs/quizzes posted and feedback - the same definition as the dashboard's
     * "Overall compliance".
     *
     * @param \stdClass|array $row Audit row.
     * @return float
     */
    public static function course_score($row): float {
        $flags = trend_snapshot::row_flags($row);
        $feedback = ($flags['assign1yes'] + $flags['assign2yes'] + $flags['assign3yes']
            + $flags['cat1yes'] + $flags['cat2yes']) / 5;
        return round(($flags['templateyes'] + $flags['attendanceyes'] + $flags['materialsyes']
            + $flags['catsyes'] + $feedback) / 5 * 100, 1);
    }

    /**
     * Lecturers ranked by the average compliance of their courses, lowest first. A co-taught
     * course ("A, B") counts for each lecturer named on it.
     *
     * @param iterable $auditrows Rows with ->lecturername and the audit columns.
     * @param int $limit
     * @return array[] ['name' => string, 'courses' => int, 'average' => float, 'unreviewed' => int]
     */
    public static function rank_lecturers(iterable $auditrows, int $limit = 5): array {
        $lecturers = [];
        foreach ($auditrows as $row) {
            $row = (object) $row;
            $score = self::course_score($row);
            $unreviewed = empty($row->reviewed) ? 1 : 0;

            foreach (explode(',', (string) ($row->lecturername ?? '')) as $name) {
                $name = trim(preg_replace('/\s+/', ' ', $name));
                if ($name === '') {
                    continue;
                }
                $key = mb_strtoupper($name);
                if (!isset($lecturers[$key])) {
                    $lecturers[$key] = ['name' => $name, 'courses' => 0, 'total' => 0.0, 'unreviewed' => 0];
                }
                $lecturers[$key]['courses']++;
                $lecturers[$key]['total'] += $score;
                $lecturers[$key]['unreviewed'] += $unreviewed;
            }
        }

        $ranked = [];
        foreach ($lecturers as $lecturer) {
            $ranked[] = [
                'name' => $lecturer['name'],
                'courses' => $lecturer['courses'],
                'average' => round($lecturer['total'] / $lecturer['courses'], 1),
                'unreviewed' => $lecturer['unreviewed'],
            ];
        }
        usort($ranked, static function (array $a, array $b): int {
            return [$a['average'], -$a['courses'], $a['name']] <=> [$b['average'], -$b['courses'], $b['name']];
        });

        return array_slice($ranked, 0, $limit);
    }
}
