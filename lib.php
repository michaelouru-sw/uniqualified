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
 * Library functions for local_courseaudit.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serves the master template file stored via the admin_setting_configstoredfile
 * setting, so it can be previewed/downloaded from Site administration.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_courseaudit_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    require_login();
    require_capability('local/courseaudit:manage', $context);

    if ($filearea !== 'mastertemplate') {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_courseaudit', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
    return true;
}

/**
 * Adds a Uniqualified dashboard link to a course category's own admin menu for users who've been
 * given local/courseaudit:view scoped to that category (e.g. a faculty/programme coordinator who
 * isn't a full site admin and so never sees this plugin under Site administration at all - see
 * index.php's access check, which accepts the capability at category context, not just system).
 * Full admins already reach the dashboard via Site administration and don't need this too.
 *
 * @param \navigation_node $categorynode
 * @param \context_coursecat $catcontext
 * @return void
 */
function local_courseaudit_extend_navigation_category_settings(\navigation_node $categorynode, \context_coursecat $catcontext) {
    if (has_capability('local/courseaudit:view', context_system::instance())) {
        return;
    }
    if (!has_capability('local/courseaudit:view', $catcontext)) {
        return;
    }

    $url = new moodle_url('/local/courseaudit/index.php', ['categoryid' => $catcontext->instanceid]);
    $categorynode->add(get_string('dashboard', 'local_courseaudit'), $url, \navigation_node::TYPE_SETTING,
        null, 'local_courseaudit', new \pix_icon('i/report', ''));
}
