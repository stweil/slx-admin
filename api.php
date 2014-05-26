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


if (empty($_REQUEST['do'])) {
	// No specific module - set default
	$module = 'main';
} else {
	$module = preg_replace('/[^a-z]/', '', $_REQUEST['do']);
}

$module = 'apis/' . $module . '.inc.php';

if (!file_exists($module)) {
	Util::traceError('Invalid module: ' . $module);
}

Header('Content-Type: text/plain; charset=utf-8');

// Load module - it will execute pre-processing, or act upon request parameters
require_once($module);
unset($module);


