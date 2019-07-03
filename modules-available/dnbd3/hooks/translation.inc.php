<?php

$HANDLER = array();

/**
 * List of valid subsections
 */
$HANDLER['subsections'] = array(
	'config-variables'
);

/*
 * Handlers for the subsections that will return an array of expected tags.
 * This is optional, if you don't want to define expected tags, don't create a function.
 */

/**
 * Configuration variables.
 * @param \Module $module
 * @return array
 */
$HANDLER['grep_config-variables'] = function($module) {
	if (!$module->activate(1, false) || !Module::isAvailable('baseconfig'))
		return array();
	$want = BaseConfigUtil::getVariables($module);
	foreach ($want as &$entry) {
		$entry = true;
	}
	return $want;
};
