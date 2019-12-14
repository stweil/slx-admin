<?php

$res = array();

$res[] = tableCreate('role', "
	roleid int(10) unsigned NOT NULL AUTO_INCREMENT,
	rolename varchar(200) NOT NULL,
	roledescription TEXT,
	PRIMARY KEY (roleid)
");

if (tableExists('user_x_role')) {
	if (tableExists('role_x_user')) {
		Database::exec('DROP TABLE user_x_role');
	} else {
		$res[] = tableRename('user_x_role', 'role_x_user');
	}
}
$res[] = tableCreate('role_x_user', "
	userid int(10) unsigned NOT NULL,
	roleid int(10) unsigned NOT NULL,
	PRIMARY KEY (userid, roleid)
");

$res[] = tableCreate('role_x_location', "
	roleid int(10) unsigned NOT NULL,
	locationid int(11),
	CONSTRAINT role_loc UNIQUE (roleid, locationid) 
");

$res[] = tableCreate('role_x_permission', "
	roleid int(10) unsigned NOT NULL,
	permissionid varchar(200) NOT NULL,
	PRIMARY KEY (roleid, permissionid)
");

if (tableHasColumn('role_x_location', 'id')) {
	$cnt = Database::exec('DELETE a FROM role_x_location a, role_x_location b
			WHERE a.roleid = b.roleid AND (a.locationid = b.locationid OR (a.locationid IS NULL AND b.locationid IS NULL))
			AND a.id > b.id');
	$ret = Database::exec('ALTER TABLE role_x_location DROP COLUMN id,
			ADD CONSTRAINT role_loc UNIQUE (roleid, locationid)');
	if ($ret === false) {
		$res[] = UPDATE_NOOP;
	} else {
		$res[] = UPDATE_DONE;
	}

}

if (!tableExists('user') || !tableExists('location')) {
	finalResponse(UPDATE_RETRY, 'Cannot add constraint yet. Please retry.');
} else {
	$c = tableGetConstraints('role_x_user', 'userid', 'user', 'userid');
	if ($c === false) {
		$alter = Database::exec('ALTER TABLE role_x_user ADD FOREIGN KEY (userid) REFERENCES user (userid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add userid constraint referencing user table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetConstraints('role_x_user', 'roleid', 'role', 'roleid');
	if ($c === false) {
		$alter = Database::exec('ALTER TABLE role_x_user ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetConstraints('role_x_location', 'roleid', 'role', 'roleid');
	if ($c === false) {
		$alter = Database::exec('ALTER TABLE role_x_location ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetConstraints('role_x_location', 'locationid', 'location', 'locationid');
	if ($c === false) {
		$alter = Database::exec('ALTER TABLE role_x_location ADD FOREIGN KEY (locationid) REFERENCES location (locationid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add locationid constraint referencing location table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetConstraints('role_x_permission', 'roleid', 'role', 'roleid');
	if ($c === false) {
		$alter = Database::exec('ALTER TABLE role_x_permission ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}
}

// 2018-04-13 role description field; add a couple default roles
if (!tableHasColumn('role', 'roledescription')) {
	$alter = Database::exec("ALTER TABLE role ADD roledescription TEXT");
	if ($alter === false)
		finalResponse(UPDATE_FAILED, 'Cannot add roledescription field to table role: ' . Database::lastError());
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('role', 'roledescription')) {
	finalResponse(UPDATE_RETRY, 'Try again later');
}

if (Database::exec("INSERT INTO `role` VALUES
			(1,'Super-Admin', 'Hat keinerlei Zugriffsbeschränkungen'),
			(2,'Admin', 'Alles bis auf Rechte-/Nutzerverwaltung'),
			(3,'Prüfungsadmin', 'Kann E-Prüfungen verwalten, Prüfungsmodus einschalten, etc.'),
			(4,'Lesezugriff', 'Kann auf die meisten Seiten zugreifen, jedoch keine Änderungen vornehmen')") !== false) {
	// Success, there probably were no roles before, keep going
	// Assign roles to location (all)
	Database::exec("INSERT INTO `role_x_location` VALUES (1,NULL),(2,NULL),(3,NULL),(4,NULL)");
	// Assign permissions to roles
	Database::exec("INSERT INTO `role_x_permission` VALUES
			(3,'exams.exams.*'),
			(3,'rebootcontrol.action.*'),
			(3,'statistics.hardware.projectors.view'),
			(3,'statistics.machine.note.*'),
			(3,'statistics.machine.view-details'),
			(3,'statistics.view.*'),
			(3,'syslog.view'),
			
			(1,'*'),
			
			(4,'adduser.user.view-list'),
			(4,'backup.create'),
			(4,'baseconfig.view'),
			(4,'dnbd3.access-page'),
			(4,'dnbd3.refresh'),
			(4,'dnbd3.view.details'),
			(4,'dozmod.actionlog.view'),
			(4,'dozmod.users.view'),
			(4,'eventlog.view'),
			(4,'exams.exams.view'),
			(4,'locationinfo.backend.check'),
			(4,'locationinfo.panel.list'),
			(4,'locations.location.view'),
			(4,'minilinux.view'),
			(4,'news.*'),
			(4,'permissionmanager.locations.view'),
			(4,'permissionmanager.roles.view'),
			(4,'permissionmanager.users.view'),
			(4,'runmode.list-all'),
			(4,'serversetup.access-page'),
			(4,'serversetup.download'),
			(4,'statistics.hardware.projectors.view'),
			(4,'statistics.machine.note.view'),
			(4,'statistics.machine.view-details'),
			(4,'statistics.view.*'),
			(4,'statistics_reporting.reporting.download'),
			(4,'statistics_reporting.table.export'),
			(4,'statistics_reporting.table.view.*'),
			(4,'sysconfig.config.view-list'),
			(4,'sysconfig.module.download'),
			(4,'sysconfig.module.view-list'),
			(4,'syslog.view'),
			(4,'systemstatus.show.overview.*'),
			(4,'systemstatus.tab.*'),
			(4,'webinterface.access-page'),
			
			(2,'adduser.user.view-list'),
			(2,'backup.*'),
			(2,'baseconfig.*'),
			(2,'dnbd3.*'),
			(2,'dozmod.*'),
			(2,'eventlog.view'),
			(2,'exams.exams.*'),
			(2,'locationinfo.*'),
			(2,'locations.*'),
			(2,'minilinux.*'),
			(2,'news.*'),
			(2,'permissionmanager.locations.view'),
			(2,'permissionmanager.roles.view'),
			(2,'permissionmanager.users.view'),
			(2,'rebootcontrol.*'),
			(2,'roomplanner.edit'),
			(2,'runmode.list-all'),
			(2,'serversetup.*'),
			(2,'statistics.*'),
			(2,'statistics_reporting.*'),
			(2,'sysconfig.*'),
			(2,'syslog.*'),
			(2,'systemstatus.*'),
			(2,'vmstore.edit'),
			(2,'webinterface.*')");
	// Assign the first user to the superadmin role (if one exists)
	Database::exec("INSERT INTO `role_x_user` VALUES (1,1)");
	$res[] = UPDATE_DONE;
}

//
//

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}
finalResponse(UPDATE_NOOP, 'Everything already up to date');
