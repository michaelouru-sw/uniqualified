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
 * One dropdown per unclassified programme code, pre-selected with the
 * heuristic best-guess where available.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class programmap_form extends \moodleform {

    /**
     * @inheritDoc
     */
    public function definition() {
        $mform = $this->_form;
        $rows = $this->_customdata['rows'] ?? [];

        $options = ['' => get_string('choosedots')];
        foreach (program_mapper::LEVELS as $level) {
            $options[$level] = $level;
        }

        foreach ($rows as $row) {
            $elementname = 'level_' . $row->id;
            $label = $row->programcode . ($row->programname !== '' ? " ({$row->programname})" : '');
            if (empty($row->confirmed)) {
                $label .= ' ' . get_string('needsclassification', 'local_courseaudit');
            }
            $mform->addElement('select', $elementname, $label, $options);
            $mform->setType($elementname, PARAM_ALPHAEXT);
            if (!empty($row->programlevel) && in_array($row->programlevel, program_mapper::LEVELS, true)) {
                $mform->setDefault($elementname, $row->programlevel);
            }
            $mform->addElement('hidden', 'programcode_' . $row->id, $row->programcode);
            $mform->setType('programcode_' . $row->id, PARAM_RAW);
        }

        $mform->addElement('hidden', 'ids', implode(',', array_map(fn($row) => $row->id, $rows)));
        $mform->setType('ids', PARAM_SEQUENCE);

        $this->add_action_buttons(false, get_string('save', 'local_courseaudit'));
    }
}
