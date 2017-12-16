<?php

$HANDLER = array();

/**
 * List of valid subsections
 */
$HANDLER['subsections'] = array(
	'global-tags'
);

/*
 * Handlers for the subsections that will return an array of expected tags.
 * This is optional, if you don't want to define expected tags, don't create a function.
 */

/**
 * Global tags.
 * This just returns the union of global tags of all languages, as there is no
 * way to define a definite set of required global tags.
 *
 * @param Module $module
 * @return array dem tags
 */
$HANDLER['grep_global-tags'] = function($module) {
	$want = array();
	foreach (Dictionary::getLanguages() as $lang) {
		$want += Dictionary::getArray($module->getIdentifier(), 'global-tags', $lang);
	}
	return $want;
};
