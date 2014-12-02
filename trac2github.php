<?php
/**
 * @package trac2github
 * @version 1.1
 * @author Vladimir Sibirov
 * @author Lukas Eder
 * @copyright (c) Vladimir Sibirov 2011
 * @license BSD
 */

// Edit configuration below

$username   = 'Put your github username here';
$password   = 'Put your github password here';
$project    = 'Organization or User name';
$repo       = 'Organization or User name';

// All users must be valid github logins!
$users_list = array(
	'TracUsermame' => 'GithubUsername',
	'Trustmaster' => 'trustmaster',
	'John.Done' => 'johndoe'
);

// The PDO driver name to use.
// Options are: 'mysql', 'sqlite', 'pgsql'
$pdo_driver = 'mysql';

// MySQL connection info
$mysqlhost_trac     = 'Trac MySQL host';
$mysqluser_trac     = 'Trac MySQL user';
$mysqlpassword_trac = 'Trac MySQL password';
$mysqldb_trac       = 'Trac MySQL database name';

// Path to SQLite database file
$sqlite_trac_path = '/path/to/trac.db';

// Postgresql connection info
$pgsql_host = 'localhost';
$pgsql_port  = '5432';
$pgsql_dbname = 'Postgres database name';
$pgsql_user = 'Postgres user name';
$pgsql_password = 'Postgres password';

// Do not convert milestones at this run
$skip_milestones = false;

// Do not convert labels at this run
$skip_labels = false;

// Do not convert tickets
$skip_tickets   = false;
$ticket_offset  = 0; // Start at this offset if limit > 0
$ticket_limit   = 0; // Max tickets per run if > 0

// Do not convert comments
$skip_comments   = true;
$comments_offset = 0; // Start at this offset if limit > 0
$comments_limit  = 0; // Max comments per run if > 0

// Whether to add a "Migrated-From:" suffix to each issue's body
$add_migrated_suffix = false;
$trac_url = 'http://my.domain/trac/env';

// Paths to milestone/ticket cache if you run it multiple times with skip/offset
$save_milestones = '/tmp/trac_milestones.list';
$save_tickets = '/tmp/trac_tickets.list';

// Set this to true if you want to see the JSON output sent to GitHub
$verbose = false;

// Uncomment to refresh cache
// @unlink($save_milestones);
// @unlink($save_labels);
// @unlink($save_tickets);

// DO NOT EDIT BELOW

if (file_exists('trac2github.cfg')) {
  include 'trac2github.cfg';
}

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(0);
date_default_timezone_set("UTC");

// Connect to Trac database using PDO
switch ($pdo_driver) {
	case 'mysql':
		$trac_db = new PDO('mysql:host='.$mysqlhost_trac.';dbname='.$mysqldb_trac, $mysqluser_trac, $mysqlpassword_trac);
		break;

	case 'sqlite':
		// Check the the file exists
		if (!file_exists($sqlite_trac_path)) {
			echo "SQLITE file does not exist.\n";
			exit;
		}

		$trac_db = new PDO('sqlite:'.$sqlite_trac_path);
		break;

	case 'pgsql':
		$trac_db = new PDO("pgsql:host=$pgsql_host;port=$pgsql_port;dbname=$pgsql_dbname;user=$pgsql_user;password=$pgsql_password");
		break;

	default:
		echo "Unknown PDO driver.\n";
		exit;
}

// Set PDO to throw exceptions on error.
$trac_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Connected to Trac\n";

$milestones = array();
if (file_exists($save_milestones)) {
	$milestones = unserialize(file_get_contents($save_milestones));
}

