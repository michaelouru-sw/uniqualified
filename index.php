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

/**
 * Uniqualified dashboard (course quality audit).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_courseaudit\program_mapper;
use local_courseaudit\dashboard_charts;
use local_courseaudit\dashboard_filters;
use local_courseaudit\ml_insights;
use local_courseaudit\attention_report;
use local_courseaudit\trend_report;
use local_courseaudit\trend_snapshot;
use local_courseaudit\trend_view;

require_login();
$systemcontext = context_system::instance();

// local/courseaudit:view is assignable at category context (see db/access.php) so a faculty/
// programme coordinator can be scoped to just their own category, not only full site admins -
// which is why this can't use admin_externalpage_setup(): that always checks the capability at
// system context only. A user with it at system level still sees every course, unchanged;
// a category-scoped user sees only the categories they were actually granted it in.
$hassystemview = has_capability('local/courseaudit:view', $systemcontext);
$allowedcategories = $hassystemview ? null : \core_course_category::make_categories_list('local/courseaudit:view');
if (!$hassystemview && empty($allowedcategories)) {
    throw new \required_capability_exception($systemcontext, 'local/courseaudit:view', 'nopermissions', '');
}
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/courseaudit/styles.css');

/** Rows per dashboard page. A site with thousands of courses must not render them all in one HTML table. */
const COURSEAUDIT_PERPAGE = 50;

$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$trendgrain = optional_param('trendgrain', trend_report::GRAIN_WEEK, PARAM_ALPHA);
if (!in_array($trendgrain, [trend_report::GRAIN_DAY, trend_report::GRAIN_WEEK], true)) {
    $trendgrain = trend_report::GRAIN_WEEK;
}
$trendweeks = optional_param('trendweeks', 12, PARAM_INT);
if (!in_array($trendweeks, [4, 12, 26, 0], true)) {
    $trendweeks = 12;
}

$filters = dashboard_filters::from_request();
$categoryid = $filters['categoryid'];
$school = $filters['school'];
$programlevel = $filters['programlevel'];
$reviewedfilter = $filters['reviewedfilter'];
$needsclassificationfilter = $filters['needsclassification'];
$search = $filters['search'];
$mlcourserisk = $filters['mlcourserisk'];
$mlstudentrisk = $filters['mlstudentrisk'];

$pageurl = new moodle_url('/local/courseaudit/index.php', [
    'categoryid' => $categoryid,
    'school' => $school,
    'programlevel' => $programlevel,
    'reviewedfilter' => $reviewedfilter,
    'needsclassification' => $needsclassificationfilter,
    'search' => $search,
    'mlcourserisk' => $mlcourserisk,
    'mlstudentrisk' => $mlstudentrisk,
    'trendgrain' => $trendgrain,
    'trendweeks' => $trendweeks,
]);
$pageurlwithpage = new moodle_url($pageurl, ['page' => $page]);

$PAGE->set_url($pageurlwithpage);
$PAGE->set_title(get_string('dashboard', 'local_courseaudit'));
$PAGE->set_heading(get_string('dashboard_heading', 'local_courseaudit'));

$canmanage = has_capability('local/courseaudit:manage', context_system::instance());

