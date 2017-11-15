<?php

if (mt_rand(1, 10) === 1) {
	Database::exec("DELETE FROM clientlog WHERE (UNIX_TIMESTAMP() - 86400 * 190) > dateline");
	if (mt_rand(1, 100) === 1) {
		Database::exec("OPTIMIZE TABLE clientlog");
	}
}