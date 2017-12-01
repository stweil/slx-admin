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
 * @param string $status one of the UPDATE_* status codes
 * @param string $message Human readable description of the status (optional)
 */
function finalResponse($status, $message = '')
{
	if (!DIRECT_MODE && AJAX) {
		echo json_encode(array('status' => $status, 'message' => $message));
	} else {
		echo 'STATUS=', $status, "\n";
		echo 'MESSAGE=', str_replace("\n", " -- ", $message);
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
	while ($row = $res->fetch(PDO::FETCH_NUM)) {
		if ($row[0] === $table)
			return true;
	}
	return false;
}

function tableRename($old, $new) {
	$res = Database::simpleQuery("RENAME TABLE $old TO $new", []); 
	return $res;
}


/**
 * Get all constraints from given table+column to another table+column.
 *
 * @param string $table source table, being constrained
 * @param string $column source column
 * @param string $refTable referenced table, dictating the constraints
 * @param string $refColumn referenced column
 * @return false|string[] list of constraints matching the request, false on error
 */
function tableGetConstraints($table, $column, $refTable, $refColumn)
{
	$db = 'openslx';
	if (defined('CONFIG_SQL_DB')) {
		$db = CONFIG_SQL_DB;
	} elseif (defined('CONFIG_SQL_DSN')) {
		if (preg_match('/dbname\s*=\s*([^;\s]+)\s*(;|$)/i', CONFIG_SQL_DSN, $out)) {
			$db = $out[1];
			define('CONFIG_SQL_DB', $db);
		}
	}
	$res = Database::simpleQuery('SELECT `CONSTRAINT_NAME` FROM information_schema.KEY_COLUMN_USAGE'
		. ' WHERE `TABLE_SCHEMA` = :db AND `TABLE_NAME` = :table AND `COLUMN_NAME` = :column'
		. ' AND `REFERENCED_TABLE_NAME` = :refTable AND `REFERENCED_COLUMN_NAME` = :refColumn',
		compact('db', 'table', 'column', 'refTable', 'refColumn'));
	if ($res === false)
		return false;
	return $res->fetchAll(PDO::FETCH_COLUMN, 0);
}

/**
 * Because I'm stupid and can't type properly.
 */
function tableGetContraints($table, $column, $refTable, $refColumn)
{
	return tableGetConstraints($table, $column, $refTable, $refColumn);
}

/**
 * Add constraint to table if it doesn't exist already.
 * On failure, trigger finalResponse with error message.
 *
 * @param string $table table to add constraint to
 * @param string $column foreign key column of that table
 * @param string $refTable destination table
 * @param string $refColumn primary key column in destination table
 * @param string $actions "ON xxx ON yyy" string
 * @return string UPDATE_* result code
 */
function tableAddConstraint($table, $column, $refTable, $refColumn, $actions)
{
	$test = tableExists($refTable) && tableHasColumn($refTable, $refColumn);
	if ($test === false) {
		// Most likely, destination table does not exist yet or isn't up to date
		return UPDATE_RETRY;
	}
	$test = tableGetConstraints($table, $column, $refTable, $refColumn);
	if ($test === false) {
		// Should never happen!?
		finalResponse(UPDATE_FAILED, 'DB: Cannot query constraints: ' . Database::lastError());
	}
	if (!empty($test)) {
		// Already exists
		return UPDATE_NOOP;
	}
	// Need to create
	$ret = Database::exec("ALTER TABLE `$table` ADD CONSTRAINT FOREIGN KEY (`$column`)
			REFERENCES `$refTable` (`$refColumn`)
			$actions");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'DB: Cannot add constraint: ' . Database::lastError());
	}
	return UPDATE_DONE;
}

/**
 * Drop constraint from a table.
 *
 * @param string $table table name
 * @param string $constraint constraint name
 * @return bool success indicator
 */
function tableDeleteConstraint($table, $constraint)
{
	return Database::exec("ALTER TABLE `$table` DROP FOREIGN KEY `$constraint`") !== false;
}

function tableCreate($table, $structure, $fatalOnError = true)
{
	if (tableExists($table)) {
		return UPDATE_NOOP;
	}
	$ret = Database::exec("CREATE TABLE IF NOT EXISTS `{$table}` ( {$structure} ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	if ($ret !== false) {
		return UPDATE_DONE;
	}
	if ($fatalOnError) {
		finalResponse(UPDATE_FAILED, 'DB-Error: ' . Database::lastError());
	}
	return UPDATE_FAILED;
}

function responseFromArray($array)
{
	if (in_array(UPDATE_FAILED, $array)) {
		finalResponse(UPDATE_FAILED, 'Update failed!');
	}
	if (in_array(UPDATE_RETRY, $array)) {
		finalResponse(UPDATE_RETRY, 'Temporary failure, will try again.');
	}
	if (in_array(UPDATE_DONE, $array)) {
		finalResponse(UPDATE_DONE, 'Tables created/updated successfully');
	}

	finalResponse(UPDATE_NOOP, 'Everything already up to date');

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
		<button onclick="slxRunInstall()" class="install-btn">Install/Upgrade</button>
		<br>
		<br>
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
		<button onclick="slxRunInstall()" class="install-btn">Install/Upgrade</button>
		<script src="script/jquery.js"></script>
		<script src="script/install.js"></script>
	</body>
</html>
HERE;

}
