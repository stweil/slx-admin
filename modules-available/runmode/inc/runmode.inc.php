<?php

class RunMode
{
	const DATA_DETAILED = 1;
	const DATA_MACHINE_DATA = 2;
	const DATA_STRINGS = 4;

	private static $moduleConfigs = array();

	/**
	 * Get runmode config for a specific module
	 *
	 * @param string $module name of module
	 * @return \RunModeModuleConfig|false config, false if moudles doesn't support run modes
	 */
	public static function getModuleConfig($module)
	{
		if (isset(self::$moduleConfigs[$module]))
			return self::$moduleConfigs[$module];
		if (Module::get($module) === false)
			return false;
		$file = 'modules/' . $module . '/hooks/runmode/config.json';
		if (!file_exists($file))
			return false;
		return (self::$moduleConfigs[$module] = new RunModeModuleConfig($file));
	}

	/**
	 * @param string $machineuuid
	 * @param string|\Module $moduleId
	 * @param string|null $modeId an ID specific to the module to further specify the run mode, NULL to delete the run mode entry
	 * @param string|null $modeData optional, additional data for the run mode
	 * @param bool|null $isClient whether to count the machine as a client (in statistics etc.) NULL for looking at module's general runmode config
	 * @return bool whether it was set/deleted
	 */
	public static function setRunMode($machineuuid, $moduleId, $modeId, $modeData = null, $isClient = null)
	{
		if (is_object($moduleId)) {
			$moduleId = $moduleId->getIdentifier();
		}
		// - Check if machine exists
		$machine = Statistics::getMachine($machineuuid, Machine::NO_DATA);
		if ($machine === false)
			return false;
		// - Delete entry if mode is null
		if ($modeId === null) {
			return Database::exec('DELETE FROM runmode WHERE machineuuid = :machineuuid', compact('machineuuid')) > 0;
		}
		// - Add/replace entry in runmode table
		// - Check if module provides runmode config at all
		$config = self::getModuleConfig($moduleId);
		if ($config === false)
			return false;
		if ($isClient === null) {
			$isClient = $config->isClient;
		}
		Database::exec('INSERT INTO runmode (machineuuid, module, modeid, modedata, isclient)'
			. ' VALUES (:uuid, :module, :modeid, :modedata, :isclient)'
			. ' ON DUPLICATE KEY'
			. ' UPDATE module = VALUES(module), modeid = VALUES(modeid), modedata = VALUES(modedata), isclient = VALUES(isclient)', array(
				'uuid' => $machineuuid,
				'module' => $moduleId,
				'modeid' => $modeId,
				'modedata' => $modeData,
				'isclient' => ($isClient ? 1 : 0),
		));
		return true;
	}

	/**
	 * Change the isClient flag for existing client.
	 * @param string $machineUuid existing machine with some runmode
	 * @param string $moduleId module that assigned the current runmode of that client
	 * @param bool $isClient
	 */
	public static function updateClientFlag($machineUuid, $moduleId, $isClient)
	{
		Database::exec('UPDATE runmode SET isclient = :isclient WHERE machineuuid = :uuid AND module = :module',
			['uuid' => $machineUuid, 'module' => $moduleId, 'isclient' => ($isClient ? 1 : 0)]);
	}

	/**
	 * @param string $machineuuid
	 * @param int $returnData bitfield of data to return
	 * @return false|array {'machineuuid', 'isclient', 'module', 'modeid', 'modedata',
	 * 		('hostname', 'clientip', 'macaddr', 'locationid', 'lastseen'), ('moduleName', 'modeName')}
	 */
	public static function getRunMode($machineuuid, $returnData = self::DATA_MACHINE_DATA)
	{
		if ($returnData === true) {
			$returnData = self::DATA_MACHINE_DATA | self::DATA_DETAILED;
		}
		if ($returnData & self::DATA_MACHINE_DATA) {
			if ($returnData & self::DATA_DETAILED) {
				$sel = ', m.hostname, m.clientip, m.macaddr, m.locationid, m.lastseen';
			} else {
				$sel = '';
			}
			$res = Database::queryFirst(
				"SELECT m.machineuuid, r.isclient, r.module, r.modeid, r.modedata $sel
					FROM machine m INNER JOIN runmode r USING (machineuuid)
					WHERE m.machineuuid = :machineuuid LIMIT 1",
				compact('machineuuid'));
		} else {
			$res = Database::queryFirst('SELECT r.machineuuid, r.isclient, r.module, r.modeid, r.modedata
					FROM runmode r
					WHERE r.machineuuid = :machineuuid LIMIT 1',
				compact('machineuuid'));
		}
		if ($res === false)
			return false;
		if ($returnData & self::DATA_STRINGS) {
			$module = Module::get($res['module']);
			if ($module === false) {
				$res['moduleName'] = $res['module'];
			} else {
				$res['moduleName'] = $module->getDisplayName();
			}
			$mode = self::getModeName($res['module'], $res['modeid']);
			if ($mode === false) {
				$mode = '???? unknown';
			}
			$res['modeName'] = $mode;
		}
		return $res;
	}

