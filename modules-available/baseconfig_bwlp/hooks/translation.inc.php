<?php

$HANDLER = array();

/**
 * List of valid subsections
 */
$HANDLER['subsections'] = array(
	'config-variable-categories', 'config-variables'
);

/*
 * Handlers for the subsections that will return an array of expected tags.
 * This is optional, if you don't want to define expected tags, don't create a function.
 */

/**
 * Configuration categories
 */
$HANDLER['grep_config-variable-categories'] = function($module) {
	if (!$module->activate())
		return array();
	$want = BaseConfigUtil::getCategories($module);
	foreach ($want as &$entry) {
		$entry = true;
	}
	return $want;
};

/**
 * Configuration variables
 */
$HANDLER['grep_config-variables'] = function($module) {
	if (!$module->activate())
		return array();
	$want = BaseConfigUtil::getVariables($module);
	foreach ($want as &$entry) {
		$entry = true;
	}
	return $want;
};
