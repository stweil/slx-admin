<?php

$HANDLER = array();

if (file_exists('modules/' . $moduleName . '/permissions/permissions.json')) {

	/**
	 * List of valid subsections
	 */
	$HANDLER['subsections'] = array(
		'permissions'
	);

	/*
	 * Handlers for the subsections that will return an array of expected tags.
	 * This is optional, if you don't want to define expected tags, don't create a function.
	 */

	/**
	 * Configuration categories.
	 *
	 * @param \Module $module
	 * @return array
	 */
	$HANDLER['grep_permissions'] = function ($module) {
		$file = 'modules/' . $module->getIdentifier() . '/permissions/permissions.json';
		if (!file_exists($file))
			return [];
		$array = json_decode(file_get_contents($file), true);
		if (!is_array($array))
			return [];
		foreach ($array as &$entry) {
			$entry = true;
		}
		return $array;
	};

}
