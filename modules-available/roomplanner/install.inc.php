<?php


$res = array();

$res[] = tableCreate('location_roomplan', "
	`locationid` INT(11) NOT NULL,
	`managerip` varchar(45) CHARACTER SET ascii DEFAULT '',
	`tutoruuid` char(36) CHARACTER SET ascii DEFAULT NULL,
	`roomplan`   BLOB	 DEFAULT NULL,
	PRIMARY KEY (`locationid`),
 	KEY `tutoruuid` (`tutoruuid`)");

if (!tableHasColumn('location_roomplan', 'managerip')) {
	$ret = Database::exec("ALTER TABLE `location_roomplan` ADD COLUMN `managerip` varchar(45) CHARACTER SET ascii DEFAULT '' AFTER locationid") !== false;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding managerip to location_roomplan failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (!tableHasColumn('location_roomplan', 'tutoruuid')) {
	$ret = Database::exec("ALTER TABLE `location_roomplan` ADD COLUMN `tutoruuid` char(36) CHARACTER SET ascii DEFAULT NULL AFTER managerip,"
		. " ADD KEY `tutoruuid` (`tutoruuid`)") !== false;
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding tutoruuid to location_roomplan failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

if (in_array(UPDATE_DONE, $res)) {
	Database::exec("ALTER TABLE `location_roomplan`
  ADD CONSTRAINT `location_roomplan_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `location` (`locationid`) ON DELETE CASCADE");
	Database::exec("ALTER TABLE `location_roomplan`
  ADD CONSTRAINT `location_roomplan_ibfk_2` FOREIGN KEY (`tutoruuid`) REFERENCES `machine` (`machineuuid`) ON DELETE SET NULL ON UPDATE CASCADE");
}

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Table created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');

