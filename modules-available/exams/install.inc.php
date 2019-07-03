<?php

$res = array();

$res[] = tableCreate('exams', '
	 `examid` int(11) NOT NULL AUTO_INCREMENT,
	 `lectureid` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
	 `starttime` int(11) NOT NULL,
	 `endtime` int(11) NOT NULL,
	 `autologin` char(36) NULL,
	 `description` varchar(500) DEFAULT NULL,
	 PRIMARY KEY (`examid`),
	 KEY `idx_daterange` ( `starttime` , `endtime` )
 ');

$res[] = tableCreate('exams_x_location', '
	 `examid` int(11) NOT NULL,
	 `locationid` int(11) NULL,
	 UNIQUE KEY (`examid`, `locationid`),
	 KEY (`locationid`)
');

if (!tableHasColumn('exams', 'lectureid')) {
	$ret = Database::exec("ALTER TABLE `exams` ADD `lectureid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `examid`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding lectureid to exams failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}
if (!tableHasColumn('exams', 'autologin')) {
	$ret = Database::exec("ALTER TABLE `exams` ADD `autologin` CHAR(36) NULL DEFAULT NULL AFTER `endtime`");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding autologin to exams failed: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

Database::exec("ALTER TABLE `exams` CHANGE `description` `description` varchar(500) NULL DEFAULT NULL");

$ex = tableGetConstraints('exams_x_location', 'locationid', 'location', 'locationid');

// 2019-07-02: Fix messed up non-NULL constraint
if ($ex !== false && $ex['CONSTRAINT_NAME'] !== 'exl_locid_null') {
	tableDeleteConstraint('exams_x_location', $ex['CONSTRAINT_NAME']);
	// Again for the other one
	$ex = tableGetConstraints('exams_x_location', 'examid', 'exams', 'examid');
	if ($ex !== false) {
		tableDeleteConstraint('exams_x_location', $ex['CONSTRAINT_NAME']);
	}
	// Get rid of all keys so we can make locationid NULLable
	Database::exec('ALTER TABLE `exams_x_location` DROP PRIMARY KEY');
	$r = Database::simpleQuery('SHOW INDEX FROM exams_x_location');
	while (($name = $r->fetchColumn(2)) !== false) {
		Database::exec("ALTER TABLE `exams_x_location` DROP INDEX `$name`");
	}
	$ret = Database::exec("ALTER TABLE `exams_x_location` MODIFY `locationid` int(11) NULL");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Making locationid NULLable failed: ' . Database::lastError());
	}
	// Ad our two keys; can't use PRIMARY as it doesn't allow columns that can be NULL
	Database::exec('ALTER TABLE `exams_x_location` ADD UNIQUE KEY (`examid`, `locationid`), ADD KEY (`locationid`)');
}

// Constraints for locationid and examid
$res[] = tableAddConstraint('exams_x_location', 'locationid', 'location', 'locationid',
	'ON DELETE CASCADE ON UPDATE CASCADE', false, 'exl_locid_null');
$res[] = tableAddConstraint('exams_x_location', 'examid', 'exams', 'examid',
	'ON DELETE CASCADE ON UPDATE CASCADE');


if (in_array(UPDATE_DONE, $res)) {
    finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
