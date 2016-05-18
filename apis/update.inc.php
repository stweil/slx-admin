<?php

//
// TODO: Modularize
//

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

// TEMPORARY HACK; Rebuild AD configs.. move somewhere else
$list = ConfigModule::getAll('AdAuth');
if ($list === false) {
	Message::addError('ad-config-failed');
} else {
	foreach ($list as $ad) {
		$ad->generate(false);
	}
}

Message::addSuccess('db-update-done');
if (tableExists('eventlog'))
	EventLog::info("Database updated to version $currentVersion");
DefaultData::populate();
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
// Rename config modules
function update_7()
{
	Database::exec("UPDATE configtgz_module SET moduletype = 'Branding' WHERE moduletype = 'BRANDING'");
	Database::exec("UPDATE configtgz_module SET moduletype = 'AdAuth' WHERE moduletype = 'AD_AUTH'");
	Database::exec("UPDATE configtgz_module SET moduletype = 'CustomModule' WHERE moduletype = 'custom'");
	return true;
}

// #######################
// ##### 2015-01-16
// Extend config module db table, add argument feature to callbacks
function update_8()
{
	tableDropColumn('configtgz_module', 'haschanged');
	if (!tableHasColumn('configtgz_module', 'version'))
		Database::exec("ALTER TABLE `configtgz_module` ADD `version` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0'");
	if (!tableHasColumn('configtgz_module', 'status'))
		Database::exec("ALTER TABLE `configtgz_module` ADD `status` ENUM( 'OK', 'MISSING', 'OUTDATED' ) NOT NULL DEFAULT 'MISSING'");
	if (!tableHasColumn('callback', 'args'))
		Database::exec("ALTER TABLE `callback` ADD `args` TEXT NOT NULL DEFAULT ''");
	if (!tableHasColumn('configtgz', 'status'))
		Database::exec("ALTER TABLE `configtgz` ADD `status` ENUM( 'OK', 'OUTDATED', 'MISSING' ) NOT NULL DEFAULT 'MISSING'");
	return true;
}

// #######################
// ##### 2015-05-21
// Add statistics table, for logging of session length and idle times
function update_9()
{
	Database::exec("CREATE TABLE IF NOT EXISTS `statistic` (
  `logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `typeid` varchar(30) NOT NULL,
  `clientip` varchar(40) NOT NULL,
  `username` varchar(30) NOT NULL,
  `data` varchar(255) NOT NULL,
  PRIMARY KEY (`logid`),
  KEY `dateline` (`dateline`),
  KEY `logtypeid` (`typeid`,`dateline`),
  KEY `clientip` (`clientip`,`dateline`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8");
	return true;
}

// #######################
// ##### 2015-12-21
// Add machine uuid column to statistics table
function update_10()
{
	if (!tableHasColumn('statistic', 'machineuuid')) {
		Database::exec('ALTER TABLE statistic'
			. ' ADD COLUMN `machineuuid` varchar(36) CHARACTER SET ascii DEFAULT NULL AFTER clientip,'
			. ' ADD INDEX `machineuuid` (`machineuuid`,`dateline`)');
	}
	Database::exec("CREATE TABLE IF NOT EXISTS `machine` (
  `machineuuid` char(36) CHARACTER SET ascii NOT NULL,
  `locationid` int(11) DEFAULT NULL,
  `macaddr` char(17) CHARACTER SET ascii NOT NULL,
  `clientip` varchar(45) CHARACTER SET ascii NOT NULL,
  `firstseen` int(10) unsigned NOT NULL,
  `lastseen` int(10) unsigned NOT NULL,
  `logintime` int(10) unsigned NOT NULL,
  `position` varchar(40) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `lastboot` int(10) unsigned NOT NULL,
  `realcores` smallint(5) unsigned NOT NULL,
  `mbram` int(10) unsigned NOT NULL,
  `kvmstate` enum('UNKNOWN','UNSUPPORTED','DISABLED','ENABLED') NOT NULL,
  `cpumodel` varchar(120) NOT NULL,
  `systemmodel` varchar(120) NOT NULL DEFAULT '',
  `id44mb` int(10) unsigned NOT NULL,
  `badsectors` int(10) unsigned NOT NULL,
  `data` mediumtext NOT NULL,
  `hostname` varchar(200) NOT NULL DEFAULT '',
  `notes` text,
  PRIMARY KEY (`machineuuid`),
  KEY `macaddr` (`macaddr`),
  KEY `clientip` (`clientip`),
  KEY `realcores` (`realcores`),
  KEY `mbram` (`mbram`),
  KEY `kvmstate` (`kvmstate`),
  KEY `id44mb` (`id44mb`),
  KEY `locationid` (`locationid`),
  KEY `lastseen` (`lastseen`),
  KEY `cpumodel` (`cpumodel`),
  KEY `systemmodel` (`systemmodel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	return true;
}

function update_11()
{
	if (tableHasColumn('machine', 'roomid')) {
		Database::exec("ALTER TABLE `machine` CHANGE `roomid` `locationid` INT(11) DEFAULT NULL");
	}
	Database::exec("CREATE TABLE IF NOT EXISTS `setting_location` (
		`locationid` int(11) NOT NULL,
		`setting` varchar(28) NOT NULL,
		`value` text NOT NULL,
		`displayvalue` text NOT NULL,
		PRIMARY KEY (`locationid`,`setting`),
		KEY `setting` (`setting`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	Database::exec("CREATE TABLE IF NOT EXISTS `location` (
		`locationid` int(11) NOT NULL AUTO_INCREMENT,
		`parentlocationid` int(11) NOT NULL,
		`locationname` varchar(100) NOT NULL,
		PRIMARY KEY (`locationid`),
		KEY `locationname` (`locationname`),
		KEY `parentlocationid` (`parentlocationid`)
	) ENGINE=InnoDB  DEFAULT CHARSET=utf8;");
	Database::exec("CREATE TABLE IF NOT EXISTS `subnet` (
		`subnetid` int(11) NOT NULL AUTO_INCREMENT,
		`startaddr` decimal(39,0) unsigned NOT NULL,
		`endaddr` decimal(39,0) unsigned NOT NULL,
		`locationid` int(11) NOT NULL,
		PRIMARY KEY (`subnetid`),
		KEY `startaddr` (`startaddr`,`endaddr`),
		KEY `locationid` (`locationid`)
	) ENGINE=InnoDB  DEFAULT CHARSET=utf8;");
	return true;
}

// TODO: Remove setting_distro, setting, cat_setting
// TODO: Add toggle column to setting_global