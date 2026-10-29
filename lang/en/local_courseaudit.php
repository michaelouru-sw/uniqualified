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
 * English language strings for local_courseaudit.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Uniqualified';
$string['settings'] = 'General settings';
$string['dashboard'] = 'Audit dashboard';
$string['importtimetable'] = 'Import timetable';
$string['programmap'] = 'Manage programme codes';
$string['manageschools'] = 'Manage schools';
$string['shortnameregex'] = 'Unit code extraction pattern';
$string['shortnameregex_desc'] = 'Regular expression used to extract the Unit Code from a course shortname. Must contain one capture group.';
$string['mastertemplate'] = 'Master audit template';
$string['mastertemplate_desc'] = 'Upload the blank "Template for checking virtual campus courses" .xlsx file. Its Data sheet layout and Lists sheet (with dropdown validation) will be reused when exporting audit results.';

// Capabilities.
$string['courseaudit:manage'] = 'Manage course audit (import timetables, classify programmes, edit dashboard)';
$string['courseaudit:view'] = 'View course audit dashboard';

// Import page.
$string['importtimetable_heading'] = 'Import trimester timetables';
$string['importtimetable_help'] = 'Upload up to 10 timetable exports at once - any mix of source layouts, each auto-detected independently. This import becomes the single source of truth: as soon as you upload, it replaces every previously imported timetable row (any term, any school) and clears every course\'s School/Programme/Programme level/matched lecturer on the dashboard - including rows already marked Reviewed - since those are facts about whichever timetable is currently imported, not a permanent property of the course. A refresh is queued automatically afterwards to repopulate them from the new file. The university\'s master timetable file carries its own School and Period (term) per row, so those are used automatically wherever present - the term field below is only a fallback for older files that don\'t.';
$string['term'] = 'Term / trimester label (fallback only)';
$string['term_help'] = 'Only used for rows in files that don\'t already carry their own Period column (e.g. older per-school exports) - the university\'s master timetable file always carries its own Period per row, e.g. "JAN-APRIL 2026", which takes priority over whatever is typed here. Leave blank when every file you\'re uploading already has its own Period.';
$string['timetablefiles'] = 'Timetable file(s)';
$string['timetablefiles_help'] = 'Select up to 10 .xlsx files. Each file\'s Source/Institution label is derived automatically from its filename.';
$string['upload'] = 'Upload';
$string['importsuccess'] = '{$a->rows} row(s) imported from {$a->files} file(s) ({$a->sheets} sheet(s) parsed, {$a->skipped} skipped) for term(s): {$a->terms}. This replaced every previously imported timetable row, and cleared every course\'s School/Programme/Programme level/matched lecturer on the dashboard (including already-reviewed rows) so nothing from the old timetable lingers. A refresh has been queued to repopulate them from the new data - check back in a few minutes.';
$string['importfilesummary'] = '{$a->filename}: {$a->rows} row(s), {$a->sheets} sheet(s) parsed, {$a->skipped} skipped';
$string['importnorows'] = 'No recognisable timetable rows were found in any of these files. Every sheet needs a header row containing both "UNIT CODE" and "UNIT NAME" columns.';
$string['importnofiles'] = 'No files were selected to upload.';
$string['unclassifiedwarning'] = '{$a} programme code(s) from this import still need to be classified before affected courses will show a confirmed Programme level.';
$string['importerror'] = 'Could not read "{$a->filename}" as an Excel workbook: {$a->message}';

// Programme mapping page.
$string['programmap_heading'] = 'Manage programme codes';
$string['programmap_help'] = 'Every distinct Programme Code seen in an imported timetable is listed here with its current Programme level - codes marked "Needs classification" have never been reviewed by a person yet. Change any code\'s level at any time; it is remembered for all future imports and audits.';
$string['programcode'] = 'Programme code';
$string['programname'] = 'Programme name (from timetable)';
$string['programlevel'] = 'Programme level';
$string['save'] = 'Save classifications';
$string['nounclassified'] = 'All known programme codes are classified.';
$string['noprogramcodes'] = 'No programme codes have been seen yet - import a timetable first.';

