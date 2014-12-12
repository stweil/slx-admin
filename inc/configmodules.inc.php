<?php

class ConfigModules
{

	private static $moduleTypes = false;

	/**
	 * Load all known config module types. This is done
	 * by including *.inc.php from inc/configmodule/. The
	 * files there should in turn call ConfigModule::registerModule()
	 * to register themselves.
	 */
	public static function loadDb()
	{
		if (self::$moduleTypes !== false)
			return;
		self::$moduleTypes = array();
		foreach (glob('inc/configmodule/*.inc.php') as $file) {
			require_once $file;
		}
	}

	/**
	 * Get all known config modules.
	 *
	 * @return array list of modules
	 */
	public static function getList()
	{
		self::loadDb();
		return self::$moduleTypes;
	}

	/**
	 * Add a known configuration module. Every inc/configmodule/*.inc.php should call this.
	 *
	 * @param string $id Identifier for the module.
	 * 		The module class must be called ConfigModule_{$id}, the wizard start class {$id}_Start.
	 * 		The wizard's classes should be located in modules/sysconfig/addmodule_{$id_lowercase}.inc.php
	 * @param string $title Title of this module type
	 * @param string $description Description for this module type
	 * @param string $group Title for group this module type belongs to
	 * @param bool $unique Can only one such module be added to a config?
	 * @param int $sortOrder Lower comes first, alphabetical ordering otherwiese
	 */
	public static function registerModule($id, $title, $description, $group, $unique, $sortOrder = 0)
	{
		if (isset(self::$moduleTypes[$id]))
			Util::traceError("Config Module $id already registered!");
		$moduleClass = 'ConfigModule_' . $id;
		$wizardClass = $id . '_Start';
		if (!class_exists($moduleClass))
			Util::traceError("Class $moduleClass does not exist!");
		if (get_parent_class($moduleClass) !== 'ConfigModule')
			Util::traceError("$moduleClass does not have ConfigModule as its parent!");
		self::$moduleTypes[$id] = array(
			'title' => $title,
			'description' => $description,
			'group' => $group,
			'unique' => $unique,
			'sortOrder' => $sortOrder,
			'moduleClass' => $moduleClass,
			'wizardClass' => $wizardClass
		);
	}

	/**
	 * Will be called if the server's IP address changes. The event will be propagated
	 * to all config module classes so action can be taken if appropriate.
	 */
	public static function serverIpChanged()
	{
		self::loadDb();
		foreach (self::$moduleTypes as $module) {
			$instance = new $module['moduleClass'];
			$instance->event_serverIpChanged();
		}
	}

}

/**
 * Base class for config modules
 */
abstract class ConfigModule
{

	public function event_serverIpChanged()
	{
		
	}

}
