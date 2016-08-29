<?php

class BaseConfigUtil
{

	/**
	 * Return all config variables to be handled directly by the baseconfig edit module.
	 * The array will contain a list of mapping of type:
	 * VARNAME => array(
	 *     catid => xx,
	 *     defaultvalue => xx,
	 *     permissions => xx,
	 *     validator => xx,
	 * )
	 *
	 * @param \Module $module optional, only consider given module, not all enabled modules
	 * @return array all known config variables
	 */
	public static function getVariables($module = false)
	{
		$settings = array();
		if ($module === false) {
			$module = '*';
		} else {
			$module = $module->getIdentifier();
		}
		foreach (glob("modules/{$module}/baseconfig/settings.json", GLOB_NOSORT) as $file) {
			$data = json_decode(file_get_contents($file), true);
			if (!is_array($data))
				continue;
			preg_match('#^modules/([^/]+)/#', $file, $out);
			foreach ($data as &$entry) {
				$entry['module'] = $out[1];
			}
			$settings += $data;
		}
		return $settings;
	}

	public static function getCategories($module = false)
	{
		$categories = array();
		if ($module === false) {
			$module = '*';
		} else {
			$module = $module->getIdentifier();
		}
		foreach (glob("modules/{$module}/baseconfig/categories.json", GLOB_NOSORT) as $file) {
			$data = json_decode(file_get_contents($file), true);
			if (!is_array($data))
				continue;
			preg_match('#^modules/([^/]+)/#', $file, $out);
			foreach ($data as &$entry) {
				$entry = array('module' => $out[1], 'sortpos' => $entry);
			}
			$categories += $data;
		}
		return $categories;
	}

	/**
	 * Mark variables that would be shadowed according to the given values.
	 *
	 * @param $vars list of vars as obtained from BaseConfigUtil::getVariables()
	 * @param $values key-value-pairs of variable assignments to work with
	 */
	public static function markShadowedVars(&$vars, $values) {
		foreach ($vars as $key => &$var) {
			if (!isset($var['shadows']))
				continue;
			foreach ($var['shadows'] as $triggerVal => $destSettings) {
				if (isset($values[$key]) && $values[$key] !== $triggerVal)
					continue;
				foreach ($destSettings as $destSetting) {
					if (isset($vars[$destSetting])) {
						$vars[$destSetting]['shadowed'] = true;
					}
				}
			}
		}
	}

}
