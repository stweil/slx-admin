<?php

foreach ($list as $entry) {
	unset($entry['datelimit']);
	Database::exec('UPDATE IGNORE runmode SET machineuuid = :new WHERE machineuuid = :old', $entry);
}
