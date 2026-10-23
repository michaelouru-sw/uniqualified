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
 * Builds the dashboard's compliance-summary charts using Moodle core's own
 * Chart.js wrapper (\core\chart_*) - no new dependency, same reuse-what's-
 * already-there approach as the rest of the plugin.
 *
 * All aggregation happens in SQL (one query per chart) against whatever
 * filtered set the dashboard is currently showing, so this stays cheap on a
 * large site rather than pulling every row into PHP to count client-side.
 *
 * Every chart is coloured explicitly from KCA University's own Brand Manual
 * ("Colour system" page: KCA Blue #192C57, KCA Gold #CBAE2D) via brand_colors()/
 * tint_color() below, rather than Moodle's generic default/theme chart
 * palette - so the dashboard stays visually on-brand regardless of which
 * theme or site-wide chart colour set is active.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_charts {

    /** @var string KCA Blue, per the university's Brand Manual "Colour system" (page 12). */
    const BRAND_BLUE = '#192C57';

    /** @var string KCA Gold, per the university's Brand Manual "Colour system" (page 12). */
    const BRAND_GOLD = '#CBAE2D';

    /**
     * A neutral, low-emphasis tint of KCA Blue used for "not set"/unknown buckets - present
     * without competing with the two real brand colours for attention.
     *
     * @var string
     */
    const BRAND_NEUTRAL = '#9AA5B8';

    /** @var string[] Plain Yes/No columns charted on the compliance-rate bar chart, in display order. */
    const PLAIN_YESNO_FIELDS = [
        'templatedeployed' => 'col_templatedeployed',
        'attendancelink' => 'col_attendancelink',
    ];

    /**
     * @var string[] Assign/CAT feedback columns charted on the compliance-rate bar chart.
     * These hold either 'No' (the activity doesn't exist) or a graded percentage like '67%' -
     * not plain Yes/No - so they can't be bucketed with a SQL exact-string match the way
     * PLAIN_YESNO_FIELDS are; see compliance_chart().
     */
    const PERCENTAGE_FIELDS = [
        'assign1feedback' => 'col_assign1feedback',
        'assign2feedback' => 'col_assign2feedback',
        'assign3feedback' => 'col_assign3feedback',
        'cat1feedback' => 'col_cat1feedback',
        'cat2feedback' => 'col_cat2feedback',
    ];

    /**
     * Lightens a hex colour by mixing it towards white - used to extend the two-colour KCA Blue/
     * Gold brand palette into enough distinct shades for charts with more than two categories
     * (e.g. programme level, school), the same tinting approach the Brand Manual's own "Colour
     * system" page shows as a wheel of tints for each brand colour.
     *
     * @param string $hex e.g. '#192C57'.
     * @param float $percent 0 (no change) to 1 (white).
     * @return string
     */
    protected static function tint_color(string $hex, float $percent): string {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $r = (int) round($r + ($percent * (255 - $r)));
        $g = (int) round($g + ($percent * (255 - $g)));
        $b = (int) round($b + ($percent * (255 - $b)));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * A palette of $count colours built from KCA Blue and KCA Gold (alternating), each pair
     * progressively lighter than the last - so every chart on this dashboard stays visually
     * on-brand instead of falling back to Moodle's generic default/theme chart palette.
     *
     * @param int $count How many colours are needed (one per bar/slice/category).
     * @return string[]
     */
    protected static function brand_colors(int $count): array {
        $tints = [0, 0.35, 0.6, 0.8];
        $palette = [];
        foreach ($tints as $tint) {
            $palette[] = self::tint_color(self::BRAND_BLUE, $tint);
            $palette[] = self::tint_color(self::BRAND_GOLD, $tint);
        }

        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $palette[$i % count($palette)];
        }
        return $colors;
    }

    /**
     * Yes/No/Not-set counts for each of PLAIN_YESNO_FIELDS and PERCENTAGE_FIELDS, for the
     * currently filtered set. "Yes"/compliant for a percentage field means at least one student
     * has been graded (>0%), matching how the dashboard itself treats these values.
     *
     * @param string $fromwheresql
     * @param array $params
     * @return \core\chart_bar|null Null if there's nothing to chart.
     */
    public static function compliance_chart(string $fromwheresql, array $params): ?\core\chart_bar {
        global $DB;

        $total = (int) $DB->count_records_sql("SELECT COUNT(a.id) $fromwheresql", $params);
        if ($total === 0) {
            return null;
        }

        $labels = [];
        $yesvalues = [];
        $novalues = [];
        $othervalues = [];
        $anydata = false;

        // Plain Yes/No fields: a single cheap SQL aggregate, same as before.
        $selects = [];
        foreach (array_keys(self::PLAIN_YESNO_FIELDS) as $field) {
            $selects[] = "SUM(CASE WHEN a.$field = 'Yes' THEN 1 ELSE 0 END) AS {$field}_yes";
            $selects[] = "SUM(CASE WHEN a.$field = 'No' THEN 1 ELSE 0 END) AS {$field}_no";
        }
        $plainrow = $DB->get_record_sql('SELECT ' . implode(', ', $selects) . " $fromwheresql", $params);
        foreach (self::PLAIN_YESNO_FIELDS as $field => $langkey) {
            $yes = (int) $plainrow->{$field . '_yes'};
            $no = (int) $plainrow->{$field . '_no'};
            $other = max(0, $total - $yes - $no);

            $labels[] = get_string($langkey, 'local_courseaudit');
            $yesvalues[] = $yes;
            $novalues[] = $no;
            $othervalues[] = $other;
            if ($yes + $no + $other > 0) {
                $anydata = true;
            }
        }

        // Percentage fields: the value is text ('No' or e.g. '67%'), not a fixed vocabulary a SQL
        // exact-match can bucket - fetched raw and bucketed in PHP instead. The audit table has
        // at most a few thousand rows even at full site scale, so this stays cheap.
        $percentagefields = array_keys(self::PERCENTAGE_FIELDS);
        $selectcols = implode(', ', array_map(fn($f) => "a.$f", $percentagefields));
        $rawrows = $DB->get_records_sql("SELECT a.id, $selectcols $fromwheresql", $params);
        foreach (self::PERCENTAGE_FIELDS as $field => $langkey) {
            $yes = 0;
            $no = 0;
            foreach ($rawrows as $row) {
                $value = $row->$field ?? null;
                if ($value === null || $value === '') {
                    continue; // Falls into $other below.
                }
                if ($value === 'No') {
                    $no++;
                } else if (preg_match('/^(\d+)%$/', $value, $matches)) {
                    if ((int) $matches[1] > 0) {
                        $yes++;
                    } else {
                        $no++;
                    }
                }
                // Else: some other value outside the known vocabulary - e.g. a reviewer typed
                // free text into this field (it's a plain text input, not a fixed dropdown) -
                // falls through to $other below rather than being miscounted as "No", the same
                // way a percentage field's blank/null value already does.
            }
            $other = max(0, $total - $yes - $no);

            $labels[] = get_string($langkey, 'local_courseaudit');
            $yesvalues[] = $yes;
            $novalues[] = $no;
            $othervalues[] = $other;
            if ($yes + $no + $other > 0) {
                $anydata = true;
            }
        }

        if (!$anydata) {
            return null;
        }

        $chart = new \core\chart_bar();
        $chart->set_title(get_string('chart_compliance', 'local_courseaudit'));
        $chart->set_stacked(true);

        // KCA Gold for "Yes" (the positive/on-brand-accent outcome), KCA Blue for "No" (the one
        // that needs attention), and a neutral blue tint for "Not set" - see the Brand Manual's
        // "Colour system" page for the two source colours.
        $yesseries = new \core\chart_series(get_string('yes'), $yesvalues);
        $yesseries->set_color(self::BRAND_GOLD);
        $noseries = new \core\chart_series(get_string('no'), $novalues);
        $noseries->set_color(self::BRAND_BLUE);
        $otherseries = new \core\chart_series(get_string('notset', 'local_courseaudit'), $othervalues);
        $otherseries->set_color(self::BRAND_NEUTRAL);

        $chart->add_series($yesseries);
        $chart->add_series($noseries);
        $chart->add_series($otherseries);
        $chart->set_labels($labels);

        return $chart;
    }

    /**
     * Course count per Programme level, for the currently filtered set. Unclassified/unmatched
     * rows (programlevel IS NULL) are included as their own bucket rather than dropped silently,
     * since "how many courses still don't have a Programme level" is itself a compliance signal.
     *
     * @param string $fromwheresql
     * @param array $params
     * @return \core\chart_pie|null Null if there's nothing to chart.
     */
    public static function programlevel_chart(string $fromwheresql, array $params): ?\core\chart_pie {
        global $DB;

        $sql = "SELECT a.programlevel, COUNT(a.id) AS coursecount $fromwheresql GROUP BY a.programlevel";
        $rows = $DB->get_records_sql($sql, $params);

        if (empty($rows)) {
            return null;
        }

        $counts = [];
        foreach (program_mapper::LEVELS as $level) {
            $counts[$level] = 0;
        }
        $unclassified = 0;
        foreach ($rows as $row) {
            $level = $row->programlevel;
            if ($level === null || $level === '') {
                $unclassified += (int) $row->coursecount;
            } else if (isset($counts[$level])) {
                $counts[$level] += (int) $row->coursecount;
            } else {
                // Defensive: a level value that somehow isn't in the current vocabulary.
                $counts[$level] = ($counts[$level] ?? 0) + (int) $row->coursecount;
            }
        }
        $counts[get_string('notset', 'local_courseaudit')] = $unclassified;

        $chart = new \core\chart_pie();
        $chart->set_title(get_string('chart_programlevel', 'local_courseaudit'));
        $series = new \core\chart_series(get_string('col_programlevel', 'local_courseaudit'), array_values($counts));
        $series->set_colors(self::brand_colors(count($counts)));
        $chart->add_series($series);
        $chart->set_labels(array_keys($counts));

        return $chart;
    }

    /**
     * Course count per School, for the currently filtered set.
     *
     * @param string $fromwheresql
     * @param array $params
     * @return \core\chart_pie|null Null if there's nothing to chart.
     */
    public static function school_chart(string $fromwheresql, array $params): ?\core\chart_pie {
        global $DB;

        $sql = "SELECT a.school, COUNT(a.id) AS coursecount $fromwheresql GROUP BY a.school";
        $rows = $DB->get_records_sql($sql, $params);

        if (empty($rows)) {
            return null;
        }

        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = ($row->school !== null && $row->school !== '') ? $row->school : get_string('notset', 'local_courseaudit');
            $values[] = (int) $row->coursecount;
        }

        $chart = new \core\chart_pie();
        $chart->set_title(get_string('chart_school', 'local_courseaudit'));
        $series = new \core\chart_series(get_string('chart_school', 'local_courseaudit'), $values);
        $series->set_colors(self::brand_colors(count($values)));
        $chart->add_series($series);
        $chart->set_labels($labels);

        return $chart;
    }

    /**
     * Total enrolled students vs. distinct teachers, for the currently filtered set. Teachers are
     * de-duplicated by name (case/whitespace-insensitive) across courses via
     * local_courseaudit_audit.lmslecturer, which is comma-joined for co-taught courses (see
     * audit_assembler::build_row()) - so one lecturer teaching 3 courses counts once, not 3 times.
     *
     * @param string $fromwheresql
     * @param array $params
     * @return \core\chart_pie|null Null if there's nothing to chart.
     */
    public static function students_teachers_chart(string $fromwheresql, array $params): ?\core\chart_pie {
        global $DB;

        $rows = $DB->get_records_sql("SELECT a.id, a.numstudents, a.lmslecturer $fromwheresql", $params);
        if (empty($rows)) {
            return null;
        }

        $totalstudents = 0;
        $teachers = [];
        foreach ($rows as $row) {
            $totalstudents += (int) ($row->numstudents ?? 0);
            if (!empty($row->lmslecturer)) {
                foreach (explode(',', $row->lmslecturer) as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $teachers[\core_text::strtoupper($name)] = true;
                    }
                }
            }
        }
        $totalteachers = count($teachers);

        if ($totalstudents === 0 && $totalteachers === 0) {
            return null;
        }

        $chart = new \core\chart_pie();
        $chart->set_title(get_string('chart_studentsteachers', 'local_courseaudit'));
        $series = new \core\chart_series(get_string('chart_studentsteachers', 'local_courseaudit'), [$totalstudents, $totalteachers]);
        $series->set_colors([self::BRAND_BLUE, self::BRAND_GOLD]);
        $chart->add_series($series);
        $chart->set_labels([get_string('col_numstudents', 'local_courseaudit'), get_string('chart_teacherslabel', 'local_courseaudit')]);

        return $chart;
    }

    /** @var string[] Line colours for the trend chart, by metric - chosen to stay distinguishable (and colour-blind friendly) rather than brand-only, since six lines need more than two hues. */
    const TREND_COLORS = [
        'overall' => '#192C57',
        'template' => '#CBAE2D',
        'attendance' => '#2A9D8F',
        'materials' => '#C8553D',
        'feedback' => '#7B5EA7',
        'reviewed' => '#9AA5B8',
    ];

    /**
     * Compliance over time: one line per requirement group, plus overall and % reviewed.
     *
     * @param array[] $buckets As returned by trend_report::bucket().
     * @param string[] $serieslabels Translated series name per metric key (also decides which lines are drawn).
     * @param string $title
     * @return \core\chart_line|null Null with fewer than 2 points - a single dot isn't a trend.
     */
    public static function trend_chart(array $buckets, array $serieslabels, string $title): ?\core\chart_line {
        if (count($buckets) < 2) {
            return null;
        }

        $chart = new \core\chart_line();
        $chart->set_title($title);
        $chart->set_smooth(false);
        $chart->set_labels(array_column($buckets, 'label'));

        foreach ($serieslabels as $key => $label) {
            $values = [];
            foreach ($buckets as $bucket) {
                $values[] = $bucket['pct'][$key];
            }
            $series = new \core\chart_series($label, $values);
            $series->set_color(self::TREND_COLORS[$key] ?? self::BRAND_NEUTRAL);
            $series->set_smooth(false);
            $chart->add_series($series);
        }

        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_min(0);
        $yaxis->set_max(100);
        $yaxis->set_label('%');

        return $chart;
    }

    /**
     * Courses added to the audit vs. courses marked Reviewed, per week.
     *
     * @param array $activity As returned by trend_report::weekly_activity().
     * @param string $addedlabel
     * @param string $reviewedlabel
     * @param string $title
     * @return \core\chart_bar|null Null when nothing happened in the period.
     */
    public static function activity_chart(array $activity, string $addedlabel, string $reviewedlabel,
            string $title): ?\core\chart_bar {
        if (array_sum($activity['added']) + array_sum($activity['reviewed']) === 0) {
            return null;
        }

        $chart = new \core\chart_bar();
        $chart->set_title($title);
        $chart->set_labels($activity['labels']);

        $added = new \core\chart_series($addedlabel, $activity['added']);
        $added->set_color(self::BRAND_BLUE);
        $reviewed = new \core\chart_series($reviewedlabel, $activity['reviewed']);
        $reviewed->set_color(self::BRAND_GOLD);
        $chart->add_series($added);
        $chart->add_series($reviewed);

        return $chart;
    }
}
