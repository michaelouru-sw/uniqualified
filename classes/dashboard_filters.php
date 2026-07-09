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
 * Builds the dashboard's course filter set from the request, and the matching SQL WHERE
 * fragment - shared between index.php (dashboard grid + charts) and export.php (so "Export"
 * always exports exactly the rows currently on screen, never the whole site regardless of
 * what's filtered).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_filters {

    /**
     * Reads every filter param from the current request. Safe to call from both index.php
     * (GET) and export.php (also GET, carrying the same param names through from the Export link).
     *
     * @return array
     */
    public static function from_request(): array {
        return [
            'categoryid' => optional_param('categoryid', 0, PARAM_INT),
            'school' => optional_param('school', '', PARAM_TEXT),
            'programlevel' => optional_param('programlevel', '', PARAM_TEXT),
            'reviewedfilter' => optional_param('reviewedfilter', '', PARAM_ALPHA),
            'needsclassification' => optional_param('needsclassification', '', PARAM_ALPHA),
            'search' => trim(optional_param('search', '', PARAM_TEXT)),
            'mlcourserisk' => optional_param('mlcourserisk', '', PARAM_ALPHA),
            'mlstudentrisk' => optional_param('mlstudentrisk', '', PARAM_ALPHA),
        ];
    }

    /**
     * @param array $filters As returned by from_request().
     * @return bool Whether any filter (other than category, which the summary chips already
     *              account for separately) is actually narrowing the result set.
     */
    public static function any_active(array $filters): bool {
        $keys = ['categoryid', 'school', 'programlevel', 'reviewedfilter', 'needsclassification', 'search',
            'mlcourserisk', 'mlstudentrisk'];
        foreach ($keys as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $filters As returned by from_request().
     * @param bool $hassystemview Whether the current user holds local/courseaudit:view at system
     *             context (see index.php's access check) - if false, $allowedcategoryids is
     *             enforced regardless of what $filters['categoryid'] asked for.
     * @param int[] $allowedcategoryids Category ids the current user is scoped to; ignored when
     *              $hassystemview is true.
     * @return array [string $wheresql, array $params] - $wheresql is just the boolean expression,
     *         without the "WHERE " keyword, so callers can compose their own FROM/JOIN around it.
     */
    public static function build_where(array $filters, bool $hassystemview, array $allowedcategoryids = []): array {
        global $DB;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['categoryid'])) {
            $where[] = 'c.category = :categoryid';
            $params['categoryid'] = (int) $filters['categoryid'];
        }
        if (!$hassystemview) {
            list($catinsql, $catinparams) = $DB->get_in_or_equal($allowedcategoryids, SQL_PARAMS_NAMED, 'allowedcat');
            $where[] = "c.category $catinsql";
            $params = array_merge($params, $catinparams);
        }
        if (($filters['school'] ?? '') !== '') {
            $where[] = 'a.school = :school';
            $params['school'] = $filters['school'];
        }
        if (($filters['programlevel'] ?? '') !== '') {
            $where[] = 'a.programlevel = :programlevel';
            $params['programlevel'] = $filters['programlevel'];
        }
        if (($filters['reviewedfilter'] ?? '') === 'yes') {
            $where[] = 'a.reviewed = 1';
        } else if (($filters['reviewedfilter'] ?? '') === 'no') {
            $where[] = 'a.reviewed = 0';
        }
        if (($filters['needsclassification'] ?? '') === 'yes') {
            $where[] = 'a.needsclassification = 1';
        } else if (($filters['needsclassification'] ?? '') === 'no') {
            $where[] = 'a.needsclassification = 0';
        }
        if (($filters['search'] ?? '') !== '') {
            $searchparam = '%' . $DB->sql_like_escape($filters['search']) . '%';
            $likes = [
                $DB->sql_like('a.unitcode', ':search1', false),
                $DB->sql_like('a.unitname', ':search2', false),
                $DB->sql_like('a.lecturername', ':search3', false),
                $DB->sql_like('c.fullname', ':search4', false),
            ];
            $where[] = '(' . implode(' OR ', $likes) . ')';
            $params['search1'] = $searchparam;
            $params['search2'] = $searchparam;
            $params['search3'] = $searchparam;
            $params['search4'] = $searchparam;
        }

        // ML Backend risk filters - the flagged/at-risk course ids come from a completely
        // separate subsystem (Moodle's own Analytics API, see ml_insights.php), not a column on
        // this table, so filtering on them means resolving the id list first and folding it into
        // an IN/NOT IN condition rather than a plain column comparison.
        if (($filters['mlcourserisk'] ?? '') !== '') {
            $flaggedids = array_keys(ml_insights::course_risk_flags());
            $where[] = self::in_or_not_in_condition('c.id', $flaggedids, $filters['mlcourserisk'] === 'yes',
                $params, 'mlcrisk');
        }
        if (($filters['mlstudentrisk'] ?? '') !== '') {
            $flaggedids = array_keys(ml_insights::student_dropout_counts());
            $where[] = self::in_or_not_in_condition('c.id', $flaggedids, $filters['mlstudentrisk'] === 'yes',
                $params, 'mlsrisk');
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Builds a "column IN (ids)" / "column NOT IN (ids)" condition from a plain PHP id list,
     * handling the empty-list edge case a raw get_in_or_equal() can't: an empty list means either
     * "nothing at all matches" ($include=true) or "everything matches, no restriction needed"
     * ($include=false) - both need a literal boolean rather than an empty SQL IN(...) clause.
     *
     * @param string $column
     * @param int[] $ids
     * @param bool $include True for IN (must be one of these ids), false for NOT IN.
     * @param array $params Passed by reference; named params for the ids are added to it.
     * @param string $paramprefix Unique prefix so repeated calls don't collide on param names.
     * @return string
     */
    protected static function in_or_not_in_condition(string $column, array $ids, bool $include,
            array &$params, string $paramprefix): string {
        global $DB;

        if (empty($ids)) {
            return $include ? '1=0' : '1=1';
        }
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $paramprefix, $include);
        $params = array_merge($params, $inparams);
        return "$column $insql";
    }
}
