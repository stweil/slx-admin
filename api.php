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
	$module = preg_replace('/[^a-z0-9]/', '', $_REQUEST['do']);
} elseif (!empty($argv[1])) {
	$module = preg_replace('/[^a-z0-9]/', '', $argv[1]);
	$argc = count($argv);
	for ($i = 2; $i < $argc; ++$i) {
		if (substr($argv[$i], 0, 2) === '--') {
			// Handle --arg=value and --arg value
			$key = substr($argv[$i], 2);
			if (($pos = strpos($key, '=')) !== false) {
				$val = substr($key, $pos + 1);
				$key = substr($key, 0, $pos);
				$_REQUEST[$key] = $_GET[$key] = $val;
			} elseif ($i + 1 < $argc) {
				$_REQUEST[$key] = $_GET[$key] = $argv[$i + 1];
				++$i;
			}
		}
	}
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
if (php_sapi_name() === 'cli') {
	register_shutdown_function(function() {
		if (class_exists('Message', false)) {
			$text = Message::asString();
			if (!empty($text)) {
				error_log("The following error messages were generated:\n" . $text);
			}
		}
	});
} else {
	Header('Expires: Wed, 29 Mar 2007 09:56:28 GMT');
	Header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
	Header("Cache-Control: post-check=0, pre-check=0", false);
	Header("Pragma: no-cache");
	Header('Content-Type: text/plain; charset=utf-8');
	ob_start('ob_gzhandler');
}
// Load module - it will execute pre-processing, or act upon request parameters
require_once($module);


