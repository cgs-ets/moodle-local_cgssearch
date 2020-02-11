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
 * Defines the global settings of the block
 *
 * @package   local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_cgssearch', get_string('pluginname', 'local_cgssearch'));
    $ADMIN->add('localplugins', $settings);

    // Secret
    $name = 'local_cgssearch/secret';
    $title = get_string('config:secret', 'local_cgssearch');
    $description = get_string('config:secretdesc', 'local_cgssearch');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

    $name = 'local_cgssearch/sites';
    $title = get_string('config:sites', 'local_cgssearch');
    $description = get_string('config:sitesdesc', 'local_cgssearch');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

}

