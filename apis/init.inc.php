<?php

if (!isLocalExecution())
	exit(0);

EventLog::info('System boot...');
$everythingFine = true;

DefaultData::populate();

// Tasks: fire away
$mountId = Trigger::mount();
$ldadpId = Trigger::ldadp();
$autoIp = Trigger::autoUpdateServerIp();
$ipxeId = Trigger::ipxe();

// Check status of all tasks
// Mount vm store
if ($mountId === false) {
	EventLog::info('No VM store type defined.');
	$everythingFine = false;
} else {
	$res = Taskmanager::waitComplete($mountId, 5000);
	if (Taskmanager::isFailed($res)) {
		EventLog::failure('Mounting VM store failed: ' . $res['data']['messages']);
		$everythingFine = false;
	}
}
// LDAP AD Proxy
if ($ldadpId === false) {
	EventLog::failure('Cannot start LDAP-AD-Proxy: Taskmanager unreachable!');
	$everythingFine = false;
} else {
	$res = Taskmanager::waitComplete($ldadpId, 5000);
	if (Taskmanager::isFailed($res)) {
		EventLog::failure('Starting LDAP-AD-Proxy failed: ' . $res['data']['messages']);
		$everythingFine = false;
	}
}
// Primary IP address
if (!$autoIp) {
	EventLog::failure("The server's IP address could not be determined automatically, and there is no active address configured.");
	$everythingFine = false;
}
// iPXE generation
if ($ipxeId === false) {
	EventLog::failure('Cannot generate PXE menu: Taskmanager unreachable!');
	$everythingFine = false;
} else {
	$res = Taskmanager::waitComplete($ipxeId, 5000);
	if (Taskmanager::isFailed($res)) {
		EventLog::failure('Update PXE Menu failed: ' . $res['data']['error']);
		$everythingFine = false;
	}
}

// Just so we know booting is done (and we don't expect any more errors from booting up)
if ($everythingFine) {
	EventLog::info('Bootup finished without errors.');
} else {
	EventLog::info('There were errors during bootup. Maybe the server is not fully configured yet.');
}
