<?php

$res = array();

$res[] = tableCreate('news', "
	`newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`dateline` int(10) unsigned NOT NULL,
	`title` varchar(200) DEFAULT NULL,
	`content` text,
	PRIMARY KEY (`newsid`),
	KEY `dateline` (`dateline`)
");

// Update path

// *crickets*

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
