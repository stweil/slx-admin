<?php

$res = array();



if (tableExists('news')) {
	/* rename news and add column "type" */
	tableRename('news', 'vmchooser_pages');
	Database::exec("ALTER TABLE `vmchooser_pages` ADD COLUMN type varchar(10)", []);
	Database::exec("UPDATE `vmchooser_pages` set `type`='news` WHERE 1", []);

	finalResponse(UPDATE_DONE, 'Tables updated successfully');

} else {
	$res[] = tableCreate('vmchooser_pages', "
		`newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`dateline` int(10) unsigned NOT NULL,
		`title` varchar(200) DEFAULT NULL,
		`content` text,
		`type` varchar(10),
		PRIMARY KEY (`newsid`),
		KEY `dateline` (`dateline`)
	");


	// *crickets*

	// Create response for browser

	if (in_array(UPDATE_DONE, $res)) {
		finalResponse(UPDATE_DONE, 'Tables created successfully');
	}

	finalResponse(UPDATE_NOOP, 'Everything already up to date');
}
