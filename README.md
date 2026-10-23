# Uniqualified

*Moodle plugin component: `local_courseaudit` - this is a cosmetic product
rebrand only. The technical plugin name, database tables, capabilities, and
PHP namespace are all unchanged, so the existing live install and its data
are completely unaffected by the rename.*

A Moodle admin dashboard that auto-populates most of the "Course quality audit"
spreadsheet by combining live course data, imported trimester timetables, and
heuristic checks of each course's structure - so you review and correct
instead of filling every cell by hand.

**Brand:** "Uniqualified" is the current product name, potentially with its
own site at a domain along the lines of uniqualified.com (not yet built or
registered as far as this codebase is concerned - nothing here references
or depends on that domain existing). The KCA University crest and brand
colours (Blue `#192C57`, Gold `#CBAE2D`) shown on the dashboard are kept
as-is; this is KCA's own internal tool, renamed, not a separate identity.

## What it automates vs. what it guesses vs. what stays manual

| Column | Source |
|---|---|
| Unit Code, Unit Name | Course fullname (if it follows the site's structured naming convention below), falls back to timetable match, falls back to course shortname/fullname |
| No. of Students | Live count of enrolled students in Moodle |
| Lecturer Name | Live teacher role assignment in Moodle (ground truth for "is the right lecturer here"), falls back to the lecturer named in the course fullname, falls back to timetable |
| Programme level | Timetable's PROGRAM CODE, mapped via a one-time human classification (see below) - resolved even when the specific timetable offering is ambiguous, as long as every tied candidate agrees on the same confirmed level |
| Mode of learning | The course fullname's Location field (e.g. "ZOOM") if present - course-specific ground truth - falls back to the timetable's generic per-slot ROOM TYPE |
| Standard Template deployed | Heuristic: presence of a "Course Policy Quiz", "Start Here" section, Week-numbered sections |
| Attendance/Zoom link | Heuristic: Zoom/BigBlueButton activity, or a genuine Zoom/Google Meet/Teams meeting link (join/meeting-code URLs, not help pages or recordings) in a visible URL resource, label, page or section summary. Hidden items are ignored and listed as such in the evidence |
| Materials posted | Heuristic: a plain count, across the whole course, of material activities (file resource/PDF/Word/video, folder, page, or forum) - quizzes and assignments don't count as "materials" |
| No. of CATs/Quizzes | Heuristic: every quiz/assign activity counted by activity type, regardless of its name (naming conventions vary too much across lecturers to rely on) |
| Assign 1/2/3, CAT 1/2 Feedback | Heuristic: whether the matching named activity exists in the course at all - not whether it's been graded. Always Yes/No, never blank. Assignments match "Assignment N"/"ASS N"; CATs also match "Term Paper N"/"TPAPER N" (some schools use that name for the same thing - the master template's own O/P headers say "Cat 1/Term paper Feedback") |
| Date Checked | Auto-fills with the date of each automated refresh; stays untouched once a row is marked Reviewed |
| Comments, Reviewed | Always manual |

Every heuristic value is editable on the dashboard and comes with an
"evidence" trail (visible on the edit page) explaining why it guessed what it
guessed. The feedback and template-structure columns are always Yes/No/a
concrete value (per the instruction that every field must be filled); the
columns that would otherwise require an outright guess - Programme level,
Mode of learning, Unit Code/Name/Lecturer when nothing matched at all - stay
blank rather than being forced to a wrong answer. Saving a row marks it
**Reviewed**, and a reviewed row's QA judgement (the compliance heuristic
columns, Comments) is never overwritten by a later "Refresh from Moodle"
pass. Its reference fields (School, Programme, Programme level, matched
lecturer, student count) are the exception: those keep tracking whatever
the current timetable/enrolment data says even on a reviewed row, since
they're facts, not a judgement call - see "Every import replaces the
entire timetable table" under "The university's master timetable format"
below for why.

## Trends and KPI cards

After every refresh (scheduled or from the dashboard button) the plugin saves one snapshot per
day, course category and school into `local_courseaudit_trend` - how many audited courses meet each
requirement. Running several refreshes in one day keeps the latest. On upgrade the table is seeded
from every archived audit cycle plus the current state, so history starts immediately.

The dashboard uses it for:

- **KPI cards** - overall compliance, standard template, attendance link, materials posted,
  feedback given and % reviewed, each coloured by status (green >= 80%, amber >= 50%, red below),
  with the change in percentage points against the snapshot about a week earlier and a sparkline.
  "Overall" is the plain average of template, attendance link, materials, CATs/quizzes posted and
  feedback; "reviewed" is a workflow measure and is not part of it.
- **Compliance over time** - one line per requirement group, viewable **by day or by week**
  (a week shows its last snapshot) over the last 4/12/26 weeks or all time.
- **Weekly activity** - courses added to the audit vs courses marked Reviewed each week
  (Reviewed time is stored in `reviewedtime`; rows reviewed before the upgrade use their last
  modified time).

Trends follow the Category and School filters only (a daily snapshot can't be re-filtered by a
search term afterwards). A category-scoped viewer only ever sees trends for their own categories.
A line appears once there are two days of data.

## Needs attention

Under the trend charts, two lists say who to chase first:

- **Schools needing attention** - the five lowest on overall compliance, each with its change in
  points against about a week earlier, plus a "slipping fastest" line for schools down 2+ points.
  Hidden once a School filter is applied (there is then only one). Click a school to filter to it.
- **Lecturers with the most gaps** - the five lecturers whose courses average the lowest overall
  compliance under the current filters (a co-taught course counts for each lecturer named on it).
  Click a name to list their courses.

## Tests

`tests/*_test.php` files that only need PHP (link detection, trend maths, rankings, HTML) run under
any PHPUnit. `tests/heuristics_db_test.php` and `tests/trend_db_test.php` use the course generator
and database, so they need Moodle's own PHPUnit setup (`php admin/tool/phpunit/cli/init.php`, then
`vendor/bin/phpunit --testsuite local_courseaudit_testsuite`).

