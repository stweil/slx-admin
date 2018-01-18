<?php

foreach ($list as $entry) {
	Database::exec('UPDATE IGNORE clientlog SET machineuuid = :new WHERE machineuuid = :old AND dateline < :datelimit', $entry);
}
