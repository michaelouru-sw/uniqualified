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
 * Unit tests for the KPI card / sparkline HTML.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\trend_view
 */
final class trend_view_test extends \basic_testcase {

    /**
     * @dataProvider status_provider
     */
    public function test_status(?float $value, string $expected): void {
        $this->assertSame($expected, trend_view::status($value));
    }

    public static function status_provider(): array {
        return [
            [100.0, 'good'], [80.0, 'good'], [79.9, 'warn'], [50.0, 'warn'], [49.9, 'poor'], [0.0, 'poor'], [null, 'none'],
        ];
    }

    public function test_delta_formats_direction_and_size(): void {
        $this->assertSame(['text' => "\u{25B2} +2.5 pts", 'tone' => 'up'], trend_view::delta(2.5, 'pts'));
        $this->assertSame(['text' => "\u{25BC} \u{2212}1 pts", 'tone' => 'down'], trend_view::delta(-1.0, 'pts'));
        $this->assertSame('flat', trend_view::delta(0.0, 'pts')['tone']);
        $this->assertSame(['text' => '', 'tone' => 'none'], trend_view::delta(null, 'pts'));
    }

    public function test_sparkline_needs_two_points(): void {
        $this->assertSame('', trend_view::sparkline([]));
        $this->assertSame('', trend_view::sparkline([50.0]));
    }

    public function test_sparkline_draws_a_line_and_end_marker(): void {
        $svg = trend_view::sparkline([10.0, 20.0, 15.0]);
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('<circle', $svg);
    }

    public function test_sparkline_flat_series_does_not_divide_by_zero(): void {
        $this->assertStringContainsString('<polyline', trend_view::sparkline([40.0, 40.0, 40.0]));
    }

    public function test_cards_show_value_delta_status_and_escape_labels(): void {
        $kpis = ['metrics' => [
            'overall' => ['value' => 39.5, 'previous' => 37.0, 'delta' => 2.5, 'spark' => [37.0, 39.5]],
            'template' => ['value' => null, 'previous' => null, 'delta' => null, 'spark' => []],
        ]];
        $html = trend_view::cards($kpis, ['overall' => '<b>Overall</b>', 'template' => 'Template'], 'pts', 'vs 7 days ago');

        $this->assertStringContainsString('39.5', $html);
        $this->assertStringContainsString('local_courseaudit-kpi-poor', $html);
        $this->assertStringContainsString('+2.5 pts', $html);
        $this->assertStringContainsString('vs 7 days ago', $html);
        $this->assertStringContainsString('&lt;b&gt;Overall&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Overall', $html);
        $this->assertStringNotContainsString('Template', $html); // No data - no card.
    }

    public function test_cards_show_progress_bar_caption_and_hide_empty_trend_row(): void {
        $kpis = ['metrics' => [
            'template' => ['value' => 46.5, 'previous' => null, 'delta' => null, 'spark' => [46.5]],
        ]];
        $html = trend_view::cards($kpis, ['template' => 'Template'], 'pts', 'vs 7 days ago',
            ['template' => '620 of 1,332 courses']);

        $this->assertStringContainsString('width:46.5%', $html);
        $this->assertStringContainsString('local_courseaudit-fill-poor', $html);
        $this->assertStringContainsString('620 of 1,332 courses', $html);
        // No change and no sparkline yet - so no empty trend row either.
        $this->assertStringNotContainsString('local_courseaudit-kpi-trend', $html);
    }
}
