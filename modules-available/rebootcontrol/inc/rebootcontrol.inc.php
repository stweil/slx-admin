<?php

class RebootControl
{

	/**
	 * @param string[] $uuids List of machineuuids to reboot
	 * @return false|array task struct for the reboot job
	 */
	public static function reboot($uuids)
	{
		$list = RebootQueries::getMachinesByUuid($uuids);
		if (empty($list))
			return false;
		return self::execute($list, false, 0, 0);
	}

	public static function execute($list, $shutdown, $minutes, $locationId)
	{
		return Taskmanager::submit("RemoteReboot", array(
			"clients" => $list,
			"shutdown" => $shutdown,
			"minutes" => $minutes,
			"locationId" => $locationId,
			"sshkey" => SSHKey::getPrivateKey(),
			"port" => 9922, // Hard-coded, must match mgmt-sshd module
		));
	}

}