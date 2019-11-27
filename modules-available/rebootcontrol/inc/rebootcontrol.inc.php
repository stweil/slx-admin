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

	/**
	 * Execute given command or script on a list of hosts. The list of hosts is an array of structs containing
	 * each a known machine-uuid and/or hostname, and optionally a port to use, which would otherwise default to 9922,
	 * and optionally a username to use, which would default to root.
	 * The command should be compatible with the remote user's default shell (most likely bash).
	 *
	 * @param array $clients [ { clientip: <host>, machineuuid: <uuid>, port: <port>, username: <username> }, ... ]
	 * @param string $command Command or script to execute on client
	 * @param int $timeout in seconds
	 * @param string|false $privkey SSH private key to use to connect
	 * @return array|false
	 */
	public static function runScript($clients, $command, $timeout = 5, $privkey = false)
	{
		$valid = [];
		$invalid = [];
		foreach ($clients as $client) {
			if (is_string($client)) {
				$invalid[strtoupper($client)] = []; // Assume machineuuid
			} elseif (!isset($client['clientip']) && !isset($client['machineuuid'])) {
				error_log('RebootControl::runScript called with list entry that has neither IP nor UUID');
			} elseif (!isset($client['clientip'])) {
				$invalid[$client['machineuuid']] = $client;
			} else {
				$valid[] = $client;
			}
		}
		if (!empty($invalid)) {
			$res = Database::simpleQuery('SELECT machineuuid, clientip FROM machine WHERE machineuuid IN (:uuids)',
				['uuids' => array_keys($invalid)]);
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				if (isset($invalid[$row['machineuuid']])) {
					$valid[] = $row + $invalid[$row['machineuuid']];
				} else {
					$valid[] = $row;
				}
			}
		}
		if ($privkey === false) {
			$privkey = SSHKey::getPrivateKey();
		}
		$task = Taskmanager::submit('RemoteExec', [
			'clients' => $valid,
			'command' => $command,
			'timeoutSeconds' => $timeout,
			'sshkey' => $privkey,
			'port' => 9922, // Fallback if no port given in client struct
		]);
		if (!Taskmanager::isFailed($task)) {
			Property::addToList(RebootControl::KEY_TASKLIST, '0/' . $task["id"], 60 * 24);
		}
		return $task;
	}

	public static function connectionCheckCallback($task, $hostId)
	{
		$reachable = 0;
		if (isset($task['data']['result'])) {
			foreach ($task['data']['result'] as $res) {
				if ($res['exitCode'] == 0) {
					$reachable = 1;
				}
			}
		}
		Database::exec('UPDATE reboot_jumphost SET reachable = :reachable WHERE hostid = :id',
			['id' => $hostId, 'reachable' => $reachable]);
	}

}