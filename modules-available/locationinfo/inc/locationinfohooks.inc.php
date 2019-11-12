<?php

class LocationInfoHooks
{

	/**
	 * @param string $uuid panel uuid
	 * @return bool|string panel name if exists, false otherwise
	 */
	public static function getPanelName($uuid)
	{
		$ret = Database::queryFirst('SELECT panelname FROM locationinfo_panel WHERE paneluuid = :uuid', compact('uuid'));
		if ($ret === false)
			return false;
		return $ret['panelname'];
	}

	/**
	 * Hook called by runmode module where we should modify the client config according to our
	 * needs. Disable standby/logout timeouts, enable autologin, set URL.
	 *
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
			// Additionally, update runmode "isclient" flag depending on whether split-login is allowed or not
			if (isset($data['split-login']) && $data['split-login']) {
				RunMode::updateClientFlag($machineUuid, 'locationinfo', true);
			} else { // Automatic login
				RunMode::updateClientFlag($machineUuid, 'locationinfo', false);
				ConfigHolder::add('SLX_AUTOLOGIN', '1', 1000);
			}
			if (isset($data['interactive']) && $data['interactive']) {
				ConfigHolder::add('SLX_BROWSER_INTERACTIVE', '1', 1000);
			}
			if (!empty($data['browser'])) {
				if ($data['browser'] === 'chromium') {
					$browser = 'chromium chrome';
				} else {
					$browser = 'slxbrowser slx-browser';
				}
				ConfigHolder::add('SLX_BROWSER', $browser, 1000);
			}
			if (!empty($data['bookmarks'])) {
				ConfigHolder::add('SLX_BROWSER_BOOKMARKS', $data['bookmarks'], 1000);
			}
		} else {
			// Not URL panel
			ConfigHolder::add('SLX_BROWSER_URL', 'http://' . $_SERVER['SERVER_ADDR'] . '/panel/' . $panelUuid);
			ConfigHolder::add('SLX_BROWSER_INSECURE', '1'); // TODO: Sat server might redirect to HTTPS, which in turn could have a self-signed cert - push to client
			ConfigHolder::add('SLX_AUTOLOGIN', '1', 1000);
		}
		ConfigHolder::add('SLX_ADDONS', '', 1000);
		ConfigHolder::add('SLX_LOGOUT_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_SCREEN_STANDBY_TIMEOUT', '', 1000);
		ConfigHolder::add('SLX_SYSTEM_STANDBY_TIMEOUT', '', 1000);
	}

}