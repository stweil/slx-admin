<?php

error_reporting(E_ALL);

require_once 'config.php';

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
function slxAutoloader($class) {
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file)) return;
	require_once $file;
}
spl_autoload_register('slxAutoloader');

function isLocalExecution()
{
	return !isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === '127.0.0.1';
}


if (!empty($_REQUEST['do'])) {
	$module = preg_replace('/[^a-z]/', '', $_REQUEST['do']);
} elseif (!empty($argv[1])) {
	$module = preg_replace('/[^a-z]/', '', $argv[1]);
} else {
	// No specific module - set default
	$module = 'main';
	
}

$module = 'apis/' . $module . '.inc.php';

if (!file_exists($module)) {
	Util::traceError('Invalid module: ' . $module);
}

Header('Content-Type: text/plain; charset=utf-8');

// Load module - it will execute pre-processing, or act upon request parameters
require_once($module);
unset($module);


