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
 * Moodle-hosted tests for the heuristics that read a real course: the attendance link
 * (meeting links in URL resources, labels, pages and section summaries; hidden items ignored),
 * the Course Policy Quiz template signal, material counts and the CAT/quiz count.
 *
 * Run with Moodle's PHPUnit setup (these use the course generator and the database, so they
 * cannot run standalone like the other test files).
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\heuristics
 */
final class heuristics_db_test extends \advanced_testcase {

    /** Creates a course and returns it. */
    protected function make_course(): \stdClass {
        $this->resetAfterTest();
        return $this->getDataGenerator()->create_course();
    }

    /** Runs every heuristic for a fresh read of the course. */
    protected function evaluate(\stdClass $course): array {
        rebuild_course_cache($course->id, true);
        return heuristics::evaluate_course(get_course($course->id));
    }

    protected function add(string $module, \stdClass $course, array $record = []): \stdClass {
        return $this->getDataGenerator()->create_module($module, ['course' => $course->id] + $record);
    }

    public function test_course_with_no_links_has_no_attendance_link(): void {
        $result = $this->evaluate($this->make_course());
        $this->assertSame('No', $result['attendancelink']);
        $this->assertSame([], $result['evidence']['attendancelink']);
    }

    public function test_zoom_join_url_resource_counts(): void {
        $course = $this->make_course();
        $this->add('url', $course, ['name' => 'Live class', 'externalurl' => 'https://kca.zoom.us/j/81234567890?pwd=abc']);

        $result = $this->evaluate($course);
        $this->assertSame('Yes', $result['attendancelink']);
        $this->assertStringContainsString('Zoom', implode(' ', $result['evidence']['attendancelink']));
    }

    public function test_google_meet_url_resource_counts(): void {
        $course = $this->make_course();
        $this->add('url', $course, ['externalurl' => 'https://meet.google.com/abc-defg-hij']);
        $this->assertSame('Yes', $this->evaluate($course)['attendancelink']);
    }

    public function test_zoom_help_page_and_recording_do_not_count(): void {
        $course = $this->make_course();
        $this->add('url', $course, ['externalurl' => 'https://support.zoom.us/hc/en-us/articles/201362193']);
        $this->add('url', $course, ['externalurl' => 'https://kca.zoom.us/rec/share/abc123']);
        $this->assertSame('No', $this->evaluate($course)['attendancelink']);
    }

    public function test_link_in_a_label_counts(): void {
        $course = $this->make_course();
        $this->add('label', $course, ['intro' => '<p>Join: <a href="https://meet.google.com/abc-defg-hij">Meet</a></p>']);
        $this->assertSame('Yes', $this->evaluate($course)['attendancelink']);
    }

    public function test_link_in_a_page_counts(): void {
        $course = $this->make_course();
        $this->add('page', $course, ['content' => '<p>https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0</p>']);
        $this->assertSame('Yes', $this->evaluate($course)['attendancelink']);
    }

    public function test_link_in_a_section_summary_counts(): void {
        global $DB;
        $course = $this->make_course();
        course_create_sections_if_missing($course->id, 1);
        $DB->set_field('course_sections', 'summary', '<p>Class: zoom.us/j/81234567890</p>',
            ['course' => $course->id, 'section' => 1]);
        $this->assertSame('Yes', $this->evaluate($course)['attendancelink']);
    }

    public function test_hidden_link_is_ignored_but_reported_in_the_evidence(): void {
        $course = $this->make_course();
        $this->add('url', $course, ['externalurl' => 'https://zoom.us/j/81234567890', 'visible' => 0]);

        $result = $this->evaluate($course);
        $this->assertSame('No', $result['attendancelink']);
        $this->assertStringContainsString('ignored (hidden)', implode(' ', $result['evidence']['attendancelink']));
    }

    public function test_course_policy_quiz_marks_the_template_deployed(): void {
        $course = $this->make_course();
        $this->assertSame('No', $this->evaluate($course)['templatedeployed']);

        $this->add('quiz', $course, ['name' => 'Course Policy Quiz']);
        $this->assertSame('Yes', $this->evaluate($course)['templatedeployed']);
    }

    public function test_materials_count_files_pages_and_folders_but_not_the_announcements_forum(): void {
        $course = $this->make_course();
        $this->add('page', $course);
        $this->add('folder', $course);
        $this->assertSame('2', $this->evaluate($course)['materialsweeks']);
    }

    public function test_cats_and_quizzes_are_counted_by_type(): void {
        $course = $this->make_course();
        $this->assertSame('None', $this->evaluate($course)['numcatsquizzes']);

        $this->add('quiz', $course, ['name' => 'Anything']);
        $this->add('assign', $course, ['name' => 'Anything else']);
        $this->assertSame('2', $this->evaluate($course)['numcatsquizzes']);
    }
}
