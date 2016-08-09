<?php


$res = array();

$res[] = tableCreate('location_roomplan', '
	`locationid` INT(11) NOT NULL,
	`roomplan`   BLOB	 DEFAULT NULL,
	PRIMARY KEY (`locationid`)');



if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Table created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');

