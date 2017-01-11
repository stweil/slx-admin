<?php

// locationid trigger
$addTrigger = false;

$res = array();

// The main statistic table used for log entries

$res[] = tableCreate('statistic', "
  `logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateline` int(10) unsigned NOT NULL,
  `typeid` varchar(30) NOT NULL,
  `clientip` varchar(40) NOT NULL,
  `machineuuid` varchar(36) CHARACTER SET ascii DEFAULT NULL,
  `username` varchar(30) NOT NULL,
  `data` varchar(255) NOT NULL,
  PRIMARY KEY (`logid`),
  KEY `dateline` (`dateline`),
  KEY `logtypeid` (`typeid`,`dateline`),
  KEY `clientip` (`clientip`,`dateline`),
  KEY `machineuuid` (`machineuuid`,`dateline`)
");

// Main table containing all known clients

$res[] = tableCreate('machine', "
  `machineuuid` char(36) CHARACTER SET ascii NOT NULL,
  `fixedlocationid` int(11) DEFAULT NULL           COMMENT 'Manually set location (e.g. roomplanner)',
  `subnetlocationid` int(11) DEFAULT NULL          COMMENT 'Automatically determined location (e.g. from subnet match),
  `locationid` int(11) DEFAULT NULL                COMMENT 'Will be automatically set to fixedlocationid if not null, subnetlocationid otherwise',
  `macaddr` char(17) CHARACTER SET ascii NOT NULL,
  `clientip` varchar(45) CHARACTER SET ascii NOT NULL,
  `firstseen` int(10) unsigned NOT NULL,
  `lastseen` int(10) unsigned NOT NULL,
  `logintime` int(10) unsigned NOT NULL,
  `position` varchar(200) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
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
  `currentsession` varchar(120) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `currentuser` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
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
");

// PCI-ID cache

$res[] = tableCreate('pciid', "
	`category` enum('CLASS','VENDOR','DEVICE') NOT NULL,
	`id` varchar(10) CHARACTER SET ascii NOT NULL,
	`value` varchar(200) NOT NULL,
	`dateline` int(10) unsigned NOT NULL,
	PRIMARY KEY (`category`,`id`)
");

if (in_array(UPDATE_DONE, $res)) {
	$addTrigger = true;
}

//
// This was added/changed later -- keep update path
//

// 2015-12-21: Add machine uuid column to statistics table
if (!tableHasColumn('statistic', 'machineuuid')) {
	$ret = Database::exec('ALTER TABLE statistic'
		. ' ADD COLUMN `machineuuid` varchar(36) CHARACTER SET ascii DEFAULT NULL AFTER clientip,'
		. ' ADD INDEX `machineuuid` (`machineuuid`,`dateline`)');
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding machineuuid to statistic failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// Rename roomid to locationid
if (tableHasColumn('machine', 'roomid')) {
	$ret = Database::exec("ALTER TABLE `machine` CHANGE `roomid` `locationid` INT(11) DEFAULT NULL") !== false;
	$ret = Database::exec("ALTER TABLE `machine` DROP `roomid`") !== false || $ret;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Renaming roomid to locationid in statistic failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// 2016-08-31: Add lectureid and user name
if (!tableHasColumn('machine', 'currentsession')) {
	$ret = Database::exec("ALTER TABLE `machine` ADD COLUMN `currentsession` varchar(120) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL AFTER hostname") !== false;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding currentsession to machine failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}
if (!tableHasColumn('machine', 'currentuser')) {
	$ret = Database::exec("ALTER TABLE `machine` ADD COLUMN `currentuser` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL AFTER currentsession") !== false;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding currentuser to machine failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}
// 2016-09-01: Fix position column size
$ret = Database::exec("ALTER TABLE `machine` CHANGE `position` `position` VARCHAR( 200 ) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL");
if ($ret === false) {
	finalResponse(UPDATE_FAILED, 'Expanding position column failed: ' . Database::lastError());
}

// 2016-12-06:
// Add subnetlocationid - contains automatically determined location (by subnet)
if (!tableHasColumn('machine', 'subnetlocationid')) {
	$ret = Database::exec('ALTER TABLE machine'
		. ' ADD COLUMN `subnetlocationid` int(11) DEFAULT NULL AFTER `machineuuid`');
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding subnetlocationid to machine failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
	$addTrigger = true;
}
// And fixedlocationid - manually set location, currently used by roomplanner
if (!tableHasColumn('machine', 'fixedlocationid')) {
	$ret = Database::exec('ALTER TABLE machine'
		. ' ADD COLUMN `fixedlocationid` int(11) DEFAULT NULL AFTER `machineuuid`');
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding fixedlocationid to machine failed: ' . Database::lastError());
	}
	// Now copy over the values from locationid, since this was used before
	Database::exec("UPDATE machine SET fixedlocationid = locationid");
	$res[] = UPDATE_DONE;
	$addTrigger = true;
}
// If any of these was added, create the trigger
if ($addTrigger) {
	$ret = Database::exec(" 
	CREATE TRIGGER set_automatic_locationid
		BEFORE UPDATE ON machine FOR EACH ROW
	BEGIN
		SET NEW.locationid = If(NEW.fixedlocationid IS NULL, NEW.subnetlocationid, NEW.fixedlocationid);
	END");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding locationid trigger to machine failed: ' . Database::lastError());
	}
	// This might be an update - calculate all subnetlocationid values (if location module is installed yet)
	if (Module::isAvailable('locations')) {
		if (tableExists('subnet')) {
			AutoLocation::rebuildAll();
		} else {
			finalResponse(UPDATE_RETRY, 'Locations module not installed yet, retry later');
		}
	}
}

// Create response

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
