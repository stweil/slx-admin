<?php

$res = array();

$res[] = tableCreate('configtgz', "
	`configid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`title` varchar(200) NOT NULL,
	`filepath` varchar(255) NOT NULL,
	`status` enum('OK','OUTDATED','MISSING') NOT NULL DEFAULT 'MISSING',
	PRIMARY KEY (`configid`)
");

$res[] = tableCreate('configtgz_module', "
	`moduleid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`title` varchar(200) NOT NULL,
	`moduletype` varchar(16) NOT NULL,
	`filepath` varchar(250) NOT NULL,
	`contents` text NOT NULL,
	`version` int(10) unsigned NOT NULL DEFAULT '0',
	`status` enum('OK','MISSING','OUTDATED') NOT NULL DEFAULT 'MISSING',
	PRIMARY KEY (`moduleid`),
	KEY `title` (`title`),
	KEY `moduletype` (`moduletype`,`title`)
");

$res[] = tableCreate('configtgz_x_module', "
	`configid` int(10) unsigned NOT NULL,
	`moduleid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`configid`,`moduleid`),
	KEY `moduleid` (`moduleid`)
");

$res[] = tableCreate('configtgz_location', "
	`locationid` int(11) NOT NULL,
	`configid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`locationid`),
	KEY `configid` (`configid`)
");

// Constraints
if (in_array(UPDATE_DONE, $res)) {
	$ret = Database::exec("ALTER TABLE `configtgz_x_module`
		ADD CONSTRAINT `configtgz_x_module_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `configtgz` (`configid`)
		ON DELETE CASCADE");
	$ret = Database::exec("ALTER TABLE `configtgz_x_module`
		ADD CONSTRAINT `configtgz_x_module_ibfk_2` FOREIGN KEY (`moduleid`) REFERENCES `configtgz_module` (`moduleid`)") || $ret;
	$ret = Database::exec("ALTER TABLE `configtgz_location`
		ADD CONSTRAINT `configtgz_location_fk_configid` FOREIGN KEY ( `configid` ) REFERENCES `openslx`.`configtgz` (`configid`)
		ON DELETE CASCADE ON UPDATE CASCADE") || $ret;
	if ($ret) {
		$res[] = UPDATE_DONE;
	}
}

// Update path

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
	$res[] = UPDATE_DONE;
}
if (!tableHasColumn('configtgz_module', 'status')) {
	if (Database::exec("ALTER TABLE `configtgz_module` ADD `status` ENUM( 'OK', 'MISSING', 'OUTDATED' ) NOT NULL DEFAULT 'MISSING'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add status to configtgz_module: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}
if (!tableHasColumn('configtgz', 'status')) {
	if (Database::exec("ALTER TABLE `configtgz` ADD `status` ENUM( 'OK', 'OUTDATED', 'MISSING' ) NOT NULL DEFAULT 'MISSING'") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add status to configtgz: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// ----- rebuild AD configs ------
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

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
