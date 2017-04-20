<?php

$res = array();

// TODO: serverid NULL, constraint to serverlist on delete set NULL
$res[] = tableCreate('locationinfo_locationconfig', '
	`locationid` INT(10) UNSIGNED NOT NULL,
	`serverid` INT(11) NOT NULL,
	`serverlocationid` VARCHAR(150),
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`openingtime` BLOB,
	`config` BLOB,
	`calendar` BLOB,
	`lastcalendarupdate` INT(11) NOT NULL DEFAULT 0,
	PRIMARY KEY (`locationid`)
');

// TODO: KEY `servername` (`servername`)
$res[] = tableCreate('locationinfo_coursebackend', '
	`serverid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`servername` VARCHAR(200) NOT NULL,
	`servertype` VARCHAR(100) NOT NULL,
	`credentials` BLOB,
	`error` VARCHAR(1000),
	PRIMARY KEY (`serverid`)
');

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
