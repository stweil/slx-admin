<?php

$res = array();

$res[] = tableCreate('cities', "
	`cityid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`name` varchar(100) NOT NULL,
	`ip` varchar(10) NOT NULL,
	PRIMARY KEY (`cityid`)
");

// Update path

// -- none --

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