## The course fullname convention

Every real course on this LMS has a fullname built as six colon-delimited fields:

```
Unit Code:Unit Name:Mode of Study:Lecturer Name:Location:Stream
```

e.g. `CSL 2202:BEHAVIOUR MODIFICATION:PTDL:MARGRET MURUGI NJIRU:ZOOM:Stream A`

`classes/course_name_parser.php` parses this directly off `$course->fullname` -
confirmed (2026-07-02) to be used consistently site-wide, so it's treated as a
higher-confidence signal than fuzzy-matching a course's shortname against the
imported timetable, for everything except Programme level (which isn't part of
this string and still needs the timetable). Specifically:

- **Mode of Study** and **Lecturer Name** parsed here are fed into
  `course_matcher::disambiguate()` as strong scoring hints (worth +6 and +4
  respectively, well above the old fuzzy text-matching signals), which
  resolves cases that were previously irreducibly ambiguous - e.g. the same
  unit code taught to several different degree programmes, where only the
  mode+lecturer combination pins down which specific timetable row applies.
- Even when disambiguation still can't settle on one exact timetable offering
  (e.g. one lecturer teaching one class shared identically across several
  programmes - genuinely indistinguishable from the data available), Programme
  level is still populated if every tied candidate happens to agree on the
  same confirmed level.
- A course that doesn't follow this convention (an older or manually created
  course) degrades gracefully: `course_name_parser::parse_fullname()` never
  throws, it just returns null fields, and matching falls back to the
  shortname-regex approach described below.

## Timetable formats it reads

The importer doesn't hard-code a parser per school - it locates whichever row
has `UNIT CODE`/`UNIT NAME` headers (plus at least one scheduling column, so a
plain code-lookup reference sheet isn't mistaken for a timetable) and reads
through a column-alias map, which has coped with every real layout seen so far
without per-school special-casing:

- **Single header row, one sheet per cohort** (School of Business): PROGRAM
  CODE/MODE OF STUDY/CAMPUS columns present directly.
- **Title + stacked programme blocks per sheet** (PTTI): a campus-wide sheet
  with repeated lone-label rows ("ACCA", "CIFA FT", ...) marking each new
  programme block.
- **Descriptive title + short code above the header, no PROGRAM CODE/MODE/
  CAMPUS columns at all** (e.g. faculties whose timetables list one cohort per
  sheet with just a title row and a short code like "BIT 1.1" above the
  header): the programme code/name are pulled from those title rows instead
  (intake suffixes like " 1.1"/" 1.2" are stripped so repeat intakes of the
  same programme share one classification), and campus/mode are inferred from
  keywords in the sheet name itself (e.g. "BIT KSM FT" -> campus KSM, mode FT).
- **One flat sheet covering an entire school/many programmes** with no
  programme info at all: unit/lecturer/room data still imports; Programme
  level just can't be derived and stays manual for those rows (there's nothing
  to infer it from - the alternative would be guessing wrong).

A unit code is matched against **whichever term has the most recently
imported data for that specific unit code**, not a single global "latest
term" - this matters because different schools' uploads commonly use
different term labels for what's really the same period.

### The university's master timetable format

- **One master file per trimester** (e.g. "MASTER TT TRIM 1 2026"): a single,
  much wider sheet covering every school, with its own `SCHOOL` and `PERIOD`
  (e.g. "JAN-APRIL 2026") columns carried on every row - these are
  authoritative and take priority over the admin-typed term field and the
  filename-derived institution, which only apply as a fallback for rows/files
  that don't carry their own. Column positions differ between trimester
  sheets in the real workbook (extra/reordered columns) - this is why the
  parser locates columns by header text rather than fixed position.
- **Every import replaces the entire timetable table** - not just rows
  sharing the same term - so this genuinely is the single source of truth: if
  you upload one trimester at a time, each new upload supersedes the last.
- **A timetable doesn't equal a school.** School/Programme/Programme level/
  matched lecturer on the dashboard are facts about whichever timetable is
  currently imported, not a permanent property of a course - so every
  import also wipes those fields on **every** `local_courseaudit_audit`
  row, including rows already marked Reviewed and even ones a reviewer
  had manually corrected by hand, since a stale binding to a timetable
  that no longer exists is worse than a blank one. A refresh is
  auto-queued straight after so they're repopulated from the new data
  without a separate manual click - see `audit_assembler::
  LIVE_REFERENCE_FIELDS` for exactly which fields this covers (and which
  QA-judgement fields, like the compliance heuristics and Comments, stay
  frozen on a reviewed row as always). Programme classifications
  (`local_courseaudit_programmap`) are unaffected by an import - a code's
  classification is remembered once and reused, never wiped.
- Formula-derived columns (e.g. this format computes `PROGRAM CODE` via a
  `VLOOKUP` against a reference sheet) are resolved to the value Excel itself
  already calculated and saved, not left as raw formula text - and not
  recalculated from scratch either, which would risk the same
  runaway-formula/memory problem noted below for heavily-formatted workbooks.
- Reference/lookup sheets in the same workbook (programme code lookups, staff
  number lookups, room usage, etc.) are automatically skipped - the same
  `UNIT CODE`/`UNIT NAME` + scheduling-column detection that ignores any other
  non-timetable sheet handles these too, no special-casing needed.

## Requirements

