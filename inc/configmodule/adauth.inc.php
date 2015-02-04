<?php

ConfigModule::registerModule(
	'AdAuth', // ID
	Dictionary::translate('config-module', 'adAuth_title'), // Title
	Dictionary::translate('config-module', 'adAuth_description'), // Description
	Dictionary::translate('config-module', 'group_authentication'), // Group
	true // Only one per config?
);

class ConfigModule_AdAuth extends ConfigModule
{

	const VERSION = 1;

	private static $REQUIRED_FIELDS = array('server', 'searchbase', 'binddn');
	private static $OPTIONAL_FIELDS = array('bindpw', 'home');

	protected function generateInternal($tgz, $parent)
	{
		$config = $this->moduleData;
		$config['parentTask'] = $parent;
		$config['failOnParentFail'] = false;
		$config['proxyip'] = Property::getServerIp();
		$config['proxyport'] = 3100 + $this->id();
		$config['filename'] = $tgz;
		$config['moduleid'] = $this->id();
		return Taskmanager::submit('CreateAdConfig', $config);
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

	public function getData($key)
	{
		if (!is_array($this->moduleData) || !isset($this->moduleData[$key]))
			return false;
		return $this->moduleData[$key];
	}

	// ############## Callbacks #############################

	/**
	 * Server IP changed - rebuild all AD modules.
	 */
	public function event_serverIpChanged()
	{
		error_log('Calling generate on ' . $this->title());
		$this->generate(false);
	}

}