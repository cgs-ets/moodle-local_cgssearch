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
 * @package   local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/// CONSTANTS ///////////////////////////////////////////////////////////


/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////
function curl_get_contents($url) {
    //$result = file_get_contents($url);
    //var_export($result); exit;
    // Initiate the curl session
    $ch = curl_init();
      // Set the URL
    curl_setopt($ch, CURLOPT_URL, $url);
     // Removes the headers from the output
    curl_setopt($ch, CURLOPT_HEADER, 0);
     // Return the output instead of displaying it directly
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // Execute the curl session
    $output = curl_exec($ch);
    // Close the curl session
    curl_close($ch);
    // Return the output as a variable
    return $output;
}