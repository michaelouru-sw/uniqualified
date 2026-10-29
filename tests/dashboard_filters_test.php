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
 * Unit tests for the pure parts of dashboard_filters (URL params and the "is anything applied" check).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\dashboard_filters
 */
final class dashboard_filters_test extends \basic_testcase {

    protected static function none(): array {
        return array_fill_keys(dashboard_filters::KEYS, '') + ['categoryid' => 0, 'minstudents' => 0];
    }

    public function test_to_params_keeps_only_filters_that_are_set(): void {
        $filters = ['school' => 'SOB', 'template' => 'no', 'minstudents' => 5] + self::none();
        $this->assertSame(['school' => 'SOB', 'template' => 'no', 'minstudents' => 5], dashboard_filters::to_params($filters));
    }

    public function test_to_params_can_leave_one_filter_out_for_a_remove_link(): void {
        $filters = ['school' => 'SOB', 'template' => 'no'] + self::none();
        $this->assertSame(['template' => 'no'], dashboard_filters::to_params($filters, ['school']));
    }

    public function test_any_active_reflects_the_new_compliance_filters(): void {
        $this->assertFalse(dashboard_filters::any_active(self::none()));
        foreach (['template' => 'no', 'feedback' => 'none', 'compliance' => 'gaps', 'timetable' => 'unmatched', 'minstudents' => 3] as $key => $value) {
            $this->assertTrue(dashboard_filters::any_active([$key => $value] + self::none()), $key);
        }
    }
}
