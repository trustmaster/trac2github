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

$username = 'Put your github username here';
$password = 'Put your github password here';
$project = 'Organization or User name';
$repo = 'Repository name';

// All users must be valid github logins!
$users_list = array(
	'TracUsermame' => 'GithubUsername',
	'Trustmaster' => 'trustmaster',
	'John.Done' => 'johndoe'
);

$mysqlhost_trac = 'Trac MySQL host';
$mysqluser_trac = 'Trac MySQL user';
$mysqlpassword_trac = 'Trac MySQL password';
$mysqldb_trac = 'Trac MySQL database name';

// Do not convert milestones at this run
$skip_milestones = false;

// Do not convert tickets
$skip_tickets = false;
$ticket_offset = 0; // Start at this offset if limit > 0
$ticket_limit = 0; // Max tickets per run if > 0

// Do not convert comments
$skip_comments = true;
$comments_offset = 0; // Start at this offset if limit > 0
$comments_limit = 0; // Max comments per run if > 0

// Paths to milestone/ticket cache if you run it multiple times with skip/offset
$save_milestones = '/tmp/trac_milestones.list';
$save_tickets = '/tmp/trac_tickets.list';

// Uncomment to refresh cache
// @unlink($save_milestones);
// @unlink($save_tickets);

// DO NOT EDIT BELOW

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(0);

$trac_db = new PDO('mysql:host='.$mysqlhost_trac.';dbname='.$mysqldb_trac, $mysqluser_trac, $mysqlpassword_trac);

echo "Connected to Trac\n";

$milestones = array();
if (file_exists($save_milestones)) {
	$milestones = unserialize(file_get_contents($save_milestones));
}

if (!$skip_milestones) {
	// Export all milestones
	$res = $trac_db->query("SELECT * FROM `milestone` ORDER BY `due`");
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

// Try get previously fetched tickets
$tickets = array();
if (file_exists($save_tickets)) {
	$tickets = unserialize(file_get_contents($save_tickets));
}

if (!$skip_tickets) {
	// Export tickets
	$limit = $ticket_limit > 0 ? "LIMIT $ticket_offset, $ticket_limit" : '';
	$res = $trac_db->query("SELECT * FROM `ticket` ORDER BY `id` $limit");
	foreach ($res->fetchAll() as $row) {
		if (empty($row['milestone'])) {
			continue;
		}
		if (empty($row['owner']) || !isset($users_list[$row['owner']])) {
			$row['owner'] = $username;
		}
		$resp = github_add_issue(array(
			'title' => $row['summary'],
			'body' => empty($row['description']) ? 'None' : $row['description'],
			'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
			'milestone' => $milestones[crc32($row['milestone'])]
		));
		if (isset($resp['number'])) {
			// OK
			$tickets[$row['id']] = (int) $resp['number'];
			echo "Ticket #{$row['id']} converted to issue #{$resp['number']}\n";
			if ($row['status'] == 'closed') {
				// Close the issue
				$resp = github_update_issue($resp['number'], array(
					'title' => $row['summary'],
					'body' => empty($row['description']) ? 'None' : $row['description'],
					'assignee' => isset($users_list[$row['owner']]) ? $users_list[$row['owner']] : $row['owner'],
					'milestone' => $milestones[crc32($row['milestone'])],
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
	$res = $trac_db->query("SELECT * FROM `ticket_change` where `field` = 'comment' AND `newvalue` != '' ORDER BY `ticket`, `time` $limit");
	foreach ($res->fetchAll() as $row) {
		$text = strtolower($row['author']) == strtolower($username) ? $row['newvalue'] : '**Author: ' . $row['author'] . "**\n" . $row['newvalue'];
		$resp = github_add_comment($tickets[$row['ticket']], $text);
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
	if ($patch) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
	}
	$ret = curl_exec($ch);
	if(!$ret) { 
        trigger_error(curl_error($ch)); 
    } 
	curl_close($ch);
	return $ret;
}

function github_add_milestone($data) {
	global $project, $repo;
	return json_decode(github_post("/repos/$project/$repo/milestones", json_encode($data)), true);
}

function github_add_issue($data) {
	global $project, $repo;
	return json_decode(github_post("/repos/$project/$repo/issues", json_encode($data)), true);
}

function github_add_comment($issue, $body) {
	global $project, $repo;
	return json_decode(github_post("/repos/$project/$repo/issues/$issue/comments", json_encode(array('body' => $body))), true);
}

function github_update_issue($issue, $data) {
	global $project, $repo;
	return json_decode(github_post("/repos/$project/$repo/issues/$issue", json_encode($data), true), true);
}

?>
