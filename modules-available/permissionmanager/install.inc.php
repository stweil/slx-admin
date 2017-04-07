<?php

$res = array();

$res[] = tableCreate('role', "
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`name` varchar(200) NOT NULL,
	PRIMARY KEY (`id`)
");

$res[] = tableCreate('user_x_role', "
	`userid` int(10) unsigned NOT NULL,
	`roleid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`userid`, `roleid`)
");

$res[] = tableCreate('role_x_location', "
	`roleid` int(10) unsigned NOT NULL,
	`locid` int(10) unsigned NOT NULL,
	PRIMARY KEY (`roleid`, `locid`)
");

$res[] = tableCreate('role_x_permission', "
	`roleid` int(10) unsigned NOT NULL,
	`permissionid` varchar(200) NOT NULL,
	PRIMARY KEY (`roleid`, `permissionid`)
");
