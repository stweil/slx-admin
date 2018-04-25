<?php

class RebootControl
{

	const KEY_TASKLIST = 'rebootcontrol.tasklist';

	const REBOOT = 'REBOOT';
	const KEXEC_REBOOT = 'KEXEC_REBOOT';
	const SHUTDOWN = 'SHUTDOWN';

	/**
	 * @param string[] $uuids List of machineuuids to reboot
	 * @param bool $kexec whether to trigger kexec-reboot instead of full BIOS cycle
	 * @return false|array task struct for the reboot job
	 */
	public static function reboot($uuids, $kexec = false)
	{
		$list = RebootQueries::getMachinesByUuid($uuids);
		if (empty($list))
			return false;
		return self::execute($list, $kexec ? RebootControl::KEXEC_REBOOT : RebootControl::REBOOT, 0, 0);
	}

	/**
	 * @param array $list list of clients containing each keys 'machineuuid' and 'clientip'
	 * @param string $mode reboot mode: RebootControl::REBOOT ::KEXEC_REBOOT or ::SHUTDOWN
	 * @param int $minutes delay in minutes for action
	 * @param int $locationId meta data only: locationId of clients
	 * @return array|false the task, or false if it could not be started
	 */
	public static function execute($list, $mode, $minutes, $locationId)
	{
		$task = Taskmanager::submit("RemoteReboot", array(
			"clients" => $list,
			"mode" => $mode,
			"minutes" => $minutes,
			"locationId" => $locationId,
			"sshkey" => SSHKey::getPrivateKey(),
			"port" => 9922, // Hard-coded, must match mgmt-sshd module
		));
		if (!Taskmanager::isFailed($task)) {
			Property::addToList(RebootControl::KEY_TASKLIST, $locationId . '/' . $task["id"], 60 * 24);
		}
		return $task;
	}

	/**
	 * @param int[]|null $locations filter by these locations
	 * @return array list of active tasks for reboots/shutdowns.
	 */
	public static function getActiveTasks($locations = null)
	{
		if (is_array($locations) && in_array(0,$locations)) {
			$locations = null;
		}
		$list = Property::getList(RebootControl::KEY_TASKLIST);
		$return = [];
		foreach ($list as $entry) {
			$p = explode('/', $entry, 2);
			if (count($p) !== 2) {
				Property::removeFromList(RebootControl::KEY_TASKLIST, $entry);
				continue;
			}
			if (is_array($locations) && !in_array($p[0], $locations)) // Ignore
				continue;
			$id = $p[1];
			$task = Taskmanager::status($id);
			if (!Taskmanager::isTask($task)) {
				Property::removeFromList(RebootControl::KEY_TASKLIST, $entry);
				continue;
			}
			$return[] = [
				'taskId' => $task['id'],
				'locationId' => $task['data']['locationId'],
				'time' => $task['data']['time'],
				'mode' => $task['data']['mode'],
				'clientCount' => count($task['data']['clients']),
				'status' => $task['statusCode'],
			];
		}
		return $return;
	}

}