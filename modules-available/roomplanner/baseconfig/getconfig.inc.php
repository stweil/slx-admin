<?php

ConfigHolder::add("SLX_PVS_CONFIG_URL", 'http://' . $_SERVER['SERVER_ADDR'] . $_SERVER['SCRIPT_NAME'] . '?do=roomplanner');

$res = Database::queryFirst('SELECT dedicatedmgr FROM location_roomplan WHERE managerip = :ip LIMIT 1', ['ip' => $ip]);
if ($res !== false) {
	if ((int)$res['dedicatedmgr'] !== 0) {
		ConfigHolder::add("SLX_PVS_DEDICATED", 'yes');
	} else {
		ConfigHolder::add("SLX_PVS_HYBRID", 'yes');
	}
}