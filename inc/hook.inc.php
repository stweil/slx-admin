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

}
