<?php

$res = array();

$res[] = tableCreate('callback', "
	`taskid` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`dateline` int(10) unsigned NOT NULL,
	`cbfunction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`args` text NOT NULL,
	PRIMARY KEY (`taskid`,`cbfunction`),
	KEY `dateline` (`dateline`)
");

$res[] = tableCreate('permission', "
	`mask` int(10) unsigned NOT NULL,
	`identifier` varchar(32) NOT NULL,
	PRIMARY KEY (`mask`),
	UNIQUE KEY `identifier` (`identifier`)
");

$res[] = tableCreate('property', "
	`name` varchar(50) NOT NULL,
	`dateline` int(10) unsigned NOT NULL DEFAULT '0',
	`value` text NOT NULL,
	PRIMARY KEY (`name`),
	KEY `dateline` (`dateline`)
");

$res[] = tableCreate('property_list', "
	`name` varchar(50) NOT NULL,
	`dateline` int(10) unsigned NOT NULL DEFAULT '0',
	`value` text NOT NULL,
	KEY (`name`),
	KEY `dateline` (`dateline`)
");

$res[] = tableCreate('user', "
	`userid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`login` varchar(100) NOT NULL,
	`passwd` varchar(150) NOT NULL,
	`fullname` varchar(100) DEFAULT NULL,
	`phone` varchar(100) DEFAULT NULL,
	`email` varchar(100) DEFAULT NULL,
	`permissions` int(10) unsigned NOT NULL,
	`lasteventid` int(10) unsigned NOT NULL DEFAULT '0',
	`serverid` int(10) unsigned NULL DEFAULT NULL,
	PRIMARY KEY (`userid`),
	UNIQUE KEY `login` (`login`)
");

// Update path

// #######################
// ##### 2014-05-28
// Add dateline field to property table
if (!tableHasColumn('property', 'dateline')) {
	Database::exec("ALTER TABLE `property` ADD `dateline` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0' AFTER `name` , ADD INDEX ( `dateline` )");
}

// #######################
// ##### 2014-08-18
// Remove description column from permission table
tableDropColumn('permission', 'description');
// Add details column to eventlog table
if (!tableHasColumn('user', 'lasteventid')) {
	Database::exec("ALTER TABLE `user` ADD `lasteventid` INT(10) UNSIGNED NOT NULL DEFAULT '0'");
}

// #######################
// ##### 2015-01-16
// Extend config module db table, add argument feature to callbacks
if (!tableHasColumn('callback', 'args')) {
	Database::exec("ALTER TABLE `callback` ADD `args` TEXT NOT NULL DEFAULT ''");
}

// #######################
// ##### 2018-03-19
// In preparation for LDAP/AD auth: Column to rembember origin server
if (!tableHasColumn('user', 'serverid')) {
	Database::exec("ALTER TABLE `user` ADD `serverid` int(10) unsigned NULL DEFAULT NULL");
}

// Make sure that if any users exist, one of the has UID=1, otherwise if the permission module is
// used we'd lock out everyone
$someUser = Database::queryFirst('SELECT userid FROM user ORDER BY userid ASC LIMIT 1');
if ($someUser !== false && (int)$someUser['userid'] !== 1) {
	Database::exec('UPDATE user SET userid = 1 WHERE userid = :oldid', ['oldid' => $someUser['userid']]);
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