	/**
	 * @param string|\Module $module
	 * @param bool true = wrap in array where key is modeid
	 * @return array key=machineuuid, value={'machineuuid', 'modeid', 'modedata'}
	 */
	public static function getForModule($module, $groupByModeId = false)
	{
		if (is_object($module)) {
			$module = $module->getIdentifier();
		}
		$res = Database::simpleQuery('SELECT machineuuid, modeid, modedata FROM runmode WHERE module = :module',
			compact('module'));
		$ret = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($groupByModeId) {
				if (!isset($ret[$row['modeid']])) {
					$ret[$row['modeid']] = array();
				}
				$ret[$row['modeid']][] = $row;
			} else {
				$ret[$row['machineuuid']] = $row;
			}
		}
		return $ret;
	}

	/**
	 * @param string|\Module $module
	 * @param string $modeId
	 * @param bool $detailed whether to return meta data about machine, not just machineuuid
	 * @param bool $assoc use machineuuid as array key
	 * @return array <key=machineuuid>, value={'machineuuid', 'modedata',
	 * 		<'hostname', 'clientip', 'macaddr', 'locationid', 'lastseen'>}
	 */
	public static function getForMode($module, $modeId, $detailed = false, $assoc = false)
	{
		if (is_object($module)) {
			$module = $module->getIdentifier();
		}
		if ($detailed) {
			$sel = ', m.hostname, m.clientip, m.macaddr, m.locationid, m.lastseen';
			$join = 'INNER JOIN machine m USING (machineuuid)';
		} else {
			$join = $sel = '';
		}
		$res = Database::simpleQuery(
			"SELECT r.machineuuid, r.modedata, r.isclient $sel
				FROM runmode r $join
				WHERE module = :module AND modeid = :modeId",
			compact('module', 'modeId'));
		$ret = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($detailed && empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			if ($assoc) {
				$ret[$row['machineuuid']] = $row;
			} else {
				$ret[] = $row;
			}
		}
		return $ret;
	}

	/**
	 * Return assoc array of all configured clients.
	 * @param bool $withData also return data field?
	 * @param bool $isClient true = return clients only, false = return non-clients only, null = return both
	 * @return array all the entries from the table
	 */
	public static function getAllClients($withData = false, $isClient = null)
	{
		$xtra = '';
		if ($withData) {
			$xtra .= ', modedata';
		}
		$res = Database::simpleQuery("SELECT machineuuid, module, modeid, isclient $xtra FROM runmode");
		$ret = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!is_null($isClient) && ($row['isclient'] != 0) !== $isClient)
				continue;
			$ret[$row['machineuuid']] = $row;
		}
		return $ret;
	}

	/**
	 * Get display name of a module's mode. If the module doesn't have a getModeName
	 * method configured, the modeId is simply returned. Otherwise the return value of
	 * that method is passed through. getModeName by contract should return false if
	 * the module doesn't think the given modeId exists.
	 *
	 * @param string|\Module $module
	 * @param string $modeId
	 * @return string|bool mode name if known, modeId as fallback, or false if mode is not known by module
	 */
	public static function getModeName($module, $modeId)
	{
		if (is_object($module)) {
			$module = $module->getIdentifier();
		}
		$conf = self::getModuleConfig($module);
		if ($conf === false || $conf->getModeName === false || !Module::isAvailable($module))
			return $modeId;
		return call_user_func($conf->getModeName, $modeId);
	}

	/**
	 * Delete given runmode.
	 *
	 * @param string|\Module $module Module runmode belongs to
	 * @param string $modeId run mode id
	 */
	public static function deleteMode($module, $modeId)
	{
		if (is_object($module)) {
			$module = $module->getIdentifier();
		}
		Database::exec('DELETE FROM runmode WHERE module = :module AND modeid = :modeId',
			compact('module', 'modeId'));
	}

}

/*                *\
|* Helper classes *|
\*                */

/**
 * Class RunModeModuleConfig represents desired config of a runmode
 */
class RunModeModuleConfig
{
	/**
	 * @var string|false
	 */
	public $systemdDefaultTarget = false;
	/**
	 * @var string[]
	 */
	public $systemdDisableTargets = [];
	/**
	 * @var string[]
	 */
	public $systemdEnableTargets = [];
	/**
	 * @var string Name of function that turns a modeId into a string
	 */
	public $getModeName = false;
	/**
	 * @var string Name of function that is called to add additional config entries
	 */
	public $configHook = false;
	/**
	 * @var bool Consider this a normal client that should e.g. be shown in client statistics by default
	 */
	public $isClient = false;
	/**
	 * @var bool Allow adding and removing machines to this mode via the generic form
	 */
	public $allowGenericEditor = true;
	/**
	 * @var string Snippet to construct URL for delete
	 */
	public $deleteUrlSnippet = false;

	/**
	 * @var string|false Permission to check when accessing/assigning
	 */
	public $permission = false;

	public function __construct($file)
	{
		$data = json_decode(file_get_contents($file), true);
		if (!is_array($data))
			return;
		$this->loadType($data, 'systemdDefaultTarget', 'string');
		$this->loadType($data, 'systemdDisableTargets', 'array');
		$this->loadType($data, 'systemdEnableTargets', 'array');
		$this->loadType($data, 'getModeName', 'string');
		$this->loadType($data, 'configHook', 'string');
		$this->loadType($data, 'isClient', 'boolean');
		$this->loadType($data, 'allowGenericEditor', 'boolean');
		$this->loadType($data, 'deleteUrlSnippet', 'string');
		$this->loadType($data, 'permission', 'string');
	}

	private function loadType($data, $key, $type)
	{
		if (!isset($data[$key]))
			return false;
		if (is_string($type) && gettype($data[$key]) !== $type)
			return false;
		if (is_array($type) && !in_array(gettype($data[$key]), $type))
			return false;
		$this->{$key} = $data[$key];
		return true;
	}

	public function userHasPermission($locationId)
	{
		return $this->permission === false || User::hasPermission($this->permission, $locationId);
	}

}
