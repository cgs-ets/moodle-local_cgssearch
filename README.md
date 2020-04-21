

# CGS Search

A local plugin that synchronises an index of documents from external systems, as well as quick link (custom block) and user information, so that Moodle can index, search and display these as results via Moodle's Global Search. The plugin consumes external site data via custom API endpoints built into the external systems. Developed for Canberra Grammar School.

Author
--------
Michael Vangelovski<br/>
<https://github.com/michaelvangelovski><br/>
<http://michaelvangelovski.com>

## Global Settings
 - secret → The secret token required to access the external endpoint.
 - sites → Comma-separated list of endpoints, e.g. "example.com/moodlesearch/, test.com/moodlesearch/".

## Technical overview
A scheduled task takes the configurated list of sites, splits it by comma and processes the endpoints one at a time. A get request is performed for each site with the secret appended to the site endpoint as a query parameter. It expects results to be a list of articles in the following JSON format:

**Example context (json):**

    {
    	[
    		{
    			"source": "kb",
    			"extid": 3233,
    			"title": "Staff parking",
    			"audiences": "Staff",
    			"keywords": "Campus,Map",
    			"url": "https://kb.example.com/guides/staff-parking",
    			"timecreated": 1582172583,
    			"timemodified": 1582172583,
    			"content": "Excerpt of article here...",
    		}
    	]
    }

Access to documents in external systems is typically granted to user roles (staff, student, parent). This plugin will ensure that users will only see what they are supposed to see, depending on the permissions in the external system. This access is defined by the "audiences" field for each document in the JSON, and the plugin depends on a locally configured "CampusRoles" custom profile field to check access.