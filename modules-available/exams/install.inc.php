<?php

$res = array();

$res[] = tableCreate('exams', '
	 `examid` int(11) NOT NULL AUTO_INCREMENT,
	 `starttime` int(11) NOT NULL,
	 `endtime` int(11) NOT NULL,
	 `description` varchar(500) DEFAULT NULL,
	 PRIMARY KEY (`examid`)
 ');

$res[] = tableCreate('exams_x_location', '
	 `examid` int(11) NOT NULL,
	 `locationid` int(11) NOT NULL,
	 PRIMARY KEY (`examid`, `locationid`)
');

if (Database::exec("ALTER TABLE exams ADD INDEX `idx_daterange` ( `starttime` , `endtime` )") === false) {
	if (!preg_match('/\b1061\b/', Database::lastError())) {
		finalResponse(UPDATE_FAILED, 'Could not add startdate/enddate index: ' . Database::lastError());
	}
} else {
	$res[] = UPDATE_DONE;
}

Database::exec("ALTER TABLE `exams` CHANGE `description` `description` varchar(500) DEFAULT NULL");

if (in_array(UPDATE_DONE, $res)) {
    finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
