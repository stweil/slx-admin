<?php

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
");

// PCI-ID cache

$res[] = tableCreate('pciid', "
	`category` enum('CLASS','VENDOR','DEVICE') NOT NULL,
	`id` varchar(10) CHARACTER SET ascii NOT NULL,
	`value` varchar(200) NOT NULL,
	`dateline` int(10) unsigned NOT NULL,
	PRIMARY KEY (`category`,`id`)
");

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

if (tableHasColumn('machine', 'roomid')) {
	$ret = Database::exec("ALTER TABLE `machine` CHANGE `roomid` `locationid` INT(11) DEFAULT NULL") !== false;
	$ret = Database::exec("ALTER TABLE `machine` DROP `roomid`") !== false || $ret;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Renaming roomid to locationid in statistic failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// Create response

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