// Manage schools page.
$string['manageschools_heading'] = 'Manage schools';
$string['manageschools_help'] = 'Every distinct School label currently in use, copied from imported timetables. If an import left behind two variants of the same school (e.g. a typo, or an abbreviation vs full name), rename one into the other below - this updates every course on the dashboard and every imported timetable row using it.';
$string['noschools'] = 'No schools have been seen yet - import a timetable first.';
$string['timetablerowcount'] = 'Timetable rows';
$string['renameschool'] = 'Rename / merge school';
$string['renameschool_help'] = 'Choose an existing school and type the name it should become. If the new name matches another existing school, the two are merged.';
$string['renamefrom'] = 'Rename';
$string['renameto'] = 'To';
$string['schoolrenamed'] = 'Renamed "{$a->from}" to "{$a->to}" - {$a->count} row(s) updated.';
$string['backtodashboard'] = 'Back to dashboard';
$string['classifysaved'] = 'Programme level classifications saved.';

// Dashboard.
$string['dashboard_heading'] = 'Uniqualified Dashboard';
$string['refresh'] = 'Refresh from Moodle';
$string['refreshqueued'] = 'Refresh queued and will run in the background shortly (rows marked as Reviewed will be left untouched). This requires the site\'s cron to be running; check back in a few minutes.';
$string['refreshpending'] = 'A refresh is queued, waiting for cron to pick it up';
$string['lastrefreshcompleted'] = 'Last refresh completed: {$a}.';
$string['lastrefreshnever'] = 'No refresh has completed yet - use "Refresh from Moodle" below, then wait for cron to run it.';
$string['refreshcountssummary'] = '({$a->processed} processed, {$a->created} created, {$a->updated} updated, {$a->skippedreviewed} skipped as already reviewed)';
$string['refreshfailures'] = '{$a} course(s) failed to refresh and were skipped rather than blocking the rest of the batch - check the task log (Site administration > Server > Tasks > Task logs) for details, or re-run "Refresh from Moodle".';
$string['clearall'] = 'Clear all data';
$string['clearallconfirm'] = 'This will archive all {$a} audit row(s) currently on the dashboard into Audit history, then clear the live dashboard so a new cycle can be repopulated. Imported timetables and programme-level classifications are NOT affected - they will be reused when you refresh. The dashboard itself cannot be undone back to its current state, but nothing is permanently lost - the archived cycle stays available under Audit history.';
$string['clearallsuccess'] = '{$a} audit row(s) archived to Audit history and cleared from the dashboard. Click "Refresh from Moodle" to repopulate it for the new audit.';
$string['cyclelabel'] = 'Label this audit cycle';
$string['cyclelabel_help'] = 'Used to identify this cycle under Audit history (e.g. "MAY-AUG-2026"). Leave blank to use today\'s date.';
$string['audithistory'] = 'Audit history';
$string['audithistory_heading'] = 'Archived audit cycles';
$string['nocycles'] = 'No audit cycles have been archived yet - use "Clear all data" on the dashboard once a cycle is finished to archive it here.';
$string['col_cyclelabel'] = 'Cycle';
$string['col_archivedtime'] = 'Archived';
$string['col_coursecount'] = 'Courses';
$string['viewcycle'] = 'View';
$string['backtohistory'] = 'Back to Audit history';
$string['export'] = 'Export to Excel';
$string['filtercategory'] = 'Category';
$string['filterschool'] = 'School';
$string['filterprogramlevel'] = 'Programme level';
$string['filterreviewed'] = 'Review status';
$string['filterneedsclassification'] = 'Classification';
$string['filtersearch'] = 'Search';
$string['filtersearch_placeholder'] = 'Unit code, unit name, lecturer, course name...';
$string['resetfilters'] = 'Reset filters';
$string['filteredcount'] = 'Showing {$a->filtered} of {$a->total} course(s) matching the current filters.';
$string['allcategories'] = 'All categories';
$string['allschools'] = 'All schools';
$string['alllevels'] = 'All levels';
$string['reviewed'] = 'Reviewed';
$string['notreviewed'] = 'Not reviewed';
$string['classified'] = 'Classified';
$string['anystatus'] = 'Any';
$string['col_unitcode'] = 'Unit Code';
$string['col_unitname'] = 'Unit Name';
$string['col_school'] = 'School';
$string['col_programme'] = 'Programme';
$string['col_numstudents'] = 'No. of Students';
$string['col_lecturername'] = 'Lecturer Name';
$string['col_programlevel'] = 'Programme level';
$string['col_modeoflearning'] = 'Mode of learning';
$string['col_templatedeployed'] = 'Standard Template deployed';
$string['col_attendancelink'] = 'Attendance/Zoom link';
$string['col_materialsweeks'] = 'Materials posted';
$string['col_numcatsquizzes'] = 'No. CATs/Quizzes';
$string['col_assign1feedback'] = 'Assign 1 Feedback';
$string['col_assign2feedback'] = 'Assign 2 Feedback';
$string['col_assign3feedback'] = 'Assign 3 Feedback';
$string['col_cat1feedback'] = 'CAT 1 Feedback';
$string['col_cat2feedback'] = 'CAT 2 Feedback';
$string['col_comments'] = 'Comments';
$string['col_datechecked'] = 'Date Checked';
$string['col_reviewed'] = 'Reviewed';
$string['col_evidence'] = 'Evidence';
$string['nocourses'] = 'No courses matched the current filters.';
$string['selectall'] = 'Select all rows on this page';
$string['bulkmarkreviewed'] = 'Mark selected as Reviewed';
$string['bulkmarkunreviewed'] = 'Mark selected as Not reviewed';
$string['bulkreviewsuccess'] = '{$a} row(s) marked as Reviewed.';
$string['bulkunreviewsuccess'] = '{$a} row(s) marked as Not reviewed.';
$string['notset'] = 'Not set';
$string['chart_compliance'] = 'Compliance rate by requirement';
$string['chart_programlevel'] = 'Courses by programme level';
$string['chart_school'] = 'Courses by school';
$string['chart_studentsteachers'] = 'No. of students vs teachers';
$string['chart_teacherslabel'] = 'Teachers';

