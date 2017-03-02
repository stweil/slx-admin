<?php

/*
 * cronjob callback. This script does periodic checks, logging,
 * housekeeping etc. Should be called every 5 mins by cron.
 * Make a crontab entry that runs this as the same user the
 * www-php is normally run as, eg. */
// */5 *   * * *   www-data   php /path/to/api.php cron

if (!isLocalExecution())
	exit(0);

define('CRON_KEY_STATUS', 'cron.key.status');

function getJobStatus($id)
{
	// Re fetch from D on every call as some jobs could take longer
	// and we don't want to work with stale data
	$activeList = Property::getList(CRON_KEY_STATUS);
	foreach ($activeList as $item) {
		$entry = explode('|', $item, 2);
		if (count($entry) !== 2 || $id !== $entry[0])
			continue;
		return array('start' => $entry[1], 'string' => $item);
	}
	return false;
}

// Hooks by other modules
function handleModule($file)
{
	include_once $file;
}

foreach (Hook::load('cron') as $hook) {
	// Check if job is still running, or should be considered crashed
	$status = getJobStatus($hook->moduleId);
	if ($status !== false) {
		$runtime = (time() - $status['start']);
		if ($runtime < 0) {
			// Clock skew
			Property::removeFromList(CRON_KEY_STATUS, $status['string']);
		} elseif ($runtime < 900) {
			// Allow up to 15 minutes for a job to complete before we complain...
			continue;
		} else {
			// Consider job crashed
			Property::removeFromList(CRON_KEY_STATUS, $status['string']);
			EventLog::failure('Cronjob for module ' . $hook->moduleId . ' seems to be stuck or has crashed. Check the php or web server error log.');
			continue;
		}
	}
	$value = $hook . '|' . time();
	Property::addToList(CRON_KEY_STATUS, $value, 1800);
	handleModule($hook->file);
	Property::removeFromList(CRON_KEY_STATUS, $value);
}
