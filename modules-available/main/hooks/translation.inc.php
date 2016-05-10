<?php

$HANDLER = array();

/**
 * List of valid subsections
 */
$HANDLER['subsections'] = array(
	'categories'
);

/*
 * Handlers for the subsections that will return an array of expected tags.
 * This is optional, if you don't want to define expected tags, don't create a function.
 */

/**
 * Configuration categories
 */
$HANDLER['grep_categories'] = function($module) {
	$skip = strlen($module->getIdentifier()) + 1;
	$want = array();
	foreach (Module::getAll() as $module) {
		$cat = $module->getCategory();
		if (is_string($cat)) {
			$want[substr($cat, $skip)] = true;
		}
	}
	return $want;
};
