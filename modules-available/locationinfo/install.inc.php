<?php

$res = array();

$t1 = $res[] = tableCreate('locationinfo_locationconfig', '
	`locationid` INT(10) UNSIGNED NOT NULL,
	`serverid` INT(10) UNSIGNED,
	`serverlocationid` VARCHAR(150),
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`openingtime` BLOB,
	`config` BLOB,
	`calendar` BLOB,
	`lastcalendarupdate` INT(11) NOT NULL DEFAULT 0,
	PRIMARY KEY (`locationid`)
');

$t2 = $res[] = tableCreate('locationinfo_coursebackend', '
	`serverid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`servername` VARCHAR(200) NOT NULL,
	`servertype` VARCHAR(100) NOT NULL,
	`credentials` BLOB,
	`error` VARCHAR(1000),
	PRIMARY KEY (`serverid`)
');

// Update

if ($t1 === UPDATE_NOOP) {
	Database::exec("ALTER TABLE locationinfo_locationconfig CHANGE `serverid` `serverid` INT(10) UNSIGNED NULL");
}
if ($t1 === UPDATE_DONE || $t2 === UPDATE_DONE) {
	Database::exec('UPDATE locationinfo_locationconfig SET serverid = NULL WHERE serverid = 0');
	Database::exec('ALTER TABLE `locationinfo_locationconfig` ADD CONSTRAINT `locationinfo_locationconfig_ibfk_1` FOREIGN KEY ( `serverid` )
			REFERENCES `openslx`.`locationinfo_coursebackend` (`serverid`) ON DELETE SET NULL ON UPDATE CASCADE');
}
if ($t1 === UPDATE_DONE) {
	if (false === Database::exec('ALTER TABLE `locationinfo_locationconfig` ADD CONSTRAINT `locationinfo_locationconfig_ibfk_2` FOREIGN KEY ( `locationid` )
			REFERENCES `openslx`.`location` (`locationid`) ON DELETE CASCADE ON UPDATE CASCADE')) {
		$res[] = UPDATE_RETRY;
	}
}

// Create response for browser

if (in_array(UPDATE_RETRY, $res)) {
	finalResponse(UPDATE_RETRY, 'Please retry: ' . Database::lastError());
}
if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
