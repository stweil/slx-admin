<?php

$res = array();

$t1 = $res[] = tableCreate('locationinfo_locationconfig', '
	`locationid` INT(11) NOT NULL,
	`serverid` INT(10) UNSIGNED,
	`serverlocationid` VARCHAR(150),
	`openingtime` BLOB,
	`calendar` BLOB,
	`lastcalendarupdate` INT(10) UNSIGNED NOT NULL DEFAULT 0,
	`lastchange` int(10) UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`locationid`)
');

$t2 = $res[] = tableCreate('locationinfo_coursebackend', '
	`serverid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`servername` VARCHAR(200) NOT NULL,
	`servertype` VARCHAR(100) NOT NULL,
	`credentials` BLOB,
	`error` VARCHAR(500),
	PRIMARY KEY (`serverid`),
	KEY `servername` (`servername`)
');

$t3 = $res[] = tableCreate('locationinfo_panel', "
	`paneluuid` char(36) CHARACTER SET ascii NOT NULL,
	`panelname` varchar(30) NOT NULL,
	`locationids` varchar(100) CHARACTER SET ascii NOT NULL,
	`paneltype` enum('DEFAULT','SUMMARY', 'URL') NOT NULL,
	`panelconfig` blob NOT NULL,
	`lastchange` int(10) UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`paneluuid`),
	KEY `panelname` (`panelname`)
");

// Update

if ($t1 === UPDATE_NOOP) {
	if ($t3 === UPDATE_DONE) {
		// Upgrade from old beta version - convert panels
		Database::exec('INSERT INTO locationinfo_panel (paneluuid, panelname, locationids, paneltype, panelconfig, lastchange)'
			. " SELECT UUID(), Concat('Import: ', l.locationname), o.locationid, 'DEFAULT', o.config, 0 "
			. " FROM locationinfo_locationconfig o INNER JOIN location l USING (locationid)"
			. ' WHERE Length(o.config) > 10 OR Length(o.openingtime) > 10');
	}
	Database::exec("ALTER TABLE locationinfo_locationconfig CHANGE `serverid` `serverid` INT(10) UNSIGNED NULL");
	tableDropColumn('locationinfo_locationconfig', 'hidden');
	tableDropColumn('locationinfo_locationconfig', 'config');
	if (!tableHasColumn('locationinfo_locationconfig', 'lastchange')) {
		$ret = Database::exec('ALTER TABLE locationinfo_locationconfig ADD `lastchange` INT(10) UNSIGNED NOT NULL DEFAULT 0');
		if ($ret === false) {
			finalResponse(UPDATE_FAILED, 'Could not add lastchange field');
		} elseif ($ret > 0) {
			$res[] = UPDATE_DONE;
		}
	}
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

if ($t3 === UPDATE_NOOP) {
	Database::exec("ALTER TABLE `locationinfo_panel` CHANGE `paneltype`
		`paneltype` ENUM('DEFAULT', 'SUMMARY', 'URL') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL");
	// 2017-12-02 expand locationids column
	Database::exec("ALTER TABLE `locationinfo_panel` CHANGE `locationids`
		`locationids` varchar(100) CHARACTER SET ascii NOT NULL");
}

// 2017-07-26 Add servername key
Database::exec("ALTER TABLE `locationinfo_coursebackend` ADD KEY `servername` (`servername`)");

// Create response for browser

if (in_array(UPDATE_RETRY, $res)) {
	finalResponse(UPDATE_RETRY, 'Please retry: ' . Database::lastError());
}
if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
