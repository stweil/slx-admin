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
define('CRON_KEY_BLOCKED', 'cron.key.blocked');

// Crash report mode - used by system crontab entry
if (($report = Request::get('crashreport', false, 'string'))) {
	$list = Property::getList(CRON_KEY_STATUS);
	if (empty($list)) {
		error_log('Cron crash report triggered but no cronjob marked active.');
		exit(0);
	}
	$str = array();
	foreach ($list as $item) {
		Property::removeFromList(CRON_KEY_STATUS, $item);
		$entry = explode('|', $item, 2);
		if (count($entry) !== 2)
			continue;
		$time = time() - $entry[1];
		if ($time > 3600) // Sanity check
			continue;
		$str[] = $entry[0] . ' (started ' . $time . 's ago)';
		Property::addToList(CRON_KEY_BLOCKED, $entry[0], 30);
	}
	if (empty($str)) {
		$str = 'an unknown module';
	}
	$message = 'Conjob failed. No reply by ' . implode(', ', $str);
	$details = '';
	if (is_readable($report)) {
		$details = file_get_contents($report);
		if (!empty($details)) {
			$message .=', click "details" for log';
		}
	}
	EventLog::failure($message, $details);
	exit(0);
}

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
/**
 * @param Hook $hook
 */
function handleModule($hook)
{
	global $cron_log_text;
	$cron_log_text = '';
	include_once $hook->file;
	if (!empty($cron_log_text)) {
		EventLog::info('CronJob ' . $hook->moduleId . ' finished.', $cron_log_text);
	}
}

$cron_log_text = '';
function cron_log($text)
{
	// XXX: Enable this code for debugging -- make this configurable some day
	//global $cron_log_text;
	//$cron_log_text .= $text . "\n";
}

$blocked = Property::getList(CRON_KEY_BLOCKED);
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
			EventLog::failure('Cronjob for module ' . $hook->moduleId . ' seems to be stuck or has crashed.');
			continue;
		}
	}
	// Are we blocked
	if (in_array($hook->moduleId, $blocked))
		continue;
	// Fire away
	$value = $hook->moduleId . '|' . time();
	Property::addToList(CRON_KEY_STATUS, $value, 30);
	try {
		handleModule($hook);
	} catch (Exception $e) {
		// Logging
		EventLog::failure('Cronjob for module ' . $hook->moduleId . ' has crashed. Check the php or web server error log.', $e->getMessage());
	}
	Property::removeFromList(CRON_KEY_STATUS, $value);
}
