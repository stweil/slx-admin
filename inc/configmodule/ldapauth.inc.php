<?php

ConfigModule::registerModule(
	'LdapAuth', // ID
	Dictionary::translate('config-module', 'ldapAuth_title'), // Title
	Dictionary::translate('config-module', 'ldapAuth_description'), // Description
	Dictionary::translate('config-module', 'group_authentication'), // Group
	true // Only one per config?
);

class ConfigModule_LdapAuth extends ConfigModule
{

	const VERSION = 1;

	private static $REQUIRED_FIELDS = array('server', 'searchbase');
	private static $OPTIONAL_FIELDS = array('binddn', 'bindpw', 'home', 'ssl', 'fingerprint', 'certificate');

	protected function generateInternal($tgz, $parent)
	{
		Trigger::ldadp($this->id(), $parent);
		$config = $this->moduleData;
		if (isset($config['certificate']) && !is_string($config['certificate'])) {
			unset($config['certificate']);
		}
		if (preg_match('/^([^\:]+)\:(\d+)$/', $config['server'], $out)) {
			$config['server'] = $out[1];
			$config['adport'] = $out[2]; // sic!
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
		$config['plainldap'] = true;
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
	 * Server IP changed - rebuild all LDAP modules.
	 */
	public function event_serverIpChanged()
	{
		error_log('Calling generate on ' . $this->title());
		$this->generate(false);
	}

}
