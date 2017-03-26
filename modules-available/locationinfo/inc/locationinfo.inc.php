<?php

class LocationInfo
{

	/**
	 * Gets the pc data and returns it's state.
	 *
	 * @param $pc The pc data from the db. Array('logintime' =>, 'lastseen' =>, 'lastboot' =>)
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

}
