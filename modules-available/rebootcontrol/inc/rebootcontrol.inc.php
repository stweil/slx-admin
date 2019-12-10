<?php

class RebootControl
{

	const KEY_TASKLIST = 'rebootcontrol.tasklist';

	const KEY_AUTOSCAN_DISABLED = 'rebootcontrol.disable.scan';

	const REBOOT = 'REBOOT';
	const KEXEC_REBOOT = 'KEXEC_REBOOT';
	const SHUTDOWN = 'SHUTDOWN';
	const TASK_REBOOTCTL = 'TASK_REBOOTCTL';
	const TASK_WOL = 'WAKE_ON_LAN';
	const TASK_EXEC = 'REMOTE_EXEC';

	/**
	 * @param string[] $uuids List of machineuuids to reboot
	 * @param bool $kexec whether to trigger kexec-reboot instead of full BIOS cycle
	 * @return false|array task struct for the reboot job
	 */
	public static function reboot($uuids, $kexec = false)
	{
		$list = RebootUtils::getMachinesByUuid($uuids);
		if (empty($list))
			return false;
		return self::execute($list, $kexec ? RebootControl::KEXEC_REBOOT : RebootControl::REBOOT, 0);
	}

	/**
	 * @param array $list list of clients containing each keys 'machineuuid', 'clientip' and 'locationid'
	 * @param string $mode reboot mode: RebootControl::REBOOT ::KEXEC_REBOOT or ::SHUTDOWN
	 * @param int $minutes delay in minutes for action
	 * @param int $locationId meta data only: locationId of clients
	 * @return array|false the task, or false if it could not be started
	 */
	public static function execute($list, $mode, $minutes)
	{
		$task = Taskmanager::submit("RemoteReboot", array(
			"clients" => $list,
			"mode" => $mode,
			"minutes" => $minutes,
			"sshkey" => SSHKey::getPrivateKey(),
			"port" => 9922, // Hard-coded, must match mgmt-sshd module
		));
		if (!Taskmanager::isFailed($task)) {
			self::addTask($task['id'], self::TASK_REBOOTCTL, $list, $task['id'], ['action' => $mode]);
		}
		return $task;
	}

	private static function extractLocationIds($from, &$to)
	{
		if (is_numeric($from)) {
			$to[$from] = true;
			return;
		}
		if (!is_array($from))
			return;
		$allnum = true;
		foreach ($from as $k => $v) {
			if (is_numeric($k) && is_numeric($v))
				continue;
			$allnum = false;
			if (is_numeric($k) && is_array($v)) {
				self::extractLocationIds($v, $to);
			} else {
				$k = strtolower($k);
				if ($k === 'locationid' || $k === 'locationids' || $k === 'location' || $k === 'locations' || $k === 'lid' || $k === 'lids') {
					self::extractLocationIds($v, $to);
				} elseif ($k === 'client' || $k === 'clients' || $k === 'machine' || $k === 'machines') {
					if (is_array($v)) {
						self::extractLocationIds($v, $to);
					}
				}
			}
		}
		if ($allnum) {
			foreach ($from as $v) {
				$to[$v] = true;
			}
		}
	}

	private static function addTask($id, $type, $clients, $taskIds, $other = false)
	{
		$lids = ArrayUtil::flattenByKey($clients, 'locationid');
		$lids = array_unique($lids);
		$newClients = [];
		foreach ($clients as $c) {
			$d = ['clientip' => $c['clientip']];
			if (isset($c['machineuuid'])) {
				$d['machineuuid'] = $c['machineuuid'];
			}
			$newClients[] = $d;
		}
		if (!is_array($taskIds)) {
			$taskIds = [$taskIds];
		}
		$data = [
			'id' => $id,
			'type' => $type,
			'locations' => $lids,
			'clients' => $newClients,
			'tasks' => $taskIds,
		];
		if (is_array($other)) {
			$data += $other;
		}
		Property::addToList(RebootControl::KEY_TASKLIST, json_encode($data), 20);
	}

