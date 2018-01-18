<?php

foreach ($list as $entry) {
	unset($entry['datelimit']);
	Database::exec('UPDATE IGNORE dnbd3_server SET machineuuid = :new WHERE machineuuid = :old', $entry);
}
