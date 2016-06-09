<?php

$res = array();

$res[] = tableCreate('setting_global', "
  `setting` varchar(28) NOT NULL,
  `value` text NOT NULL,
  `displayvalue` text NOT NULL,
  `enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT '1',
  PRIMARY KEY (`setting`)
");

// Update path

// Add toggle field

if (!tableHasColumn('setting_global', 'enabled')) {
	if (tableHasColumn('setting_global', 'toggle')) {
		$ret = Database::exec("ALTER TABLE `setting_global` CHANGE `toggle` `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1'");
	} else {
		$ret = Database::exec("ALTER TABLE `setting_global` ADD COLUMN `enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT '1'");
	}
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Adding enabled to setting_global failed: ' . Database::lastError());
	}
}

// Add displayvalue field

if (!tableHasColumn('setting_global', 'displayvalue')) {
	Database::exec("ALTER TABLE `setting_global` ADD `displayvalue` TEXT NOT NULL");
	Database::exec("UPDATE `setting_global` SET `displayvalue` = `value`");
	$res[] = UPDATE_DONE;
}

// Delete old tables

/*
Keep disabled for a while, in case some customer made unexpected important changes etc...

if (tableExists('setting')) {
	Database::exec('DROP TABLE setting');
}
if (tableExists('setting_distro')) {
	Database::exec('DROP TABLE setting_distro');
}
if (tableExists('cat_setting')) {
	Database::exec('DROP TABLE cat_setting');
}
*/

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');
