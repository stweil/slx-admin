<?php

$res = array();

$res[] = tableCreate('location_info', '
	`locationid` INT(11) NOT NULL,
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`openingtime` VARCHAR(2000) NOT NULL,
	`config` VARCHAR(2000) NOT NULL,
	`calendar` VARCHAR(2000) NOT NULL,
	PRIMARY KEY (`locationid`)
');

// Create response for browser

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

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
