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
 * Turns the daily snapshots written by trend_snapshot into what the dashboard shows: a
 * day-or-week time series and week-on-week KPI deltas.
 *
 * Everything except fetch_daily() is pure (arrays in, arrays out) and unit tested without Moodle.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trend_report {

    /** @var string Group the series by calendar day. */
    const GRAIN_DAY = 'day';

    /** @var string Group the series by ISO week (Monday start), keeping each week's last snapshot. */
    const GRAIN_WEEK = 'week';

    /** @var string[] Percentages reported, in display order (overall first). */
    const PERCENT_KEYS = ['overall', 'template', 'attendance', 'materials', 'cats', 'feedback', 'reviewed'];

    /**
     * Compliance percentages from one totals row.
     *
     * "Overall" is the plain average of the five requirement groups (template, attendance link,
     * materials, CATs/quizzes posted, feedback), so no single column dominates; "reviewed" is a
     * workflow measure, not a requirement, and is deliberately left out of it.
     *
     * @param array $totals ['courses' => int, 'reviewed' => int, 'templateyes' => int, ...]
     * @return array<string, float|null> Null for every key when there are no courses.
     */
    public static function percentages(array $totals): array {
        $courses = (int) ($totals['courses'] ?? 0);
        if ($courses <= 0) {
            return array_fill_keys(self::PERCENT_KEYS, null);
        }

        $pct = static function (int $count, int $denominator): float {
            return $count / $denominator * 100;
        };

        $template = $pct((int) ($totals['templateyes'] ?? 0), $courses);
        $attendance = $pct((int) ($totals['attendanceyes'] ?? 0), $courses);
        $materials = $pct((int) ($totals['materialsyes'] ?? 0), $courses);
        $cats = $pct((int) ($totals['catsyes'] ?? 0), $courses);
        $feedbackcount = 0;
        foreach (['assign1yes', 'assign2yes', 'assign3yes', 'cat1yes', 'cat2yes'] as $column) {
            $feedbackcount += (int) ($totals[$column] ?? 0);
        }
        $feedback = $pct($feedbackcount, $courses * 5);

        $result = [
            'overall' => ($template + $attendance + $materials + $cats + $feedback) / 5,
            'template' => $template,
            'attendance' => $attendance,
            'materials' => $materials,
            'cats' => $cats,
            'feedback' => $feedback,
            'reviewed' => $pct((int) ($totals['reviewed'] ?? 0), $courses),
        ];
        return array_map(static fn(float $v): float => round($v, 1), $result);
    }

    /**
     * Sums snapshot rows (many per day: one per category x school) into one totals row per day.
     *
     * @param iterable $rows Snapshot rows with ->snapday and the count columns.
     * @return array<int, array> Keyed by YYYYMMDD, oldest first.
     */
    public static function daily_totals(iterable $rows): array {
        $columns = array_merge(['courses', 'reviewed'], trend_snapshot::METRIC_COLUMNS);
        $daily = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            $day = (int) $row->snapday;
            if (!isset($daily[$day])) {
                $daily[$day] = array_fill_keys($columns, 0);
            }
            foreach ($columns as $column) {
                $daily[$day][$column] += (int) ($row->$column ?? 0);
            }
        }
        ksort($daily);
        return $daily;
    }

    /**
     * Collapses daily totals to the requested grain. Counts are point-in-time states (not flows),
     * so a week is represented by its last snapshot, not a sum.
     *
     * @param array<int, array> $daily As returned by daily_totals().
     * @param string $grain GRAIN_DAY or GRAIN_WEEK.
     * @return array[] List of ['key' => int, 'label' => string, 'day' => int, 'totals' => array, 'pct' => array].
     */
    public static function bucket(array $daily, string $grain): array {
        $buckets = [];
        foreach ($daily as $day => $totals) {
            $date = self::to_date((int) $day);
            if ($grain === self::GRAIN_WEEK) {
                $monday = $date->modify('monday this week');
                $key = (int) $monday->format('Ymd');
                $label = $monday->format('j M');
            } else {
                $key = (int) $day;
                $label = $date->format('j M');
            }
            // Later days in the same bucket overwrite earlier ones - last snapshot wins.
            $buckets[$key] = [
                'key' => $key,
                'label' => $label,
                'day' => (int) $day,
                'totals' => $totals,
                'pct' => self::percentages($totals),
            ];
        }
        ksort($buckets);
        return array_values($buckets);
    }

    /**
     * Headline numbers with change since about a week earlier, plus a sparkline series.
     *
     * The baseline is the newest snapshot at least $comparedays older than the latest; if the
     * history is shorter than that, the oldest snapshot is used so a change is still shown (the
     * actual gap is returned in 'baselinegap' so the UI can say "vs 3 days ago").
     *
     * @param array<int, array> $daily As returned by daily_totals().
     * @param int $comparedays
     * @param int $sparkpoints Max trailing points returned per metric.
     * @return array|null Null when there are no snapshots.
     */
    public static function kpis(array $daily, int $comparedays = 7, int $sparkpoints = 14): ?array {
        if (empty($daily)) {
            return null;
        }

        $days = array_keys($daily);
        $latest = (int) end($days);
        $latestdate = self::to_date($latest);

        $baseline = null;
        foreach (array_reverse($days) as $day) {
            if ($day === $latest) {
                continue;
            }
            if ((int) self::to_date((int) $day)->diff($latestdate)->days >= $comparedays) {
                $baseline = (int) $day;
                break;
            }
        }
        if ($baseline === null && count($days) > 1) {
            $baseline = (int) $days[0];
        }

        $latestpct = self::percentages($daily[$latest]);
        $baselinepct = $baseline !== null ? self::percentages($daily[$baseline]) : null;

        $metrics = [];
        foreach (self::PERCENT_KEYS as $key) {
            $series = [];
            foreach ($daily as $totals) {
                $value = self::percentages($totals)[$key];
                if ($value !== null) {
                    $series[] = $value;
                }
            }
            $value = $latestpct[$key];
            $previous = $baselinepct[$key] ?? null;
            $metrics[$key] = [
                'value' => $value,
                'previous' => $previous,
                'delta' => ($value !== null && $previous !== null) ? round($value - $previous, 1) : null,
                'spark' => array_slice($series, -$sparkpoints),
            ];
        }

        return [
            'asof' => $latest,
            'baselineday' => $baseline,
            'baselinegap' => $baseline !== null ? (int) self::to_date($baseline)->diff($latestdate)->days : null,
            'courses' => (int) $daily[$latest]['courses'],
            'metrics' => $metrics,
        ];
    }

    /**
     * First day (YYYYMMDD) to include for a "last N weeks" range.
     *
     * @param int $weeks 0 = no limit.
     * @param int $now
     * @return int
     */
    public static function since_day(int $weeks, int $now): int {
        if ($weeks <= 0) {
            return 0;
        }
        return trend_snapshot::day_key($now - $weeks * WEEKSECS);
    }

    /**
     * Snapshot rows visible to this viewer for the chosen filters. Category scoping mirrors the
     * dashboard: a viewer without site-wide access only ever gets their allowed categories.
     *
     * @param bool $hassystemview
     * @param int[] $allowedcategoryids Ignored when $hassystemview.
     * @param int $categoryid 0 = any.
     * @param string $school '' = any.
     * @param int $sinceday YYYYMMDD, 0 = all.
     * @return \stdClass[]
     */
    public static function fetch_rows(bool $hassystemview, array $allowedcategoryids, int $categoryid,
            string $school, int $sinceday): array {
        global $DB;

        $where = ['snapday >= :sinceday'];
        $params = ['sinceday' => $sinceday];

        if ($categoryid > 0) {
            $where[] = 'categoryid = :categoryid';
            $params['categoryid'] = $categoryid;
        }
        if (!$hassystemview) {
            if (empty($allowedcategoryids)) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal($allowedcategoryids, SQL_PARAMS_NAMED, 'trendcat');
            $where[] = "categoryid $insql";
            $params += $inparams;
        }
        if ($school !== '') {
            $where[] = 'school = :school';
            $params['school'] = $school;
        }

        return $DB->get_records_select('local_courseaudit_trend', implode(' AND ', $where), $params, 'snapday ASC');
    }

    /**
     * @param int $daykey YYYYMMDD
     * @return \DateTimeImmutable Midnight of that calendar day (timezone-neutral calendar maths).
     */
    protected static function to_date(int $daykey): \DateTimeImmutable {
        return \DateTimeImmutable::createFromFormat('!Ymd', (string) $daykey, new \DateTimeZone('UTC'));
    }
}
