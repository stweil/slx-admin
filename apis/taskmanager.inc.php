<?php

require_once 'inc/taskmanager.inc.php';

if (!is_array($_POST['ids'])) {
	die('{"error" : "No Task ids given in POST data."}');
}

$return = array();
foreach ($_POST['ids'] as $id) {
	$status = Taskmanager::status($id);
	if ($status === false) {
		$return[] = array('id' => $id, 'error' => 'No connection to TaskManager');
		continue;
	}
	$return[] = $status;
	if (!isset($status['statusCode']) || ($status['statusCode'] !== TASK_WAITING && $status['statusCode'] !== TASK_PROCESSING)) {
		Taskmanager::release($id);
	}
}

echo json_encode(array('tasks' => $return));
