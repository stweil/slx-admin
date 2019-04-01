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
	 `locationid` int(11) NOT NULL,
	 PRIMARY KEY (`examid`, `locationid`)
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

Database::exec("ALTER TABLE `exams` CHANGE `description` `description` varchar(500) DEFAULT NULL");

$res[] = tableAddConstraint('exams_x_location', 'examid', 'exams', 'examid',
		'ON DELETE CASCADE ON UPDATE CASCADE');
$res[] = tableAddConstraint('exams_x_location', 'locationid', 'location', 'locationid',
		'ON DELETE CASCADE ON UPDATE CASCADE');

if (in_array(UPDATE_DONE, $res)) {
    finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
