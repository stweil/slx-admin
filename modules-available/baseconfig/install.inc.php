<?php

$res = array();

$res[] = tableCreate('setting_global', "
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  `displayvalue` text NOT NULL,
  PRIMARY KEY (`setting`)
");

// Update path

// Add displayvalue field

if (!tableHasColumn('setting_global', 'displayvalue')) {
	Database::exec("ALTER TABLE `setting_global` ADD `displayvalue` TEXT NOT NULL");
	Database::exec("UPDATE `setting_global` SET `displayvalue` = `value`");
	$res[] = UPDATE_DONE;
}

// Delete old tables
if (tableExists('setting')) {
	Database::exec('DROP TABLE setting');
}
if (tableExists('setting_distro')) {
	Database::exec('DROP TABLE setting_distro');
}
if (tableExists('cat_setting')) {
	Database::exec('DROP TABLE cat_setting');
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