if (!$skip_milestones) {
	// Export all milestones
	$res = $trac_db->query("SELECT * FROM milestone ORDER BY due");
	$mnum = 1;
	foreach ($res->fetchAll() as $row) {
		//$milestones[$row['name']] = ++$mnum;
		$resp = github_add_milestone(array(
			'title' => $row['name'],
			'state' => $row['completed'] == 0 ? 'open' : 'closed',
			'description' => empty($row['description']) ? 'None' : $row['description'],
			'due_on' => date('Y-m-d\TH:i:s\Z', (int) $row['due'])
		));
		if (isset($resp['number'])) {
			// OK
			$milestones[crc32($row['name'])] = (int) $resp['number'];
			echo "Milestone {$row['name']} converted to {$resp['number']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert milestone {$row['name']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_milestones, serialize($milestones));
}

$labels = array();
$labels['T'] = array();
$labels['C'] = array();
$labels['P'] = array();
$labels['R'] = array();
if (file_exists($save_labels)) {
	$labels = unserialize(file_get_contents($save_labels));
}

if (!$skip_labels) {
	// Export all "labels"
	$res = $trac_db->query("SELECT DISTINCT 'T' AS label_type, type AS name, 'cccccc' AS color
	                        FROM ticket WHERE COALESCE(type, '') <> ''
	                        UNION
	                        SELECT DISTINCT 'C' AS label_type, component AS name, '0000aa' AS color
	                        FROM ticket WHERE COALESCE (component, '')  <> ''
	                        UNION
	                        SELECT DISTINCT 'P' AS label_type, priority AS name, case when lower(priority) = 'urgent'   then 'ff0000'
	                                                                                  when lower(priority) = 'high'     then 'ff6666'
	                                                                                  when lower(priority) = 'medium'   then 'ffaaaa'
	                                                                                  when lower(priority) = 'low'      then 'ffdddd'
	                                                                                  when lower(priority) = 'blocker'  then 'ffc7f8'
	                                                                                  when lower(priority) = 'critical' then 'ffffb8'
	                                                                                  when lower(priority) = 'major'    then 'f6f6f6'
	                                                                                  when lower(priority) = 'minor'    then 'dcffff'
	                                                                                  when lower(priority) = 'trivial'  then 'dce7ff'
	                                                                                  else                                   'aa8888' end color
	                        FROM ticket WHERE COALESCE(priority, '')   <> ''
	                        UNION
	                        SELECT DISTINCT 'R' AS label_type, resolution AS name, '55ff55' AS color
	                        FROM ticket WHERE COALESCE(resolution, '') <> ''");

	foreach ($res->fetchAll() as $row) {
		$resp = github_add_label(array(
			'name' => $row['label_type'] . ': ' . $row['name'],
			'color' => $row['color']
		));

		if (isset($resp['url'])) {
			// OK
			$labels[$row['label_type']][crc32($row['name'])] = $resp['name'];
			echo "Label {$row['name']} converted to {$resp['name']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert label {$row['name']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_labels, serialize($labels));
}

// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}

if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';
	$res = $trac_db->query("SELECT * FROM ticket ORDER BY id $limit");
	foreach ($res->fetchAll() as $row) {
		if (empty($row['owner']) || !isset($users_list[$row['owner']])) {
			$row['owner'] = $username;
		}
		$ticketLabels = array();
		if (!empty($labels['T'][crc32($row['type'])])) {
			$ticketLabels[] = $labels['T'][crc32($row['type'])];
		}
		if (!empty($labels['C'][crc32($row['component'])])) {
			$ticketLabels[] = $labels['C'][crc32($row['component'])];
		}
		if (!empty($labels['P'][crc32($row['priority'])])) {
			$ticketLabels[] = $labels['P'][crc32($row['priority'])];
		}
		if (!empty($labels['R'][crc32($row['resolution'])])) {
			$ticketLabels[] = $labels['R'][crc32($row['resolution'])];
		}

		$body = make_body($row['description']);
		$timestamp = date("j M Y H:i e", $row['time']);
		$body = '**Reported by ' . obfuscate_email($row['reporter']) . ' on ' . $timestamp . "**\n" . $body;

		// There is a strange issue with summaries containing percent signs...
		if (empty($row['milestone'])) {
			$milestone = NULL;
		} else {
			$milestone = $milestones[crc32($row['milestone'])];
		}
		$resp = github_add_issue(array(
			'title' => preg_replace("/%/", '[pct]', $row['summary']),
			'body' => body_with_possible_suffix($body, $row['id']),
			'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
			'milestone' => $milestone,
			'labels' => $ticketLabels
		));
		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
			if ($row['status'] == 'closed') {
				// Close the issue
				$resp = github_update_issue($resp['number'], array(
					'title' => preg_replace("/%/", '[pct]', $row['summary']),
					'body' => $body,
					'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
					'milestone' => $milestone,
					'labels' => $ticketLabels,
					'state' => 'closed'
				));
				if (isset($resp['number'])) {
					echo "Closed issue #{$resp['number']}\n";
				}
			}

		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert a ticket #{$row['id']}: $error\n";
		}
	}
	// Serialize to restore in future
	file_put_contents($save_tickets, serialize($tickets));
}

if (!$skip_comments) {
	// Export all comments
	$limit = $comments_limit > 0 ? "LIMIT $comments_offset, $comments_limit" : '';
	$res = $trac_db->query("SELECT * FROM ticket_change where field = 'comment' AND newvalue != '' ORDER BY ticket, time $limit");
	foreach ($res->fetchAll() as $row) {
		$timestamp = date("j M Y H:i e", $row['time']);
		$text = '**Commented by ' . $row['author'] . ' on ' . $timestamp . "**\n" . $row['newvalue'];
		$resp = github_add_comment($tickets[$row['ticket']], translate_markup($text));
		if (isset($resp['url'])) {
			// OK
			echo "Added comment {$resp['url']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to add a comment: $error\n";
		}
	}
}

echo "Done whatever possible, sorry if not.\n";

function github_post($url, $json, $patch = false) {
	global $username, $password;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
	curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_USERAGENT, "trac2github for $project, admin@example.com");
	if ($patch) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
	}
	$ret = curl_exec($ch);
	if (!$ret) {
		trigger_error(curl_error($ch));
	}
	curl_close($ch);
	return $ret;
}

function github_add_milestone($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/milestones", json_encode($data)), true);
}

function github_add_label($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/labels", json_encode($data)), true);
}

function github_add_issue($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_post("/repos/$project/$repo/issues", json_encode($data)), true);
}

function github_add_comment($issue, $body) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue/comments", json_encode(array('body' => $body))), true);
}

function github_update_issue($issue, $data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_post("/repos/$project/$repo/issues/$issue", json_encode($data), true), true);
}

function make_body($description) {
	return empty($description) ? 'None' : translate_markup($description);
}

function translate_markup($data) {
	// Replace code blocks with an associated language
	$data = preg_replace('/\{\{\{(\s*#!(\w+))?/m', '```$2', $data);
	$data = preg_replace('/\}\}\}/', '```', $data);

	// Avoid non-ASCII characters, as that will cause trouble with json_encode()
	$data = preg_replace('/[^(\x00-\x7F)]*/','', $data);

	// Translate Trac-style links to Markdown
	$data = preg_replace('/\[([^ ]+) ([^\]]+)\]/', '[$2]($1)', $data);

	// Possibly translate other markup as well?
	return $data;
}

function body_with_possible_suffix($body, $id) {
	global $add_migrated_suffix, $trac_url;
	if (!$add_migrated_suffix) return $body;
	return "$body\n\nMigrated-From: $trac_url/ticket/$id";
}

function obfuscate_email($text)
{
    list($text) = explode('@', $text);
    $text = preg_replace('/[^a-z0-9]/i', ' ', $text);
    return $text;
}


?>
