<?php

$targetVersion = 2;

// #######################

$res = Database::queryFirst("SELECT value FROM property WHERE name = 'webif-version' LIMIT 1", array(), true);

$currentVersion = (int) ($res === false ? 1 : $res['value']);

if ($currentVersion >= $targetVersion)
	die('Up to date :-)');

$function = 'update_' . $currentVersion;

if (!function_exists($function))
	die("Don't know how to update from version $currentVersion to $targetVersion :-(");

if (!$function())
	die("Update from $currentVersion to $targetVersion failed! :-(");

$currentVersion++;

$ret = Database::exec("INSERT INTO property (name, value) VALUES ('webif-version', :version)", array('version' => $currentVersion), true);
if ($ret === false)
	die('Writing version information back to DB failed. Next update will probably break.');

if ($currentVersion < $targetVersion) {
	Header('Location: api.php?do=update&random=' . mt_rand());
	die("Updated to $currentVersion - press F5 to continue");
}

die("Updated to $currentVersion");

// #######################

// ##### 2014-05-28
// Add dateline field to property table

function update_1()
{
	$res = Database::simpleQuery("DESCRIBE property", array(), false);
	$type = false;
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		if ($row['Field'] !== 'dateline') continue;
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


// ################