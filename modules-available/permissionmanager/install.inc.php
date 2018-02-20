<?php

$res = array();

$res[] = tableCreate('role', "
	roleid int(10) unsigned NOT NULL AUTO_INCREMENT,
	rolename varchar(200) NOT NULL,
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
	$c = tableGetContraints('role_x_user', 'userid', 'user', 'userid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of user table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE role_x_user ADD FOREIGN KEY (userid) REFERENCES user (userid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add userid constraint referencing user table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetContraints('role_x_user', 'roleid', 'role', 'roleid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of role table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE role_x_user ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetContraints('role_x_location', 'roleid', 'role', 'roleid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of role table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE role_x_location ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetContraints('role_x_location', 'locationid', 'location', 'locationid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of location table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE role_x_location ADD FOREIGN KEY (locationid) REFERENCES location (locationid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add locationid constraint referencing location table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetContraints('role_x_permission', 'roleid', 'role', 'roleid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of role table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE role_x_permission ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add roleid constraint referencing role table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}
}
if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}
finalResponse(UPDATE_NOOP, 'Everything already up to date');
