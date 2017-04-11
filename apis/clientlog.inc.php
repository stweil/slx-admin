<?php

/*
 * For compatibility with old slx-admin, where apis were not connected to a module.
 * This is clientlog, which belonged to module syslog really, plus messy hacked in
 * hook for machine+user statistics.
 */

if (empty($_POST['type'])) die('Missing options.');
$type = mb_strtolower($_POST['type']);

if ($type{0} === '~' || $type{0} === '.') {
	if (Module::isAvailable('statistics')) {
		require 'modules/statistics/api.inc.php';
	}
} else {
	if (Module::isAvailable('syslog')) {
		require 'modules/syslog/api.inc.php';
	}
}
