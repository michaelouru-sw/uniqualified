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
 * Unit tests for the trend snapshot aggregation and the trend report maths (pure parts only).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\trend_snapshot
 * @covers     \local_courseaudit\trend_report
 */
final class trend_test extends \basic_testcase {

    /** Builds an audit-like row. */
    protected static function row(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'category' => 5, 'school' => 'SOB', 'reviewed' => 0, 'templatedeployed' => 'No',
            'attendancelink' => 'No', 'materialsweeks' => '0', 'numcatsquizzes' => 'None',
            'assign1feedback' => 'No', 'assign2feedback' => 'No', 'assign3feedback' => 'No',
            'cat1feedback' => 'No', 'cat2feedback' => 'No',
        ]);
    }

    public function test_row_flags_follow_the_compliance_chart_rules(): void {
        $flags = trend_snapshot::row_flags(self::row([
            'reviewed' => 1, 'templatedeployed' => 'Yes', 'attendancelink' => 'Yes', 'materialsweeks' => ' 4 ',
            'numcatsquizzes' => '2', 'assign1feedback' => '67%', 'assign2feedback' => '0%',
            'assign3feedback' => 'No', 'cat1feedback' => '100%', 'cat2feedback' => null,
        ]));
        $this->assertSame([
            'reviewed' => 1, 'templateyes' => 1, 'attendanceyes' => 1, 'materialsyes' => 1, 'catsyes' => 1,
            'assign1yes' => 1, 'assign2yes' => 0, 'assign3yes' => 0, 'cat1yes' => 1, 'cat2yes' => 0,
        ], $flags);
    }

    public function test_row_flags_reject_free_text_and_none(): void {
        $flags = trend_snapshot::row_flags(self::row([
            'materialsweeks' => 'abc', 'numcatsquizzes' => 'none', 'assign1feedback' => 'done',
        ]));
        $this->assertSame(0, $flags['materialsyes']);
        $this->assertSame(0, $flags['catsyes']);
        $this->assertSame(0, $flags['assign1yes']);
    }

    public function test_aggregate_rows_groups_by_category_and_school(): void {
        $groups = trend_snapshot::aggregate_rows([
            self::row(['reviewed' => 1, 'templatedeployed' => 'Yes', 'attendancelink' => 'Yes', 'materialsweeks' => '3',
                'numcatsquizzes' => '2', 'assign1feedback' => '67%', 'cat1feedback' => '100%']),
            self::row(),
            self::row(['category' => 7, 'school' => null, 'templatedeployed' => 'Yes']),
        ]);

        $this->assertCount(2, $groups);
        $sob = $groups[0];
        $this->assertSame(5, $sob['categoryid']);
        $this->assertSame('SOB', $sob['school']);
        $this->assertSame(2, $sob['courses']);
        $this->assertSame(1, $sob['reviewed']);
        $this->assertSame(1, $sob['templateyes']);
        $this->assertSame(1, $sob['assign1yes']);
        $this->assertSame(0, $sob['assign2yes']);

        $this->assertSame(7, $groups[1]['categoryid']);
        $this->assertSame('', $groups[1]['school']);
        $this->assertSame(1, $groups[1]['courses']);
    }

    public function test_aggregate_rows_empty(): void {
        $this->assertSame([], trend_snapshot::aggregate_rows([]));
    }

    public function test_percentages(): void {
        $pct = trend_report::percentages([
            'courses' => 4, 'reviewed' => 1, 'templateyes' => 2, 'attendanceyes' => 1, 'materialsyes' => 4,
            'catsyes' => 0, 'assign1yes' => 4, 'assign2yes' => 0, 'assign3yes' => 0, 'cat1yes' => 0, 'cat2yes' => 0,
        ]);
        $this->assertSame(50.0, $pct['template']);
        $this->assertSame(25.0, $pct['attendance']);
        $this->assertSame(100.0, $pct['materials']);
        $this->assertSame(0.0, $pct['cats']);
        $this->assertSame(20.0, $pct['feedback']);
        $this->assertSame(39.0, $pct['overall']);
        $this->assertSame(25.0, $pct['reviewed']);
    }

    public function test_percentages_with_no_courses_are_null_not_zero(): void {
        $pct = trend_report::percentages(['courses' => 0]);
        foreach (trend_report::PERCENT_KEYS as $key) {
            $this->assertNull($pct[$key]);
        }
    }

    /** A day's rows (one per category x school) are summed into one totals row. */
    public function test_daily_totals_sum_rows_per_day_in_order(): void {
        $daily = trend_report::daily_totals([
            (object) ['snapday' => 20261021, 'courses' => 3, 'reviewed' => 1, 'templateyes' => 2],
            (object) ['snapday' => 20261019, 'courses' => 5, 'reviewed' => 0, 'templateyes' => 1],
            (object) ['snapday' => 20261021, 'courses' => 2, 'reviewed' => 1, 'templateyes' => 1],
        ]);
        $this->assertSame([20261019, 20261021], array_keys($daily));
        $this->assertSame(5, $daily[20261021]['courses']);
        $this->assertSame(2, $daily[20261021]['reviewed']);
        $this->assertSame(3, $daily[20261021]['templateyes']);
    }

    protected static function daily(array $courseandtemplate): array {
        $rows = [];
        foreach ($courseandtemplate as $day => [$courses, $template]) {
            $rows[] = (object) ['snapday' => $day, 'courses' => $courses, 'templateyes' => $template];
        }
        return trend_report::daily_totals($rows);
    }

    public function test_bucket_by_week_keeps_each_weeks_last_snapshot(): void {
        // 19 Oct 2026 is a Monday.
        $daily = self::daily([20261019 => [10, 2], 20261021 => [10, 4], 20261026 => [10, 6]]);
        $weeks = trend_report::bucket($daily, trend_report::GRAIN_WEEK);

        $this->assertCount(2, $weeks);
        $this->assertSame(20261019, $weeks[0]['key']);
        $this->assertSame('19 Oct', $weeks[0]['label']);
        $this->assertSame(20261021, $weeks[0]['day']);
        $this->assertSame(40.0, $weeks[0]['pct']['template']);
        $this->assertSame(60.0, $weeks[1]['pct']['template']);
    }

    public function test_bucket_by_day_keeps_every_day(): void {
        $daily = self::daily([20261019 => [10, 2], 20261021 => [10, 4], 20261026 => [10, 6]]);
        $this->assertCount(3, trend_report::bucket($daily, trend_report::GRAIN_DAY));
    }

    public function test_bucket_week_starts_on_monday_for_a_sunday(): void {
        // Sunday 25 Oct belongs to the week starting Monday 19 Oct.
        $weeks = trend_report::bucket(self::daily([20261025 => [10, 5]]), trend_report::GRAIN_WEEK);
        $this->assertSame(20261019, $weeks[0]['key']);
    }

    public function test_kpis_compare_with_the_snapshot_a_week_earlier(): void {
        $daily = self::daily([20261009 => [10, 5], 20261016 => [10, 6], 20261023 => [10, 8]]);
        $kpis = trend_report::kpis($daily);

        $this->assertSame(20261023, $kpis['asof']);
        $this->assertSame(20261016, $kpis['baselineday']);
        $this->assertSame(7, $kpis['baselinegap']);
        $this->assertSame(10, $kpis['courses']);
        $template = $kpis['metrics']['template'];
        $this->assertSame(80.0, $template['value']);
        $this->assertSame(60.0, $template['previous']);
        $this->assertSame(20.0, $template['delta']);
        $this->assertSame([50.0, 60.0, 80.0], $template['spark']);
    }

    public function test_kpis_short_history_falls_back_to_the_oldest_snapshot(): void {
        $kpis = trend_report::kpis(self::daily([20261022 => [10, 5], 20261023 => [10, 6]]));
        $this->assertSame(1, $kpis['baselinegap']);
        $this->assertSame(10.0, $kpis['metrics']['template']['delta']);
    }

    public function test_kpis_single_snapshot_has_no_delta(): void {
        $kpis = trend_report::kpis(self::daily([20261023 => [10, 5]]));
        $this->assertNull($kpis['baselineday']);
        $this->assertNull($kpis['metrics']['template']['delta']);
        $this->assertSame(50.0, $kpis['metrics']['template']['value']);
    }

    public function test_kpis_without_snapshots_is_null(): void {
        $this->assertNull(trend_report::kpis([]));
    }

    protected static function ts(string $datetime): int {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    }

    public function test_weekly_activity_counts_per_iso_week_and_ignores_out_of_range(): void {
        $utc = new \DateTimeZone('UTC');
        $activity = trend_report::weekly_activity(
            [self::ts('2026-10-06 10:00'), self::ts('2026-10-14 10:00'), self::ts('2026-10-20 10:00'),
                self::ts('2026-10-22 10:00'), self::ts('2026-09-01 10:00')],
            [self::ts('2026-10-21 10:00')],
            3,
            self::ts('2026-10-23 12:00'),
            $utc
        );

        $this->assertSame(['5 Oct', '12 Oct', '19 Oct'], $activity['labels']);
        $this->assertSame([1, 1, 2], $activity['added']);
        $this->assertSame([0, 0, 1], $activity['reviewed']);
    }

    public function test_weekly_activity_all_time_starts_at_the_earliest_event(): void {
        $activity = trend_report::weekly_activity(
            [self::ts('2026-09-01 10:00')], [], 0, self::ts('2026-10-23 12:00'), new \DateTimeZone('UTC'));
        $this->assertCount(8, $activity['labels']); // 31 Aug ... 19 Oct.
        $this->assertSame(1, $activity['added'][0]);
    }

    public function test_weekly_activity_with_no_events(): void {
        $activity = trend_report::weekly_activity([], [], 0, self::ts('2026-10-23 12:00'), new \DateTimeZone('UTC'));
        $this->assertSame(['19 Oct'], $activity['labels']);
        $this->assertSame([0], $activity['added']);
    }
}
