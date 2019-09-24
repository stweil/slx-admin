<?php

/**
 * Generic helper for getting and executing hooks.
 */
class Hook
{

	/**
	 * Get list of all known and enabled modules for given hook.
	 * Internally, this scans for "modules/<*>/hooks/$hookName.inc.php"
	 * and optionally checks if the module's dependencies are fulfilled,
	 * then returns a list of all matching modules.
	 * @param string $hookName Name of hook to search for.
	 * @param bool $filterBroken if true, modules that have a hook but have missing deps will not be returned
	 * @return \Hook[] list of modules with requested hooks
	 */
	public static function load($hookName, $filterBroken = true)
	{
		$retval = array();
		foreach (glob('modules/*/hooks/' . $hookName . '.inc.php', GLOB_NOSORT) as $file) {
			preg_match('#^modules/([^/]+)/#', $file, $out);
			if ($filterBroken && !Module::isAvailable($out[1]))
				continue;
			$retval[] = new Hook($out[1], $file);
		}
		return $retval;
	}

	/**
	 * Load given hook for a specific module only.
	 *
	 * @param string $moduleName Module
	 * @param string $hookName Hook
	 * @param bool $filterBroken return false if the module has missing deps
	 * @return Hook|false hook instance, false on error or if module doesn't have given hook
	 */
	public static function loadSingle($moduleName, $hookName, $filterBroken = true)
	{
		if (Module::get($moduleName) === false) // No such module
			return false;
		if ($filterBroken && !Module::isAvailable($moduleName)) // Broken
			return false;
		$file = 'modules/' . $moduleName . '/hooks/' . $hookName . '.inc.php';
		if (!file_exists($file)) // No hook
			return false;
		return new Hook($moduleName, $file);
	}

	/*
	 *
	 */

	public $moduleId;
	public $file;

	private function __construct($module, $hookFile)
	{
		$this->moduleId = $module;
		$this->file = $hookFile;
	}

	/**
	 * Run the hook's code. The include is expected to return a
	 * value, which will in turn be the return value of this
	 * method.
	 *
	 * @return mixed The return value of the include file, or false on error
	 */
	public function run()
	{
		try {
			return (include $this->file);
		} catch (Exception $e) {
			error_log($e);
			return false;
		}
	}

}
