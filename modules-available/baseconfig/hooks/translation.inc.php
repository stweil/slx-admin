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
	$want = array();
	$res = Database::simpleQuery("SELECT catid FROM cat_setting ORDER BY catid ASC");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$want['cat_' . $row['catid']] = true;
	}
	return $want;
};

/**
 * Configuration variables
 */
$HANDLER['grep_config-variables'] = function($module) {
	$want = array();
	$res = Database::simpleQuery("SELECT setting FROM setting ORDER BY setting ASC");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$want[$row['setting']] = true;
	}
	return $want;
};
