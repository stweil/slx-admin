<?php

$data = [
	'ipaddress' => Property::getServerIp()
];
$task = Taskmanager::submit('CompileIPxeNew', $data);
if (!isset($task['id']))
	return false;
Property::set('ipxe-task-id', $task['id'], 15);
return $task['id'];