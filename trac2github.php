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
	'TracUsername' => 'GithubUsername',
	'Trustmaster' => 'trustmaster',
	'John.Done' => 'johndoe'
);

//Restrict to certain components (null or Array with components name).
$use_components = null;

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

$remap_labels = array();

// Do not convert tickets
$skip_tickets   = false;
$ticket_offset  = 0; // Start at this offset if limit > 0
$ticket_limit   = 0; // Max tickets per run if > 0
$ticket_try_preserve_numbers = 0; // Try to preserve ticket numbers - create placeholders, error if not match

// Do not convert comments
$skip_comments   = false;
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

$request_count = 0;

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
		$trac_db = new PDO('mysql:host='.$mysqlhost_trac.';dbname='.$mysqldb_trac . ';charset=utf8', $mysqluser_trac, $mysqlpassword_trac);
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

//if restriction to certain components is added, put this in the SQL string
if ($use_components && is_array($use_components)) $my_components = " AND component IN ('".implode("', '", $use_components)."') ";
else $my_components = "";

$milestones = array();

if (!$skip_milestones) {
	// Export all milestones
	$res = $trac_db->query("SELECT * FROM milestone ORDER BY due");
	$mnum = 1;
	$existing_milestones = array();
	foreach (github_get_milestones() as $m) {
		$milestones[crc32(urldecode($m['title']))] = (int)$m['number'];
	}
	foreach ($res->fetchAll() as $row) {
		if (isset($milestones[crc32($row['name'])])) {
			echo "Milestone {$row['name']} already exists\n";
			continue;
		}
		//$milestones[$row['name']] = ++$mnum;
		$epochInSecs = (int) ($row['due']/1000000);
		echo "due : ".date('Y-m-d\TH:i:s\Z', $epochInSecs)."\n";
		if ($epochInSecs == 0) {
			$resp = github_add_milestone(array(
				'title' => $row['name'],
				'state' => $row['completed'] == 0 ? 'open' : 'closed',
				'description' => empty($row['description']) ? '' : translate_markup($row['description'])
			));
		}
		else {
			$resp = github_add_milestone(array(
				'title' => $row['name'],
				'state' => $row['completed'] == 0 ? 'open' : 'closed',
				'description' => empty($row['description']) ? '' : translate_markup($row['description']),
				'due_on' => date('Y-m-d\TH:i:s\Z', $epochInSecs)
			));
		}
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
}

$labels = array();
$labels['T'] = array();
$labels['C'] = array();
$labels['P'] = array();
$labels['R'] = array();

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

	$existing_labels = array();
	foreach (github_get_labels() as $l) {
		$existing_labels[] = urldecode($l['name']);
	}
	foreach ($res->fetchAll() as $row) {
		$label_name = $row['label_type'] . ': ' . str_replace(",", "", $row['name']);
		if (array_key_exists($label_name, $remap_labels)) {
			$label_name = $remap_labels[$label_name];
		}
		if (empty($label_name)) {
			$labels[$row['label_type']][crc32($row['name'])] = NULL;
			continue;
		}
		if (in_array($label_name, $existing_labels)) {
			echo "Label {$row['name']} already exists\n";
			$labels[$row['label_type']][crc32($row['name'])] = $label_name;
			continue;
		}
		$resp = github_add_label(array(
			'name' => $label_name,
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
}

// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}

if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';

	$res = $trac_db->query("SELECT * FROM ticket WHERE 1=1 $my_components ORDER BY id $limit");
	foreach ($res->fetchAll() as $row) {
		if (isset($last_ticket_number) and $ticket_try_preserve_numbers) {
			if ($last_ticket_number >= $row['id']) {
				echo "ERROR: Cannot create ticket #{$row['id']} because issue #{$last_ticket_number} was already created.";
				break;
			}
			while ($last_ticket_number < $row['id']-1) {
				$resp = github_add_issue(array(
					'title' => "Placeholder",
							'body' => "This is a placeholder created during migration to preserve original issue numbers.",
					'milestone' => NULL,
					'labels' => array()
					));
				if (isset($resp['number'])) {
					// OK
					$last_ticket_number = $resp['number'];
					echo "Created placeholder issue #{$resp['number']}\n";
					$resp = github_update_issue($resp['number'], array(
						'state' => 'closed',
						'labels' => array('invalid'),
						));
					if (isset($resp['number'])) {
						echo "Closed issue #{$resp['number']}\n";
					}
				}
			}
		}
		if (!$skip_comments) {
			// restore original values (at ticket creation time), to restore modification history later
			foreach (['owner', 'priority', 'resolution', 'milestone', 'type', 'component', 'description', 'summary'] as $f) {
				$row[$f] = trac_orig_value($row, $f);
			}
		}
		if (!empty($row['owner']) and !isset($users_list[$row['owner']])) {
			$row['owner'] = NULL;
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
		$timestamp = date("j M Y H:i e", $row['time']/1000000);
		$body = '**Reported by ' . obfuscate_email($row['reporter']) . ' on ' . $timestamp . "**\n" . $body;

		if (empty($row['milestone'])) {
			$milestone = NULL;
		} else {
			$milestone = $milestones[crc32($row['milestone'])];
		}
		if (!empty($row['owner'])) {
			$assignee = isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'];
		} else {
			$assignee = NULL;
		}
		$resp = github_add_issue(array(
			'title' => $row['summary'],
			'body' => body_with_possible_suffix($body, $row['id']),
			'assignee' => $assignee,
			'milestone' => $milestone,
			'labels' => $ticketLabels
		));
		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			$last_ticket_number = $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
			if ($ticket_try_preserve_numbers and $row['id'] != $resp['number']) {
				echo "ERROR: New ticket number do not match the original one!\n";
				break;
			}
			if (!$skip_comments) {
				if (!add_changes_for_ticket($row['id'], $ticketLabels)) {
					break;
				}
			} else {
				if ($row['status'] == 'closed') {
					// Close the issue
					$resp = github_update_issue($resp['number'], array(
								'state' => 'closed'
								));
					if (isset($resp['number'])) {
						echo "Closed issue #{$resp['number']}\n";
					}
				}
			}

		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to convert a ticket #{$row['id']}: $error\n";
			break;
		}
	}
	// Serialize to restore in future
	file_put_contents($save_tickets, serialize($tickets));
}

echo "Done whatever possible, sorry if not.\n";

function trac_orig_value($ticket, $field) {
	global $trac_db;
	$orig_value = $ticket[$field];
	$res = $trac_db->query("SELECT ticket_change.* FROM ticket_change WHERE ticket = {$ticket['id']} AND field = '$field' ORDER BY time LIMIT 1");
	foreach ($res->fetchAll() as $row) {
		$orig_value = $row['oldvalue'];
	}
	return $orig_value;
}

function add_changes_for_ticket($ticket, $ticketLabels) {
	global $trac_db, $tickets, $labels, $users_list, $milestones, $skip_comments, $verbose;
	$res = $trac_db->query("SELECT ticket_change.* FROM ticket_change, ticket WHERE ticket.id = ticket_change.ticket AND ticket = $ticket ORDER BY ticket, time, field <> 'comment'");
	foreach ($res->fetchAll() as $row) {
		if ($verbose) print_r($row);
		if (!isset($tickets[$row['ticket']])) {
			echo "Skipping comment " . $row['time'] . " on unknown ticket " . $row['ticket'] . "\n";
			continue;
		}
		$timestamp = date("j M Y H:i e", $row['time']/1000000);
		if ($row['field'] == 'comment') {
			if ($row['newvalue'] != '') {
				$text = '**Comment by ' . $row['author'] . ' on ' . $timestamp . "**\n" . $row['newvalue'];
			} else {
				$text = '**Modified by ' . $row['author'] . ' on ' . $timestamp . "**";
			}
			$resp = github_add_comment($tickets[$row['ticket']], translate_markup($text));
		} else if (in_array($row['field'], ['component', 'priority', 'type', 'resolution'])) {
			if (in_array($labels[strtoupper($row['field'])[0]][crc32($row['oldvalue'])], $ticketLabels)) {
				$index = array_search($labels[strtoupper($row['field'])[0]][crc32($row['oldvalue'])], $ticketLabels);
				$ticketLabels[$index] = $labels[strtoupper($row['field'])[0]][crc32($row['newvalue'])];
			} else {
				$ticketLabels[] = $labels[strtoupper($row['field'])[0]][crc32($row['newvalue'])];
			}
			$resp = github_update_issue($tickets[$ticket], array(
						'labels' => array_values(array_filter($ticketLabels, 'strlen'))
						));
		} else if ($row['field'] == 'status') {
			$resp = github_update_issue($tickets[$ticket], array(
				'state' => ($row['newvalue'] == 'closed') ? 'closed' : 'open'
				));
		} else if ($row['field'] == 'summary') {
			$resp = github_update_issue($tickets[$ticket], array(
						'title' => $row['newvalue']
						));
		} else if (false and $row['field'] == 'description') { // TODO?
			$body = make_body($row['newvalue']);
			$timestamp = date("j M Y H:i e", $row['time']/1000000);
			// TODO:
			//$body = '**Reported by ' . obfuscate_email($row['reporter']) . ' on ' . $timestamp . "**\n" . $body;

			$resp = github_update_issue($tickets[$ticket], array(
						'body' => $body
						));
		} else if ($row['field'] == 'owner') {
			if (!empty($row['newvalue'])) {
				$assignee = isset($users_list[$row['newvalue']]) ? $users_list[$row['newvalue']] : NULL;
			} else {
				$assignee = NULL;
			}
			$resp = github_update_issue($tickets[$ticket], array(
						'assignee' => $assignee
						));
		} else if ($row['field'] == 'milestone') {
			if (empty($row['newvalue'])) {
				$milestone = NULL;
			} else {
				$milestone = $milestones[crc32($row['newvalue'])];
			}
			$resp = github_update_issue($tickets[$ticket], array(
				'milestone' => $milestone
				));
		} else {
			echo "WARNING: ignoring change of {$row['field']} to {$row['newvalue']}\n";
			continue;
		}
		if (isset($resp['url'])) {
			// OK
			echo "Added change {$resp['url']}\n";
		} else {
			// Error
			$error = print_r($resp, 1);
			echo "Failed to add a comment for " . $row['ticket'] . ": $error\n";
			return false;
		}
		// Wait 1sec to ensure the next event will be after
		// just added (apparently github can reorder
		// changes/comments if added too fast)
		sleep(1);
	}
	return true;
}

function github_req($url, $json, $patch = false, $post = true) {
	global $username, $password, $request_count;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
	curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_POST, $post);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
	curl_setopt($ch, CURLOPT_USERAGENT, "trac2github for $project, admin@example.com");
	if ($patch) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
	} else if ($post) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	} else {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	}
	$ret = curl_exec($ch);

	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$header = substr($ret, 0, $header_size);
	$body = substr($ret, $header_size);

	if ($verbose) print_r($header);
	if (!$ret) {
		trigger_error(curl_error($ch));
	}
	curl_close($ch);

	if ($patch || $post) {
		$request_count++;
		if($request_count > 50) {
			sleep(70);
			$request_count = 0;
		}

	}

	return $body;
}

function github_add_milestone($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_req("/repos/$project/$repo/milestones", json_encode($data)), true);
}

function github_add_label($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_req("/repos/$project/$repo/labels", json_encode($data)), true);
}

function github_add_issue($data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_req("/repos/$project/$repo/issues", json_encode($data)), true);
}

function github_add_comment($issue, $body) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_req("/repos/$project/$repo/issues/$issue/comments", json_encode(array('body' => $body))), true);
}

function github_update_issue($issue, $data) {
	global $project, $repo, $verbose;
	if ($verbose) print_r($data);
	return json_decode(github_req("/repos/$project/$repo/issues/$issue", json_encode($data), true), true);
}

function github_get_milestones() {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return json_decode(github_req("/repos/$project/$repo/milestones?per_page=100&state=all", false, false, false), true);
}

function github_get_labels() {
	global $project, $repo, $verbose;
	if ($verbose) print_r($body);
	return array_merge(json_decode(github_req("/repos/$project/$repo/labels?per_page=100", false, false, false), true), json_decode(github_req("/repos/$project/$repo/labels?page=2&per_page=100", false, false, false), true));
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
