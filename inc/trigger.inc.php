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
	 * @return boolean|string true if already up to date, false if launching task failed, task-id otherwise
	 */
	public static function ipxe()
	{
		$data = Property::getBootMenu();
		$task = Taskmanager::submit('CompileIPxe', $data);
		if (!isset($task['id']))
			return false;
		return $task['id'];
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
			if ($addr['ip'] === $serverIp)
				return true;
			if (substr($addr['ip'], 0, 4) === '127.')
				continue;
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
			Property::setServerIp($publicCandidate);
			return true;
		}
		if ($privateCandidate !== 'none' && $privateCandidate !== 'many') {
			Property::setServerIp($privateCandidate);
			return true;
		}
		return false;
	}

	/**
	 * Launch all ldadp instances that need to be running.
	 *
	 * @param string $parent if not NULL, this will be the parent task of the launch-task
	 * @return boolean|string false on error, id of task otherwise
	 */
	public static function ldadp($parent = NULL)
	{
		$res = Database::simpleQuery("SELECT moduleid, configtgz.filepath FROM configtgz_module"
			. " INNER JOIN configtgz_x_module USING (moduleid)"
			. " INNER JOIN configtgz USING (configid)"
			. " WHERE moduletype = 'AD_AUTH'");
		// TODO: Multiconfig support
		$id = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (readlink('/srv/openslx/www/boot/default/config.tgz') === $row['filepath']) {
				$id[] = (int)$row['moduleid'];
				break;
			}
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
	 * To be called if the server ip changes, as it's embedded in the AD module configs.
	 * This will then recreate all AD tgz modules.
	 */
	public static function rebuildAdModules()
	{
		$res = Database::simpleQuery("SELECT moduleid, filepath, content FROM configtgz_module"
			. " WHERE moduletype = 'AD_AUTH'");
		if ($res->rowCount() === 0)
			return;
		
		$task = Taskmanager::submit('LdadpLauncher', array('ids' => array())); // Stop all running instances
		$parent = isset($task['id']) ? $task['id'] : NULL;
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$config = json_decode($row['contents']);
			$config['proxyip'] = Property::getServerIp();
			$config['moduleid'] = $row['moduleid'];
			$config['filename'] = $row['filepath'];
			$config['parentTask'] = $parent;
			$config['failOnParentFail'] = false;
			$task = Taskmanager::submit('CreateAdConfig', $config);
			$parent = isset($task['id']) ? $task['id'] : NULL;
		}
		
	}
	
	/**
	 * Mount the VM store into the server.
	 *
	 * @return array task status of mount procedure, or false on error
	 */
	public static function mount()
	{
		$vmstore = Property::getVmStoreConfig();
		if (!is_array($vmstore)) return false;
		$storetype = $vmstore['storetype'];
		if ($storetype === 'nfs') $addr = $vmstore['nfsaddr'];
		if ($storetype === 'cifs') $addr = $vmstore['cifsaddr'];
		if ($storetype === 'internal') $addr = 'null';
		return Taskmanager::submit('MountVmStore', array(
			'address' => $addr,
			'type' => 'images',
			'username' => $vmstore['cifsuser'],
			'password' => $vmstore['cifspasswd']
		));
	}

}
