<?php

$foofoo = function($machineUuid) {
	$res = Database::queryFirst('SELECT module, modeid, modedata FROM runmode WHERE machineuuid = :uuid',
		array('uuid' => $machineUuid));
	if ($res === false)
		return;
	$config = RunMode::getModuleConfig($res['module']);
	if ($config === false || $config->configHook === false)
		return;
	if (!Module::isAvailable($res['module']))
		return; // Not really possible because getModuleConfig would have failed but we should make sure
	call_user_func($config->configHook, $machineUuid, $res['modeid'], $res['modedata']);
	if ($config->systemdDefaultTarget !== false) {
		ConfigHolder::add('SLX_SYSTEMD_TARGET', $config->systemdDefaultTarget, 10000);
	}
	if ($config->noSysconfig) {
		ConfigHolder::add('SLX_NO_CONFIG_TGZ', '1', 10000);
	}
	// Disable exam mode - not sure if this is generally a good idea; for now, all modes we can think of would
	// not make sense that way so do this for now
	if (ConfigHolder::get('SLX_EXAM') !== false) {
		ConfigHolder::add('SLX_EXAM', '', 100001);
		ConfigHolder::add('SLX_EXAM_START', '', 100001);
		ConfigHolder::add('SLX_AUTOLOGIN', '', 100001);
	}
};

$foofoo($uuid);