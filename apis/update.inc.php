<?php

$targetVersion = Database::getExpectedSchemaVersion();

// #######################

$res = Database::queryFirst("SELECT value FROM property WHERE name = 'webif-version' LIMIT 1", array(), true);

$currentVersion = (int) ($res === false ? 1 : $res['value']);

if ($currentVersion >= $targetVersion)
	die('Up to date :-)');

while ($currentVersion < $targetVersion) {

	$function = 'update_' . $currentVersion;

	if (!function_exists($function))
		die("Don't know how to update from version $currentVersion to $targetVersion :-(");

	if (!$function())
		die("Update from $currentVersion to $targetVersion failed! :-(");

	$currentVersion++;

	$ret = Database::exec("INSERT INTO property (name, value) VALUES ('webif-version', :version) ON DUPLICATE KEY UPDATE value = VALUES(value)", array('version' => $currentVersion), false);
	if ($ret === false)
		die('Writing version information back to DB failed. Next update will probably break.');

	if ($currentVersion < $targetVersion) {
		echo("Updated to $currentVersion...\n");
	}
}

Message::addSuccess('db-update-done');
Util::redirect('index.php?do=Main');

// The update functions. Number at the end refers to current version, the function will update to the next version
// #######################
// ##### 2014-05-28
// Add dateline field to property table

function update_1()
{
	$res = Database::simpleQuery("DESCRIBE property", array(), false);
	$type = false;
	if ($res === false)
		return;
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		if ($row['Field'] !== 'dateline')
			continue;
		$type = $row['Type'];
		break;
	}
	if ($type === false) {
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
	$res = Database::simpleQuery("show tables", array(), false);
	$found = false;
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		if ($row['Tables_in_openslx'] !== 'news')
			continue;
		$found = true;
		break;
	}
	if ($found === false) {
		// create table
		Database::exec("CREATE TABLE `news` (
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
	$res = Database::simpleQuery("DESCRIBE setting", array(), false);
	if ($res !== false) {
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			switch ($row['Field']) {
				case 'de':
				case 'en':
				case 'pt':
				case 'description':
					Database::exec("ALTER TABLE setting DROP {$row['Field']}");
					break;
			}
		}
	}
	$res = Database::simpleQuery("DESCRIBE cat_setting", array(), false);
	if ($res !== false) {
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			switch ($row['Field']) {
				case 'de':
				case 'en':
				case 'pt':
				case 'name':
					Database::exec("ALTER TABLE cat_setting DROP {$row['Field']}");
					break;
			}
		}
	}
	return true;
}

// #######################
// ##### 2014-08-18
// Remove description column from permission table, add eventlog table
function update_4()
{
	$res = Database::simpleQuery("DESCRIBE permission", array(), false);
	if ($res !== false) {
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			switch ($row['Field']) {
				case 'description':
					Database::exec("ALTER TABLE permission DROP {$row['Field']}");
					break;
			}
		}
	}
	$res = Database::simpleQuery("show tables", array(), false);
	$found = false;
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		if ($row['Tables_in_openslx'] !== 'eventlog')
			continue;
		$found = true;
		break;
	}
	if ($found === false) {
		// create table
		Database::exec("CREATE TABLE `eventlog` (
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
