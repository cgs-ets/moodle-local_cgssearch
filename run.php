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
 * Tester
 *
 * @package   local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once(dirname(__FILE__) . '/../../config.php');
require_once('lib.php');

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/cgssearch/debug.php', array());
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_cgssearch');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title);

// Check user is logged in.
require_login();

if (!is_siteadmin()) {
    exit;
}

// Build page output
echo $OUTPUT->header();

echo "<pre>";
$cron = new \local_cgssearch\task\cron_task_sync_documents();
$cron->execute();

// Final outputs
echo $OUTPUT->footer();