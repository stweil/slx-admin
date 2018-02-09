<?php


foreach ($list as $entry) {
	unset($entry['datelimit']);
	Database::exec('UPDATE IGNORE location_roomplan SET tutoruuid = :new WHERE tutoruuid = :old', $entry);
}