	/**
	 * @param int[]|null $locations filter by these locations
	 * @return array|false list of active tasks for reboots/shutdowns.
	 */
	public static function getActiveTasks($locations = null, $id = null)
	{
		if (is_array($locations) && in_array(0, $locations)) {
			$locations = null;
		}
		$list = Property::getList(RebootControl::KEY_TASKLIST);
		$return = [];
		foreach ($list as $entry) {
			$p = json_decode($entry, true);
			if (!is_array($p) || !isset($p['id'])) {
				Property::removeFromList(RebootControl::KEY_TASKLIST, $entry);
				continue;
			}
			if (is_array($locations) && is_array($p['locations']) && array_diff($p['locations'], $locations) !== [])
				continue; // Not allowed
			if ($id !== null) {
				if ($p['id'] === $id)
					return $p;
				continue;
			}
			$valid = empty($p['tasks']);
			if (!$valid) {
				// Validate at least one task is still valid
				foreach ($p['tasks'] as $task) {
					$task = Taskmanager::status($task);
					if (Taskmanager::isTask($task)) {
						$p['status'] = $task['statusCode'];
						$valid = true;
						break;
					}
				}
			}
			if (!$valid) {
				Property::removeFromList(RebootControl::KEY_TASKLIST, $entry);
				continue;
			}
			$return[] = $p;
		}
		if ($id !== null)
			return false;
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
		$task = self::runScriptInternal($clients, $command, $timeout, $privkey);
		if (!Taskmanager::isFailed($task)) {
			self::addTask($task['id'], self::TASK_EXEC, $clients, $task['id']);
		}
		return $task;
	}

