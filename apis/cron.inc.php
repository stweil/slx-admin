<?php

/*
 * cronjob callback. This script does periodic checks, logging,
 * housekeeping etc. Should be called every 5 mins by cron.
 * Make a crontab entry that runs this as the same user the
 * www-php is normally run as, eg. */
// */5 *   * * *   www-data   php /path/to/api.php cron

if (!isLocalExecution())
	exit(0);

switch (mt_rand(1, 10)) {
case 1:
	Database::exec("DELETE FROM clientlog WHERE (UNIX_TIMESTAMP() - dateline) > 86400 * 90");
	break;
case 2:
	Database::exec("DELETE FROM eventlog WHERE (UNIX_TIMESTAMP() - dateline) > 86400 * 90");
	break;
case 3:
	Database::exec("DELETE FROM property WHERE dateline <> 0 AND dateline < UNIX_TIMESTAMP()");
	break;
case 4:
	Database::exec("DELETE FROM callback WHERE (UNIX_TIMESTAMP() - dateline) > 86400");
	break;
case 5:
	Database::exec("DELETE FROM statistic WHERE (UNIX_TIMESTAMP() - dateline) > 86400 * 90");
	break;
case 6:
	Database::exec("DELETE FROM machine WHERE (UNIX_TIMESTAMP() - lastseen) > 86400 * 365");
	break;
}

Trigger::checkCallbacks();
Trigger::ldadp();
