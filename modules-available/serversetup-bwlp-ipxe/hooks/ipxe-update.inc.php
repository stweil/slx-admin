<?php

$data = [
	'ipaddress' => Property::getServerIp()
];
if ($data['ipaddress'] === 'invalid')
	return false;
$task = Taskmanager::submit('CompileIPxeNew', $data);
if (Taskmanager::isFailed($task))
	return false;
Property::set('ipxe-task-id', $task['id'], 15);
return $task['id'];