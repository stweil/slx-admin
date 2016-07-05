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
	 * @return array all known config variables
	 */
	public static function getVariables()
	{
		$settings = array();
		foreach (glob('modules/*/baseconfig/settings.json', GLOB_NOSORT) as $file) {
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

	public static function getCategories()
	{
		$categories = array();
		foreach (glob('modules/*/baseconfig/categories.json', GLOB_NOSORT) as $file) {
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

}