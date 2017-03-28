<?php

$res = array();

$res[] = tableCreate('role', "
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`name` varchar(200) NOT NULL,
	PRIMARY KEY (`id`)
");

$res[] = tableCreate('userXrole', "
	`userid` int(10) unsigned NOT NULL,
	`roleid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`userid`, `roleid`)
");

$res[] = tableCreate('roleXlocation', "
	`roleid` int(10) unsigned NOT NULL,
	`locid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`roleid`, `locid`)
");

$res[] = tableCreate('roleXpermission', "
	`roleid` int(10) unsigned NOT NULL,
	`permissionid`int(10) unsigned NOT NULL,
	PRIMARY KEY (`roleid`, `permissionid`)
");
