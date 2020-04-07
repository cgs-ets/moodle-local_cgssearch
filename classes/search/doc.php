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
 * Add external site docs to search
 *
 * @package   local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cgssearch\search;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/cgssearch/lib.php');

class doc extends \core_search\base {

    /**
     * Returns recordset containing required data for indexing announcements.
     * See search base class for implementation info: moodle/search/classes/base.php
     *
     * @param int $modifiedfrom timestamp
     * @param \context|null $context Optional context to restrict scope of returned results
     * @return moodle_recordset|null Recordset (or null if no results)
     */
    public function get_document_recordset($modifiedfrom = 0, \context $context = null) {
        global $DB;

        $sql = "SELECT d.*
                FROM {cgssearch_docs} d
                WHERE d.timemodified >= ?
                ORDER BY d.timemodified ASC";
        $params = array($modifiedfrom);

        return $DB->get_recordset_sql($sql, $params);
    }

    /**
     * Returns the document associated with this doc id.
     *
     * @param stdClass $record
     * @param array    $options
     * @return \core_search\document
     */
    public function get_document($record, $options = array()) {
        $context = \context_system::instance();

        // Prepare associative array with data from DB.
        $doc = \core_search\document_factory::instance($record->id, $this->componentname, $this->areaname);
        $doc->set('title', html_to_text($record->title, false));
        $doc->set('content', $record->excerpt);
        $doc->set('contextid', $context->id);
        $doc->set('courseid', SITEID);
        $doc->set('owneruserid', \core_search\manager::NO_OWNER_ID);
        $doc->set('modified', $record->timemodified);

        // Check if this document should be considered new.
        if (isset($options['lastindexedtime']) && ($options['lastindexedtime'] < $record->timecreated)) {
            // If the document was created after the last index time, it must be new.
            $doc->set_is_new(true);
        }

        return $doc;
    }

    /**
     * Returns the user fullname to display as document title
     *
     * @param \core_search\document $doc
     * @return string User fullname
     */
    public function get_document_display_title(\core_search\document $doc) {
        return html_to_text($doc->get('title'));
    }

    /**
     * Checking whether I can access a document
     *
     * @param int $id user id
     * @return int
     */
    public function check_access($id) {
        global $DB, $USER;

        $allowed = false;

        // Get the document.
        $sql = "SELECT d.*
                FROM {cgssearch_docs} d
                WHERE id = ?";
        $doc = $DB->get_record_sql($sql, array($id));

        if ($doc) { // Sometimes $doc comes back false. When looking for an element that is not in the DB.

            // Access depends on cgs custom profile field "CampusRoles".
            $userroles = explode(',', strtolower($USER->profile['CampusRoles']));

            // Processing quick links.
            if ($doc->source == get_string('quicklinks', 'local_cgssearch')) {

                // Extract year levels from the audience string.
                $linkyearlevels = array();
                $audiences = explode(',', strtolower($doc->audiences));
                foreach ($audiences as $audience) {
                    if (is_numeric($audience)) {
                        $linkyearlevels[] = $audience;
                    }
                }

                // Year level field is empty. Process by role.
                if (empty($linkyearlevels)) {
                    $allowed = $this->check_quick_links_roles($audiences, $userroles);
                } else {
                    $useryears = explode(',', ($USER->profile['Year']));
                    $allowed = $this->check_quick_links_years($linkyearlevels, $useryears);
                }
            } else {
                if ($doc->source != get_string('quicklinks', 'local_cgssearch') &&
                    $doc->source != get_string('user', 'local_cgssearch')) {
                    $userroles = array_map(function($role) {
                        preg_match('/(.*)(staff|students|parents)(.*)/', $role, $matches);
                        if ($matches) {
                            return $matches[2];
                        }
                        return 'local_cgssearch:invaliduserrole';
                    }, $userroles);
                    $docroles = explode(',', strtolower($doc->audiences));

                    if (array_intersect($userroles, $docroles)) {
                        $allowed = true;
                    }
                }
            }

            if ( $doc->source == get_string('user', 'local_cgssearch')) {
                $allowed = $this->process_users($doc, $userroles);
            }

        }

        if ($allowed) {
            return \core_search\manager::ACCESS_GRANTED;
        }

        return \core_search\manager::ACCESS_DENIED;
    }

    /**
     *
     * @param type $audiences year level(s) for whom the link is available
     * @param type $useryears the year(s) of the user doing the search.
     * @return boolean
     */
    private function check_quick_links_years($audiences, $useryears) {
        if (array_intersect($useryears, $audiences)) {
            return true;
        }

        return false;
    }

    /**
     *
     * @param type $audiences for whom the link is intent to.
     * @param type $userroles the role(s) of the user doing the search.
     * @return boolean
     */
    private function check_quick_links_roles($audiences, $userroles) {
        $rolesallowed = array_intersect($userroles, $audiences);
        $userrolesstr = implode(',', $userroles);

        if (in_array("*", $audiences) || $rolesallowed || is_siteadmin()) {
            return true;
        }
        // Do regex checks.
        foreach ($audiences as $reg) {
            $regex = "/${reg}/i";
            if ($reg && (preg_match($regex, $userrolesstr) === 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a url to the single announcement.
     *
     * @param \core_search\document $doc
     * @return \moodle_url
     */
    public function get_doc_url(\core_search\document $doc) {
        return $this->get_context_url($doc);
    }

    /**
     * Returns a url to the document context.
     *
     * @param \core_search\document $doc
     * @return \moodle_url
     */
    public function get_context_url(\core_search\document $doc) {
        global $DB;
        // Get the document.
        $sql = "SELECT d.*
                FROM {cgssearch_docs} d
                WHERE id = ?";
        $doc = $DB->get_record_sql($sql, array($doc->get('itemid')));
        return new \moodle_url($doc->url);
    }

    /**
     * Returns true if this area uses file indexing.
     *
     * @return bool
     */
    public function uses_file_indexing() {
        return true;
    }

    /**
     * Return the context info required to index files for
     * this search area.
     *
     * @return array
     */
    public function get_search_fileareas() {
        return array();
    }

    /**
     * Returns the moodle component name.
     *
     * It might be the plugin name (whole frankenstyle name) or the core subsystem name.
     *
     * @return string
     */
    public function get_component_name() {
        return 'local_cgssearch';
    }

    /**
     * Returns an icon instance for the document.
     *
     * @param \core_search\document $doc
     *
     * @return \core_search\document_icon
     */
    public function get_doc_icon(\core_search\document $doc) : \core_search\document_icon {
        global $DB;
        // Get the document.
        $sql = "SELECT d.*
                  FROM {cgssearch_docs} d
                 WHERE id = ?";
        $doc = $DB->get_record_sql($sql, array($doc->get('itemid')));
        // Get icon based on external site.
        return new \core_search\document_icon('i/icon-' . $doc->source, 'local_cgssearch');
        // return new \core_search\document_icon('i/empty');
    }

    /**
     * Helper function to process users.
     * @param \core_search\document  $doc
     * @param array $userroles
     * @return boolean
     */
    private function process_users($doc, $userroles) {

        $allowed = false;
        $docroles = explode(',', strtolower($doc->audiences));

        $userroles = array_map(function($role) {
            preg_match('/(.*)(staff)(.*)/', $role, $matches);
            if ($matches) {
                return $matches[2];
            }
            return 'local_cgssearch:invaliduserrole';
        }, $userroles);

        if (array_intersect($userroles, $docroles)|| is_siteadmin()) {
            $allowed = true;
        }

        return $allowed;
    }

}