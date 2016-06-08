<?php

$res = array();

$res[] = tableCreate('eventlog', "
`logid` int(10) unsigned NOT NULL AUTO_INCREMENT,
`dateline` int(10) unsigned NOT NULL,
`logtypeid` varchar(30) NOT NULL,
`description` varchar(255) NOT NULL,
`extra` TEXT NOT NULL,
PRIMARY KEY (`logid`),
KEY `dateline` (`dateline`),
KEY `logtypeid` (`logtypeid`,`dateline`)
");

// Update path

if (!tableHasColumn('eventlog', 'extra')) {
	if (Database::exec("ALTER TABLE `eventlog` ADD `extra` TEXT NOT NULL") === false) {
		finalResponse(UPDATE_FAILED, 'Could not add extra to eventlog: ' . Database::lastError());
	}
	$res[] = UPDATE_DONE;
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
