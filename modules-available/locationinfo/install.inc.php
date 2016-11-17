<?php

$res = array();

$res[] = tableCreate('locationinfo', '
	`locationid` INT(11) NOT NULL,
	`hidden` BOOLEAN NOT NULL DEFAULT 0,
	`computers` BLOB DEFAULT NULL,
	PRIMARY KEY (`locationid`)
');

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
