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
			);
		}
		return array();
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

	/**
	 * Hook called by runmode module where we should modify the client config according to our
	 * needs. Disable standby/logout timeouts, enable autologin, set URL.
	 * @param $machineUuid
	 * @param $panelUuid
	 */
	public static function configHook($machineUuid, $panelUuid)
	{
		$type = InfoPanel::getConfig($panelUuid, $data);
		if ($type === false)
			return; // TODO: Invalid panel - what should we do?
		if ($type === 'URL') {
			// Check if we should set the insecure SSL mode (accept invalid/self signed certs etc.)
			if ($data['insecure-ssl'] !== 0) {
				ConfigHolder::add('SLX_BROWSER_INSECURE', '1');
			}
			if ($data['reload-minutes'] > 0) {
				ConfigHolder::add('SLX_BROWSER_RELOAD_SECS', $data['reload-minutes'] * 60);
			}
			ConfigHolder::add('SLX_BROWSER_URL', $data['url']);
			ConfigHolder::add('SLX_BROWSER_URLLIST', $data['urllist']);
			ConfigHolder::add('SLX_BROWSER_IS_WHITELIST', $data['iswhitelist']);
		} else {
			// Not URL panel
			ConfigHolder::add('SLX_BROWSER_URL', 'http://' . $_SERVER['SERVER_ADDR'] . '/panel/' . $panelUuid);
			ConfigHolder::add('SLX_BROWSER_INSECURE', '1'); // TODO: Sat server might redirect to HTTPS, which in turn could have a self-signed cert - push to client
		}
		ConfigHolder::add('SLX_ADDONS', '', 1000);
		ConfigHolder::add('SLX_LOGOUT_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_SCREEN_STANDBY_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_SYSTEM_STANDBY_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_AUTOLOGIN', '1', 1000);
	}

}
