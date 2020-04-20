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
 * A scheduled task to sync docs with external sites.
 *
 * @package    local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_cgssearch\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/cgssearch/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * The main scheduled task for the forum.
 *
 * @package   local_cgssearch
 * @copyright 2019 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cron_task_sync_documents extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('crontask_sync_documents', 'local_cgssearch');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB;

        $this->log_start("Executing external site sync.");

        $config = get_config('local_cgssearch');
        $sites = explode(',', $config->sites);
        $this->process_sites($sites, $config->secret);
        $this->sync_quick_links();
        $this->sync_users();

        $this->log_finish("All site searches syned.");

    }

    private function process_sites($sites, $secret) {
        foreach ($sites as $site) {
            // Assemble the endpoint url.
            $endpoint = trim($site) . '?secret=' . urlencode($secret);
            $this->log("Processing endpoint: " . $endpoint, 1);

            // Use curl to get the search index from external sites.
            $json = curl_get_contents($endpoint);

            // Decode json and store results to DB.
            $docs = json_decode($json);
            if (empty($docs)) {
                continue;
            }

            $this->sync_docs($docs);
        }
    }

    private function sync_docs($docs) {
        global $DB;

        $extids = array_column($docs, 'extid');
        $this->log("Found the following external ids: " . implode(', ', $extids), 2);

        // Delete removed docs.
        $this->delete_docs($docs[0]->source, $extids);

        foreach ($docs as $i => $doc) {
            // Set up doc record.
            $data = new \stdClass();
            $data->source = $doc->source;
            $data->extid = $doc->extid;
            // Do not store external author.
            $data->author = '';
            $data->title = $doc->title;
            $data->url = $doc->url;
            $data->audiences = strtolower($doc->audiences);
            $data->keywords = $doc->keywords;
            // Do not store external content.
            $data->content = '';
            $data->excerpt = shorten_text(html_to_text($doc->content), 300, false, '...');
            $data->timecreated = $doc->timecreated;
            $data->timemodified = $doc->timemodified;

            // Check if doc already exists.
            $record = $DB->get_record('cgssearch_docs', array('source' => $doc->source, 'extid' => $doc->extid));
            if ($record) {
                // If it has been modified since last sync, update the record.
                if ($record->timemodified != $doc->timemodified) {
                    $this->log("Updating: external id (" . $doc->extid . ") table id (" . $record->id . ")", 2);
                    $data->id = $record->id;
                    $DB->update_record('cgssearch_docs', $data);
                }
            } else {
                $this->log("Adding new: " . $doc->extid, 2);
                $DB->insert_record('cgssearch_docs', $data);
            }
        }

        return $docs;
    }

    /**
     *
     * @global type $DB
     * @param type $source of the document
     * @param type $extids external id of the document.
     * @return type
     */
    private function delete_docs($source, $extids) {
        global $DB;

        // Get ids to delete.
        list($insql, $inparams) = $DB->get_in_or_equal($extids);
        $sql = "SELECT id
                FROM {cgssearch_docs}
                WHERE id NOT IN (SELECT id from {cgssearch_docs} WHERE source = ? AND extid $insql)
                AND source = ?";
        $params[] = $source;
        $params = array_merge($params, $inparams);
        $params[] = $source;
        $deleteids = $DB->get_records_sql($sql, $params);
        $deleteids = array_column($deleteids, 'id');

        if (empty($deleteids)) {
            return;
        }

        $this->log("Deleting missing ids: " . implode(', ', $deleteids), 2);
        // Delete them.
        list($insql, $inparams) = $DB->get_in_or_equal($deleteids);
        $sql = "DELETE
                  FROM {cgssearch_docs}
                 WHERE id $insql";
        $DB->execute($sql, $inparams);

    }

    /**
     * Sync links from the custom site links block.
     */
    private function sync_quick_links() {
        global $DB;

        $customelinks = $DB->get_record('block_instances', ['blockname' => get_string('customsitelinks', 'local_cgssearch')]);

        if (empty($customelinks)) {
            return;
        }
        $records = $DB->get_records('cgssearch_docs', ['source' => get_string('quicklinks', 'local_cgssearch')], $sort='', $fields = '*', $limitfrom = 0, $limitnum = 1);

        $links = unserialize(base64_decode($customelinks->configdata));
        $timecreated = $customelinks->timecreated;
        $timemodified = $customelinks->timemodified;

        if (!empty($records)) {
            $r = $records[key($records)];
            if ( $r->timemodified == $timemodified) {
                return;
            } else {
                $this->delete_links(get_string('quicklinks', 'local_cgssearch'));
            }
        }
        foreach ($links->iconlinklabel as $index => $i) {
            $label = $links->iconlinklabel[$index];
            $url = $links->iconlinkurl[$index];
            $id = $links->iconlinkid[$index];
            $url = $links->iconlinkurl[$index];
            $audience = $links->iconlinkcampusroles[$index];
            if (!empty($links->iconlinkyear[$index])) {
                $audience .= "," . $links->iconlinkyear[$index];
            }
            $this->save_quicklink($label, $url, $id, strtolower($audience), $timecreated, $timemodified, $index);
        }

        foreach ($links->textlinklabel as $index => $i) {
            $label = $links->textlinklabel[$index];;
            $url = $links->textlinkurl[$index];
            $id = $links->textlinkid[$index];
            $audience = $links->textlinkcampusroles[$index];
            if (!empty($links->textlinkyear)) {
                $audience .= ",". $links->textlinkyear[$index];
            }
            $this->save_quicklink($label, $url, $id, strtolower($audience), $timecreated, $timemodified, $index);
        }

    }

    /**
     * Inserts a quick link into the DB.
     * @param type $label
     * @param type $url
     * @param type $id
     * @param type $audience
     * @param type $timecreated
     * @param type $timemodified
     */
    private function save_quicklink($label, $url, $id, $audience, $timecreated, $timemodified) {
        global $DB;

        // Set up doc record.
        $data = new \stdClass();
        $data->source = get_string('quicklinks', 'local_cgssearch');
        $data->id = '';
        $data->extid = $id;
        // Do not store external author.
        $data->author = '';
        $data->title = $label;
        $data->url = $url;
        $data->audiences = $audience;
        // Do not store external content.
        $data->content = '';
        $data->excerpt = '';
        $data->timecreated = $timecreated;
        $data->timemodified = $timemodified;
        if (empty($record)) {
            $this->log("Adding new: " . $data->source . " " . $label, 2);
            $DB->insert_record('cgssearch_docs', $data);
        }
    }

    /**
     * Delete quick links records.
     * @param type $source
     */
    private function delete_links($source) {
        global $DB;

        $result = $DB->delete_records('cgssearch_docs', ['source' => $source]);
        $this->log($result);
    }

    /**
     * Adds user records to index table.
     */
    private function sync_users() {
        global $DB;

        $this->delete_suspended_users();

        // Load active users.
        $sql = 'SELECT * FROM {user} WHERE suspended = ?';
        $params[] = 0;
        $activeusers = $DB->get_records_sql($sql, $params);

        foreach ($activeusers as $i => $user) {
            // Load custom fields.
            profile_load_custom_fields($user);
            // Set up doc record.

            $data = new \stdClass();
            $data->source = get_string('user', 'local_cgssearch');
            $data->extid = $user->id;
            // Do not store external author.
            $data->author = '';
            $data->title = fullname($user);

            $profilepage =  new \moodle_url('/user/profile.php', array('id' => $user->id));
            $data->url = $profilepage->out();

            $data->audiences =  'staff';
            $data->keywords = '';

             // This hash code is used to validate changes in the profile.
            $data->content = hash('md5', fullname($user));
            $data->excerpt = '';
            $data->timecreated = $user->timecreated;
            $data->timemodified = $user->timemodified; // This time changes everyday (Synergetic sync).

           // Check if user already exists.
            $record = $DB->get_record('cgssearch_docs', array('source' => $data->source, 'extid' => $user->id));

            if ($record) {
                // If it has been modified since last sync, update the record.
                if ($record->content != $data->content) {
                    $this->log("Updating user: external id (" . $user->id . ") table id (" . $record->id . ")", 2);
                    $data->id = $record->id;
                    $DB->update_record('cgssearch_docs', $data);
                }
            } else {
                $this->log("Adding new user: " . $user->id, 2);
                $DB->insert_record('cgssearch_docs', $data);
            }
        }

    }

    /**
     * Deletes suspended users from index table.
     */
    private function delete_suspended_users(){
        global $DB;

        // Load suspended users.
        $sql = 'SELECT * FROM {user} WHERE suspended = ?';
        $params[] = 1;
        $suspendedusers = $DB->get_records_sql($sql, $params);

        foreach ($suspendedusers as $i => $user) {

            $DB->delete_records('cgssearch_docs', ['extid' => $user->id]);
        }

    }


}