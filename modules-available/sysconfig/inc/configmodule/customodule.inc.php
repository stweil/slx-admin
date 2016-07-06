<?php

ConfigModule::registerModule(
	ConfigModule_CustomModule::MODID, // ID
	Dictionary::translateFileModule('sysconfig', 'config-module', 'custom_title'), // Title
	Dictionary::translateFileModule('sysconfig', 'config-module', 'custom_description'), // Description
	Dictionary::translateFileModule('sysconfig', 'config-module', 'group_generic'), // Group
	false, // Only one per config?
	100 // Sort order
);

class ConfigModule_CustomModule extends ConfigModule
{
	const MODID = 'CustomModule';
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
		if ($key !== 'tmpFile' || !file_exists($value))
			return false;
		$this->tmpFile = $value;
		return true;
	}

	public function getData($key)
	{
		return false;
	}

	public function allowDownload()
	{
		return true;
	}

}
