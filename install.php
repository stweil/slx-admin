<?php

/*
 * Modular installation system.
 *
 * Since most of slx-admin is now modularized, it might not make too much sense to have
 * static central SQL dumps of the database scheme. Tables usually belong to specific modules,
 * so they should only be created if the module is actually in use.
 * Thus, we have a modular install system where modules should create (and update) database
 * tables that belong to them.
 * The system should not be abused to modify tables of other modules by adding or changing
 * columns, which could lead to a maintenance nightmare over time.
 *
 * The install/update mechanism could also be used to setup other requirements, like directories,
 * permissions etc. Each module's install hook should report back the status of the module's
 * requirements. For examples see some modules like baseconfig, main, statistics, ...
 *
 * Warning: Be careful which classes/functionality of slx-admin you use in your scripts, since
 * they might depend on some tables that do not exist yet. ;)
 */

/**
 * Report back the update status to the browser/client and terminate execution.
 * This has to be called by an update module at some point to signal the result
 * of its execution.
 *
 * @param $status one of the UPDATE_* status codes
 * @param string $message Human readable description of the status (optional)
 */
function finalResponse($status, $message = '')
{
	if (!DIRECT_MODE && AJAX) {
		echo json_encode(array('status' => $status, 'message' => $message));
	} else {
		echo 'STATUS=', $status, "\n";
		echo 'MESSAGE=', $message;
	}
	exit;
}

define('UPDATE_DONE', 'UPDATE_DONE'); // Process completed successfully. This is a success return code.
define('UPDATE_NOOP', 'UPDATE_NOOP'); // Nothing had to be done, everything is up to date. This is also a success code.
define('UPDATE_RETRY', 'UPDATE_RETRY'); // Install/update process failed, but should be retried later.
define('UPDATE_FAILED', 'UPDATE_FAILED'); // Fatal error occured, retry will not resolve the issue.

/*
 * Helper functions for dealing with the database
 */

function tableHasColumn($table, $column)
{
	$table = preg_replace('/\W/', '', $table);
	$column = preg_replace('/\W/', '', $column);
	$res = Database::simpleQuery("DESCRIBE `$table`", array(), true);
	if ($res !== false) {
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ((is_array($column) && in_array($row['Field'], $column)) || (is_string($column) && $row['Field'] === $column))
				return true;
		}
	}
	return false;
}

function tableDropColumn($table, $column)
{
	$table = preg_replace('/\W/', '', $table);
	$column = preg_replace('/\W/', '', $column);
	$res = Database::simpleQuery("DESCRIBE `$table`", array(), true);
	if ($res !== false) {
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ((is_array($column) && in_array($row['Field'], $column)) || (is_string($column) && $row['Field'] === $column))
				Database::exec("ALTER TABLE `$table` DROP `{$row['Field']}`");
		}
	}
}

function tableExists($table)
{
	$res = Database::simpleQuery("SHOW TABLES", array(), true);
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		if ($row['Tables_in_openslx'] === $table)
			return true;
	}
	return false;
}

/*
 * Rest of install script....
 */

if (!isset($_SERVER['REMOTE_ADDR']) || isset($_REQUEST['direct'])) {
	define('DIRECT_MODE', true);
} else {
	define('DIRECT_MODE', false);
}

define('AJAX', ((isset($_REQUEST['async'])) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')));

error_reporting(E_ALL);
chdir(dirname($_SERVER['SCRIPT_FILENAME']));

// Autoload classes from ./inc which adhere to naming scheme <lowercasename>.inc.php
spl_autoload_register(function ($class) {
	$file = 'inc/' . preg_replace('/[^a-z0-9]/', '', mb_strtolower($class)) . '.inc.php';
	if (!file_exists($file))
		return;
	require_once $file;
});

if (!is_readable('config.php')) {
	finalResponse(UPDATE_FAILED, 'config.php does not exist!');
}

require_once 'config.php';

if (CONFIG_SQL_PASS === '%MYSQL_OPENSLX_PASS%') {
	finalResponse(UPDATE_FAILED, 'mysql credentials not configured yet!');
}

// Explicitly connect to the database so it won't call Util::traceError() on failure
if (!Database::init(true)) {
	finalResponse(UPDATE_RETRY, 'Connecting to the database failed');
}

// Good to go so far

/**
 * @param \Module $module
 * @return bool
 */
function hasUpdateScript($module)
{
	return is_readable($module->getDir() . '/install.inc.php');
}

function runUpdateScript($module)
{
	require_once $module->getDir() . '/install.inc.php';
}

// Build dependency tree
Module::init();
$modules = Module::getEnabled();
if (empty($modules)) {
	finalResponse(UPDATE_NOOP, 'No active modules, nothing to do');
}

if (DIRECT_MODE) {
	//
	// Direct mode - plain
	$new = array();
	foreach ($modules as $entry) {
		if (hasUpdateScript($entry)) {
			$new[] = $entry;
		}
	}
	$modules = $new;
	if (empty($modules)) {
		finalResponse(UPDATE_NOOP, 'No modules with install scripts, nothing to do');
	}
	// Get array where the key maps a module identifier to the next module object
	$assoc = array();
	$count = count($modules);
	for ($i = 0; $i < $count; ++$i) {
		$assoc[$modules[$i]->getIdentifier()] = $modules[($i + 1) % $count];
	}

	if (!empty($argv[1])) {
		$last = $argv[1];
	} else {
		$last = Request::any('last', '', 'string');
	}
	if (!empty($last) && isset($assoc[$last])) {
		$module = $assoc[$last];
	}
	if (!isset($module)) {
		$module = $modules[0];
	}
	echo 'MODULE=', $module->getIdentifier(), "\n";
	runUpdateScript($module);
} else {
	//
	// Interactive web based mode
	$mod = Request::any('module', false, 'string');
	if ($mod !== false) {
		// Execute specific module
		$module = Module::get($mod, true);
		if ($module === false) {
			finalResponse(UPDATE_NOOP, 'Given module does not exist!');
		}
		if (!hasUpdateScript($module)) {
			finalResponse(UPDATE_NOOP, 'Given module has no install script');
		}
		runUpdateScript($module);
		finalResponse(UPDATE_DONE, 'Module did not report status; assuming OK');
	}
	// Show the page that shows status and triggers jobs
	echo <<<HERE
<!DOCTYPE html>
<html>
	<head>
		<title>Install/Update SLXadmin</title>
		<meta charset="utf-8"> 
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<style type="text/css">
		body, html {
			color: #000;
			background: #fff;
		}
		table, tr, td {
			border: 1px solid #ccc;
			border-collapse: collapse;
			padding: 3px;
		}
		button {
			font-weight: bold;
			padding: 8px;
		}
		</style>
	</head>
	<body>
		<h1>Modules</h1>
		<table>
			<tr><th>Module</th><th>Status</th></tr>
HERE;
	foreach ($modules as $module) {
		$id = $module->getIdentifier();
		echo "<tr><td class=\"id-col\">{$id}</td><td id=\"mod-{$id}\">Waiting...</td></tr>";
	}
	echo <<<HERE
		</table>
		<br><br>
		<button onclick="slxRunInstall()">Install/Upgrade</button>
		<script src="script/jquery.js"></script>
		<script src="script/install.js"></script>
	</body>
</html>
HERE;

}