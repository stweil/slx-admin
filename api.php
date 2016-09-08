<?php

error_reporting(E_ALL);
chdir(dirname($_SERVER['SCRIPT_FILENAME']));

require_once 'config.php';

define('API', true);
define('AJAX', false);

if (CONFIG_SQL_PASS === '%MYSQL_OPENSLX_PASS%')
	exit(0); // Ignore API calls if not configured yet

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
spl_autoload_register(function ($class) {
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file))
		return;
	require_once $file;
});

function isLocalExecution()
{
	return !isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === '127.0.0.1';
}

if (!empty($_REQUEST['do'])) {
	$module = preg_replace('/[^a-z]/', '', $_REQUEST['do']);
} elseif (!empty($argv[1])) {
	$module = preg_replace('/[^a-z]/', '', $argv[1]);
} else {
	exit(1);
}

Module::init();
if (Module::isAvailable($module)) {
	$module = 'modules/' . $module . '/api.inc.php';
} else {
	$module = 'apis/' . $module . '.inc.php';
}

if (!file_exists($module)) {
	Util::traceError('Invalid module, or module without API: ' . $module);
}

Header('Content-Type: text/plain; charset=utf-8');
ob_start('ob_gzhandler');
// Load module - it will execute pre-processing, or act upon request parameters
require_once($module);


