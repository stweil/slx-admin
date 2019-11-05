<?php

// Location handling: figure out location
if (Request::any('force', 0, 'int') === 1 && Request::any('module', false, 'string') === 'statistics') {
	// Force location for testing, but require logged in admin
	if (User::load()) {
		$uuid = Request::any('value', '', 'string');
	}
}

if (!$uuid) // Required at this point, bail out if not given
	return;

// Query machine specific settings
$res = Database::simpleQuery("SELECT setting, value FROM setting_machine WHERE machineuuid  = :uuid", ['uuid' => $uuid]);
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	ConfigHolder::add($row['setting'], $row['value'], 500);
}
