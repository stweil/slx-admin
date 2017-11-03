<?php

$res = array();

$res[] = $tc = tableCreate('clientlog', "
	`logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`dateline` int(10) unsigned NOT NULL,
	`logtypeid` varchar(30) NOT NULL,
	`clientip` varchar(40) NOT NULL,
	`machineuuid` varchar(36) CHARACTER SET ascii DEFAULT NULL,
	`description` varchar(255) NOT NULL,
	`extra` text NOT NULL,
	PRIMARY KEY (`logid`),
	KEY `dateline` (`dateline`),
	KEY `logtypeid` (`logtypeid`,`dateline`),
	KEY `clientip` (`clientip`,`dateline`),
	KEY `machineuuid` (`machineuuid`,`logid`)
");

// Update path

if (!tableHasColumn('clientlog', 'machineuuid')) {
	$ret = Database::exec('ALTER TABLE clientlog'
		. ' ADD COLUMN `machineuuid` varchar(36) CHARACTER SET ascii DEFAULT NULL AFTER clientip,'
		. ' ADD INDEX `machineuuid` (`machineuuid`,`dateline`)');
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding machineuuid to clientlog failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// 2017-11-03: Create proper index for query in statistics module
if ($tc !== UPDATE_DONE) {
	Database::exec("ALTER TABLE `openslx`.`clientlog` DROP INDEX `machineuuid` ,
			ADD INDEX `machineuuid` ( `machineuuid` , `logid` )");
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
