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
 * Admin settings and navigation entries for local_courseaudit.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_courseaudit', get_string('pluginname', 'local_courseaudit')));

    $settings = new admin_settingpage('local_courseaudit_settings', get_string('settings', 'local_courseaudit'));
    $ADMIN->add('local_courseaudit', $settings);

    $settings->add(new admin_setting_configtext(
        'local_courseaudit/shortnameregex',
        get_string('shortnameregex', 'local_courseaudit'),
        get_string('shortnameregex_desc', 'local_courseaudit'),
        '/([A-Za-z]{2,5}\s?\d{3,4})/',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_courseaudit/mastertemplate',
        get_string('mastertemplate', 'local_courseaudit'),
        get_string('mastertemplate_desc', 'local_courseaudit'),
        'mastertemplate',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.xlsx']]
    ));

    $categoryctx = context_system::instance();

    $ADMIN->add('local_courseaudit', new admin_externalpage(
        'local_courseaudit_dashboard',
        get_string('dashboard', 'local_courseaudit'),
        new moodle_url('/local/courseaudit/index.php'),
        'local/courseaudit:view'
    ));

    $ADMIN->add('local_courseaudit', new admin_externalpage(
        'local_courseaudit_import',
        get_string('importtimetable', 'local_courseaudit'),
        new moodle_url('/local/courseaudit/import.php'),
        'local/courseaudit:manage'
    ));

    $ADMIN->add('local_courseaudit', new admin_externalpage(
        'local_courseaudit_programmap',
        get_string('programmap', 'local_courseaudit'),
        new moodle_url('/local/courseaudit/programmap.php'),
        'local/courseaudit:manage'
    ));

    $ADMIN->add('local_courseaudit', new admin_externalpage(
        'local_courseaudit_manageschools',
        get_string('manageschools', 'local_courseaudit'),
        new moodle_url('/local/courseaudit/manageschools.php'),
        'local/courseaudit:manage'
    ));
}
