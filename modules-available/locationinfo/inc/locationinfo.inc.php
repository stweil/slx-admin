<?php

class LocationInfo
{

	/**
	 * Gets the pc data and returns it's state.
	 *
	 * @param array $pc The pc data from the db. Array('state' => xx, 'lastseen' => xxx)
	 * @return int pc state
	 */
	public static function getPcState($pc)
	{
		$lastseen = (int)$pc['lastseen'];
		$NOW = time();

		if ($pc['state'] === 'OFFLINE' && $NOW - $lastseen > 21 * 86400) {
			return "BROKEN";
		}
		return $pc['state'];
	}

	/**
	 * Return list of locationids associated with given panel.
	 * @param string $paneluuid panel
	 * @param bool $recursive if true and paneltype == SUMMARY the result is recursive with all child room ids.
	 * @return int[] locationIds
	 */
	public static function getLocationsOr404($paneluuid, $recursive = true)
	{
		$panel = Database::queryFirst('SELECT paneltype, locationids FROM locationinfo_panel WHERE paneluuid = :paneluuid',
			compact('paneluuid'));
		if ($panel !== false) {
			$idArray = array_map('intval', explode(',', $panel['locationids']));
			if ($panel['paneltype'] == "SUMMARY" && $recursive) {
				$idList = Location::getRecursiveFlat($idArray);
				$idArray = array_keys($idList);
			}
			return $idArray;
		}
		http_response_code(404);
		die('Panel not found');
	}

	/**
	 * Set current error message of given server. Pass null or false to clear.
	 *
	 * @param int $serverId id of server
	 * @param string|array $message error message to set, array of error message struct, null or false clears error.
	 */
	public static function setServerError($serverId, $message)
	{
		if (is_array($message)) {
			$fatal = false;
			foreach ($message as $m) {
				if ($m['fatal']) {
					$fatal = $m['message'];
				}
				Database::exec('INSERT INTO locationinfo_backendlog (serverid, dateline, message)
						VALUES (:sid, :dateline, :message)', [
					'sid' => $serverId,
					'dateline' => $m['time'],
					'message' => ($m['fatal'] ? '[F]' : '[W]') . $m['message'],
				]);
			}
			$message = $fatal;
		}
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
	 * Creates and returns a default config for room that didn't save a config yet.
	 *
	 * @return array Return a default config.
	 */
	public static function defaultPanelConfig($type)
	{
		if ($type === 'DEFAULT') {
			return array(
				'language' => defined('LANG') ? LANG : 'en',
				'mode' => 1,
				'vertical' => false,
				'eco' => false,
				'prettytime' => true,
				'roomplanner' => true,
				'scaledaysauto' => true,
				'daystoshow' => 7,
				'rotation' => 0,
				'scale' => 50,
				'switchtime' => 20,
				'calupdate' => 30,
				'roomupdate' => 15,
				'configupdate' => 180,
				'overrides' => [],
			);
		}
		if ($type === 'SUMMARY') {
			return array(
				'language' => defined('LANG') ? LANG : 'en',
				'roomplanner' => true,
				'eco' => false,
				'panelupdate' => 60,
			);
		}
		if ($type === 'URL') {
			return array(
				'iswhitelist' => 0,
				'urllist' => '',
				'insecure-ssl' => 0,
				'reload-minutes' => 0,
				'split-login' => 0,
				'browser' => 'slx-browser',
				'interactive' => 0,
				'bookmarks' => '',
			);
		}
		return array();
	}

}
