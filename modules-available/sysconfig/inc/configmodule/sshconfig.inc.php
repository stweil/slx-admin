<?php

ConfigModule::registerModule(
	ConfigModule_SshConfig::MODID, // ID
	Dictionary::translateFileModule('sysconfig', 'config-module', 'sshconfig_title'), // Title
	Dictionary::translateFileModule('sysconfig', 'config-module', 'sshconfig_description'), // Description
	Dictionary::translateFileModule('sysconfig', 'config-module', 'group_sshconfig'), // Group
	false // Only one per config?
);

class ConfigModule_SshConfig extends ConfigModule
{
	const MODID = 'SshConfig';
	const VERSION = 1;

	protected function generateInternal($tgz, $parent)
	{
		if (!$this->validateConfig())
			return false;
		$config = $this->moduleData + array(
			'filename' => $tgz,
			'failOnParentFail' => false,
			'parent' => $parent
		);
		// Create config module, which will also check if the pubkey is valid
		return Taskmanager::submit('SshdConfigGenerator', $config);
	}

	protected function moduleVersion()
	{
		return self::VERSION;
	}

	protected function validateConfig()
	{
		return isset($this->moduleData['publicKey']) && isset($this->moduleData['allowPasswordLogin']) && isset($this->moduleData['listenPort']);
	}

	public function setData($key, $value)
	{
		switch ($key) {
		case 'publicKey':
			break;
		case 'allowPasswordLogin':
			if ($value === true || $value === 'yes')
				$value = 'yes';
			elseif ($value === false || $value === 'no')
				$value = 'no';
			else
				return false;
			break;
		case 'listenPort':
			if (!is_numeric($value) || $value < 1 || $value > 65535)
				return false;
			break;
		default:
			return false;
		}
		$this->moduleData[$key] = $value;
		return true;
	}

}
