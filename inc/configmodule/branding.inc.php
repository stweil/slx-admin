<?php

ConfigModule::registerModule(
	ConfigModule_Branding::MODID, // ID
	Dictionary::translate('config-module', 'branding_title'), // Title
	Dictionary::translate('config-module', 'branding_description'), // Description
	Dictionary::translate('config-module', 'group_branding'), // Group
	true // Only one per config?
);

class ConfigModule_Branding extends ConfigModule
{

	const MODID = 'Branding';
	const VERSION = 1;
	
	private $tmpFile = false;

	protected function generateInternal($tgz, $parent)
	{
		if (!$this->validateConfig()) {
			return $this->archive() !== false && file_exists($this->archive()); // No new temp file given, old archive still exists, pretend it worked...
		}
		$task = Taskmanager::submit('MoveFile', array(
			'source' => $this->tmpFile,
			'destination' => $tgz,
			'parentTask' => $parent,
			'failOnParentFail' => false
		));
		return $task;
	}

	protected function moduleVersion()
	{
		return self::VERSION;
	}

	protected function validateConfig()
	{
		return $this->tmpFile !== false && file_exists($this->tmpFile);
	}

	public function setData($key, $value)
	{
		if ($key !== 'tmpFile' || !is_string($value) || !file_exists($value))
			return false;
		$this->tmpFile = $value;
	}

	public function getData($key)
	{
		return false;
	}

}
