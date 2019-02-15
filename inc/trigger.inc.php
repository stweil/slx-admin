<?php

/**
 * This is one giant class containing various functions that will generate
 * required config files, daemon instances and more, mostly through the Taskmanager.
 * Most function *should* only actually do something if it is required to do so.
 * eg. a "launchSomething" function should only launch something if it isn't already
 * running. Checking if something is running can happen in that very function, or in
 * a task that the function is calling.
 */
class Trigger
{

	/**
	 * Compile iPXE pxelinux menu. Needs to be done whenever the server's IP
	 * address changes.
	 * 
	 * @param boolean $force force recompilation even if it seems up to date
	 * @return boolean|string false if launching task failed, task-id otherwise
	 */
	public static function ipxe()
	{
		$hooks = Hook::load('ipxe-update');
		$taskId = false;
		foreach ($hooks as $hook) {
			$ret = function($taskId) use ($hook) {
				$ret = include_once($hook->file);
				if (is_string($ret))
					return $ret;
				return isset($taskId) ? $taskId : false;
			};
			$ret = $ret($taskId);
			if (is_string($ret)) {
				$taskId = $ret;
			} elseif (is_array($ret) && isset($ret['id'])) {
				$taskId = $ret['id'];
			}
		}
		Property::set('ipxe-task-id', $taskId, 15);
		return $taskId;
	}

	/**
	 * Try to automatically determine the primary IP address of the server.
	 * This only works if the server has either one public IPv4 address (and potentially
	 * one or more non-public addresses), or one private address.
	 * 
	 * @return boolean true if current configured IP address is still valid, or if a new address could
	 * successfully be determined, false otherwise
	 */
	public static function autoUpdateServerIp()
	{
		$task = Taskmanager::submit('LocalAddressesList');
		if ($task === false)
			return false;
		$task = Taskmanager::waitComplete($task, 10000);
		if (!isset($task['data']['addresses']) || empty($task['data']['addresses']))
			return false;

		$serverIp = Property::getServerIp();
		$publicCandidate = 'none';
		$privateCandidate = 'none';
		foreach ($task['data']['addresses'] as $addr) {
			if (substr($addr['ip'], 0, 4) === '127.' || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $addr['ip']) !== 1)
				continue;
			if ($addr['ip'] === $serverIp)
				return true;
			if (Util::isPublicIpv4($addr['ip'])) {
				if ($publicCandidate === 'none')
					$publicCandidate = $addr['ip'];
				else
					$publicCandidate = 'many';
			} else {
				if ($privateCandidate === 'none')
					$privateCandidate = $addr['ip'];
				else
					$privateCandidate = 'many';
			}
		}
		if ($publicCandidate !== 'none' && $publicCandidate !== 'many') {
			Property::setServerIp($publicCandidate, true);
			return true;
		}
		if ($privateCandidate !== 'none' && $privateCandidate !== 'many') {
			Property::setServerIp($privateCandidate, true);
			return true;
		}
		return false;
	}

	/**
	 * Launch all ldadp instances that need to be running.
	 *
	 * @param int $exclude if not NULL, id of config module NOT to start
	 * @param string $parent if not NULL, this will be the parent task of the launch-task
	 * @return boolean|string false on error, id of task otherwise
	 */
	public static function ldadp($exclude = NULL, $parent = NULL)
	{
		// TODO: Fetch list from ConfigModule_AdAuth (call loadDb first)
		$res = Database::simpleQuery("SELECT DISTINCT moduleid FROM configtgz_module"
				. " INNER JOIN configtgz_x_module USING (moduleid)"
				. " INNER JOIN configtgz USING (configid)"
				. " INNER JOIN configtgz_location USING (configid)"
				. " WHERE moduletype IN ('AdAuth', 'LdapAuth')");
		$id = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!is_null($exclude) && (int)$row['moduleid'] === (int)$exclude)
				continue;
			$id[] = (int)$row['moduleid'];
		}
		$task = Taskmanager::submit('LdadpLauncher', array(
				'ids' => $id,
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		if (!isset($task['id']))
			return false;
		return $task['id'];
	}

	/**
	 * Mount the VM store into the server.
	 *
	 * @param array $vmstore VM Store configuration to use. If false, read from properties
	 * @return array|false task status of mount procedure, or false on error
	 */
	public static function mount($vmstore = false)
	{
		if ($vmstore === false) {
			$vmstore = Property::getVmStoreConfig();
		}
		if (!is_array($vmstore))
			return false;
		if (isset($vmstore['storetype'])) {
			$storetype = $vmstore['storetype'];
		} else {
			$storetype = 'unknown';
		}
		if ($storetype === 'nfs') {
			$addr = $vmstore['nfsaddr'];
			$opts = 'nfsopts';
		} elseif ($storetype === 'cifs') {
			$addr = $vmstore['cifsaddr'];
			$opts = 'cifsopts';
		} else {
			$opts = null;
			$addr = 'null';
		}
		if (isset($vmstore[$opts])) {
			$opts = $vmstore[$opts];
		}else {
			$opts = null;
		}
		return Taskmanager::submit('MountVmStore', array(
				'address' => $addr,
				'type' => 'images',
				'opts' => $opts,
				'username' => $vmstore['cifsuser'],
				'password' => $vmstore['cifspasswd']
		));
	}

	/**
	 * Check and process all callbacks.
	 * 
	 * @return boolean Whether there are still callbacks pending
	 */
	public static function checkCallbacks()
	{
		$tasksLeft = false;
		$callbackList = TaskmanagerCallback::getPendingCallbacks();
		foreach ($callbackList as $taskid => $callbacks) {
			$status = Taskmanager::status($taskid);
			if ($status === false)
				continue;
			foreach ($callbacks as $callback) {
				TaskmanagerCallback::handleCallback($callback, $status);
			}
			if (Taskmanager::isFailed($status) || Taskmanager::isFinished($status))
				Taskmanager::release($status);
			else
				$tasksLeft = true;
		}
		return $tasksLeft;
	}

	private static function triggerDaemons($action, $parent, &$taskids)
	{
		$task = Taskmanager::submit('Systemctl', array(
				'operation' => $action,
				'service' => 'dmsd',
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		if (isset($task['id'])) {
			$taskids['dmsdid'] = $task['id'];
			$parent = $task['id'];
		}
		$task = Taskmanager::submit('Systemctl', array(
			'operation' => $action,
			'service' => 'dnbd3-server',
			'parentTask' => $parent,
			'failOnParentFail' => false
		));
		if (isset($task['id'])) {
			$taskids['dnbd3id'] = $task['id'];
			$parent = $task['id'];
		}
		return $parent;
	}

	public static function stopDaemons($parent, &$taskids)
	{
		$parent = self::triggerDaemons('stop', $parent, $taskids);
		$task = Taskmanager::submit('LdadpLauncher', array(
				'ids' => array(),
				'parentTask' => $parent,
				'failOnParentFail' => false
		));
		if (isset($task['id'])) {
			$taskids['ldadpid'] = $task['id'];
			$parent = $task['id'];
		}
		return $parent;
	}

	public static function startDaemons($parent, &$taskids)
	{
		$parent = self::triggerDaemons('start', $parent, $taskids);
		$taskid = self::ldadp($parent);
		if ($taskid !== false) {
			$taskids['ldadpid'] = $taskid;
			$parent = $taskid;
		}
		return $parent;
	}

}
