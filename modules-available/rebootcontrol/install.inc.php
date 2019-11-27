<?php

$output = array();

$output[] = tableCreate('reboot_subnet', "
	`subnetid` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`start` INT(10) UNSIGNED NOT NULL,
	`end` INT(10) UNSIGNED NOT NULL,
	`fixed` BOOL NOT NULL,
	`isdirect` BOOL NOT NULL,
	`lastdirectcheck` INT(10) UNSIGNED NOT NULL DEFAULT '0',
	`lastseen` INT(10) UNSIGNED NOT NULL DEFAULT '0',
	`seencount` INT(10) UNSIGNED NOT NULL DEFAULT '0',
	PRIMARY KEY (`subnetid`),
 	UNIQUE KEY `range` (`start`, `end`)");

$output[] = tableCreate('reboot_jumphost', "
	`hostid` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`host` VARCHAR(100) NOT NULL,
	`port` SMALLINT(10) UNSIGNED NOT NULL,
	`username` VARCHAR(30) NOT NULL,
	`reachable` BOOL NOT NULL,
	`sshkey` BLOB NOT NULL,
	`script` BLOB NOT NULL,
	PRIMARY KEY (`hostid`)");

$output[] = tableCreate('reboot_jumphost_x_subnet', "
	`hostid` INT(10) UNSIGNED NOT NULL,
	`subnetid` INT(10) UNSIGNED NOT NULL,
	PRIMARY KEY (`hostid`, `subnetid`)");

$output[] = tableCreate('reboot_subnet_x_subnet', "
	`srcid` INT(10) UNSIGNED NOT NULL,
	`dstid` INT(10) UNSIGNED NOT NULL,
	`reachable` BOOL NOT NULL,
	`lastcheck` INT(10) UNSIGNED NOT NULL DEFAULT '0',
	PRIMARY KEY (`srcid`, `dstid`),
	KEY `lastcheck` (`lastcheck`)");

$output[] = tableAddConstraint('reboot_jumphost_x_subnet', 'hostid', 'reboot_jumphost', 'hostid',
	'ON UPDATE CASCADE ON DELETE CASCADE');
$output[] = tableAddConstraint('reboot_jumphost_x_subnet', 'subnetid', 'reboot_subnet', 'subnetid',
	'ON UPDATE CASCADE ON DELETE CASCADE');
$output[] = tableAddConstraint('reboot_subnet_x_subnet', 'srcid', 'reboot_subnet', 'subnetid',
	'ON UPDATE CASCADE ON DELETE CASCADE');
$output[] = tableAddConstraint('reboot_subnet_x_subnet', 'dstid', 'reboot_subnet', 'subnetid',
	'ON UPDATE CASCADE ON DELETE CASCADE');

responseFromArray($output);