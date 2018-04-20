<?php

$foofoo = function($machineUuid) {
	$res = Database::queryFirst('SELECT module, modeid, modedata FROM runmode WHERE machineuuid = :uuid',
		array('uuid' => $machineUuid));
	if ($res === false)
		return;
	$config = RunMode::getModuleConfig($res['module']);
	if ($config === false)
		return;
	if (!Module::isAvailable($res['module']))
		return; // Not really possible because getModuleConfig would have failed but we should make sure
	if ($config->configHook !== false) {
		call_user_func($config->configHook, $machineUuid, $res['modeid'], $res['modedata']);
	}
	if ($config->systemdDefaultTarget !== false) {
		ConfigHolder::add('SLX_SYSTEMD_TARGET', $config->systemdDefaultTarget, 10000);
	}
};

$foofoo($uuid);