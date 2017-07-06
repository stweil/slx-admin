<?php

class LocationInfo
{

	/**
	 * Gets the pc data and returns it's state.
	 *
	 * @param array $pc The pc data from the db. Array('logintime' =>, 'lastseen' =>, 'lastboot' =>)
	 * @return int pc state
	 */
	public static function getPcState($pc)
	{
		$logintime = (int)$pc['logintime'];
		$lastseen = (int)$pc['lastseen'];
		$lastboot = (int)$pc['lastboot'];
		$NOW = time();

		if ($NOW - $lastseen > 14 * 86400) {
			return "BROKEN";
		} elseif (($NOW - $lastseen > 610) || $lastboot === 0) {
			return "OFF";
		} elseif ($logintime === 0) {
			return "IDLE";
		} elseif ($logintime > 0) {
			return "OCCUPIED";
		}
		return -1;
	}

	/**
	 * Set current error message of given server. Pass null or false to clear.
	 *
	 * @param int $serverId id of server
	 * @param string $message error message to set, null or false clears error.
	 */
	public static function setServerError($serverId, $message)
	{
		if ($message === false || $message === null) {
			Database::exec("UPDATE `locationinfo_coursebackend` SET error = NULL
					WHERE serverid = :id", array('id' => $serverId));
		} else {
			if (empty($message))  {
				$message = '<empty error message>';
			}
			$error = json_encode(array(
				'timestamp' => time(),
				'error' => (string)$message
			));
			Database::exec("UPDATE `locationinfo_coursebackend` SET error = :error
					WHERE serverid = :id", array('id' => $serverId, 'error' => $error));
		}
	}

	/**
	 * Creates and returns a default config for room that didn't saved a config yet.
	 *
	 * @return array Return a default config.
	 */
	public static function defaultPanelConfig($type)
	{
		return array(
			'language' => 'en',
			'mode' => 1,
			'vertical' => false,
			'eco' => false,
			'scaledaysauto' => true,
			'daystoshow' => 7,
			'rotation' => 0,
			'scale' => 50,
			'switchtime' => 20,
			'calupdate' => 30,
			'roomupdate' => 15,
			'configupdate' => 180,
		);
	}

	/**
	 * @param string $uuid panel uuid
	 * @return bool|string panel name if exists, false otherwise
	 */
	public static function getPanelName($uuid)
	{
		$ret = Database::queryFirst('SELECT panelname FROM locationinfo_panel WHERE paneluuid = :uuid', compact('uuid'));
		if ($ret === false) return false;
		return $ret['panelname'];
	}

}