if ($action === 'refresh' && $canmanage && confirm_sesskey()) {
    // Queued as a background adhoc task rather than run inline: a full refresh touches every
    // course and can mean tens of thousands of queries on a large site, which risks the web
    // server's own timeout (not just PHP's) killing the request partway through. Cron has no
    // such timeout, so this is safe at any site size - it just needs cron running, same as
    // every other Moodle background feature (scheduled emails, restores, etc.). See
    // refresh_audit_adhoc::queue_fresh() for why old rows are cleared before queuing.
    \local_courseaudit\task\refresh_audit_adhoc::queue_fresh();
    redirect($pageurl, get_string('refreshqueued', 'local_courseaudit'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$dashboardurl = new moodle_url('/local/courseaudit/index.php');

$bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
if (in_array($bulkaction, ['review', 'unreview'], true) && $canmanage && confirm_sesskey()) {
    $rowids = optional_param_array('rowids', [], PARAM_INT);
    $reviewedvalue = $bulkaction === 'review' ? 1 : 0;
    $updated = 0;
    if (!empty($rowids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($rowids, SQL_PARAMS_NAMED);
        // A plain bulk UPDATE, not a row-by-row save_row() call: this is a direct reviewer
        // action on rows already on the dashboard, not a re-run of the heuristics, so there's
        // nothing to merge - just flip the flag on exactly the rows the reviewer selected.
        $updated = $DB->count_records_select('local_courseaudit_audit', "id $insql", $inparams);
        $DB->execute(
            "UPDATE {local_courseaudit_audit}
                SET reviewed = :reviewed, reviewedby = :reviewedby, reviewedtime = :reviewedtime,
                    timemodified = :timemodified
              WHERE id $insql",
            array_merge(['reviewed' => $reviewedvalue, 'reviewedby' => $reviewedvalue ? $USER->id : null,
                'reviewedtime' => $reviewedvalue ? time() : null, 'timemodified' => time()], $inparams)
        );
    }
    $langkey = $bulkaction === 'review' ? 'bulkreviewsuccess' : 'bulkunreviewsuccess';
    redirect($pageurlwithpage, get_string($langkey, 'local_courseaudit', $updated), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'clear' && $canmanage && confirm_sesskey()) {
    // Archives the current audit table into local_courseaudit_history first, then wipes the live
    // table (the populated "sheet") so a new cycle starts clean. Timetable imports and
    // programme-level classifications are deliberately left alone - both are designed to be
    // reused across audit cycles, not re-entered every time. Clearing the last-refresh
    // indicators too, so the dashboard doesn't show a stale "last refresh completed" timestamp
    // against zero rows.
    $cyclelabel = trim(optional_param('cyclelabel', '', PARAM_TEXT));
    $archivedcount = \local_courseaudit\audit_history::archive_current($cyclelabel, $USER->id);
    $clearedcount = $DB->count_records('local_courseaudit_audit');
    $DB->delete_records('local_courseaudit_audit');
    unset_config('lastrefreshcompleted', 'local_courseaudit');
    unset_config('lastrefreshcounts', 'local_courseaudit');
    unset_config('lastrefreshfailures', 'local_courseaudit');
    redirect($dashboardurl, get_string('clearallsuccess', 'local_courseaudit', $archivedcount), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'clearconfirm' && $canmanage) {
    $clearcount = $DB->count_records('local_courseaudit_audit');
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('dashboard_heading', 'local_courseaudit'));
    echo html_writer::tag('p', get_string('clearallconfirm', 'local_courseaudit', $clearcount));
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => (new moodle_url('/local/courseaudit/index.php'))->out_omit_querystring()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'clear']);
    echo html_writer::tag('label', get_string('cyclelabel', 'local_courseaudit'), ['for' => 'id_cyclelabel', 'class' => 'mr-2']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'cyclelabel', 'id' => 'id_cyclelabel',
        'placeholder' => \local_courseaudit\audit_history::default_cycle_label(), 'class' => 'mb-2', 'size' => 40]);
    echo html_writer::tag('p', get_string('cyclelabel_help', 'local_courseaudit'), ['class' => 'text-muted small']);
    echo html_writer::start_div('mt-2');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('clearall', 'local_courseaudit'),
        'class' => 'btn btn-danger mr-2']);
    echo html_writer::link($dashboardurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

// Build the filtered row list. Category-scoped user (see access check above): restrict to
// categories they were actually granted local/courseaudit:view in, regardless of what
// $categoryid was requested - so tampering with the URL's categoryid can only narrow the
// result further, never widen it past what make_categories_list() already allowed.
$allowedcategoryids = $hassystemview ? [] : array_keys($allowedcategories);
list($wheresql, $params) = dashboard_filters::build_where($filters, $hassystemview, $allowedcategoryids);
$fromwheresql = "  FROM {local_courseaudit_audit} a
                  JOIN {course} c ON c.id = a.courseid
             LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE " . $wheresql;

$filteredcount = $DB->count_records_sql("SELECT COUNT(a.id) $fromwheresql", $params);

$sql = "SELECT a.*, c.shortname AS courseshortname, c.fullname AS coursefullname,
               c.category AS courseid_category, cc.name AS categoryname
        $fromwheresql
      ORDER BY cc.name ASC, c.fullname ASC";

$rows = $DB->get_records_sql($sql, $params, $page * COURSEAUDIT_PERPAGE, COURSEAUDIT_PERPAGE);

// Filter option sources.
$categories = $hassystemview
    ? $DB->get_records_menu('course_categories', null, 'name ASC', 'id, name')
    : $allowedcategories;
$schools = $DB->get_fieldset_sql(
    "SELECT DISTINCT school FROM {local_courseaudit_audit} WHERE school IS NOT NULL AND school <> '' ORDER BY school ASC"
);

if ($hassystemview) {
    $totalcount = $DB->count_records('local_courseaudit_audit');
    $needsclassificationcount = $DB->count_records('local_courseaudit_audit', ['needsclassification' => 1]);
    $unreviewedcount = $DB->count_records('local_courseaudit_audit', ['reviewed' => 0]);
} else {
    // Scoped to the categories this user actually has access to, not the whole site - a
    // coordinator shouldn't see "3000 courses audited" when they can only see 50. Deliberately
    // ignores the dashboard's own filters (school/programlevel/search/etc.) - these are sitewide
    // scope totals, not "how many match my current filter" (that's $filteredcount below).
    list($scopewhere, $scopeparams) = dashboard_filters::build_where([], false, $allowedcategoryids);
    $scopewheresql = "FROM {local_courseaudit_audit} a JOIN {course} c ON c.id = a.courseid WHERE $scopewhere";
    $totalcount = (int) $DB->count_records_sql("SELECT COUNT(a.id) $scopewheresql", $scopeparams);
    $needsclassificationcount = (int) $DB->count_records_sql(
        "SELECT COUNT(a.id) $scopewheresql AND a.needsclassification = 1", $scopeparams);
    $unreviewedcount = (int) $DB->count_records_sql("SELECT COUNT(a.id) $scopewheresql AND a.reviewed = 0", $scopeparams);
}
$unclassifiedprogrammes = count(program_mapper::get_unclassified());

// ML Backend (Moodle's own Analytics API) risk summary - scoped the same way totalcount is
// above (category-scoped for non-system viewers), not affected by the dashboard's own filters,
// same reasoning as totalcount: "how many at-risk courses/students exist in what I can see",
// not "how many match my current filter" (the mlcourserisk/mlstudentrisk filters below handle
// that). Gracefully empty (not an error) if no model is enabled/trained/has predictions yet -
// see ml_insights::has_any_usable_predictions().
$mlavailable = ml_insights::has_any_usable_predictions();
$mlscopedcourseids = null;
if ($mlavailable && !$hassystemview) {
    $mlscopedcourseids = array_keys($DB->get_records_sql(
        "SELECT DISTINCT c.id FROM {local_courseaudit_audit} a JOIN {course} c ON c.id = a.courseid WHERE $scopewhere",
        $scopeparams
    ));
}
// Reused for both the summary chips above and the per-row "ML Insights" table column below -
// one query for each target regardless of how many rows are on the current page.
$mlcourseriskflags = $mlavailable ? ml_insights::course_risk_flags($mlscopedcourseids) : [];
$mlstudentriskbycourse = $mlavailable ? ml_insights::student_dropout_counts($mlscopedcourseids) : [];
$mlcourseriskcount = count($mlcourseriskflags);
$mlstudentriskcount = array_sum($mlstudentriskbycourse);

$lastrefreshcompleted = get_config('local_courseaudit', 'lastrefreshcompleted');
$lastrefreshcounts = json_decode((string) get_config('local_courseaudit', 'lastrefreshcounts'), true);
$refreshpending = $DB->record_exists('task_adhoc', ['classname' => '\\local_courseaudit\\task\\refresh_audit_adhoc']);

// Unit/lecturer rows with no timetable match: the clearest data-quality signal on this page
// (School and Programme come from the timetable, so an unmatched course has neither).
$unmatchedcount = $hassystemview
    ? (int) $DB->count_records('local_courseaudit_audit', ['hastimetablematch' => 0])
    : (int) $DB->count_records_sql("SELECT COUNT(a.id) $scopewheresql AND a.hastimetablematch = 0", $scopeparams);

echo $OUTPUT->header();

// Title row: the actions people use every day stay visible, everything occasional goes in "More".
echo html_writer::start_div('d-flex flex-wrap align-items-center justify-content-between mb-3');
echo $OUTPUT->heading(get_string('dashboard_heading', 'local_courseaudit'), 2, 'mb-0');
echo html_writer::start_div('local_courseaudit-actions d-flex flex-wrap align-items-center');
echo html_writer::link(new moodle_url('/local/courseaudit/atriskstudents.php', [
    'categoryid' => $categoryid, 'school' => $school, 'programlevel' => $programlevel, 'search' => $search,
]), get_string('atrisk_button', 'local_courseaudit'), ['class' => 'btn btn-outline-secondary mr-2 me-2']);
if ($canmanage) {
    echo html_writer::link(new moodle_url($pageurl, ['action' => 'refresh', 'sesskey' => sesskey()]),
        get_string('refresh', 'local_courseaudit'), ['class' => 'btn btn-outline-secondary mr-2 me-2']);
    // Carries every applied filter through, so Export always matches what is on screen.
    echo html_writer::link(new moodle_url('/local/courseaudit/export.php', [
        'categoryid' => $categoryid, 'school' => $school, 'programlevel' => $programlevel,
        'reviewedfilter' => $reviewedfilter, 'needsclassification' => $needsclassificationfilter, 'search' => $search,
        'mlcourserisk' => $mlcourserisk, 'mlstudentrisk' => $mlstudentrisk,
    ]), get_string('export', 'local_courseaudit'), ['class' => 'btn btn-primary mr-2 me-2']);

    $menuitems = [
        html_writer::link(new moodle_url('/local/courseaudit/import.php'), get_string('importtimetable', 'local_courseaudit'),
            ['class' => 'dropdown-item']),
        html_writer::link(new moodle_url('/local/courseaudit/programmap.php'), get_string('programmap', 'local_courseaudit') .
            ($unclassifiedprogrammes > 0 ? " ({$unclassifiedprogrammes})" : ''), ['class' => 'dropdown-item']),
        html_writer::link(new moodle_url('/local/courseaudit/manageschools.php'), get_string('manageschools', 'local_courseaudit'),
            ['class' => 'dropdown-item']),
        html_writer::link(new moodle_url('/local/courseaudit/history.php'), get_string('audithistory', 'local_courseaudit'),
            ['class' => 'dropdown-item']),
        html_writer::div('', 'dropdown-divider'),
        html_writer::link(new moodle_url('/local/courseaudit/index.php', ['action' => 'clearconfirm']),
            get_string('clearall', 'local_courseaudit'), ['class' => 'dropdown-item text-danger']),
    ];
    // Both attribute spellings: Bootstrap 4 themes read data-toggle, Bootstrap 5 reads data-bs-toggle.
    echo html_writer::start_div('dropdown d-inline-block');
    echo html_writer::tag('button', get_string('more', 'local_courseaudit'), ['type' => 'button',
        'class' => 'btn btn-outline-secondary dropdown-toggle', 'data-toggle' => 'dropdown', 'data-bs-toggle' => 'dropdown',
        'aria-haspopup' => 'true', 'aria-expanded' => 'false']);
    echo html_writer::div(implode('', $menuitems), 'dropdown-menu dropdown-menu-right dropdown-menu-end');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

// One quiet status line (manage only): how fresh the data is, plus anything that failed.
if ($canmanage) {
    $statusbits = [];
    if ($refreshpending) {
        $statusbits[] = html_writer::tag('span', get_string('refreshpending', 'local_courseaudit'), ['class' => 'badge badge-info bg-info']);
    }
    if (!empty($lastrefreshcompleted)) {
        $statusbits[] = get_string('lastrefreshcompleted', 'local_courseaudit',
            userdate($lastrefreshcompleted, get_string('strftimedatetimeshort', 'core_langconfig')));
    } else {
        $statusbits[] = get_string('lastrefreshnever', 'local_courseaudit');
    }
    echo html_writer::tag('p', implode(' &middot; ', $statusbits), ['class' => 'text-muted small mb-2']);
    if (is_array($lastrefreshcounts) && !empty($lastrefreshcounts['failed'])) {
        echo $OUTPUT->notification(get_string('refreshfailures', 'local_courseaudit', $lastrefreshcounts['failed']),
            \core\output\notification::NOTIFY_WARNING);
    }
}

// What needs doing, in one line: each to-do figure links to the rows it counts.
$todo = [html_writer::tag('strong', number_format($totalcount)) . ' ' . get_string('summary_audited', 'local_courseaudit')];
$todo[] = html_writer::link(new moodle_url($dashboardurl, ['reviewedfilter' => 'no']),
    number_format($unreviewedcount) . ' ' . get_string('notreviewed', 'local_courseaudit'));
if ($needsclassificationcount > 0) {
    $todo[] = html_writer::link(new moodle_url($dashboardurl, ['needsclassification' => 'yes']),
        number_format($needsclassificationcount) . ' ' . get_string('needsclassification', 'local_courseaudit'));
}
if ($unmatchedcount > 0) {
    $todo[] = html_writer::tag('span', number_format($unmatchedcount) . ' ' . get_string('summary_unmatched', 'local_courseaudit'),
        ['title' => get_string('summary_unmatched_help', 'local_courseaudit')]);
}
if ($mlavailable && $mlstudentriskcount > 0) {
    $todo[] = html_writer::link(new moodle_url('/local/courseaudit/atriskstudents.php'),
        get_string('mlstudentriskcount', 'local_courseaudit', number_format($mlstudentriskcount)), ['class' => 'text-danger']);
}
echo html_writer::tag('p', implode(' &nbsp;&middot;&nbsp; ', $todo), ['class' => 'local_courseaudit-summaryline mb-3']);

// KPI cards and the compliance line graph. Both read the daily snapshots (see trend_snapshot), so
// they follow the Category and School filters only - a snapshot can't be re-filtered by a search
// term after the fact.
$trendrows = trend_report::fetch_rows($hassystemview, $allowedcategoryids, (int) $categoryid, (string) $school,
    trend_report::since_day($trendweeks, time()));
$trenddaily = trend_report::daily_totals($trendrows);
$trendbuckets = trend_report::bucket($trenddaily, $trendgrain);
$kpis = trend_report::kpis($trenddaily);

// The five requirements plus their average. "Reviewed" is a workflow measure, not a requirement,
// so it lives in the summary line (X not reviewed) rather than as a compliance card.
$kpilabels = [
    'overall' => get_string('kpi_overall', 'local_courseaudit'),
    'template' => get_string('kpi_template', 'local_courseaudit'),
    'attendance' => get_string('kpi_attendance', 'local_courseaudit'),
    'materials' => get_string('kpi_materials', 'local_courseaudit'),
    'cats' => get_string('kpi_cats', 'local_courseaudit'),
    'feedback' => get_string('kpi_feedback', 'local_courseaudit'),
];
$countlabels = [
    'courses' => get_string('trend_series_courses', 'local_courseaudit'),
    'template' => get_string('kpi_template', 'local_courseaudit'),
    'attendance' => get_string('kpi_attendance', 'local_courseaudit'),
    'materials' => get_string('kpi_materials', 'local_courseaudit'),
    'cats' => get_string('trend_series_cats', 'local_courseaudit'),
];
$trendchart = dashboard_charts::trend_count_chart($trendbuckets, $countlabels, get_string('trend_chart_title', 'local_courseaudit'));

$barchart = $kpis !== null
    ? dashboard_charts::compliance_bar_chart($kpis, $kpilabels, get_string('barchart_title', 'local_courseaudit'))
    : null;

if ($kpis !== null) {
    $compareday = $kpis['baselinegap'] ?? 7;
    $captions = ['overall' => get_string('kpi_overall_caption', 'local_courseaudit')];
    foreach ($kpis['metrics'] as $metrickey => $metric) {
        if (($metric['count'] ?? null) !== null) {
            $captions[$metrickey] = get_string($metrickey === 'feedback' ? 'kpi_countof_items' : 'kpi_countof', 'local_courseaudit',
                (object) ['count' => number_format($metric['count']), 'total' => number_format($metric['total'])]);
        }
    }
    echo html_writer::tag('p',
        get_string('trend_asof', 'local_courseaudit', userdate(
            \DateTimeImmutable::createFromFormat('!Ymd', (string) $kpis['asof'], new \DateTimeZone('UTC'))->getTimestamp(),
            get_string('strftimedatefullshort', 'core_langconfig'), 99, false))
        . ' &middot; ' . get_string('kpi_changevs', 'local_courseaudit', $compareday),
        ['class' => 'text-muted small mb-1']);
    echo trend_view::cards($kpis, $kpilabels, get_string('kpi_pts', 'local_courseaudit'),
        get_string('kpi_vsprevious', 'local_courseaudit', $compareday), $captions);
}

// Two charts side by side: where each requirement stands today (works from the first refresh) and
// how the numbers moved over time (needs two dated snapshots).
echo html_writer::start_div('row local_courseaudit-chartrow');
if ($barchart) {
    echo html_writer::start_div('col-lg-5 mb-3');
    echo html_writer::start_div('local_courseaudit-chartbox h-100');
    echo $OUTPUT->render_chart($barchart, false);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::start_div($barchart ? 'col-lg-7 mb-3' : 'col-12 mb-3');
echo html_writer::start_div('local_courseaudit-chartbox h-100');
echo html_writer::start_div('d-flex flex-wrap align-items-center justify-content-end mb-1 local_courseaudit-trend-controls');
foreach ([trend_report::GRAIN_DAY => 'trend_grain_day', trend_report::GRAIN_WEEK => 'trend_grain_week'] as $grain => $stringkey) {
    echo html_writer::link(new moodle_url($pageurl, ['trendgrain' => $grain]), get_string($stringkey, 'local_courseaudit'),
        ['class' => 'btn btn-sm mr-1 me-1 ' . ($grain === $trendgrain ? 'btn-primary' : 'btn-outline-secondary')]);
}
echo html_writer::tag('span', '|', ['class' => 'mx-2 text-muted']);
foreach ([4, 12, 26, 0] as $weeks) {
    $rangelabel = $weeks === 0 ? get_string('trend_range_all', 'local_courseaudit')
        : get_string('trend_range_weeks', 'local_courseaudit', $weeks);
    echo html_writer::link(new moodle_url($pageurl, ['trendweeks' => $weeks]), $rangelabel,
        ['class' => 'btn btn-sm mr-1 me-1 ' . ($weeks === $trendweeks ? 'btn-primary' : 'btn-outline-secondary')]);
}
echo html_writer::end_div();
if ($trendchart) {
    echo $OUTPUT->render_chart($trendchart, false);
    echo html_writer::tag('p', get_string('trend_scope_note', 'local_courseaudit'), ['class' => 'text-muted small mb-0']);
} else {
    echo $OUTPUT->notification(get_string('trend_none', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// "Needs attention": who to chase first. Schools use the snapshots (so they carry a week-on-week
// change) and are hidden once a School filter is on, since there is then only one; lecturers use
// the live rows under every active filter.
$attentionschools = ['lowest' => [], 'drops' => []];
if ($school === '') {
    $attentionschools = attention_report::rank_schools(trend_report::fetch_rows(
        $hassystemview, $allowedcategoryids, (int) $categoryid, '', trend_report::since_day(5, time())));
}
$attentionlecturers = attention_report::rank_lecturers($DB->get_records_sql(
    'SELECT a.id, a.lecturername, ' . trend_snapshot::AUDIT_COLUMNS . " $fromwheresql", $params));

$formatpts = static function (?float $delta): string {
    $delta = \local_courseaudit\trend_view::delta($delta, get_string('kpi_pts', 'local_courseaudit'));
    return $delta['text'] === '' ? '-' : html_writer::tag('span', s($delta['text']),
        ['class' => 'local_courseaudit-delta-' . $delta['tone']]);
};

if ($attentionschools['lowest'] || $attentionlecturers) {
    echo html_writer::start_div('row local_courseaudit-chartrow local_courseaudit-attention');

    if ($attentionschools['lowest']) {
        $schooltable = new html_table();
        $schooltable->attributes['class'] = 'generaltable table-sm mb-2';
        $schooltable->head = [
            get_string('attention_col_school', 'local_courseaudit'),
            get_string('attention_col_courses', 'local_courseaudit'),
            get_string('attention_col_overall', 'local_courseaudit'),
            get_string('attention_col_change', 'local_courseaudit'),
        ];
        foreach ($attentionschools['lowest'] as $entry) {
            $schooltable->data[] = [
                html_writer::link(new moodle_url($pageurl, ['school' => $entry['school'], 'page' => 0]), s($entry['school'])),
                $entry['courses'],
                html_writer::tag('span', $entry['overall'] . '%',
                    ['class' => 'badge local_courseaudit-pill-' . trend_view::status($entry['overall'])]),
                $formatpts($entry['delta']),
            ];
        }
        echo html_writer::start_div('col-lg-6 mb-3');
        echo html_writer::start_div('local_courseaudit-chartbox h-100');
        echo $OUTPUT->heading(get_string('attention_schools_title', 'local_courseaudit'), 5);
        echo html_writer::table($schooltable);
        if ($attentionschools['drops']) {
            $dropnames = array_map(static function (array $entry) use ($pageurl, $formatpts): string {
                return html_writer::link(new moodle_url($pageurl, ['school' => $entry['school'], 'page' => 0]),
                    s($entry['school'])) . ' ' . $formatpts($entry['delta']);
            }, $attentionschools['drops']);
            echo html_writer::tag('p', html_writer::tag('strong', get_string('attention_drops_title', 'local_courseaudit') . ': ')
                . implode(' &middot; ', $dropnames), ['class' => 'small mb-0']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    if ($attentionlecturers) {
        $lecturertable = new html_table();
        $lecturertable->attributes['class'] = 'generaltable table-sm mb-2';
        $lecturertable->head = [
            get_string('attention_col_lecturer', 'local_courseaudit'),
            get_string('attention_col_courses', 'local_courseaudit'),
            get_string('attention_col_average', 'local_courseaudit'),
            get_string('attention_col_unreviewed', 'local_courseaudit'),
        ];
        foreach ($attentionlecturers as $entry) {
            $lecturertable->data[] = [
                html_writer::link(new moodle_url($pageurl, ['search' => $entry['name'], 'page' => 0]), s($entry['name'])),
                $entry['courses'],
                html_writer::tag('span', $entry['average'] . '%',
                    ['class' => 'badge local_courseaudit-pill-' . trend_view::status($entry['average'])]),
                $entry['unreviewed'],
            ];
        }
        echo html_writer::start_div($attentionschools['lowest'] ? 'col-lg-6 mb-3' : 'col-12 mb-3');
        echo html_writer::start_div('local_courseaudit-chartbox h-100');
        echo $OUTPUT->heading(get_string('attention_lecturers_title', 'local_courseaudit'), 5);
        echo html_writer::table($lecturertable);
        echo html_writer::tag('p', get_string('attention_lecturers_note', 'local_courseaudit'), ['class' => 'small text-muted mb-0']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
}

// Filters: the five that matter inline, the rest tucked under "More filters".
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out_omit_querystring(), 'class' => 'form-inline mb-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'trendgrain', 'value' => $trendgrain]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'trendweeks', 'value' => $trendweeks]);

echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'id' => 'id_search', 'value' => s($search),
    'placeholder' => get_string('filtersearch_placeholder', 'local_courseaudit'), 'class' => 'mr-2 me-2 form-control',
    'size' => 26, 'aria-label' => get_string('filtersearch', 'local_courseaudit')]);
echo html_writer::select([0 => get_string('allcategories', 'local_courseaudit')] + $categories, 'categoryid', $categoryid, null,
    ['class' => 'mr-2 me-2', 'id' => 'id_categoryid', 'aria-label' => get_string('filtercategory', 'local_courseaudit')]);
$schooloptions = ['' => get_string('allschools', 'local_courseaudit')];
foreach ($schools as $s) {
    $schooloptions[$s] = $s;
}
echo html_writer::select($schooloptions, 'school', $school, null,
    ['class' => 'mr-2 me-2', 'id' => 'id_school', 'aria-label' => get_string('filterschool', 'local_courseaudit')]);
$programleveloptions = ['' => get_string('alllevels', 'local_courseaudit')];
foreach (program_mapper::LEVELS as $level) {
    $programleveloptions[$level] = $level;
}
echo html_writer::select($programleveloptions, 'programlevel', $programlevel, null,
    ['class' => 'mr-2 me-2', 'id' => 'id_programlevel', 'aria-label' => get_string('filterprogramlevel', 'local_courseaudit')]);
echo html_writer::select([
    '' => get_string('anystatus', 'local_courseaudit'),
    'yes' => get_string('reviewed', 'local_courseaudit'),
    'no' => get_string('notreviewed', 'local_courseaudit'),
], 'reviewedfilter', $reviewedfilter, null,
    ['class' => 'mr-2 me-2', 'id' => 'id_reviewedfilter', 'aria-label' => get_string('filterreviewed', 'local_courseaudit')]);

// Secondary filters stay open if one is already applied, so an active filter is never hidden.
$morefiltersopen = $needsclassificationfilter !== '' || $mlcourserisk !== '' || $mlstudentrisk !== '';
echo html_writer::start_tag('details', ['class' => 'mr-2 me-2 local_courseaudit-morefilters'] + ($morefiltersopen ? ['open' => 'open'] : []));
echo html_writer::tag('summary', get_string('morefilters', 'local_courseaudit'), ['class' => 'btn btn-link p-0']);
echo html_writer::select([
    '' => get_string('anystatus', 'local_courseaudit'),
    'yes' => get_string('needsclassification', 'local_courseaudit'),
    'no' => get_string('classified', 'local_courseaudit'),
], 'needsclassification', $needsclassificationfilter, null,
    ['class' => 'ml-2 ms-2', 'id' => 'id_needsclassification', 'aria-label' => get_string('filterneedsclassification', 'local_courseaudit')]);
if ($mlavailable) {
    $mloptions = [
        '' => get_string('anystatus', 'local_courseaudit'),
        'yes' => get_string('mlatrisk', 'local_courseaudit'),
        'no' => get_string('mlnotflagged', 'local_courseaudit'),
    ];
    echo html_writer::select($mloptions, 'mlcourserisk', $mlcourserisk, null,
        ['class' => 'ml-2 ms-2', 'id' => 'id_mlcourserisk', 'aria-label' => get_string('filtermlcourserisk', 'local_courseaudit')]);
    echo html_writer::select($mloptions, 'mlstudentrisk', $mlstudentrisk, null,
        ['class' => 'ml-2 ms-2', 'id' => 'id_mlstudentrisk', 'aria-label' => get_string('filtermlstudentrisk', 'local_courseaudit')]);
}
echo html_writer::end_tag('details');

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary mr-2 me-2']);
if (dashboard_filters::any_active($filters)) {
    echo html_writer::link($dashboardurl, get_string('resetfilters', 'local_courseaudit'), ['class' => 'btn btn-link']);
}
echo html_writer::end_tag('form');

echo html_writer::tag('p', get_string('filteredcount', 'local_courseaudit', (object) [
    'filtered' => $filteredcount, 'total' => $totalcount,
]), ['class' => 'text-muted small mb-2']);

// Compliance cell helpers: a coloured pill reads faster down a column than the words "Yes"/"No".
$yesno = static function (?string $value): string {
    if ($value === 'Yes') {
        return html_writer::tag('span', get_string('yes'), ['class' => 'badge local_courseaudit-pill-good']);
    }
    if ($value === 'No') {
        return html_writer::tag('span', get_string('no'), ['class' => 'badge local_courseaudit-pill-poor']);
    }
    return '-';
};

if ($filteredcount === 0) {
    echo $OUTPUT->notification(get_string('nocourses', 'local_courseaudit'), \core\output\notification::NOTIFY_INFO);
} else {
    if ($canmanage) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $dashboardurl->out_omit_querystring()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    }

    // Slim table: what you need to judge a course at a glance. Programme, level, mode of learning,
    // the individual feedback columns, comments and check date are all in Edit and in the export.
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local_courseaudit-table';
    $table->head = [];
    if ($canmanage) {
        $table->head[] = html_writer::empty_tag('input', [
            'type' => 'checkbox', 'id' => 'courseaudit-selectall',
            'title' => get_string('selectall', 'local_courseaudit'),
            'onclick' => "document.querySelectorAll('.courseaudit-rowcheckbox').forEach(function(cb) { cb.checked = this.checked; }, this)",
        ]);
    }
    $table->head = array_merge($table->head, [
        get_string('col_unit', 'local_courseaudit'),
        get_string('col_school', 'local_courseaudit'),
        get_string('col_lecturername', 'local_courseaudit'),
        get_string('col_numstudents', 'local_courseaudit'),
        get_string('col_templatedeployed', 'local_courseaudit'),
        get_string('col_attendancelink', 'local_courseaudit'),
        get_string('col_materialsweeks', 'local_courseaudit'),
        get_string('col_numcatsquizzes', 'local_courseaudit'),
        get_string('col_feedback', 'local_courseaudit'),
        get_string('col_reviewed', 'local_courseaudit'),
    ]);
    if ($mlavailable) {
        $table->head[] = get_string('col_mlinsights', 'local_courseaudit');
    }
    if ($canmanage) {
        $table->head[] = '';
    }
    $table->data = [];

    foreach ($rows as $row) {
        $unitcell = html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]),
            s($row->unitcode ?: '-'), ['class' => 'font-weight-bold fw-bold']);
        if (!empty($row->needsclassification)) {
            $unitcell .= ' ' . html_writer::tag('span', '!', [
                'class' => 'badge badge-danger bg-danger', 'title' => get_string('needsclassification', 'local_courseaudit'),
            ]);
        }
        if (!empty($row->unitname)) {
            $unitcell .= html_writer::tag('div', s(shorten_text($row->unitname, 48)), ['class' => 'text-muted small', 'title' => s($row->unitname)]);
        }

        // Materials: weeks posted. Zero is the failing case, so it is the one that is coloured.
        $materials = $row->materialsweeks;
        $materialscell = ($materials === null || $materials === '') ? '-'
            : html_writer::tag('span', s($materials), ['class' => 'badge ' . ((int) $materials > 0 ? 'local_courseaudit-pill-good' : 'local_courseaudit-pill-poor')]);
        $cats = trim((string) $row->numcatsquizzes);
        $catscell = $cats === '' ? '-'
            : html_writer::tag('span', s($cats), ['class' => 'badge ' . (strcasecmp($cats, 'None') === 0 ? 'local_courseaudit-pill-poor' : 'local_courseaudit-pill-good')]);

        // Feedback: how many of the five feedback items (3 assignments, 2 CATs) have grades.
        $flags = trend_snapshot::row_flags($row);
        $feedbackdone = $flags['assign1yes'] + $flags['assign2yes'] + $flags['assign3yes'] + $flags['cat1yes'] + $flags['cat2yes'];
        $feedbackcell = html_writer::tag('span', $feedbackdone . '/5', [
            'class' => 'badge ' . ($feedbackdone === 5 ? 'local_courseaudit-pill-good' : ($feedbackdone === 0 ? 'local_courseaudit-pill-poor' : 'local_courseaudit-pill-warn')),
            'title' => get_string('col_assign1feedback', 'local_courseaudit') . ': ' . ($row->assign1feedback ?: '-') . '; '
                . get_string('col_assign2feedback', 'local_courseaudit') . ': ' . ($row->assign2feedback ?: '-') . '; '
                . get_string('col_assign3feedback', 'local_courseaudit') . ': ' . ($row->assign3feedback ?: '-') . '; '
                . get_string('col_cat1feedback', 'local_courseaudit') . ': ' . ($row->cat1feedback ?: '-') . '; '
                . get_string('col_cat2feedback', 'local_courseaudit') . ': ' . ($row->cat2feedback ?: '-'),
        ]);

        $reviewedbadge = !empty($row->reviewed)
            ? html_writer::tag('span', get_string('reviewed', 'local_courseaudit'), ['class' => 'badge badge-success bg-success'])
            : html_writer::tag('span', get_string('notreviewed', 'local_courseaudit'), ['class' => 'badge badge-secondary bg-secondary']);

        $datarow = [];
        if ($canmanage) {
            $datarow[] = html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => 'rowids[]', 'value' => $row->id, 'class' => 'courseaudit-rowcheckbox',
            ]);
        }
        $datarow = array_merge($datarow, [
            $unitcell,
            s($row->school ?: '-'),
            s($row->lecturername ?: '-'),
            $row->numstudents ?? '-',
            $yesno($row->templatedeployed),
            $yesno($row->attendancelink),
            $materialscell,
            $catscell,
            $feedbackcell,
            $reviewedbadge,
        ]);
        if ($mlavailable) {
            $mlbadges = [];
            if (isset($mlcourseriskflags[$row->courseid])) {
                $mlbadges[] = html_writer::tag('span', get_string('mlcourseriskbadge', 'local_courseaudit'),
                    ['class' => 'badge badge-danger bg-danger mr-1 me-1', 'title' => get_string('mlcourseriskbadge_help', 'local_courseaudit')]);
            }
            if (!empty($mlstudentriskbycourse[$row->courseid])) {
                $mlbadges[] = html_writer::tag('span',
                    get_string('mlstudentriskbadge', 'local_courseaudit', $mlstudentriskbycourse[$row->courseid]),
                    ['class' => 'badge badge-warning bg-warning', 'title' => get_string('mlstudentriskbadge_help', 'local_courseaudit')]);
            }
            $datarow[] = empty($mlbadges) ? '-' : implode(' ', $mlbadges);
        }
        if ($canmanage) {
            $datarow[] = html_writer::link(new moodle_url('/local/courseaudit/edit.php',
                ['id' => $row->id, 'returnurl' => $pageurlwithpage->out_as_local_url(false)]), get_string('edit'));
        }
        $table->data[] = $datarow;
    }

    echo html_writer::start_div('table-responsive');
    echo html_writer::table($table);
    echo html_writer::end_div();

    if ($filteredcount > COURSEAUDIT_PERPAGE) {
        echo $OUTPUT->render(new \core\output\paging_bar($filteredcount, $page, COURSEAUDIT_PERPAGE, $pageurl));
    }

    if ($canmanage) {
        echo html_writer::start_div('local_courseaudit-bulkactions mt-2');
        // Two submit buttons sharing name="bulkaction": the browser sends whichever one was clicked.
        echo html_writer::tag('button', get_string('bulkmarkreviewed', 'local_courseaudit'),
            ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'review', 'class' => 'btn btn-success mr-2 me-2']);
        echo html_writer::tag('button', get_string('bulkmarkunreviewed', 'local_courseaudit'),
            ['type' => 'submit', 'name' => 'bulkaction', 'value' => 'unreview', 'class' => 'btn btn-outline-secondary']);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
