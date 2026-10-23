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
 * Moodle-hosted tests for the daily trend snapshots: writing, same-day replacement, the
 * upgrade backfill from archived cycles, and per-viewer category scoping when reading them back.
 *
 * Run with Moodle's PHPUnit setup (these use the database, so they cannot run standalone like
 * the pure unit tests).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\trend_snapshot
 * @covers     \local_courseaudit\trend_report
 */
final class trend_db_test extends \advanced_testcase {

    /** @var \stdClass[] Two courses in two different categories. */
    protected array $courses = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        foreach (['a', 'b'] as $key) {
            $category = $generator->create_category();
            $this->courses[$key] = $generator->create_course(['category' => $category->id]);
        }
    }

    /** Inserts an audit row for a course. */
    protected function audit(\stdClass $course, array $fields = []): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_courseaudit_audit', (object) ($fields + [
            'courseid' => $course->id, 'school' => 'SOB', 'reviewed' => 0, 'templatedeployed' => 'Yes',
            'attendancelink' => 'No', 'materialsweeks' => '0', 'numcatsquizzes' => 'None',
            'timecreated' => $now, 'timemodified' => $now,
        ]));
    }

    public function test_capture_writes_one_row_per_category_and_school(): void {
        global $DB;
        $this->audit($this->courses['a']);
        $this->audit($this->courses['b'], ['templatedeployed' => 'No']);

        $this->assertSame(2, trend_snapshot::capture());

        $rows = $DB->get_records('local_courseaudit_trend');
        $this->assertCount(2, $rows);
        $this->assertSame(2, array_sum(array_column($rows, 'courses')));
        $this->assertSame(1, array_sum(array_column($rows, 'templateyes')));
    }

    public function test_capturing_twice_on_the_same_day_replaces_the_snapshot(): void {
        global $DB;
        $this->audit($this->courses['a']);
        $now = time();

        trend_snapshot::capture($now);
        trend_snapshot::capture($now + 60);

        $this->assertSame(1, $DB->count_records('local_courseaudit_trend'));
    }

    public function test_a_later_day_adds_rather_than_replaces(): void {
        global $DB;
        $this->audit($this->courses['a']);
        $now = time();

        trend_snapshot::capture($now);
        trend_snapshot::capture($now + 2 * DAYSECS);

        $this->assertSame(2, $DB->count_records('local_courseaudit_trend'));
    }

    public function test_an_empty_audit_table_does_not_wipe_todays_snapshot(): void {
        global $DB;
        $this->audit($this->courses['a']);
        $now = time();
        trend_snapshot::capture($now);

        $DB->delete_records('local_courseaudit_audit');
        $this->assertSame(0, trend_snapshot::capture($now));
        $this->assertSame(1, $DB->count_records('local_courseaudit_trend'));
    }

    public function test_backfill_seeds_from_archived_cycles_and_is_skipped_when_data_exists(): void {
        global $DB;
        $this->setAdminUser();
        $this->audit($this->courses['a']);
        $this->audit($this->courses['b']);
        audit_history::archive_current('Cycle 1', get_admin()->id);
        $DB->delete_records('local_courseaudit_audit');

        trend_snapshot::backfill();
        $this->assertSame(2, $DB->count_records('local_courseaudit_trend'));

        // Already seeded: a second call must not duplicate anything.
        trend_snapshot::backfill();
        $this->assertSame(2, $DB->count_records('local_courseaudit_trend'));
    }

    /** Snapshot rows for two categories, two schools, on two days. */
    protected function seed_trend(): void {
        global $DB;
        $base = ['courses' => 5, 'reviewed' => 1, 'templateyes' => 3, 'attendanceyes' => 0, 'materialsyes' => 0,
            'catsyes' => 0, 'assign1yes' => 0, 'assign2yes' => 0, 'assign3yes' => 0, 'cat1yes' => 0, 'cat2yes' => 0,
            'timecaptured' => time()];
        foreach ([[20261016, 'a', 'SOB'], [20261023, 'a', 'SOB'], [20261023, 'b', 'SOE']] as [$day, $key, $school]) {
            $DB->insert_record('local_courseaudit_trend', (object) ($base + [
                'snapday' => $day, 'categoryid' => $this->courses[$key]->category, 'school' => $school,
            ]));
        }
    }

    public function test_fetch_rows_for_a_site_wide_viewer(): void {
        $this->seed_trend();
        $this->assertCount(3, trend_report::fetch_rows(true, [], 0, '', 0));
    }

    public function test_fetch_rows_only_returns_the_allowed_categories_to_a_scoped_viewer(): void {
        $this->seed_trend();
        $categorya = (int) $this->courses['a']->category;

        $rows = trend_report::fetch_rows(false, [$categorya], 0, '', 0);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($categorya, (int) $row->categoryid);
        }
    }

    public function test_a_scoped_viewer_cannot_widen_access_by_asking_for_another_category(): void {
        $this->seed_trend();
        $rows = trend_report::fetch_rows(false, [(int) $this->courses['a']->category], (int) $this->courses['b']->category, '', 0);
        $this->assertSame([], $rows);
    }

    public function test_a_scoped_viewer_with_no_categories_sees_nothing(): void {
        $this->seed_trend();
        $this->assertSame([], trend_report::fetch_rows(false, [], 0, '', 0));
    }

    public function test_fetch_rows_filters_by_school_and_start_day(): void {
        $this->seed_trend();
        $this->assertCount(2, trend_report::fetch_rows(true, [], 0, 'SOB', 0));
        $this->assertCount(2, trend_report::fetch_rows(true, [], 0, '', 20261020));
    }

    public function test_since_day_is_zero_for_all_time_and_a_valid_day_otherwise(): void {
        $now = time();
        $this->assertSame(0, trend_report::since_day(0, $now));
        $since = trend_report::since_day(4, $now);
        $this->assertTrue($since < trend_snapshot::day_key($now));
        $this->assertSame(8, strlen((string) $since));
    }
}
