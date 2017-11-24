<?php

$res = array();

$res[] = tableCreate('runmode', "
	`machineuuid` char(36) CHARACTER SET ascii NOT NULL,
	`module` varchar(30) CHARACTER SET ascii NOT NULL,
	`modeid` varchar(60) CHARACTER SET ascii NOT NULL,
	`modedata` blob DEFAULT NULL,
	`isclient` bool DEFAULT '1',
	PRIMARY KEY (`machineuuid`),
	KEY `module` (`module`,`modeid`)
");

$res[] = tableAddConstraint('runmode', 'machineuuid', 'machine', 'machineuuid',
		'ON DELETE CASCADE ON UPDATE CASCADE');

if (!tableHasColumn('runmode', 'isclient')) {
	$ret = Database::exec("ALTER TABLE runmode ADD COLUMN isclient bool DEFAULT '1'");
	if ($ret === false) {
		finalResponse(UPDATE_FAILED, 'Could not add lastchange field');
	} elseif ($ret > 0) {
		$res[] = UPDATE_DONE;
	}
}

// Create response for browser
responseFromArray($res);