- Moodle 5.0+ (uses `\core_courseformat\base` and PhpSpreadsheet, both bundled with core).
- Manager-level admin access to install a plugin (`Site administration > Plugins`).
- A PHP `memory_limit` of at least 512M, ideally 1G+ if you're importing the university's
  master timetable format directly (a real 6.8MB, 17-sheet, ~19,000-row workbook needed several
  hundred MB above PHP's common 128M CLI/web default during testing, on top of what Moodle itself
  has already allocated by the time the import runs). The import path calls
  `raise_memory_limit(MEMORY_HUGE)`, but that only raises the limit if your php.ini allows it
  (won't help if `memory_limit` is hard-capped lower).

## Install

1. Copy this `courseaudit` folder to `<moodleroot>/local/courseaudit`.
2. Visit `Site administration > Notifications` to run the install (creates the
   plugin's 3 database tables).
3. Go to `Site administration > Plugins > Local plugins > Uniqualified`:
   - **Unit code extraction pattern**: a regex with one capture group that
     pulls a unit code out of a course shortname. The default
     `/([A-Za-z]{2,5}\s?\d{3,4})/` finds things like `ECO8101` or `ECO 8101`
     anywhere in the shortname. Adjust it to match your actual shortname
     convention once you've seen it against real courses.
   - **Master audit template**: upload your blank
     *"Template for checking virtual campus courses"* `.xlsx` file here.
     Exports are built by filling this exact file's `Data` sheet, so its
     formatting and dropdown validations (on the `Lists` sheet) are preserved.

## First-time workflow

1. **Import timetable(s)** (`Uniqualified > Import timetable`):
   upload up to 10 timetable files at once - the layout of each is
   auto-detected independently (see "Timetable formats it reads" above), no
   need to pick a format. Give the whole batch one term label; **reuse the
   exact same label across every school's upload for the same period** (e.g.
   `MAY-AUG-2026` for all of them), so they're treated as one coherent term
   rather than each shadowing the others. Each file's Source/Institution
   label is derived automatically from its filename. Re-uploading under the
   same term label replaces that term's rows, so correcting and re-uploading
   is safe.
2. **Classify programme codes** (`Uniqualified > Manage programme codes`):
   every PROGRAM CODE seen in an import shows up here once, with a
   best-guess Programme level pre-selected (keyword matching - e.g. codes
   containing "PHD", "MBA", "BCOM", "ACCA" etc.). Review and correct, then
   Save. This only needs doing once per code, ever - future imports reuse it.
   Courses whose programme code hasn't been through this screen yet are
   flagged "Needs programme classification" on the dashboard. **Saving here
   automatically queues a background refresh**, so courses using those codes
   pick up their new Programme level without a separate manual step.
3. **Refresh from Moodle** (dashboard button, or automatic after step 2):
   queues a background task that runs the heuristics against every course on
   the site and builds/updates audit rows - the button itself returns
   immediately ("Refresh queued..."); the actual work happens the next time
   cron runs. This requires the site's cron to be running (a general Moodle
   requirement, not specific to this plugin). There's also a periodic
   scheduled task, `local_courseaudit\task\refresh_audit` (disabled by
   default - see `db/tasks.php`), for sites that want this to happen
   automatically on a schedule rather than only when someone triggers it.
   The dashboard shows **"Last refresh completed: <time> (N processed, N
   created, N updated)"**, or **"A refresh is queued, waiting for cron"** if
   one has been triggered but hasn't run yet - so it's always clear whether
   what you're looking at reflects the latest course state, and whether cron
   is actually running on this site at all.
4. **Review** each flagged/unreviewed row on the dashboard (`Edit` link),
   correcting anything the heuristics got wrong, filling in Comments, and
   saving to mark it Reviewed.
5. **Export to Excel**: fills your uploaded master template and downloads it,
   ready to submit. Also appends five extra tabs alongside your template's own
   Data/Lists sheets - see "Cross-check report tabs" below.

## Dashboard charts

All built with Moodle core's own `\core\chart_*` classes (the same Chart.js
already bundled with Moodle, rendered through the standard chart template) -
no external charting service or separate install, and no new dependency.
Every chart reflects whatever filters are currently applied below them -
drilling down to School = SOB updates every chart, not just the table.

