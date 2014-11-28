<?php

/**
 * Class with static functions that are called when a specific event
 * took place, like the server has been booted, or the interface address
 * has been changed.
 * In contrast to the trigger class, this class should contain functions
 * for things that happen semi-automatically in reaction to something else
 * (which in turn might have been triggered explicitly).
 */
class Event
{

	/**
	 * Called when the system (re)booted. Could be implemented
	 * by a @reboot entry in crontab (running as the same user php does)
	 */
	public static function systemBooted()
	{
		EventLog::info('System boot...');
		$everythingFine = true;

		DefaultData::populate();

		// Tasks: fire away
		$mountId = Trigger::mount();
		$autoIp = Trigger::autoUpdateServerIp();
		$ldadpId = Trigger::ldadp();

		Taskmanager::submit('DozmodLauncher', array(
			'operation' => 'start'
		));

		// Check status of all tasks
		// Mount vm store
		if ($mountId === false) {
			EventLog::info('No VM store type defined.');
			$everythingFine = false;
		} else {
			$res = Taskmanager::waitComplete($mountId, 5000);
			if (Taskmanager::isFailed($res)) {
				EventLog::failure('Mounting VM store failed', $res['data']['messages']);
				$everythingFine = false;
			}
		}
		// LDAP AD Proxy
		if ($ldadpId === false) {
			EventLog::failure('Cannot start LDAP-AD-Proxy: Taskmanager unreachable!');
			$everythingFine = false;
		} else {
			$res = Taskmanager::waitComplete($ldadpId, 5000);
			if (Taskmanager::isFailed($res)) {
				EventLog::failure('Starting LDAP-AD-Proxy failed', $res['data']['messages']);
				$everythingFine = false;
			}
		}
		// Primary IP address
		if (!$autoIp) {
			EventLog::failure("The server's IP address could not be determined automatically, and there is no valid address configured.");
			$everythingFine = false;
		}

		// Just so we know booting is done (and we don't expect any more errors from booting up)
		if ($everythingFine) {
			EventLog::info('Bootup finished without errors.');
		} else {
			EventLog::warning('There were errors during bootup. Maybe the server is not fully configured yet.');
		}
	}

	/**
	 * Server's primary IP address changed.
	 */
	public static function serverIpChanged()
	{
		global $tidAdModules, $tidIpxe;
		$tidAdModules = Trigger::rebuildAdModules();
		$tidIpxe = Trigger::ipxe();
	}

	/**
	 * The activated configuration changed.
	 */
	public static function activeConfigChanged()
	{
		$task = Trigger::ldadp();
		TaskmanagerCallback::addCallback($task, 'ldadpStartup');
	}

}