<?php

ConfigHolder::add("SLX_PVS_CONFIG_URL", 'http://' . $_SERVER['SERVER_ADDR'] . $_SERVER['SCRIPT_NAME'] . '?do=roomplanner');

$res = Database::queryFirst('SELECT dedicatedmgr FROM location_roomplan WHERE managerip = :ip LIMIT 1', ['ip' => $ip]);
if ($res !== false) {
	if ((int)$res['dedicatedmgr'] !== 0) {
		// TODO: Runmode
		ConfigHolder::add("SLX_PVS_DEDICATED", 'yes');
		ConfigHolder::add("SLX_EXAM", false, 100000);
		ConfigHolder::add("SLX_SYSTEMD_TARGET", false, 100000);
	} else {
		ConfigHolder::add("SLX_PVS_HYBRID", 'yes');
	}
}