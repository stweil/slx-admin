<?php

if (Request::any('action') === 'rebuild' && isLocalExecution()) {
	if (Module::isAvailable('sysconfig')) {
		SSHKey::getPrivateKey($regen);
		if (!$regen) {
			ConfigTgz::rebuildAllConfigs();
		}
		echo "OK";
	}
	exit(0);
}
/*
	Needed POST-Parameters:
		'token' -- for authentication
		'action' -- which action should be performed (shutdown or reboot)
		'clients' -- which are to reboot/shutdown (json encoded array!)
		'timer' -- (optional) when to perform action in minutes (default value is 0)
*/

$ips = json_decode(Request::post('clients'));
$minutes = Request::post('timer', 0, 'int');

$clients = array();
foreach ($ips as $client) {
	$clients[] = array("ip" => $client);
}

$apikey = Property::get("rebootcontrol_APIPOSTKEY", 'not-set');
if (!empty($apikey) && Request::post('token') === $apikey) {
	if (Request::isPost()) {
		if (Request::post('action') == 'shutdown') {
			$shutdown = true;
			$task = Taskmanager::submit("RemoteReboot", array("clients" => $clients, "shutdown" => $shutdown, "minutes" => $minutes));
			echo $task["id"];
		} else if (Request::post('action') == 'reboot') {
			$shutdown = false;
			$task = Taskmanager::submit("RemoteReboot", array("clients" => $clients, "shutdown" => $shutdown, "minutes" => $minutes));
			echo $task["id"];
		} else {
			echo "Only action=shutdown and action=reboot available.";
		}
	} else {
		echo "Only POST Method available.";
	}
} else {
	echo "Not authorized";
}