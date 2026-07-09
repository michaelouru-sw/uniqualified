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

/**
 * Upload form for up to 10 trimester timetable exports in one go (any mix of
 * source layouts - each is auto-detected independently). Every import
 * replaces whatever timetable data was previously stored (any term, any
 * school) - see timetable_importer::import_files(). Institution/term are
 * read from the file's own SCHOOL/PERIOD columns when present (the
 * university's master timetable format always carries them); the fields
 * below are only a fallback for older per-school files that don't.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {

    /** @var int Maximum number of timetable files accepted in one upload. */
    const MAX_FILES = 10;

    /**
     * @inheritDoc
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'term', get_string('term', 'local_courseaudit'));
        $mform->setType('term', PARAM_TEXT);
        $mform->addHelpButton('term', 'term', 'local_courseaudit');

        $mform->addElement('filemanager', 'timetablefiles', get_string('importtimetable', 'local_courseaudit'), null, [
            'maxbytes' => 0,
            'maxfiles' => self::MAX_FILES,
            'subdirs' => 0,
            'accepted_types' => ['.xlsx'],
        ]);
        $mform->addHelpButton('timetablefiles', 'timetablefiles', 'local_courseaudit');

        $this->add_action_buttons(true, get_string('upload', 'local_courseaudit'));
    }
}
