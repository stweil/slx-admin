<?php

/*
 * cronjob callback. This script does periodic checks, logging,
 * housekeeping etc. Should be called every 5 mins by cron.
 * Make a crontab entry that runs this as the same user the
 * www-php is normally run as, eg. */
// */5 *   * * *   www-data   php /path/to/api.php cron

if (!isLocalExecution())
	exit(0);

// Hooks by other modules
function handleModule($file)
{
	include_once $file;
}

foreach (glob('modules/*/hooks/cron.inc.php') as $file) {
	preg_match('#^modules/([^/]+)/#', $file, $out);
	$mod = Module::get($out[1]);
	if ($mod === false)
		continue;
	$mod->activate();
	handleModule($file);
}

switch (mt_rand(1, 10)) {
case 2:
	Database::exec("DELETE FROM property_list WHERE dateline <> 0 AND dateline < UNIX_TIMESTAMP()");
	break;
case 3:
	Database::exec("DELETE FROM property WHERE dateline <> 0 AND dateline < UNIX_TIMESTAMP()");
	break;
case 4:
	Database::exec("DELETE FROM callback WHERE (UNIX_TIMESTAMP() - dateline) > 86400");
	break;
}

Trigger::checkCallbacks();
Trigger::ldadp();
