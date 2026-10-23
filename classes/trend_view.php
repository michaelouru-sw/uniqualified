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
 * HTML for the dashboard's KPI cards and their sparklines. Plain string building (no Moodle
 * output API) so it stays unit testable; callers pass in already-translated labels.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trend_view {

    /** @var float At or above this percentage a card is "good" (green). */
    const GOOD_AT = 80.0;

    /** @var float At or above this percentage (and below GOOD_AT) a card is "warn" (amber); below is "poor". */
    const WARN_AT = 50.0;

    /**
     * Status class for a percentage.
     *
     * @param float|null $value
     * @return string 'good', 'warn', 'poor' or 'none'.
     */
    public static function status(?float $value): string {
        if ($value === null) {
            return 'none';
        }
        if ($value >= self::GOOD_AT) {
            return 'good';
        }
        return $value >= self::WARN_AT ? 'warn' : 'poor';
    }

    /**
     * Inline SVG sparkline. Fixed 0-100 scale would flatten small movements, so it scales to the
     * series' own min/max; a flat series is drawn as a level line.
     *
     * @param float[] $values
     * @param int $width
     * @param int $height
     * @return string SVG markup, or '' when there are fewer than 2 points.
     */
    public static function sparkline(array $values, int $width = 96, int $height = 28): string {
        $values = array_values($values);
        $count = count($values);
        if ($count < 2) {
            return '';
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $pad = 3;
        $points = [];
        foreach ($values as $i => $value) {
            $x = $pad + ($width - 2 * $pad) * $i / ($count - 1);
            $y = $range > 0
                ? $pad + ($height - 2 * $pad) * (1 - ($value - $min) / $range)
                : $height / 2;
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        $lastpoint = explode(',', end($points));

        return '<svg class="local_courseaudit-spark" width="' . $width . '" height="' . $height . '" viewBox="0 0 '
            . $width . ' ' . $height . '" role="img" aria-hidden="true" focusable="false">'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
            . 'stroke-linejoin="round" points="' . implode(' ', $points) . '"/>'
            . '<circle cx="' . $lastpoint[0] . '" cy="' . $lastpoint[1] . '" r="2.4" fill="currentColor"/></svg>';
    }

    /**
     * Change indicator: arrow, signed points, and a tone. Reaching zero change reads as neutral.
     *
     * @param float|null $delta Percentage points.
     * @param string $unit e.g. 'pts'.
     * @return array ['text' => string, 'tone' => 'up'|'down'|'flat'|'none']
     */
    public static function delta(?float $delta, string $unit): array {
        if ($delta === null) {
            return ['text' => '', 'tone' => 'none'];
        }
        if (abs($delta) < 0.05) {
            return ['text' => "\u{25AC} 0 {$unit}", 'tone' => 'flat'];
        }
        $arrow = $delta > 0 ? "\u{25B2}" : "\u{25BC}";
        $sign = $delta > 0 ? '+' : "\u{2212}";
        return [
            'text' => $arrow . ' ' . $sign . rtrim(rtrim(number_format(abs($delta), 1), '0'), '.') . ' ' . $unit,
            'tone' => $delta > 0 ? 'up' : 'down',
        ];
    }

    /**
     * The row of KPI cards.
     *
     * @param array $kpis As returned by trend_report::kpis().
     * @param string[] $labels Card title per metric key (translated).
     * @param string $unit Translated points unit, e.g. 'pts'.
     * @param string $comparelabel Translated "vs last week" style caption.
     * @return string
     */
    public static function cards(array $kpis, array $labels, string $unit, string $comparelabel): string {
        $html = '<div class="local_courseaudit-kpis row">';
        foreach ($labels as $key => $label) {
            $metric = $kpis['metrics'][$key] ?? null;
            if ($metric === null || $metric['value'] === null) {
                continue;
            }
            $value = (float) $metric['value'];
            $delta = self::delta($metric['delta'], $unit);
            $display = rtrim(rtrim(number_format($value, 1), '0'), '.');

            $html .= '<div class="col-6 col-md-4 col-xl-2 mb-3">'
                . '<div class="local_courseaudit-kpi local_courseaudit-kpi-' . self::status($value) . '">'
                . '<div class="local_courseaudit-kpi-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div class="local_courseaudit-kpi-value">' . $display . '<small>%</small></div>'
                . '<div class="local_courseaudit-kpi-delta local_courseaudit-delta-' . $delta['tone'] . '">'
                . htmlspecialchars($delta['text'], ENT_QUOTES, 'UTF-8')
                . ($delta['text'] !== '' ? ' <span class="local_courseaudit-kpi-compare">'
                    . htmlspecialchars($comparelabel, ENT_QUOTES, 'UTF-8') . '</span>' : '')
                . '</div>'
                . '<div class="local_courseaudit-kpi-spark">' . self::sparkline($metric['spark']) . '</div>'
                . '</div></div>';
        }
        return $html . '</div>';
    }
}