	private static function runScriptInternal(&$clients, $command, $timeout = 5, $privkey = false)
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
			$res = Database::simpleQuery('SELECT machineuuid, clientip, locationid FROM machine WHERE machineuuid IN (:uuids)',
				['uuids' => array_keys($invalid)]);
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				if (isset($invalid[$row['machineuuid']])) {
					$valid[] = $row + $invalid[$row['machineuuid']];
				} else {
					$valid[] = $row;
				}
			}
		}
		$clients = $valid;
		if (empty($clients)) {
			error_log('RebootControl::runScript called without any clients');
			return false;
		}
		if ($privkey === false) {
			$privkey = SSHKey::getPrivateKey();
		}
		return Taskmanager::submit('RemoteExec', [
			'clients' => $clients,
			'command' => $command,
			'timeoutSeconds' => $timeout,
			'sshkey' => $privkey,
			'port' => 9922, // Fallback if no port given in client struct
		]);
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

	/**
	 * @param array $sourceMachines list of source machines. array of [clientip, machineuuid] entries
	 * @param string $bcast directed broadcast address to send to
	 * @param string|string[] $macaddr destination mac address(es)
	 * @param string $passwd optional WOL password, mac address or ipv4 notation
	 * @return array|false task struct, false on error
	 */
	public static function wakeViaClient($sourceMachines, $macaddr, $bcast = false, $passwd = false)
	{
		$command = 'jawol';
		if (!empty($bcast)) {
			$command .= " -d '$bcast'";
		}
		if (!empty($passwd)) {
			$command .= " -p '$passwd'";
		}
		if (is_array($macaddr)) {
			$macaddr = implode("' '", $macaddr);
		}
		$command .= " '$macaddr'";
		// Yes there is one zero missing from the usleep -- that's the whole point: we prefer 100ms sleeps
		return self::runScriptInternal($sourceMachines,
			"for i in 1 1 0; do $command; usleep \${i}00000 2> /dev/null || sleep \$i; done");
	}

	/**
	 * @param string|string[] $macaddr destination mac address(es)
	 * @param string $bcast directed broadcast address to send to
	 * @param string $passwd optional WOL password, mac address or ipv4 notation
	 * @return array|false task struct, false on error
	 */
	public static function wakeDirectly($macaddr, $bcast = false, $passwd = false)
	{
		if (!is_array($macaddr)) {
			$macaddr = [$macaddr];
		}
		return Taskmanager::submit('WakeOnLan', [
			'ip' => $bcast,
			'password' => $passwd,
			'macs' => $macaddr,
		]);
	}

	public static function wakeViaJumpHost($jumphost, $bcast, $clients)
	{
		$hostid = $jumphost['hostid'];
		$macs = ArrayUtil::flattenByKey($clients, 'macaddr');
		if (empty($macs)) {
			error_log('Called wakeViaJumpHost without clients');
			return false;
		}
		$macs = "'" . implode("' '", $macs) . "'";
		$macs = str_replace('-', ':', $macs);
		$script = str_replace(['%IP%', '%MACS%'], [$bcast, $macs], $jumphost['script']);
		$task = RebootControl::runScriptInternal($_ = [[
			'clientip' => $jumphost['host'],
			'port' => $jumphost['port'],
			'username' => $jumphost['username'],
		]], $script, 6, $jumphost['sshkey']);
		if ($task !== false && isset($task['id'])) {
			TaskmanagerCallback::addCallback($task, 'rbcConnCheck', $hostid);
		}
		return $task;
	}

	/**
	 * @param array $list list of clients containing each keys 'macaddr' and 'clientip'
	 * @return string id of this job
	 */
	public static function wakeMachines($list)
	{
		/* TODO: Refactor mom's spaghetti
		 * Now that I figured out what I want, do something like this:
		 * 1) Group clients by subnet
		 * 2) Only after step 1, start to collect possible ways to wake up clients for each subnet that's not empty
		 * 3) Habe some priority list for the methods, extend Taskmanager to have "negative dependency"
		 *    i.e. submit task B with task A as parent task, but only launch task B if task A failed.
		 *    If task A succeeded, mark task B as FINISHED immediately without actually running it.
		 *    (or introduce new statusCode for this?)
		 */
		$errors = '';
		$tasks = [];
		$bad = $unknown = [];
		// Need all subnets...
		$subnets = [];
		$res = Database::simpleQuery('SELECT subnetid, start, end, isdirect FROM reboot_subnet');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row += [
				'jumphosts' => [],
				'direct' => [],
				'indirect' => [],
			];
			$subnets[$row['subnetid']] = $row;
		}
		// Get all jump hosts
		$jumphosts = [];
		$res = Database::simpleQuery('SELECT jh.hostid, host, port, username, sshkey, script, jh.reachable,
       	Group_Concat(jxs.subnetid) AS subnets1, Group_Concat(sxs.dstid) AS subnets2
			FROM reboot_jumphost jh
			LEFT JOIN reboot_jumphost_x_subnet jxs ON (jh.hostid = jxs.hostid)
			LEFT JOIN reboot_subnet s ON (INET_ATON(jh.host) BETWEEN s.start AND s.end)
			LEFT JOIN reboot_subnet_x_subnet sxs ON (sxs.srcid = s.subnetid AND sxs.reachable <> 0)
			GROUP BY jh.hostid');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['subnets1'] === null && $row['subnets2'] === null)
				continue;
			$nets = explode(',', $row['subnets1'] . ',' . $row['subnets2']);
			foreach ($nets as $net) {
				if (empty($net) || !isset($subnets[$net]))
					continue;
				$subnets[$net]['jumphosts'][$row['hostid']] = $row['hostid'];
			}
			$row['jobs'] = [];
			$jumphosts[] = $row;
		}
		// Group by subnet
		foreach ($list as $client) {
			$ip = sprintf('%u', ip2long($client['clientip']));
			//$client['numip'] = $ip;
			unset($subnet);
			$subnet = false;
			foreach ($subnets as &$sn) {
				if ($sn['start'] <= $ip && $sn['end'] >= $ip) {
					$subnet =& $sn;
					break;
				}
			}
			$ok = false;
			if (!$ok && $subnet === false) {
				$unknown[] = $client;
				$ok = true;
			}
			if (!$ok && $subnet['isdirect']) {
				// Directly reachable
				$subnet['direct'][] = $client;
				$ok = true;
			}
			if (!$ok && !empty($subnet['jumphosts'])) {
				foreach ($subnet['jumphosts'] as $hostid) {
					if ($jumphosts[$hostid]['reachable'] != 0) {
						$jumphosts[$hostid]['jobs'][$subnet['end']][] = $client;
						$ok = true;
						break;
					}
				}
			}
			if (!$ok) {
				// find clients in same subnet, or reachable ones
				self::findMachinesForSubnet($subnet);
				if (empty($subnet['dclients']) && empty($subnet['iclients'])) {
					// Nothing found -- cannot wake this host
					$bad[] = $client;
				} else {
					// Found suitable indirect host
					$subnet['indirect'][] = $client;
				}
			}
		}
		unset($subnet);
		// Batch process
		// First, via jump host
		foreach ($jumphosts as $jh) {
			foreach ($jh['jobs'] as $bcast => $clients) {
				$errors .= 'Via jumphost ' . $jh['host'] . ': ' . implode(', ', ArrayUtil::flattenByKey($clients, 'clientip')) . "\n";
				$task = self::wakeViaJumpHost($jh, $bcast, $clients);
				if (Taskmanager::isFailed($task)) {
					// TODO: Figure out $subnet from $bcast and queue as indirect
					// (rather, overhaul this whole spaghetti code)
					$errors .= ".... FAILED TO LAUNCH TASK ON JUMPHOST!\n";
				}
			}
		}
		// Server or client
		foreach ($subnets as $subnet) {
			if (!empty($subnet['direct'])) {
				// Can wake directly
				if (!self::wakeGroup('From server', $tasks, $errors, null, $subnet['direct'], $subnet['end'])) {
					if (!empty($subnet['dclients']) || !empty($subnet['iclients'])) {
						$errors .= "Re-queueing clients for indirect wakeup\n";
						$subnet['indirect'] = array_merge($subnet['indirect'], $subnet['direct']);
					}
				}
			}
			if (!empty($subnet['indirect'])) {
				// Can wake indirectly
				$ok = false;
				if (!empty($subnet['dclients'])) {
					$ok = true;
					if (!self::wakeGroup('in same subnet', $tasks, $errors, $subnet['dclients'], $subnet['indirect'])) {
						if (!empty($subnet['iclients'])) {
							$errors .= "Re-re-queueing clients for indirect wakeup\n";
							$ok = false;
						}
					}
				}
				if (!$ok && !empty($subnet['iclients'])) {
					$ok = self::wakeGroup('across subnets', $tasks, $errors, $subnet['dclients'], $subnet['indirect'], $subnet['end']);
				}
				if (!$ok) {
					$errors .= "I'm all out of ideas.\n";
				}
			}
		}
		if (!empty($bad)) {
			$ips = ArrayUtil::flattenByKey($bad, 'clientip');
			$errors .= "**** WARNING ****\nNo way to send WOL packets to the following machines:\n" . implode("\n", $ips) . "\n";
		}
		if (!empty($unknown)) {
			$ips = ArrayUtil::flattenByKey($unknown, 'clientip');
			$errors .= "**** WARNING ****\nThe following clients do not belong to a known subnet (bug?)\n" . implode("\n", $ips) . "\n";
		}
		$id = Util::randomUuid();
		self::addTask($id, self::TASK_WOL, $list, $tasks, ['log' => $errors]);
		return $id;
	}

	private static function wakeGroup($type, &$tasks, &$errors, $via, $clients, $bcast = false)
	{
		$macs = ArrayUtil::flattenByKey($clients, 'macaddr');
		$ips = ArrayUtil::flattenByKey($clients, 'clientip');
		if ($via !== null) {
			$srcips = ArrayUtil::flattenByKey($via, 'clientip');
			$errors .= 'Via ' . implode(', ', $srcips) . ' ';
		}
		$errors .= $type . ': ' . implode(', ', $ips);
		if ($bcast !== false) {
			$errors .= ' (UDP to ' . long2ip($bcast) . ')';
		}
		$errors .= "\n";
		if ($via === null) {
			$task = self::wakeDirectly($macs, $bcast);
		} else {
			$task = self::wakeViaClient($via, $macs, $bcast);
		}
		if ($task !== false && isset($task['id'])) {
			$tasks[] = $task['id'];
		}
		if (Taskmanager::isFailed($task)) {
			$errors .= ".... FAILED TO START ACCORDING TASK!\n";
			return false;
		}
		return true;
	}

	private static function findMachinesForSubnet(&$subnet)
	{
		if (isset($subnet['dclients']))
			return;
		$cutoff = time() - 302;
		// Get clients from same subnet first
		$subnet['dclients'] = Database::queryAll("SELECT machineuuid, clientip FROM machine
			WHERE state IN ('IDLE', 'OCCUPIED') AND INET_ATON(clientip) BETWEEN :start AND :end AND lastseen > :cutoff
			LIMIT 3",
			['start' => $subnet['start'], 'end' => $subnet['end'], 'cutoff' => $cutoff]);
		$subnet['iclients'] = [];
		if (!empty($subnet['dclients']))
			return;
		// If none, get clients from other subnets known to be able to reach this one
		$subnet['iclients'] = Database::queryAll("SELECT m.machineuuid, m.clientip FROM reboot_subnet_x_subnet sxs
    		INNER JOIN reboot_subnet s ON (s.subnetid = sxs.srcid AND sxs.dstid = :subnetid)
			INNER JOIN machine m ON (INET_ATON(m.clientip) BETWEEN s.start AND s.end AND state IN ('IDLE', 'OCCUPIED') AND m.lastseen > :cutoff)
			LIMIT 20", ['subnetid' => $subnet['subnetid'], 'cutoff' => $cutoff]);
		shuffle($subnet['iclients']);
		$subnet['iclients'] = array_slice($subnet['iclients'], 0, 3);
	}

	public static function prepareExec()
	{
		User::assertPermission('action.exec');
		$uuids = array_values(Request::post('uuid', Request::REQUIRED, 'array'));
		$machines = RebootUtils::getFilteredMachineList($uuids, 'action.exec');
		if ($machines === false)
			return;
		RebootUtils::sortRunningFirst($machines);
		$id = mt_rand();
		Session::set('exec-' . $id, $machines, 60);
		Session::save();
		Util::redirect('?do=rebootcontrol&show=exec&what=prepare&id=' . $id);
	}

}