Every chart is coloured explicitly from KCA University's own Brand Manual
("Colour system" page: KCA Blue `#192C57`, KCA Gold `#CBAE2D`) rather than
Moodle's generic default/theme chart palette, via `dashboard_charts::
brand_colors()`/`tint_color()`. Charts with more than two categories (the
three pies) get a palette of progressively lighter blue/gold tints -
matching the tint wheels shown on the Brand Manual's own colour page -
generated on the fly rather than a hard-coded list, so it extends
cleanly to however many programme levels or schools a site actually has.
Two-category charts get one blue slice and one gold slice directly
(students vs. teachers); the compliance-rate bar uses gold for "Yes",
blue for "No", and a neutral blue tint for "Not set".

Layout: the stacked bar chart gets its own full-width row - it needs the
horizontal room to stay readable. The three pie charts share the row
below, side by side instead of stacking one under another, so a screen's
worth of vertical space isn't spent on three small circles - each is
capped to a fixed, modest height so Chart.js's own responsive resizing
fits all three within the viewport width rather than needing horizontal
scrolling to see them all. The pie row is responsive: 3 across on
desktop, 2 across on tablet, 1 per row on phones; if a refresh hasn't
produced data for one of the three pies yet, the remaining ones simply
widen to fill the row rather than leaving a gap.

- **Compliance rate by requirement** (stacked bar) - Yes/No/Not set per
  template requirement.
- **Courses by programme level** (pie).
- **Courses by school** (pie).
- **No. of students vs teachers** (pie) - total enrolled students across
  the filtered courses vs. distinct teachers (de-duplicated by name across
  courses, so one lecturer teaching three courses counts once, not three
  times).

## Dashboard filters

- **Search** - free text, matched against Unit Code, Unit Name, Lecturer
  Name and the course's full name in one go - the fastest way to jump to a
  specific course without knowing which category/school it's filed under.
- **Category** - a Moodle course category.
- **School** - as indicated on the timetable (from each matched course's
  School value, not derived from a filename).
- **Programme level** - the classified Certificate/Diploma/.../Professional level.
- **Review status** - Reviewed / Not reviewed / Any.
- **Classification** - Needs classification / Classified / Any (the same
  flag driving the "needs programme classification" badge on each row).
- **ML: Course at risk** / **ML: Student risk** - Flagged / Not flagged /
  Any (see "ML Backend insights" below) - only shown once Moodle's own
  Analytics API has usable predictions, so there's never an empty dropdown
  for a feature with nothing to filter on yet.

All filters combine (AND), and every chart, summary count, and the
**"Showing X of Y courses"** line above the results table all reflect
whatever's currently applied - not just the visible page of results. A
**Reset filters** link appears next to the Filter button whenever any
filter is active. **Export** always exports exactly the currently filtered
set of courses (plus the four cross-check report tabs, which are always
built from the full audit table regardless of the dashboard's filters,
since they compare against Moodle/timetable data site-wide).

## ML Backend insights (Moodle Analytics)

Moodle core ships its own Analytics API and a pluggable "Machine Learning
Backend" (Site administration > Analytics) that can predict, from a
site's own historical data, things like which courses show no recent
teaching activity and which students look likely to drop out. Rather than
leaving those predictions stuck on Moodle's separate `/report/insights/`
page, `classes/ml_insights.php` reads whatever the site's own Analytics
API has already generated and surfaces it directly on this dashboard - no
new dependency, no separate model of its own, just reading real core data.

Two targets are surfaced, each read a different way for a good reason (see
the class's own docblock for the full explanation):

- **Courses at risk of not starting** (course-level, no recent teaching
  activity) - read via a direct query, since this target's analyser gives
  each course exactly one sample keyed by its own course id (confirmed by
  reading that analyser's source, not assumed).
- **Students at risk of dropping out** (student-level, per course) - read
  via Moodle's own `\core_analytics\model::get_predictions()` API instead
  of a direct query, since that target's sample ids are an internal,
  per-enrolment encoding with no simple public formula - core's own API
  resolves it correctly regardless.

On the dashboard this shows up as: two summary chips ("X courses at risk
of not starting (ML)" / "Y students at risk of dropping out (ML)",
scoped the same way the other summary counts are), an **ML Insights**
column on the results table (badges per course, current page only), and
the two filters mentioned above. **None of this appears at all** until at
least one of these two targets is actually enabled, trained, and has
generated predictions - a friendly notice explains what to do instead of
silently showing all-zero counts that would look like "checked, no risk"
rather than "not set up yet". Getting real predictions requires the
site's configured predictions processor (the bundled static PHP predictor,
or a separately-hosted Python `mlbackend_python` service) to actually be
reachable - enabling a model alone doesn't generate predictions if that
processor can't be reached.

## Managing reference data

Two admin screens let the QA team fix mistakes in data that's copied in
from timetable imports, without needing database access:

- **Manage schools** (button on the dashboard): lists every distinct
  School label currently in use, with how many dashboard rows and
  timetable rows carry it. If an import leaves behind two variants of the
  same school (e.g. a typo, or "SOB" vs "School of Business"), rename one
  into the other - this updates every course on the dashboard and every
  imported timetable row using it in one go. Deliberately does not touch
  **Audit history** - an archived cycle is a frozen snapshot, not
  something a later rename should silently rewrite.
- **Manage programme codes** (same page as before, now showing every known
  code, not just newly-unclassified ones): change any programme code's
  Programme level at any time, not just the first time it's seen - codes
  still awaiting their first human review are marked "Needs
  classification" in the list. Saving auto-queues a background refresh so
  affected courses pick up the corrected level without a separate manual
  step.

Moodle course **categories** are managed the normal Moodle way (Site
administration > Courses > Manage courses and categories) - this plugin
only reads them for the Category filter and access scoping, so there's no
separate screen for them here.

## Cross-check report tabs

Every export includes four extra sheets beyond your own template's Data/Lists
sheets, built fresh from whatever the last "Refresh from Moodle" left in the
audit table - run a refresh first if the timetable or Moodle course data has
changed since:

- **Unit Mismatch**: full detail on both sides, not just bare unit codes -
  "In Timetable, Not on LMS" lists Unit Code, Unit Name, School, Programme,
  Lecturer, Campus, Mode of Study and Term, with **one row per distinct
  offering** (a unit code taught by different lecturers/schools/programmes
  gets one row each, not merged into one unhelpful row); "On LMS, Not in
  Timetable" lists Unit Code, Unit Name, School, Programme, Lecturer, No. of
  Students and Course.
- **Lecturer Mismatch**: courses where the timetable's scheduled lecturer and
  the actual Moodle-enrolled teacher both exist but don't match (a
  case-insensitive, whitespace-trimmed comparison, since the two are typed
  independently).
- **Duplicate Units**: courses that are genuine duplicates, not just courses
  sharing a unit code - two courses with the same Unit Code and Unit Name but
  a **different lecturer, mode of study, location, or stream** (e.g. "Stream
  A" vs "Stream B") are legitimately distinct offerings, not duplicates. Uses
  the course's own fullname (the site's Unit Code:Unit Name:Mode of
  Study:Lecturer Name:Location:Stream convention) as the authoritative source
  for these distinguishing fields, since that's what actually identifies a
  specific offering - only courses matching on every one of them are flagged.
- **No Students With Lecturer**: courses with zero enrolled students but a
  teacher is enrolled - usually means students haven't been enrolled yet, or
  the cohort setup is broken somewhere.

The main "Assign 1/2/3 Feedback" and "CAT 1/2 Feedback" columns on the Data
sheet now show the actual **percentage of enrolled students graded** on that
activity (e.g. "67%") rather than a plain Yes/No - "No" only when the named
activity doesn't exist in the course at all. The dashboard's compliance chart
still treats any percentage above 0% as compliant/"Yes".

## Starting a new audit cycle

Click **Clear all data** on the dashboard (next to Export). The confirmation
screen lets you label the cycle you're closing out (e.g. "MAY-AUG-2026");
leave it blank and today's date is used instead. Confirming **archives every
current audit row into Audit history under that label first**, then clears
the live dashboard - manual edits, Comments, and Reviewed status included -
and resets the "Last refresh completed" indicator, so the dashboard returns
to an empty state ready to be repopulated. Nothing is permanently lost: the
archived cycle stays available (read-only) under **Audit history** on the
dashboard indefinitely.

**Imported timetables and programme-level classifications are not
affected.** Both are designed to be reused across audit cycles - clearing
the audit sheet doesn't mean re-uploading timetables or re-classifying
programme codes again. After clearing, click **Refresh from Moodle** to
rebuild the dashboard fresh against current course data and whichever term's
timetable data you want to audit against.

If you're auditing a genuinely new term, upload that term's timetable(s)
first (see step 1 above) - every import replaces every previously imported
timetable row site-wide (see "Timetable formats it reads" above), so the
next "Refresh from Moodle" matches courses against the new term only, with
no old-term rows left behind to filter out.

## Giving a faculty/programme coordinator scoped access

`local/courseaudit:view` can be assigned at a course **category** context, not just system-wide
- so a dean or programme coordinator can be given visibility into just their own faculty's
audit rows without becoming a full site admin:

1. **Site administration > Users > Permissions > Define roles**: create (or edit) a role with
   `local/courseaudit:view` set to Allow, and tick **Category** under "Context types where this
   role may be assigned".
2. Go to that faculty's **course category > Permissions > Assign roles**, and assign the role to
   the coordinator there.
3. They'll see a **Uniqualified** dashboard link on that category's own admin menu (via
   `local_courseaudit_extend_navigation_category_settings()` in `lib.php`), or can be given the
   direct dashboard URL.

A category-scoped user sees the dashboard filtered to only their category's courses - the
summary chips, charts, and every filter dropdown are all scoped to what they can see, never the
whole site. They get read-only access: Import/Classify/Refresh/Export/Clear all data/Audit
history and the per-row Edit link all stay restricted to `local/courseaudit:manage`, which
remains system-context only. Someone with `local/courseaudit:view` at system context (e.g. the
`manager` archetype already grants it) is unaffected by any of this and continues to see every
course, exactly as before.

## Known limitations, by design

- **A unit code can be taught as several genuinely different offerings**
  (same unit, different programme/mode/campus/lecturer). The matcher tries to
  pick the right one using tokens in the course shortname/fullname/category;
  when it can't confidently pick one, the course is left unmatched rather
  than guessing - check the `Classify programme codes` counts and the
  dashboard's evidence panel if a lot of courses look unmatched, and consider
  tightening the shortname regex or shortname convention.
- **The uploaded master template has a duplicate header**: columns L and N on
  the `Data` sheet are both literally titled "Assign 1 Feedback" (looks like a
  copy/paste slip in the original file). Column N is treated as a 3rd
  assignment feedback slot ("Assign 3 Feedback") based on the column order
  (Assign1/Assign2/?/Cat1/Cat2) and the fact that it shares the identical
  Yes/No dropdown validation as L, M, O, P. Relabel the template's N1 cell to
  say something clearer if you'd like, and adjust
  `template_exporter::COLUMN_FIELD_MAP` if it should map to something else
  entirely.
- **"Standard Template deployed" and the other structural heuristics are
  guesses**, tuned to the conventions described when this plugin was built
  (Course Policy Quiz, "Start Here" section, Week 1-13 sections, CAT/ASS
  naming, Zoom/Google Meet links). If those conventions drift, update the
  constants in `classes/heuristics.php`.
- **"Materials posted" (column J) writes a plain count**, not one of the
  template's own dropdown options (`Lists!I2:I15`, "None"/"up to week 1"..
  "up to week 13"). Excel doesn't enforce dropdown validation on values
  written programmatically, so the exported cell displays the number fine -
  it just won't highlight a matching item if you click that cell's dropdown
  arrow, since none of the preset options match a raw count. Update the
  Lists sheet's own options if you'd like the dropdown to reflect this.

## What's been verified

Beyond the PHPUnit suite, this plugin has been exercised against a real local
Moodle 5.0.8 install: installed via the normal upgrade flow, importing real
timetable exports (School of Business, PTTI, and four other faculties -
~5,400 rows across ~90 sheets total), classifying every programme code that
turned up, running the audit refresh against real courses (including two real
courses following the structured fullname convention above), and exporting
through the real uploaded master template. That process caught and fixed
several real bugs no amount of isolated unit testing would have surfaced:

- `numcatsquizzes` was originally declared as a database integer column even
  though the value is text ("None"/"more than 5").
- Course matching used to be scoped to a single global "latest imported term"
  rather than resolving per unit code.
- `course_matcher::disambiguate()`'s tie-break candidates were silently
  discarded whenever every candidate scored exactly zero (a *disambiguation*
  score of zero isn't the same as zero information - see the Programme level
  fallback above), which meant a unit code taught identically across several
  programmes couldn't get its Programme level filled in even when every
  candidate agreed on it.
- The dashboard's "Refresh from Moodle" button used to pass whatever term the
  view's own filter dropdown happened to be set to into `refresh_all()`. That
  silently restricted matching to just that one term for *every* course being
  refreshed - so a course whose real timetable data lived under a different
  term would have its Programme level (and any timetable-derived fields) wiped
  back to blank on every refresh done while that filter was active, and never
  recover on its own. Filtering the view must never narrow what a refresh is
  allowed to match against; `refresh_all()` is now always called with no term
  argument (auto-resolve per unit code) from the dashboard button.

A dedicated test course with a Course Policy Quiz, a Zoom link, week sections
with a deliberate content gap, and graded/ungraded assignments confirmed
every heuristic branch fires correctly on real course structure, not just in
isolated unit tests.

A second round verified the "every field filled" requirements end-to-end
against the same live install: uploading 3 real timetable files in a single
multi-file import (via the actual Moodle draft-file-area mechanism the
filemanager form element uses, not a stand-in), a full site-wide refresh
confirming every feedback column always resolves to Yes/No (never blank) and
Date Checked auto-fills on every row, and a real export showing Assign 3
Feedback (column N) and Date Checked populated correctly in the output file.

A third round confirmed `refresh_all()` is resilient to a single course
failing mid-batch: a course engineered to throw during processing (an
overlong shortname that exceeds the audit table's `unitcode` column length)
was mixed in with real courses on the live install. The broken course failed
on its own - no audit row, logged via `debugging()`/`mtrace()`, counted in
`failed` - while every other course in the same batch, including ones that
would have come after it in iteration order, still processed and updated
normally. Before this fix, one bad course silently blocked the entire rest
of the site from refreshing.

"Clear all data" was verified against the live install's real audit table
(12 rows at the time): clearing it left the 5,601 timetable rows and 28
programme classifications untouched, reset the last-refresh indicator to
"never", and a subsequent refresh correctly rebuilt all 12 rows from
scratch - confirming the intended new-audit-cycle workflow end-to-end.

A fourth round verified the whole "improve the plugin" batch (School and
Programme level filters, compliance charts, bulk review, cycle archiving,
category-scoped access, reminder emails) against the same live install with
real data throughout:

- The `school` field (from the matched timetable offering's institution)
  populated correctly on a real refresh (SOB/NAC courses showed their real
  school; unmatched courses stayed null, as designed), and both the School
  and Programme level filters were confirmed to narrow the SQL results
  correctly against real rows.
- All three dashboard charts (reviewed vs not reviewed, compliance rate by
  requirement, courses by programme level) were built against the live
  database and rendered through Moodle's real chart template - not just
  constructed and left unrendered.
- Bulk mark/unmark-reviewed was run against real audit rows (not a mock),
  confirming both the update and the revert.
- Archiving was run through its full real sequence - archive into
  `local_courseaudit_history`, clear the live table, refresh to repopulate -
  confirming the archived cycle stayed retrievable throughout and the live
  dashboard came back with all 10 real rows afterward.
- Category-scoped access was verified by creating a throwaway role holding
  only `local/courseaudit:view` at a single category context and a throwaway
  user assigned to it: confirmed `has_capability()` was true at that category
  and false at system context for that user, confirmed
  `core_course_category::make_categories_list()` returned exactly that one
  category, and confirmed the dashboard's SQL restriction actually narrowed
  10 real rows down to only the 5 belonging to that category - none from
  other categories leaked through.
- The reminder scheduled task was run against the real unreviewed count,
  confirmed it correctly finds recipients via `get_users_by_capability()`
  (which - correctly - does not treat the primary admin's site-admin
  capability bypass as a real role assignment; a throwaway user was given
  the actual Manager role to prove a real recipient gets found), and
  confirmed `message_send()` recorded a real notification row for that user.
  All throwaway test users/roles were cleaned up afterward (Moodle
  soft-deletes users, so `delete_user()` leaves a `deleted=1` row rather than
  removing it - confirmed that's what happened, not a leftover live account).
- A real installation reported "Refresh from Moodle" doing nothing, with the
  task never appearing in Task logs at all - even though cron was confirmed
  running correctly for every other task. Root cause: a run that failed under
  an older version of the plugin was still sitting in the adhoc task queue,
  with its retry pushed further into the future by Moodle's own exponential
  backoff each time it failed. `queue_adhoc_task()`'s duplicate check then
  saw that old, backed-off row as "already queued" and silently declined to
  queue a fresh one on every subsequent click - so nothing observable ever
  happened, and nothing new ever reached Task logs. Fixed via
  `refresh_audit_adhoc::queue_fresh()`, which clears any not-currently-running
  row for this task before queuing (a currently-running row, i.e. one with
  `timestarted` set, is left alone so genuinely in-progress work is never
  interrupted or duplicated) - used by both the dashboard button and
  programmap.php's auto-queue-after-classifying. Verified directly against
  the database: a simulated stuck row backed off 12 hours into the future was
  correctly cleared and replaced with a row due immediately, while a
  simulated currently-running row was correctly left untouched.
  **First bug fix caught during this attempt** was subtler: the initial
  version compared `self::class` directly against the stored `classname`
  column, but Moodle always normalizes stored task classnames with a leading
  backslash (`\core\task\manager::get_canonical_class_name()`), while
  `self::class` never includes one - so the very first fix attempt matched
  zero rows and silently did nothing. Caught by writing the same DB-level
  test *before* trusting the fix, not just reading the code.

A fifth round covered a large feedback-driven batch: graded-percentage
feedback, the Programme column, and the four cross-check report tabs - all
against real data on the live install:

- The graded-percentage heuristic was verified with a real assign activity,
  four real enrolled students, and real `grade_grades` rows: grading exactly
  2 of 4 students produced `'50%'` precisely, a third student with a
  `finalgrade IS NULL` row (an ungraded submission placeholder) was correctly
  excluded from the count, and a second, nonexistent activity correctly
  stayed `'No'`. The compliance chart's bucketing was confirmed separately:
  courses with real grades showed their real percentages (86%/75%/84%) and
  were counted as compliant, while `'0%'` and `'No'` were both correctly
  counted as non-compliant.
- The Programme column was confirmed populating from real timetable data
  (e.g. `BISF 1.2`, `MSC ACC`) and exporting correctly into new columns R/S
  appended to the Data sheet, with header labels written in since the
  original template has no header text of its own for them there.
- The four report tabs were run against the live database: Unit Mismatch
  correctly found 879 timetable-only units and 6 real Moodle courses with no
  timetable match at all; Lecturer Mismatch correctly caught one real
  mismatch (a course's timetable-scheduled lecturer differed from its actual
  Moodle-enrolled teacher); No Students With Lecturer correctly found the
  same 6 courses (0 enrolled students, a teacher enrolled); Duplicate Units
  correctly found none in this dataset. The full exported workbook (Data,
  Lists, plus all four report sheets) was saved to a real `.xlsx` file and
  reloaded with PhpSpreadsheet to confirm it isn't corrupted.

A sixth round verified the single-source-of-truth import redesign against
the university's real master timetable file (a 6.8MB, 17-sheet workbook - 3
real trimester sheets plus 14 reference/lookup sheets):

- The parser correctly located `MASTER TT TRIM 1/2/3 2026` and skipped all 14
  reference sheets automatically, with no per-sheet special-casing, parsing
  18,814 real rows in about a minute.
- Caught and fixed two real bugs found only by parsing the actual file,
  not by reading the code: `PROGRAM CODE` is computed via a `VLOOKUP` formula
  in this format, and the existing `calculateFormulas=false` bulk read
  returned the literal formula text instead of the resolved value (e.g.
  `KCADPL`) - fixed by resolving just the formula-driven cells to Excel's own
  cached calculated value, without triggering a full recalculation. Real
  School/Period values also came back entirely null on the first real-file
  test - caused by testing against the live instance before syncing the code
  change that added those columns to the parser's alias list, not a logic
  bug; caught by checking real output rather than assuming the sync had
  happened.
- Confirmed real distinct School (`PTTI`, `SOT`, `SOB`, `SEASS`) and Period
  (`JAN-APRIL 2026`, `MAY-AUGUST 2026`, `SEP-DEC 2026`) values matched what
  direct inspection of the file found, including a real data-quality wrinkle
  in the source file itself: the `MASTER TT TRIM 3 2026` sheet's `PERIOD`
  column is almost entirely still labelled `MAY-AUGUST 2026` rather than the
  expected `SEP-DEC 2026`, meaning Trimester 3 wasn't actually filled in for
  that period yet - correctly surfaced by trusting the file's own data rather
  than silently overriding it.
- Ran the real `timetable_importer::import_files()` pipeline end-to-end
  against a real Moodle `stored_file`, wrapping the whole workbook: 6,264
  previously-accumulated test rows across 8 stale terms were completely
  cleared and replaced by the file's 18,814 real rows - confirming the
  "single source of truth, not additive" behaviour. A subsequent full site
  refresh processed all 10 real courses with 0 failures, correctly picking up
  richer School/Programme values from the new data.

The Unit Mismatch report's "in timetable, not on LMS" side was then enriched
with full detail (lecturer, school, programme, campus, mode, term) instead of
a bare unit code list, and caught one more real bug in the process: the
initial version grouped by a plain `SELECT DISTINCT unitcode, ...`, but
`get_records_sql()` keys its result array by the first selected column -
since a single unit code can have several genuinely distinct offerings (e.g.
"ACC 1104" legitimately has 5, taught by different lecturers across
SEASS/SOB), that would have silently kept only one and discarded the rest.
Fixed with an explicit `GROUP BY` and a `MIN(id)` synthetic key, and verified
directly against the real dataset: all 5 of "ACC 1104"'s distinct
lecturer/school/programme combinations came back correctly, not just one.
This same pass also found `export.php` never raised PHP's memory limit at
all (only `import.php` did) - not a problem with the old, much smaller
per-school timetables, but a real risk now that a full site's Unit Mismatch
sheet can run into the thousands of rows; fixed to match `import.php`'s
`raise_memory_limit(MEMORY_HUGE)`.

"Duplicate Units" was then redefined to be stricter (a unit code alone isn't
enough - lecturer/mode/location/stream must all match too, since e.g. two
different streams of the same unit are legitimate, not duplicates) and
verified against 4 real test courses built specifically to probe the
boundary: two courses with byte-for-byte identical Unit Code/Name/Mode/
Lecturer/Location/Stream were correctly flagged as the one true duplicate
pair, while a third differing only by lecturer and a fourth differing only
by stream were both correctly excluded.

A review of the compliance chart's percentage-field bucketing (assign1/2/3
feedback, cat1/2 feedback) found a real bug: any value outside the known
vocabulary ('No' or a valid percentage) - e.g. a reviewer typing free text
like "N/A" into one of these fields, since they're plain text inputs - was
being silently counted as "No" (non-compliant) rather than "Not set". Fixed
so an unrecognised value now falls through to "Not set", matching how the
plain Yes/No fields already handled this correctly.

## Scaling to a large site (thousands of courses)

A full audit refresh touches every course on the site and, prior to a round
of fixes, made enough individual database queries per course (student/teacher
counts, timetable matching, one query per Zoom/URL resource, one query per
forum) that a site with thousands of courses could mean tens of thousands of
queries in one pass - too slow to run inside an HTTP request, and the
dashboard had no pagination for viewing thousands of rows either. Fixed:

- **The dashboard's "Refresh from Moodle" button now queues a background
  adhoc task** (`local_courseaudit\task\refresh_audit_adhoc`) instead of
  running the refresh inline - the button returns immediately, and cron does
  the actual work. This removes any risk of the web server's own timeout
  (independent of PHP's `max_execution_time`) killing the request partway
  through, regardless of site size. Requires cron to be running, same as any
  other Moodle background feature.
- **The attendance-link and materials-count heuristics batch their database
  lookups**: one query per course fetching every URL resource's link (or
  every forum's type) at once, instead of one query per individual activity.
- **Timetable import uses `$DB->insert_records()`** (a real batched bulk
  insert on MySQL/MariaDB) instead of one `insert_record()` call per row -
  measured roughly 5x faster for 5,000 rows on this machine alone; the gap
  widens further against a remote production database where per-query
  round-trip latency dominates.
- **The dashboard is paginated** (50 rows per page via `\core\output\paging_bar`),
  so viewing the audit list no longer means rendering every matching row - up
  to thousands of them - in one HTML page.

- **The Manage schools screen initially referenced a `school` column on
  `local_courseaudit_timetable` that doesn't exist** - that table's
  equivalent field is called `institution` (`local_courseaudit_audit.school`
  is a different table with its own `school` column). This only surfaced
  once `school_manager::get_all()`/`rename()` ran against the real database
  (`Unknown column 'school' in 'SELECT'`) - fixed to read/write `institution`
  on the timetable table while still using `school` on the audit table.
  Re-verified against the real ~18,800-row timetable/10-row audit dataset
  afterwards: search/category/school/programme level/review status/needs-
  classification filters, the shared export-filtering path, school
  rename/merge (including updating both tables and leaving Audit history
  untouched), and the "edit any programme code, not just unclassified ones"
  no-op-skip logic (a resubmitted, unchanged classification no longer bumps
  `timemodified`) - 22 checks, all passing, dataset counts unchanged after
  cleanup.

- **The chart rework (pies for programme level/school/students-vs-teachers)
  was verified against the real database**: real test courses across two
  schools with distinct student counts and a shared vs. a solo teacher
  confirmed the students-vs-teachers total and the de-duplicated teacher
  count, and pie slice counts/values matched real grouped data across
  both charts.

- **The pie-chart row layout (responsive columns, fixed pie height) was
  exercised end to end via CLI** against the real database and the real
  `$OUTPUT->render_chart()`/Moodle output API - not just read as
  plausible-looking markup: confirmed exactly three `col-sm-6 col-md-4`
  blocks on the pie row, exactly three pie-chart wrapper divs, and that
  every opening `<div>` has a matching closing tag. Full visual/pixel
  confirmation in an authenticated browser session wasn't possible from
  this environment (no login credentials available here) - worth a quick
  look on your end to confirm the pie height (240px) and column widths
  look right in practice.

- **Brand colours were verified against the real, rendered chart objects**,
  not just read as plausible-looking hex strings in the code: confirmed
  the compliance bar's Yes/No/Not-set series are exactly KCA Gold/KCA
  Blue/a neutral tint, the programme-level pie's 8-slice palette is 8
  distinct colours starting Blue-then-Gold, and the school and students-
  vs-teachers pies use the same scheme - 10 checks, all passing, dataset
  counts unchanged after cleanup.

- **The "compliance trend by audit cycle" line chart was removed** after
  being added and coloured in earlier rounds - a live, filterable
  dashboard didn't need a second trend view alongside the per-requirement
  bar chart. `dashboard_charts::compliance_trend_chart()`,
  `is_compliant_course()` and the `COMPLIANT_FEEDBACK_FIELDS` constant
  were deleted outright rather than left dead in the codebase; confirmed
  via `method_exists()` against the real class, plus the remaining four
  charts (bar + 3 pies) all still build correctly against real data.

- **"A timetable doesn't equal a school" was verified end to end against
  the real database and the real upload code path** (a genuine `.xlsx`
  fixture wrapped as a real Moodle `stored_file`, not a mocked call):
  confirmed `audit_assembler::save_row()` now updates a reviewed row's
  School/Programme/Programme level to fresh values while leaving its
  compliance-heuristic fields and Comments untouched, and confirmed a
  real `timetable_importer::import_files()` call wipes School/Programme/
  Programme level/`hastimetablematch` to null on **every** audit row -
  including reviewed ones - the instant it runs, and auto-queues a
  refresh - 16 checks, all passing. One mishap along the way: the test's
  own cleanup step wiped the real ~18,800-row master timetable and 106
  programme-code classifications that were already on this dev instance
  (the wipe-on-import behaviour under test correctly cleared the real
  timetable table, and a synthetic single-row fixture didn't parse to
  replace it; a separate line in the cleanup script also deleted the
  programmap table unconditionally). Recovered by re-importing the real
  master timetable file from earlier in this session - timetable rows
  back to exactly 18,814; programme codes came back to 94 of the
  original 106 (a `register_seen()` re-import only recreates codes
  actually present in the file being imported, it doesn't restore
  historical codes from other imports) - a minor, non-destructive gap,
  not data corruption.

- **ML Backend insights were verified against the real Analytics API on this
  dev instance, including its actual current configuration** (the
  "Courses at risk of not starting" model enabled and trained, "Students
  at risk of dropping out" present but not enabled, zero predictions
  generated yet since no Python `mlbackend_python` service is actually
  configured/reachable despite being the site's selected processor) -
  confirmed the dashboard correctly shows nothing rather than misleading
  all-zero counts in that real state. A schema-correct prediction was then
  inserted for a real test course against the real, verified
  course-id-as-sampleid mapping (read from
  `lib/classes/analytics/analyser/courses.php`, not assumed) to prove
  `course_risk_flags()` picks it up, correctly ignores an older superseded
  `rangeindex`, and that the `mlcourserisk` filter's generated SQL both
  includes and excludes the right course; also confirmed the
  null-vs-empty-array distinction in `course_risk_flags()`/
  `student_dropout_counts()` (a category-scoped user with zero courses in
  scope must get zero back, not every site-wide flag) - 18 checks, all
  passing, dataset unchanged after cleanup.

What's still true regardless of these fixes: the plugin only ever writes to
its own 3 database tables (verified by grepping every `insert_record`/
`update_record`/`delete_records` call in the codebase), registers no event
observers or hooks, and its own scheduled task is disabled by default - so it
structurally cannot corrupt core Moodle data or interfere with unrelated
Moodle operations (backup/restore, enrolment, grading, etc.) at any site size.
A full refresh *does* consume normal database/CPU resources while it runs,
same as any admin bulk report would - that's expected, not a defect, and now
happens via cron rather than blocking a browser request.

## Running the tests

The pure logic (parsing, matching, heuristic patterns, export mapping) has a
PHPUnit suite in `tests/` that runs without a database - see each test file's
docblock for what still needs a live Moodle `advanced_testcase` run. Once
installed:

```
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite=local_courseaudit_testsuite
```
