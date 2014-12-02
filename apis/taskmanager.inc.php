<?php

require_once 'inc/taskmanager.inc.php';
require_once 'inc/property.inc.php';

if (!is_array($_POST['ids'])) {
	die('{"error" : "No Task ids given in POST data."}');
}

$callbacks = false;

$return = array();
foreach ($_POST['ids'] as $id) {
	// Get task status
	$status = Taskmanager::status($id);
	if ($status === false) {
		$return[] = array('id' => $id, 'error' => 'No connection to TaskManager');
		continue;
	}
	$return[] = $status;
	// Handle callbacks (if any)
	if ($callbacks === false)
		$callbacks = TaskmanagerCallback::getPendingCallbacks();
	if (isset($callbacks[$id])) {
		foreach ($callbacks[$id] as $callback) {
			TaskmanagerCallback::handleCallback($callback, $status);
		}
	}
	// Release task if done
	if (Taskmanager::isFailed($status) || Taskmanager::isFinished($status)) {
		Taskmanager::release($id);
	}
}

echo json_encode(array('tasks' => $return));
