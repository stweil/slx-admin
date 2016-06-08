<?php

ConfigModule::registerModule(
	ConfigModule_AdAuth::MODID, // ID
	Dictionary::translateFileModule('sysconfig', 'config-module', 'adAuth_title'), // Title
	Dictionary::translateFileModule('sysconfig', 'config-module', 'adAuth_description'), // Description
	Dictionary::translateFileModule('sysconfig', 'config-module', 'group_authentication'), // Group
	true // Only one per config?
);

class ConfigModule_AdAuth extends ConfigModule
{

	const MODID = 'AdAuth';
	const VERSION = 1;

	private static $REQUIRED_FIELDS = array('server', 'searchbase', 'binddn');
	private static $OPTIONAL_FIELDS = array('bindpw', 'home', 'ssl', 'fingerprint', 'certificate', 'homeattr');

	protected function generateInternal($tgz, $parent)
	{
		Trigger::ldadp($this->id(), $parent);
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
		return Taskmanager::submit('CreateLdapConfig', $config);
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
