<?php

$targetVersion = Database::getExpectedSchemaVersion();

function fatal($message)
{
	if (tableExists('eventlog'))
		EventLog::failure($message);
	die("$message\n");
}

// #######################

$res = Database::queryFirst("SELECT value FROM property WHERE name = 'webif-version' LIMIT 1", array(), true);

$currentVersion = (int) ($res === false ? 1 : $res['value']);

if ($currentVersion >= $targetVersion)
	die('Up to date :-)');

while ($currentVersion < $targetVersion) {

	$function = 'update_' . $currentVersion;

	if (!function_exists($function))
		fatal("Don't know how to update from version $currentVersion to $targetVersion :-(");

	if (!$function())
		fatal("Update from $currentVersion to $targetVersion failed! :-(");

	$currentVersion++;

	$ret = Database::exec("INSERT INTO property (name, value) VALUES ('webif-version', :version) ON DUPLICATE KEY UPDATE value = VALUES(value)", array('version' => $currentVersion), false);
	if ($ret === false)
		fatal('Writing version information back to DB failed. Next update will probably break.');

	if ($currentVersion < $targetVersion) {
		echo("Updated to $currentVersion...\n");
	}
}

Message::addSuccess('db-update-done');
if (tableExists('eventlog'))
	EventLog::info("Database updated to version $currentVersion");
Util::redirect('index.php?do=Main');

////////////////

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

// The update functions. Number at the end refers to current version, the function will update to the next version
// #######################
// ##### 2014-05-28
// Add dateline field to property table

function update_1()
{
	if (!tableHasColumn('property', 'dateline')) {
		Database::exec("ALTER TABLE `property` ADD `dateline` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0' AFTER `name` , ADD INDEX ( `dateline` )");
	} else {
		Database::exec("ALTER TABLE `property` CHANGE `dateline` `dateline` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0'");
	}
	return true;
}

// #######################
// ##### 2014-06-05
// Add 'news' table to database schema
function update_2()
{
	if (!tableExists('news')) {
		// create table
		Database::exec("CREATE TABLE IF NOT EXISTS `news` (
			`newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`dateline` int(10) unsigned NOT NULL,
			`title` varchar(200) DEFAULT NULL,
			`content` text,
			PRIMARY KEY (`newsid`),
			KEY `dateline` (`dateline`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8
		");
	}
	return true;
}

// #######################
// ##### 2014-08-18
// Remove setting descriptions from DB, put into json files now
function update_3()
{
	tableDropColumn('setting', array('de', 'en', 'pt', 'description'));
	tableDropColumn('cat_setting', array('de', 'en', 'pt', 'name'));
	return true;
}

// #######################
// ##### 2014-08-18
// Remove description column from permission table, add eventlog table
function update_4()
{
	tableDropColumn('permission', 'description');
	if (!tableExists('eventlog')) {
		// create table
		Database::exec("CREATE TABLE IF NOT EXISTS `eventlog` (
			`logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`dateline` int(10) unsigned NOT NULL,
			`logtypeid` varchar(30) NOT NULL,
			`description` varchar(255) NOT NULL,
			PRIMARY KEY (`logid`),
			KEY `dateline` (`dateline`),
			KEY `logtypeid` (`logtypeid`,`dateline`)
		) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
		");
	}
	return true;
}

// #######################
// ##### 2014-08-18
// Add details column to eventlog table, add callback table
function update_5()
{
	if (!tableHasColumn('eventlog', 'extra'))
		Database::exec("ALTER TABLE `eventlog` ADD `extra` TEXT NOT NULL");
	if (!tableHasColumn('user', 'lasteventid'))
		Database::exec("ALTER TABLE `user` ADD `lasteventid` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0'");
	if (!tableExists('callback')) {
		Database::exec("CREATE TABLE IF NOT EXISTS `callback` (
			`taskid` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`dateline` int(10) unsigned NOT NULL,
			`cbfunction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			PRIMARY KEY (`taskid`,`cbfunction`),
			KEY `dateline` (`dateline`)
		 ) ENGINE=InnoDB DEFAULT CHARSET=utf8
		");
	}
	return true;
}

// #######################
// ##### 2014-12-04
// Add displayvalue column to setting_*
function update_6()
{
	foreach (array('setting_global', 'setting_distro') as $table) {
		if (!tableHasColumn($table, 'displayvalue')) {
			Database::exec("ALTER TABLE $table ADD `displayvalue` TEXT NOT NULL");
			Database::exec("UPDATE $table SET displayvalue = value");
		}
	}
	return true;
}

// #######################
// ##### 2014-12-12
// Rename config modules, add "has changed" column to modules
function update_7()
{
	if (!tableHasColumn('configtgz_module', 'haschanged'))
		Database::exec("ALTER TABLE configtgz_module ADD `haschanged` TINYINT DEFAULT '0'");
	Database::exec("UPDATE configtgz_module SET moduletype = 'Branding' WHERE moduletype = 'BRANDING'");
	Database::exec("UPDATE configtgz_module SET moduletype = 'AdAuth' WHERE moduletype = 'AD_AUTH'");
	Database::exec("UPDATE configtgz_module SET moduletype = 'CustomModule' WHERE moduletype = 'custom'");
	return true;
}
