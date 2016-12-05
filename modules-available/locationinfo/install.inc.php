<?php

$res = array();

$res[] = tableCreate('location_info', '
 `locationid` INT(11) NOT NULL,
  `serverid` INT(11) NOT NULL,
	`serverroomid` INT(11) NOT NULL,
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`openingtime` VARCHAR(2000) NOT NULL,
	`config` VARCHAR(2000) NOT NULL,
	`calendar` VARCHAR(2000) NOT NULL,
	PRIMARY KEY (`locationid`)
');

$res[] = tableCreate('setting_location_info', '
	`serverid` int(10) NOT NULL AUTO_INCREMENT,
	`servername` VARCHAR(2000) NOT NULL,
	`serverurl` VARCHAR(2000) NOT NULL,
	`servertype` VARCHAR(100) NOT NULL,
	`login` VARCHAR(100) NOT NULL,
	`passwd` VARCHAR(150) NOT NULL,
	PRIMARY KEY (`serverid`)
');

// Create response for browser

if(tableExists('locationinfo')) {
	$ret = Database::exec("DROP TABLE `locationinfo`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Droping table locationinfo failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('location_info', 'config')) {
	$ret = Database::exec("ALTER TABLE `location_info` ADD `config` VARCHAR(2000) NOT NULL DEFAULT '' AFTER `openingtime`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding config to location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('location_info', 'calendar')) {
	$ret = Database::exec("ALTER TABLE `location_info` ADD `calendar` VARCHAR(2000) NOT NULL DEFAULT '' AFTER `config`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding calendar to location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('location_info', 'serverid')) {
	$ret = Database::exec("ALTER TABLE `location_info` ADD `serverid` INT(11) NOT NULL AFTER `locationid`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding serverid to location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('location_info', 'serverroomid')) {
	$ret = Database::exec("ALTER TABLE `location_info` ADD `serverroomid` INT(11) NOT NULL AFTER `serverid`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding serverroomid to location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
