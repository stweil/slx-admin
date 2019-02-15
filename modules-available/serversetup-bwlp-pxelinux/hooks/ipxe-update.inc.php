<?php

$data = Property::getBootMenu();
$data['ipaddress'] = Property::getServerIp();
$task = Taskmanager::submit('CompileIPxeLegacy', $data);
if (!isset($task['id']))
	return false;
Property::set('ipxe-task-id', $task['id'], 15);
return $task['id'];