<?php


$res = array();

$res[] = tableCreate('location_roomplan', "
	`locationid` INT(11) NOT NULL,
	`managerip` varchar(45) CHARACTER SET ascii DEFAULT '',
	`tutoruuid` char(36) CHARACTER SET ascii DEFAULT NULL,
	`roomplan`   BLOB	 DEFAULT NULL,
	PRIMARY KEY (`locationid`),
 	KEY `tutoruuid` (`tutoruuid`),
 	KEY `managerip` (`managerip`)");

if (!tableHasColumn('location_roomplan', 'managerip')) {
	$ret = Database::exec("ALTER TABLE `location_roomplan` ADD COLUMN `managerip` varchar(45) CHARACTER SET ascii DEFAULT '' AFTER locationid,"
		. " ADD KEY `managerip` (`managerip`)") !== false;
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

// 2017-11-30: Refactor to runmode
// managerip, dedicatedmgr,  --> runmode
if (tableHasColumn('location_roomplan', 'dedicatedmgr')) {
	if (!tableExists('runmode') || !tableExists('machine')) {
		$res[] = UPDATE_RETRY;
	} else {
		$ret = Database::simpleQuery('SELECT lr.locationid, lr.managerip, lr.dedicatedmgr, m.machineuuid
			FROM location_roomplan lr INNER JOIN machine m ON (m.clientip = lr.managerip)');
		if ($ret === false) {
			$res[] = UPDATE_FAILED;
		} else {
			while ($row = $ret->fetch(PDO::FETCH_ASSOC)) {
				$dedi = $row['dedicatedmgr'] != 0;
				$data = json_encode(array('dedicatedmgr' => $dedi));
				Database::exec("INSERT IGNORE INTO runmode (machineuuid, module, modeid, modedata, isclient)
				VALUES (:machineuuid, 'roomplanner', :locationid, :modedata, :isclient)", array(
					'machineuuid' => $row['machineuuid'],
					'locationid' => $row['locationid'],
					'modedata' => $data,
					'isclient' => ($dedi ? 0 : 1)
				));
			}
			Database::exec('ALTER TABLE location_roomplan DROP COLUMN dedicatedmgr');
			$res[] = UPDATE_DONE;
		}
	}
}

responseFromArray($res);
