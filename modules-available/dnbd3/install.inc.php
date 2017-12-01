<?php

$res = array();

$res[] = tableCreate('dnbd3_server', "
	`serverid` int(11) NOT NULL AUTO_INCREMENT,
	`machineuuid` char(36) CHARACTER SET ascii DEFAULT NULL,
	`fixedip` varchar(45) CHARACTER SET ascii DEFAULT NULL,
	`runid` bigint(20) NOT NULL DEFAULT '0',
	`lastseen` bigint(20) NOT NULL DEFAULT '0',
	`uptime` bigint(20) NOT NULL DEFAULT '0',
	`totalup` bigint(20) NOT NULL DEFAULT '0',
	`totaldown` bigint(20) NOT NULL DEFAULT '0',
	`lastup` bigint(20) NOT NULL DEFAULT '0',
	`lastdown` bigint(20) NOT NULL DEFAULT '0',
	`clientcount` int(11) NOT NULL DEFAULT '0',
	`disktotal` bigint(20) NOT NULL DEFAULT '0',
	`diskfree` bigint(20) NOT NULL DEFAULT '0',
	`errormsg` varchar(200) DEFAULT NULL,
	PRIMARY KEY (`serverid`),
	UNIQUE KEY `machineuuid` (`machineuuid`),
	UNIQUE KEY `fixedip` (`fixedip`)
");

$res[] = tableCreate('dnbd3_server_x_location', '
	`serverid` int(11) NOT NULL,
	`locationid` int(11) NOT NULL,
	PRIMARY KEY (`serverid`,`locationid`),
	KEY `locationid` (`locationid`)
');

$res[] = tableAddConstraint('dnbd3_server', 'machineuuid', 'machine', 'machineuuid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

$res[] = tableAddConstraint('dnbd3_server_x_location', 'locationid', 'location', 'locationid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

$res[] = tableAddConstraint('dnbd3_server_x_location', 'serverid', 'dnbd3_server', 'serverid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

responseFromArray($res);
