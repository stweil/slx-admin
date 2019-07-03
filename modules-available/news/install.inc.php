<?php

$res = array();



if (tableExists('news')) {

	/* rename news and add column "type" */
	if (!tableRename('news', 'vmchooser_pages')) {
		finalResponse(UPDATE_FAILED, "Could not rename news to vmchooser_pages: " . Database::lastError());
	}
	$res[] = UPDATE_DONE;
	if (false === Database::exec("ALTER TABLE `vmchooser_pages` ADD COLUMN type VARCHAR(10)")) {
		EventLog::warning("Could not add type column to vmchooser_pages: " . Database::lastError());
	}
	if (false === Database::exec("UPDATE `vmchooser_pages` SET `type` = 'news' WHERE 1")) {
		EventLog::warning("News module update: Could not set default type to news: " . Database::lastError());
	}

}

$res[] = tableCreate('vmchooser_pages', "
	`newsid` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`dateline` int(10) unsigned NOT NULL,
	`expires` int(10) unsigned NOT NULL,
	`title` varchar(200) DEFAULT NULL,
	`content` text,
	`type` varchar(10),
	PRIMARY KEY (`newsid`),
	KEY `type` (`type`, `dateline`),
	KEY `all3` (`type`, `expires`, `dateline`)
");

if (tableGetIndex('vmchooser_pages', ['dateline']) !== false) {
	Database::exec('ALTER TABLE vmchooser_pages DROP KEY `dateline`');
	Database::exec('ALTER TABLE vmchooser_pages ADD KEY `type` (`type`, `dateline`)');
}
if (tableGetIndex('vmchooser_pages', ['type', 'expires', 'dateline']) === false) {
	Database::exec('ALTER TABLE vmchooser_pages ADD KEY `all3` (`type`, `expires`, `dateline`)');
}
if (!tableHasColumn('vmchooser_pages', 'expires')) {
	Database::exec('ALTER TABLE vmchooser_pages ADD COLUMN `expires` int(10) unsigned NOT NULL AFTER `dateline`');
	Database::exec('UPDATE vmchooser_pages SET expires = dateline + 86400 * 3650 WHERE expires = 0'); // ~10 Years
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
