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
		/*   pcState:
		 *  [0] =  IDLE (NOT IN USE)
		 *  [1] = OCCUPIED (IN USE)
		 *  [2] = OFF
		 *  [3] = 10 days offline (BROKEN?)
		 */
		// TODO USE STATE NAME instead of numbers

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

}
