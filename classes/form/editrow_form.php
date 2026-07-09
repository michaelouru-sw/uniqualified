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

namespace local_courseaudit\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_courseaudit\program_mapper;

/**
 * Full edit form for a single course's audit row. Every value the
 * heuristics produced stays editable - reviewing a row means confirming or
 * correcting it here, then saving, which marks it Reviewed so future
 * "Refresh from Moodle" passes leave it alone.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editrow_form extends \moodleform {

    /** @var string[] Matches the Lists sheet's Mode of learning column. */
    const MODE_OPTIONS = ['Virtual', 'Lab', 'Physical', 'PG', 'PCP', 'Studio', 'Project', 'Zoom'];

    /** @var string[] Matches the Lists sheet's "No. of CATs and Quizzes posted" column. */
    const CATSQUIZZES_OPTIONS = ['None', '1', '2', '3', '4', '5', 'more than 5'];

    /**
     * @inheritDoc
     */
    public function definition() {
        $mform = $this->_form;
        $record = $this->_customdata['record'];
        $course = $this->_customdata['course'];

        $mform->addElement('static', 'coursename', get_string('col_unitname', 'local_courseaudit'), $course->fullname);

        $mform->addElement('text', 'unitcode', get_string('col_unitcode', 'local_courseaudit'));
        $mform->setType('unitcode', PARAM_TEXT);

        $mform->addElement('text', 'unitname', get_string('col_unitname', 'local_courseaudit'));
        $mform->setType('unitname', PARAM_TEXT);

        $mform->addElement('text', 'school', get_string('col_school', 'local_courseaudit'));
        $mform->setType('school', PARAM_TEXT);

        $mform->addElement('text', 'programme', get_string('col_programme', 'local_courseaudit'));
        $mform->setType('programme', PARAM_TEXT);

        $mform->addElement('text', 'numstudents', get_string('col_numstudents', 'local_courseaudit'));
        $mform->setType('numstudents', PARAM_INT);

        $mform->addElement('text', 'lecturername', get_string('col_lecturername', 'local_courseaudit'));
        $mform->setType('lecturername', PARAM_TEXT);

        $mform->addElement('select', 'programlevel', get_string('col_programlevel', 'local_courseaudit'),
            self::options_with_blank(program_mapper::LEVELS));
        $mform->setType('programlevel', PARAM_ALPHAEXT);

        $mform->addElement('select', 'modeoflearning', get_string('col_modeoflearning', 'local_courseaudit'),
            self::options_with_blank(self::MODE_OPTIONS));
        $mform->setType('modeoflearning', PARAM_ALPHAEXT);

        $mform->addElement('select', 'templatedeployed', get_string('col_templatedeployed', 'local_courseaudit'),
            self::options_with_blank(['Yes', 'No']));
        $mform->setType('templatedeployed', PARAM_ALPHA);

        $mform->addElement('select', 'attendancelink', get_string('col_attendancelink', 'local_courseaudit'),
            self::options_with_blank(['Yes', 'No', 'Not Applicable']));
        $mform->setType('attendancelink', PARAM_ALPHAEXT);

        $mform->addElement('text', 'materialsweeks', get_string('col_materialsweeks', 'local_courseaudit'), ['size' => 5]);
        $mform->setType('materialsweeks', PARAM_INT);

        $mform->addElement('select', 'numcatsquizzes', get_string('col_numcatsquizzes', 'local_courseaudit'),
            self::options_with_blank(self::CATSQUIZZES_OPTIONS));
        $mform->setType('numcatsquizzes', PARAM_TEXT);

        // Free text, not a fixed Yes/No dropdown: the heuristic now reports either 'No' (the
        // activity doesn't exist) or the percentage of enrolled students actually graded on it
        // (e.g. '67%'), so there's no small fixed vocabulary to offer as a select.
        foreach (['assign1feedback', 'assign2feedback', 'assign3feedback', 'cat1feedback', 'cat2feedback'] as $field) {
            $mform->addElement('text', $field, get_string('col_' . $field, 'local_courseaudit'), ['size' => 8]);
            $mform->setType($field, PARAM_TEXT);
        }

        $mform->addElement('date_selector', 'datechecked', get_string('col_datechecked', 'local_courseaudit'), ['optional' => true]);

        $mform->addElement('textarea', 'comments', get_string('col_comments', 'local_courseaudit'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('comments', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'reviewed', get_string('col_reviewed', 'local_courseaudit'));
        $mform->setType('reviewed', PARAM_INT);

        $mform->addElement('hidden', 'id', $record->id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'returnurl', $this->_customdata['returnurl'] ?? '');
        $mform->setType('returnurl', PARAM_LOCALURL);

        $this->add_action_buttons();

        $this->set_data($record);
    }

    /**
     * Prefixes an options list with a blank choice, so unset heuristic
     * fields don't force a value onto the reviewer.
     *
     * @param string[] $options
     * @return array
     */
    protected static function options_with_blank(array $options): array {
        $result = ['' => get_string('choosedots')];
        foreach ($options as $option) {
            $result[$option] = $option;
        }
        return $result;
    }
}
