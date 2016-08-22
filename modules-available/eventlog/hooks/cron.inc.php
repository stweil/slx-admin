<?php

if (mt_rand(1, 10) === 1) {
	Database::exec("DELETE FROM eventlog WHERE (UNIX_TIMESTAMP() - dateline) > 86400 * 190");
}