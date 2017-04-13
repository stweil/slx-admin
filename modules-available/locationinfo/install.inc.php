<?php

$res = array();

// TODO: serverid NULL, constraint to serverlist on delete set NULL
$res[] = tableCreate('location_info', '
	`locationid` INT(11) NOT NULL,
	`serverid` INT(11) NOT NULL,
	`serverroomid` VARCHAR(2000),
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`openingtime` VARCHAR(2000),
	`config` VARCHAR(2000),
	`calendar` VARCHAR(2000),
	`lastcalendarupdate` INT(11) NOT NULL DEFAULT 0,
	PRIMARY KEY (`locationid`)
');

// TODO: KEY `servername` (`servername`)
$res[] = tableCreate('setting_location_info', '
	`serverid` int(10) NOT NULL AUTO_INCREMENT,
	`servername` VARCHAR(2000) NOT NULL,
	`serverurl` VARCHAR(2000) NOT NULL,
	`servertype` VARCHAR(100) NOT NULL,
	`credentials` VARCHAR(2000),
	`error` VARCHAR(2000),
	PRIMARY KEY (`serverid`)
');

// Create response for browser

if (!tableHasColumn('setting_location_info', 'credentials')) {
	$ret = Database::exec("ALTER TABLE `setting_location_info` ADD `credentials` VARCHAR(2000) AFTER `servertype`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding column credentials failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('setting_location_info', 'error')) {
	$ret = Database::exec("ALTER TABLE `setting_location_info` ADD `error` VARCHAR(2000) AFTER `credentials`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding column error failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('setting_location_info', 'login')) {
	$ret = Database::exec("ALTER TABLE `setting_location_info` DROP COLUMN login");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Dropping column login failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('setting_location_info', 'passwd')) {
	$ret = Database::exec("ALTER TABLE `setting_location_info` DROP COLUMN passwd");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Dropping column passwd failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('location_info', 'serverroomid')) {
	$ret = Database::exec("ALTER TABLE `location_info` MODIFY serverroomid VARCHAR(2000)");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Updateing column serverroomid failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('location_info', 'openingtime')) {
	$ret = Database::exec("ALTER TABLE `location_info` MODIFY openingtime VARCHAR(2000)");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Updateing column openingtime failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('location_info', 'config')) {
	$ret = Database::exec("ALTER TABLE `location_info` MODIFY config VARCHAR(2000)");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Updateing column config failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('location_info', 'calendar')) {
	$ret = Database::exec("ALTER TABLE `location_info` MODIFY calendar VARCHAR(2000)");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Updateing column calendar failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableHasColumn('location_info', 'lastcalendarupdate')) {
	$ret = Database::exec("ALTER TABLE `location_info` MODIFY lastcalendarupdate INT(11) NOT NULL DEFAULT 0");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Updateing column lastcalendarupdate failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (tableExists('locationinfo')) {
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

if (!tableHasColumn('location_info', 'lastcalendarupdate')) {
	$ret = Database::exec("ALTER TABLE `location_info` ADD `lastcalendarupdate` INT(11) NOT NULL AFTER `calendar`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding lastcalendarupdate to location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('setting_location_info', 'servername')) {
	$ret = Database::exec("ALTER TABLE `setting_location_info` ADD `servername` VARCHAR(2000) NOT NULL AFTER `serverid`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding servername to setting_location_info failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
