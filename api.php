<?php

error_reporting(E_ALL);

require_once('inc/user.inc.php');
require_once('inc/util.inc.php');
require_once('inc/db.inc.php');
require_once('inc/permission.inc.php');
require_once('inc/crypto.inc.php');
require_once('inc/validator.inc.php');

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


