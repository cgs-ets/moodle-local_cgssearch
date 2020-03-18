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
     * Sync Links from the custom site links block.
     */
    private function sync_quick_links() {
        global $DB;

        $customelinks = $DB->get_record('block_instances', ['blockname' => get_string('customsitelinks', 'local_cgssearch')]);

        if (empty($customelinks)) {
            return;
        }
        $records = $DB->get_records('cgssearch_docs', ['source' => get_string('quicklinks', 'local_cgssearch')]);

        $links = unserialize(base64_decode($customelinks->configdata));
        $timecreated = $customelinks->timecreated;
        $timemodified = $customelinks->timemodified;

        if (!empty($records)) {
            $r = $records[array_key_first($records)];

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
            $audience = $links->iconlinkcampusroles[$index] . " " . $links->iconlinkyear[$index];
            $audience = $this->format_audience(strtolower($audience));
            $this->quick_links_helper($label, $url, $id, $audience  , $timecreated, $timemodified, $index);
        }

        foreach ($links->textlinklabel as $index => $i) {
            $label = $links->textlinklabel[$index];;
            $url = $links->textlinkurl[$index];
            $id = $links->textlinkid[$index];
            $audience = $links->textlinkcampusroles[$index] ." ". $links->textlinkyear[$index];
            $audience = $this->format_audience(strtolower($audience));

            $this->quick_links_helper($label, $url, $id, $audience, $timecreated, $timemodified, $index);
        }

    }
    /**
     * Format the audience of custom links to match the format other sources have.
     * @param type $audience
     */
    private function format_audience($audience) {
        $pattern = array('/,/', '/:/', '/\.\*/', '(senior|school|early|learning|centre|southside|northside|junior|school|primary|whole|future)');
        $replace = ' ';
        $r = preg_replace($pattern, $replace, $audience);
        $r = explode(" ", trim($r));
        $r = array_filter(array_map('trim', array_unique($r)));
        $r = implode(",", $r);

        $r = preg_replace('/\*/', 'staff,students,parents,admin', $r);
        return $r;
    }

    private function quick_links_helper($label, $url, $id, $audience, $timecreated, $timemodified) {
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
        //  $data->keywords = $doc->keywords;
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

    private function delete_links($source) {
        global $DB;

        $result = $DB->delete_records('cgssearch_docs', ['source' => $source]);
        $this->log($result);
    }
}
