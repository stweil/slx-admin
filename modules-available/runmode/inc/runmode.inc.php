<?php

class RunMode
{

	private static $moduleConfigs = array();

	/**
	 * Get runmode config for a specific module
	 *
	 * @param string $module name of module
	 * @return RunModeModuleConfig|false
	 */
	private static function getModuleConfig($module)
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

	public static function setRunMode($machineuuid, $moduleId, $modeId, $modeData)
	{
		// - Check if module provides runmode config at all
		$config = self::getModuleConfig($moduleId);
		if ($config === false)
			return false;
		// - Check if machine exists
		$machine = Statistics::getMachine($machineuuid, Machine::NO_DATA);
		if ($machine === false)
			return false;
		// - Add/replace entry in runmode table
		if (is_null($modeId)) {
			Database::exec('DELETE FROM runmode WHERE machineuuid = :machineuuid', compact('machineuuid'));
		} else {
			Database::exec('INSERT INTO runmode (machineuuid, module, modeid, modedata)'
				. ' VALUES (:uuid, :module, :modeid, :modedata)'
				. ' ON DUPLICATE KEY UPDATE module = VALUES(module), modeid = VALUES(modeid), modedata = VALUES(modedata)', array(
					'uuid' => $machineuuid,
					'module' => $moduleId,
					'modeid' => $modeId,
					'modedata' => $modeData,
			));
		}
		return true;
	}

	/**
	 * @param string|\Module $module
	 * @return array
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
	 * @return array
	 */
	public static function getForMode($module, $modeId, $detailed = false)
	{
		if (is_object($module)) {
			$module = $module->getIdentifier();
		}
		if ($detailed) {
			$sel = ', m.hostname, m.clientip, m.macaddr, m.locationid';
			$join = 'INNER JOIN machine m USING (machineuuid)';
		} else {
			$join = $sel = '';
		}
		$res = Database::simpleQuery(
			"SELECT r.machineuuid, r.modedata $sel
				FROM runmode r $join
				WHERE module = :module AND modeid = :modeId",
			compact('module', 'modeId'));
		$ret = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($detailed && empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			$ret[] = $row;
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
	 * @var bool Consider this a normal client that should e.g. be shown in client statistics by default
	 */
	public $isClient = false;

	public function __construct($file)
	{
		$data = json_decode(file_get_contents($file), true);
		if (!is_array($data))
			return;
		$this->loadType($data, 'systemdDefaultTarget', 'string');
		$this->loadType($data, 'systemdDisableTargets', 'array');
		$this->loadType($data, 'systemdEnableTargets', 'array');
		$this->loadType($data, 'getModeName', 'string');
		$this->loadType($data, 'isClient', 'string');
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
}
