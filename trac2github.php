<?php

require("github.php");
require("trac.php");
require("ticket.php");
require("config.php");


error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(0);

$github = new Github($username, $password);

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
		$resp = $github->add_milestone(array(
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
		$resp = $github->add_issue(array(
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
				$resp = $github->update_issue($resp['number'], array(
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
		$resp = $github->add_comment($tickets[$row['ticket']], $text);
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