// ML Backend (Moodle Analytics API) insights.
$string['mlnotavailable'] = 'Machine Learning Backend insights aren\'t available yet - no analytics model is both enabled and trained with predictions yet. Set this up under Site administration > Analytics > Analytics models, and ensure a predictions processor (Site administration > Analytics > Analytics settings) is configured and reachable.';
$string['mlcourseriskcount'] = '{$a} course(s) at risk of not starting (ML)';
$string['mlstudentriskcount'] = '{$a} student(s) at risk of dropping out (ML)';
$string['filtermlcourserisk'] = 'ML: Course at risk';
$string['filtermlstudentrisk'] = 'ML: Student risk';
$string['mlatrisk'] = 'Flagged';
$string['mlnotflagged'] = 'Not flagged';
$string['col_mlinsights'] = 'ML Insights';
$string['mlcourseriskbadge'] = 'At risk';
$string['mlcourseriskbadge_help'] = 'Flagged by Moodle\'s "Courses at risk of not starting" analytics model - no recent teaching activity detected.';
$string['mlstudentriskbadge'] = '{$a} at risk';
$string['mlstudentriskbadge_help'] = 'Number of enrolled students flagged by Moodle\'s "Students at risk of dropping out" analytics model.';

$string['rowsaved'] = 'Audit row saved.';
$string['needsclassification'] = 'Needs programme classification';
$string['exportmissingtemplate'] = 'Upload the master audit template in plugin settings before exporting.';
$string['exportmissingdatasheet'] = 'The uploaded master template has no sheet named "Data". Check it is the correct template file.';
$string['exportnorows'] = 'No audit rows to export yet. Generate the dashboard first.';

// Task.
$string['task_refreshaudit'] = 'Refresh course audit heuristics';
$string['task_reminder'] = 'Send unreviewed-course reminder';
$string['messageprovider:reviewreminder'] = 'Unreviewed course audit reminders';
$string['remindersubject'] = '{$a} course(s) still need audit review';
$string['remindermessage'] = '{$a->count} course(s) on the Uniqualified dashboard still need review.

By school:
{$a->breakdown}

Review them here: {$a->url}';

$string['privacy:metadata'] = 'The Uniqualified plugin stores lecturer names copied from institutional timetables and Moodle course data, and the name of the staff member who reviewed each course, purely for internal quality-assurance reporting.';

// Trends and KPI cards.
$string['trend_heading'] = 'Trends';
$string['trend_chart_title'] = 'Courses meeting each requirement, by date';
$string['trend_grain'] = 'View by';
$string['trend_grain_day'] = 'Day';
$string['trend_grain_week'] = 'Week';
$string['trend_range'] = 'Period';
$string['trend_range_weeks'] = 'Last {$a} weeks';
$string['trend_range_all'] = 'All time';
$string['trend_none'] = 'Trends start building after the next refresh: one point is saved per day, so a line appears once there are two days of data.';
$string['trend_scope_note'] = 'Trends follow the Category and School filters only, and show the state as of each day\'s last refresh.';
$string['trend_asof'] = 'As of {$a}';
$string['kpi_overall'] = 'Overall compliance';
$string['kpi_template'] = 'Standard template';
$string['kpi_attendance'] = 'Attendance link';
$string['kpi_materials'] = 'Materials posted';
$string['kpi_feedback'] = 'Feedback given';
$string['kpi_reviewed'] = 'Reviewed';
$string['kpi_pts'] = 'pts';
$string['kpi_vsprevious'] = 'vs {$a} days ago';

