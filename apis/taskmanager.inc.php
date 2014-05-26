<?php

require_once 'inc/taskmanager.inc.php';
require_once 'inc/property.inc.php';

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
	// HACK HACK - should be pluggable
	if (isset($status['statusCode']) && $status['statusCode'] === TASK_FINISHED // iPXE Update
			&& $id === Property::getIPxeTaskId() && Property::getServerIp() !== Property::getIPxeIp()) {
		Property::setIPxeIp(Property::getServerIp());
	}
	if (isset($status['statusCode']) && $status['statusCode'] === TASK_FINISHED // MiniLinux Version check
			&& $id === Property::getVersionCheckTaskId()) {
		Property::setVersionCheckInformation(Property::getServerIp());
	}
	// -- END HACKS --
	if (!isset($status['statusCode']) || ($status['statusCode'] !== TASK_WAITING && $status['statusCode'] !== TASK_PROCESSING)) {
		Taskmanager::release($id);
	}
}

echo json_encode(array('tasks' => $return));
