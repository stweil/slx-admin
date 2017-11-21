<?php

$res = array();

$res[] = tableCreate('role', "
	roleid int(10) unsigned NOT NULL AUTO_INCREMENT,
	rolename varchar(200) NOT NULL,
	PRIMARY KEY (roleid)
");

$res[] = tableCreate('user_x_role', "
	userid int(10) unsigned NOT NULL,
	roleid int(10) unsigned NOT NULL,
	PRIMARY KEY (userid, roleid)
");

$res[] = tableCreate('role_x_location', "
	id int(10) unsigned NOT NULL AUTO_INCREMENT,
	roleid int(10) unsigned NOT NULL,
	locationid int(11),
	PRIMARY KEY (id)
");

$res[] = tableCreate('role_x_permission', "
	roleid int(10) unsigned NOT NULL,
	permissionid varchar(200) NOT NULL,
	PRIMARY KEY (roleid, permissionid)
");

if (!tableExists('user') || !tableExists('location')) {
	finalResponse(UPDATE_RETRY, 'Cannot add constraint yet. Please retry.');
} else {
	$c = tableGetContraints('user_x_role', 'userid', 'user', 'userid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of user table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE user_x_role ADD FOREIGN KEY (userid) REFERENCES user (userid) ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add userid constraint referencing user table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}

	$c = tableGetContraints('user_x_role', 'roleid', 'role', 'roleid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of role table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE user_x_role ADD FOREIGN KEY (roleid) REFERENCES role (roleid) ON DELETE CASCADE ON UPDATE CASCADE');
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
