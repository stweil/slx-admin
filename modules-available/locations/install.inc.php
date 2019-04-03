<?php

$res = array();

$res[] = tableCreate('setting_location', '
	`locationid` INT(11) NOT NULL,
	`setting` VARCHAR(28) NOT NULL,
	`value` TEXT NOT NULL,
	`displayvalue` TEXT NOT NULL,
	PRIMARY KEY (`locationid`,`setting`),
	KEY `setting` (`setting`)
');

$res[] = tableCreate('location', '
	`locationid` INT(11) NOT NULL AUTO_INCREMENT,
	`parentlocationid` INT(11) NOT NULL,
	`locationname` VARCHAR(100) NOT NULL,
	PRIMARY KEY (`locationid`),
	KEY `locationname` (`locationname`),
	KEY `parentlocationid` (`parentlocationid`)
');

$res[] = tableCreate('subnet', '
	`subnetid` INT(11) NOT NULL AUTO_INCREMENT,
	`startaddr` DECIMAL(39,0) UNSIGNED NOT NULL,
	`endaddr` DECIMAL(39,0) UNSIGNED NOT NULL,
	`locationid` INT(11) NOT NULL,
	PRIMARY KEY (`subnetid`),
	KEY `startaddr` (`startaddr`,`endaddr`),
	KEY `locationid` (`locationid`)
');

$res[] = tableAddConstraint('subnet', 'locationid', 'location', 'locationid',
	'ON UPDATE CASCADE ON DELETE CASCADE');
$res[] = tableAddConstraint('setting_location', 'locationid', 'location', 'locationid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

// Create response for browser
responseFromArray($res);
