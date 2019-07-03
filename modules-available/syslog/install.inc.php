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
	KEY `machineuuidnew` (`machineuuid`,`logid`)
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
if (tableGetIndex('clientlog', ['machineuuid']) !== false) {
	$r = Database::exec("ALTER TABLE `clientlog` DROP INDEX `machineuuid`");
	$res[] = $r === false ? UPDATE_FAILED : UPDATE_DONE;
}
if (tableGetIndex('clientlog', ['machineuuid', 'logid']) === false) {
	$r = Database::exec("ALTER TABLE `clientlog`
		ADD INDEX `machineuuid` ( `machineuuid` , `logid` )");
	$res[] = $r === false ? UPDATE_FAILED : UPDATE_DONE;
}

// 2019-02-20: Add constraint for machineuuid
if (tableExists('machine')) {
	$rr = tableAddConstraint('clientlog', 'machineuuid', 'machine', 'machineuuid',
		'ON DELETE SET NULL ON UPDATE CASCADE', true);
	if ($rr === UPDATE_FAILED) {
		// The table might still be populated with orphaned rows
		$dups = Database::queryColumnArray("SELECT DISTINCT l.machineuuid FROM clientlog l LEFT JOIN machine m USING (machineuuid) WHERE m.machineuuid IS NULL");
		if (!empty($dups)) {
			Database::exec("UPDATE clientlog SET machineuuid = NULL WHERE machineuuid IN (:dups)", ['dups' => $dups]);
			$rr = tableAddConstraint('clientlog', 'machineuuid', 'machine', 'machineuuid',
				'ON DELETE SET NULL ON UPDATE CASCADE');
		}
	}
	$res[] = $rr;
} elseif (Module::get('statistics') !== false) {
	$res[] = UPDATE_RETRY;
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
