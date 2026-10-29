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
 * The dashboard's one chart: number of compliant courses over time, built with Moodle core's own
 * Chart.js wrapper (\core\chart_*) - no new dependency.
 *
 * Earlier versions also drew a stacked compliance bar and three pies (programme level, school,
 * students vs teachers). They repeated what the KPI cards and the "needs attention" tables already
 * say, so they were removed.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_charts {

    /** @var string A neutral, low-emphasis colour for the "courses audited" reference line. */
    const BRAND_NEUTRAL = '#9AA5B8';

    /** @var string[] Line colours for the count graph, by series key. */
    const COUNT_COLORS = [
        'courses' => '#9AA5B8',
        'template' => '#CBAE2D',
        'attendance' => '#2A9D8F',
        'materials' => '#C8553D',
        'cats' => '#7B5EA7',
    ];

    /**
     * Number of compliant courses over time: x = date, y = number of courses. One line per
     * requirement plus a grey reference line for how many courses were audited that day, so the gap
     * between a line and the reference is the number still to fix.
     *
     * @param array[] $buckets As returned by trend_report::bucket().
     * @param string[] $serieslabels Translated name per series key (decides which lines are drawn).
     * @param string $title
     * @return \core\chart_line|null Null with fewer than 2 dates - a single dot isn't a trend.
     */
    public static function trend_count_chart(array $buckets, array $serieslabels, string $title): ?\core\chart_line {
        if (count($buckets) < 2) {
            return null;
        }

        $values = trend_report::count_series($buckets);
        $chart = new \core\chart_line();
        $chart->set_title($title);
        $chart->set_smooth(false);
        $chart->set_labels(array_column($buckets, 'label'));

        foreach ($serieslabels as $key => $label) {
            if (!isset($values[$key])) {
                continue;
            }
            $series = new \core\chart_series($label, $values[$key]);
            $series->set_color(self::COUNT_COLORS[$key] ?? self::BRAND_NEUTRAL);
            $series->set_smooth(false);
            $chart->add_series($series);
        }

        $xaxis = $chart->get_xaxis(0, true);
        $xaxis->set_label(get_string('trend_xaxis', 'local_courseaudit'));
        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_min(0);
        $yaxis->set_label(get_string('trend_yaxis', 'local_courseaudit'));

        return $chart;
    }

    /** @var string[] Bar colours by status, matching the KPI cards. */
    const STATUS_COLORS = ['good' => '#2E8B57', 'warn' => '#CBAE2D', 'poor' => '#C0392B', 'none' => '#9AA5B8'];

    /**
     * Where every compliance metric stands today: one bar per metric, coloured green/amber/red by
     * the same thresholds as the KPI cards, so a glance shows which requirement is furthest behind.
     *
     * @param array $kpis As returned by trend_report::kpis().
     * @param string[] $labels Translated name per metric key (decides which bars are drawn, in order).
     * @param string $title
     * @return \core\chart_bar|null Null when there is no snapshot yet.
     */
    public static function compliance_bar_chart(array $kpis, array $labels, string $title): ?\core\chart_bar {
        $names = [];
        $values = [];
        $colors = [];
        foreach ($labels as $key => $label) {
            $value = $kpis['metrics'][$key]['value'] ?? null;
            if ($value === null) {
                continue;
            }
            $names[] = $label;
            $values[] = (float) $value;
            $colors[] = self::STATUS_COLORS[trend_view::status((float) $value)];
        }
        if (empty($values)) {
            return null;
        }

        $chart = new \core\chart_bar();
        $chart->set_title($title);
        $chart->set_labels($names);
        $series = new \core\chart_series(get_string('trend_yaxis_percent', 'local_courseaudit'), $values);
        $series->set_color(self::BRAND_NEUTRAL);
        $series->set_colors($colors);
        $chart->add_series($series);
        $chart->set_legend_options(['display' => false]);

        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_min(0);
        $yaxis->set_max(100);
        $yaxis->set_label('%');

        return $chart;
    }
}
