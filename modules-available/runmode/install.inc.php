<?php

$res = array();

$res[] = tableCreate('runmode', '
	`machineuuid` char(36) CHARACTER SET ascii NOT NULL,
	`module` varchar(30) CHARACTER SET ascii NOT NULL,
	`modeid` varchar(60) CHARACTER SET ascii NOT NULL,
	`modedata` blob DEFAULT NULL,
	PRIMARY KEY (`machineuuid`),
	KEY `module` (`module`,`modeid`)
');

if (!tableExists('machine')) {
	// Cannot add constraint yet
	$res[] = UPDATE_RETRY;
} else {
	$c = tableGetContraints('runmode', 'machineuuid', 'machine', 'machineuuid');
	if ($c === false)
		finalResponse(UPDATE_FAILED, 'Cannot get constraints of runmode table: ' . Database::lastError());
	if (empty($c)) {
		$alter = Database::exec('ALTER TABLE runmode ADD FOREIGN KEY (machineuuid) REFERENCES machine (machineuuid)
			ON DELETE CASCADE ON UPDATE CASCADE');
		if ($alter === false)
			finalResponse(UPDATE_FAILED, 'Cannot add machineuuid constraint to runmode table: ' . Database::lastError());
		$res[] = UPDATE_DONE;
	}
}

// Create response for browser

if (in_array(UPDATE_DONE, $res)) {
	finalResponse(UPDATE_DONE, 'Tables created successfully');
}

finalResponse(UPDATE_NOOP, 'Everything already up to date');