// Needs attention.
$string['attention_schools_title'] = 'Schools needing attention (lowest overall compliance)';
$string['attention_drops_title'] = 'Slipping fastest this week';
$string['attention_lecturers_title'] = 'Lecturers with the most gaps';
$string['attention_lecturers_note'] = 'Average compliance of each lecturer\'s courses under the current filters. Click a name to see their courses.';
$string['attention_col_school'] = 'School';
$string['attention_col_courses'] = 'Courses';
$string['attention_col_overall'] = 'Overall';
$string['attention_col_change'] = 'Change';
$string['attention_col_lecturer'] = 'Lecturer';
$string['attention_col_average'] = 'Average';
$string['attention_col_unreviewed'] = 'Unreviewed';

// Students at risk of dropping out report.
$string['atrisk_heading'] = 'Students at risk of dropping out';
$string['atrisk_button'] = 'At-risk students';
$string['atrisk_help'] = 'Students flagged by Moodle\'s "Students at risk of dropping out" analytics model, one row per student per course. Filters match the dashboard. The list is only as good as the model: it reflects Moodle\'s predictions, not a confirmed outcome.';
$string['atrisk_notready'] = 'The "Students at risk of dropping out" model is not ready yet. In Site administration > Analytics > Analytics models, enable and train it, and make sure its predictions processor is reachable. This report fills in once it has generated predictions.';
$string['atrisk_none'] = 'No students match: nobody is flagged for the courses and filters selected.';
$string['atrisk_count'] = '{$a->students} student(s) flagged, {$a->rows} row(s)';
$string['atrisk_minrisk'] = 'Minimum risk %';
$string['atrisk_search_course'] = 'Course / unit / lecturer';
$string['atrisk_search_student'] = 'Student name, username, ID or email';
$string['atrisk_downloadcsv'] = 'Download CSV';
$string['atrisk_downloadxlsx'] = 'Download Excel';
$string['atrisk_col_student'] = 'Student';
$string['atrisk_col_lecturer'] = 'Lecturer';
$string['atrisk_col_risk'] = 'Risk score';
$string['atrisk_col_flagged'] = 'Flagged on';

// Dashboard redesign.
$string['more'] = 'More';
$string['morefilters'] = 'More filters';
$string['summary_audited'] = 'courses audited';
$string['summary_unmatched'] = 'with no timetable match';
$string['summary_unmatched_help'] = 'These courses could not be matched to a timetable row, so they have no School or Programme. Check the timetable import and the course names.';
$string['col_unit'] = 'Unit';
$string['col_feedback'] = 'Feedback';
$string['trend_xaxis'] = 'Date';
$string['trend_yaxis'] = 'Number of courses';
$string['trend_series_courses'] = 'Courses audited';
$string['trend_series_cats'] = 'CATs/quizzes posted';
$string['kpi_cats'] = 'CATs & quizzes';
$string['kpi_overall_caption'] = 'Average of the five requirements';
$string['kpi_countof'] = '{$a->count} of {$a->total} courses';
$string['kpi_countof_items'] = '{$a->count} of {$a->total} items graded';
$string['barchart_title'] = 'Where each requirement stands today';
$string['trend_yaxis_percent'] = 'Courses meeting it (%)';
$string['kpi_changevs'] = 'change shown is against {$a} days ago';

// Filters.
$string['filters_heading'] = 'Filter courses';
$string['filters_advanced'] = 'Compliance and data checks';
$string['filters_apply'] = 'Apply filters';
$string['filters_quick'] = 'Quick filters';
$string['filters_active'] = 'Applied';
$string['filters_remove'] = 'Remove filter: {$a}';
$string['filters_scope_note'] = 'Category and School also drive the cards and graphs above. Every other filter narrows the course table and the export only.';
$string['fl_compliance'] = 'Overall';
$string['fl_timetable'] = 'Timetable match';
$string['fl_minstudents'] = 'Minimum students';
$string['opt_any'] = 'Any';
$string['opt_gaps'] = 'Has gaps';
$string['opt_compliant'] = 'Fully compliant';
$string['opt_matched'] = 'Matched';
$string['opt_unmatched'] = 'No timetable match';
$string['opt_fb_none'] = 'None graded';
$string['opt_fb_some'] = 'Some graded';
$string['opt_fb_all'] = 'All graded';
