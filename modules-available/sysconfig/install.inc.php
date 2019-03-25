<?php

$update = array();

$update[] = tableCreate('configtgz', "
	`configid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`title` varchar(200) NOT NULL,
	`filepath` varchar(255) NOT NULL,
	`status` enum('OK','OUTDATED','MISSING') NOT NULL DEFAULT 'MISSING',
	`dateline` int(10) unsigned NOT NULL DEFAULT '0',
	PRIMARY KEY (`configid`)
");

$update[] = tableCreate('configtgz_module', "
	`moduleid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`title` varchar(200) NOT NULL,
	`moduletype` varchar(16) NOT NULL,
	`filepath` varchar(250) NOT NULL,
	`contents` text NOT NULL,
	`version` int(10) unsigned NOT NULL DEFAULT '0',
	`status` enum('OK','MISSING','OUTDATED') NOT NULL DEFAULT 'MISSING',
	`dateline` int(10) unsigned NOT NULL DEFAULT '0',
	PRIMARY KEY (`moduleid`),
	KEY `title` (`title`),
	KEY `moduletype` (`moduletype`,`title`)
");

$update[] = tableCreate('configtgz_x_module', "
	`configid` int(10) unsigned NOT NULL,
	`moduleid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`configid`,`moduleid`),
	KEY `moduleid` (`moduleid`)
");

$update[] = tableCreate('configtgz_location', "
	`locationid` int(11) NOT NULL,
	`configid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`locationid`),
	KEY `configid` (`configid`)
");

// Constraints
if (in_array(UPDATE_DONE, $update)) {
	// To self
	$update[] = tableAddConstraint('configtgz_x_module', 'configid', 'configtgz', 'configid',
			'');
	$update[] = tableAddConstraint('configtgz_x_module', 'moduleid', 'configtgz_module', 'moduleid',
			'');
	$update[] = tableAddConstraint('configtgz_location', 'configid', 'configtgz', 'configid',
		'ON DELETE CASCADE ON UPDATE CASCADE');
}

// Update path

// #######################
// ##### 2014-12-12
// Rename config modules
Database::exec("UPDATE configtgz_module SET moduletype = 'Branding' WHERE moduletype = 'BRANDING'");
Database::exec("UPDATE configtgz_module SET moduletype = 'AdAuth' WHERE moduletype = 'AD_AUTH'");
Database::exec("UPDATE configtgz_module SET moduletype = 'CustomModule' WHERE moduletype = 'custom'");

// #######################
// ##### 2015-01-16
// Extend config module db tables
tableDropColumn('configtgz_module', 'haschanged');
if (!tableHasColumn('configtgz_module', 'version')) {
	if (Database::exec("ALTER TABLE `configtgz_module` ADD `version` INT( 10 ) UNSIGNED NOT NULL DEFAULT '0'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add version to configtgz_module: ' . Database::lastError());
	}
	$update[] = UPDATE_DONE;
}
if (!tableHasColumn('configtgz_module', 'status')) {
	if (Database::exec("ALTER TABLE `configtgz_module` ADD `status` ENUM( 'OK', 'MISSING', 'OUTDATED' ) NOT NULL DEFAULT 'MISSING'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add status to configtgz_module: ' . Database::lastError());
	}
	$update[] = UPDATE_DONE;
}
if (!tableHasColumn('configtgz', 'status')) {
	if (Database::exec("ALTER TABLE `configtgz` ADD `status` ENUM( 'OK', 'OUTDATED', 'MISSING' ) NOT NULL DEFAULT 'MISSING'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add status to configtgz: ' . Database::lastError());
	}
	$update[] = UPDATE_DONE;
}
if (!tableHasColumn('configtgz_module', 'dateline')) {
	if (Database::exec("ALTER TABLE `configtgz_module` ADD `dateline` int(10) unsigned NOT NULL DEFAULT '0'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add dateline to configtgz_module: ' . Database::lastError());
	}
	$update[] = UPDATE_DONE;
	// Infer from module's filemtime
	$res = Database::simpleQuery('SELECT moduleid, filepath FROM configtgz_module');
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		Database::exec('UPDATE configtgz_module SET dateline = :mtime WHERE moduleid = :moduleid',
			['moduleid' => $row['moduleid'], 'mtime' => filemtime($row['filepath'])]);
	}
}
if (!tableHasColumn('configtgz', 'dateline')) {
	if (Database::exec("ALTER TABLE `configtgz` ADD `dateline` int(10) unsigned NOT NULL DEFAULT '0'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add dateline to configtgz: ' . Database::lastError());
	}
	$update[] = UPDATE_DONE;
	// Infer from latest module (since module injection by slx-admin modules would alter the timestamp)
	$res = Database::simpleQuery('SELECT c.configid, Max(m.dateline) AS dateline FROM configtgz c
			INNER JOIN configtgz_x_module cxm USING (configid)
			INNER JOIN configtgz_module m USING (moduleid)
			GROUP BY configid');
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		Database::exec('UPDATE configtgz SET dateline = :mtime WHERE configid = :configid',
			['configid' => $row['configid'], 'mtime' => $row['dateline']]);
	}
}

// ----- rebuild configs ------
// TEMPORARY HACK; Rebuild configs.. move somewhere else?
Module::isAvailable('sysconfig');
$list = ConfigModule::getAll();
if ($list === false) {
	EventLog::warning('Could not regenerate AD/LDAP configs - please do so manually');
} else {
	foreach ($list as $ad) {
		if ($ad->needRebuild()) {
			$ad->generate(false);
		}
	}
}

// Create response for browser
responseFromArray($update);
