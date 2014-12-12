<?php

ConfigModules::registerModule(
	ConfigModule_AdAuth::MODID, // ID
	Dictionary::translate('config-module', 'adAuth_title'), // Title
	Dictionary::translate('config-module', 'adAuth_description'), // Description
	Dictionary::translate('config-module', 'group_authentication'), // Group
	true // Only one per config?
);

class ConfigModule_AdAuth extends ConfigModule
{
	const MODID = 'AdAuth';

	public static function insert($title, $server, $searchbase, $binddn, $bindpw, $home)
	{
		Database::exec("LOCK TABLE configtgz_module WRITE");
		Database::exec("INSERT INTO configtgz_module (title, moduletype, filepath, contents) "
			. " VALUES (:title, :modid, '', '')", array('title' => $title, 'modid' => self::MODID));
		$id = Database::lastInsertId();
		if (!is_numeric($id)) Util::traceError('Inserting new AD config to DB did not yield a numeric insert id');
		// Entry created, now try to get a free port for the proxy
		$res = Database::simpleQuery("SELECT moduleid, contents FROM configtgz_module WHERE moduletype = :modid", array(
			'modid' => self::MODID
		));
		$ports = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['moduleid'] == $id) {
				// ...
			} else {
				$data = json_decode($row['contents'], true);
				if (isset($data['proxyport'])) $ports[] = $data['proxyport'];
			}
		}
		$port = 3300;
		while (in_array($port, $ports)) {
			$port++;
		}
		// Port determined, carry on...
		$ownEntry = array(
			'server' => $server,
			'searchbase' => $searchbase,
			'binddn' => $binddn,
			'bindpw' => $bindpw,
			'home' => $home,
			'proxyport' => $port
		);
		$data = json_encode($ownEntry);
		if ($data === false) Util::traceError('Serializing the AD data failed.');
		$moduleTgz = CONFIG_TGZ_LIST_DIR . '/modules/AD_AUTH_id_' . $id . '.' . mt_rand() . '.tgz';
		Database::exec("UPDATE configtgz_module SET filepath = :filename, contents = :contents WHERE moduleid = :id LIMIT 1", array(
			'id' => $id,
			'filename' => $moduleTgz,
			'contents' => $data
		));
		Database::exec("UNLOCK TABLES");
		// Add archive file name to array before returning it
		$ownEntry['moduleid'] = $id;
		$ownEntry['filename'] = $moduleTgz;
		return $ownEntry;
	}

	/**
	 * To be called if the server ip changes, as it's embedded in the AD module configs.
	 * This will then recreate all AD tgz modules.
	 */
	private static function rebuildAll($parent = NULL)
	{
		// Stop all running instances of ldadp
		$task = Taskmanager::submit('LdadpLauncher', array(
				'parentTask' => $parent,
				'failOnParentFail' => false,
				'ids' => array()
		));
		$ads = self::getAll();
		if (empty($ads)) // Nothing to do
			return false;

		if (isset($task['id']))
			$parent = $task['id'];
		foreach ($ads as $ad) {
			$ad['parentTask'] = $parent;
			$ad['failOnParentFail'] = false;
			$ad['proxyip'] = Property::getServerIp();
			$task = Taskmanager::submit('CreateAdConfig', $ad);
			if (isset($task['id']))
				$parent = $task['id'];
		}
		Trigger::ldadp($parent);
		return $parent;
	}
	
	/**
	 * Get all existing AD proxy configs.
	 * 
	 * @return array array of ad configs in DB with fields:
	 *		moduleid, filename, server, searchbase, binddn, bindpw, home, proxyport
	 */
	public static function getAll()
	{
		$res = Database::simpleQuery("SELECT moduleid, filepath, contents FROM configtgz_module WHERE moduletype = :modid", array(
			'modid' => self::MODID
		));
		$mods = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data = json_decode($row['contents'], true);
			$data['moduleid'] = $row['moduleid'];
			$data['filename'] = $row['filepath'];
			$mods[] = $data;
		}
		return $mods;
	}
	
	// ############## Callbacks #############################
	
	/**
	 * Server IP changed - rebuild all AD modules.
	 */
	public function event_serverIpChanged()
	{
		self::rebuildAll();
	}
	
}
