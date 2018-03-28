<?php

abstract class ConfigModuleBaseLdap extends ConfigModule
{

	const VERSION = 3;

	private static $REQUIRED_FIELDS = array('server', 'searchbase');
	private static $OPTIONAL_FIELDS = array('binddn', 'bindpw', 'home', 'ssl', 'fixnumeric', 'fingerprint', 'certificate', 'homeattr',
		'shareRemapMode', 'shareRemapCreate', 'shareDocuments', 'shareDownloads', 'shareDesktop', 'shareMedia',
		'shareOther', 'shareHomeDrive', 'shareDomain', 'credentialPassthrough', 'mapping',
		'ldapAttrMountOpts', 'shareHomeMountOpts');

	public static function getMapping($config = false, &$empty = true)
	{
		$list = array(
			['name' => 'uid', 'field' => 'uid', 'ad' => 'sAMAccountName'],
			['name' => 'uidnumber', 'field' => 'uidnumber', 'ad' => false],
			['name' => 'uncHomePath', 'field' => 'homemount', 'ad' => 'homeDirectory'],
			['name' => 'homeDirectory', 'field' => 'localhome', 'ad' => false],
			['name' => 'posixAccount', 'field' => 'posixAccount', 'ad' => 'user'],
			//['name' => 'shadowAccount', 'field' => 'shadowAccount'],
		);
		if (is_array($config)) {
			foreach ($list as &$item) {
				if (!empty($config[$item['field']])) {
					$item['value'] = $config[$item['field']];
					$empty = false;
				}
			}
		}
		return $list;
	}

	protected function generateInternal($tgz, $parent)
	{
		$np = Trigger::ldadp($this->id(), $parent);
		if ($np !== false) {
			$parent = $np;
		}
		$config = $this->moduleData;
		if (isset($config['certificate']) && !is_string($config['certificate'])) {
			unset($config['certificate']);
		}
		if (preg_match('/^([^\:]+)\:(\d+)$/', $config['server'], $out)) {
			$config['server'] = $out[1];
			$config['adport'] = $out[2];
		} else {
			if (isset($config['certificate'])) {
				$config['adport'] = 636;
			} else {
				$config['adport'] = 389;
			}
		}
		$config['parentTask'] = $parent;
		$config['failOnParentFail'] = false;
		$config['proxyip'] = Property::getServerIp();
		$config['proxyport'] = 3100 + $this->id();
		$config['filename'] = $tgz;
		$config['moduleid'] = $this->id();
		if (!isset($config['shareRemapMode'])) {
			$config['shareRemapMode'] = 3;
		}
		if (!isset($config['shareHomeDrive'])) {
			$config['shareHomeDrive'] = 'H:';
		}
		if (!isset($config['fixnumeric'])) {
			$config['fixnumeric'] = 's';
		}
		$this->preTaskmanagerHook($config);
		$task = Taskmanager::submit('CreateLdapConfig', $config);
		if (is_array($task) && isset($task['id'])) {
			Trigger::ldadp(null, $task['id']);
		}
		return $task;
	}

	/**
	 * Hook called before running CreateLdapConfig task with the
	 * configuration to be passed to the task. Passed by reference
	 * so it can be modified.
	 *
	 * @param array $config
	 */
	protected function preTaskmanagerHook(&$config)
	{
	}

	protected function moduleVersion()
	{
		return self::VERSION;
	}

	protected function validateConfig()
	{
		// Check if required fields are filled
		return Util::hasAllKeys($this->moduleData, self::$REQUIRED_FIELDS);
	}

	public function setData($key, $value)
	{
		if (!in_array($key, self::$REQUIRED_FIELDS) && !in_array($key, self::$OPTIONAL_FIELDS))
			return false;
		$this->moduleData[$key] = $value;
		return true;
	}

	// ############## Callbacks #############################

	/**
	 * Server IP changed - rebuild all AD modules.
	 */
	public function event_serverIpChanged()
	{
		$this->generate(false);
	}

}
