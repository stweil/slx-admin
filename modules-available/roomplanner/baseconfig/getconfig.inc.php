<?php

ConfigHolder::add("SLX_PVS_CONFIG_URL", 'http://' . $_SERVER['SERVER_ADDR'] . $_SERVER['SCRIPT_NAME'] . '?do=roomplanner');

/**
 * Make sure we switch to dedicated mode if this is a hybrid mode
 * manager and we're in exam mode.
 * Also disable exam mode for any kind of manager.
 */
ConfigHolder::addPostHook(function() {
	$exam = (bool)ConfigHolder::get('SLX_EXAM');
	$hybrid = ConfigHolder::get('SLX_PVS_HYBRID') === 'yes';
	$dedi = (bool)ConfigHolder::get('SLX_PVS_DEDICATED');
	if ($exam) {
		if ($dedi || $hybrid) {
			ConfigHolder::add('SLX_EXAM', false, 100000);
			ConfigHolder::add('SLX_SYSTEMD_TARGET', false, 100000);
		}
		if ($hybrid) {
			ConfigHolder::add('SLX_PVS_HYBRID', false, 100000);
			ConfigHolder::add('SLX_PVS_DEDICATED', 'yes', 100000);
		}
	}
});