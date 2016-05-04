<?php

$HANDLER = array();

/**
 * List of valid subsections
 */
$HANDLER['subsections'] = array(
	'categories', 'settings'
);

/*
 * Handlers for the subsections that will return an array of expected tags.
 * This is optional, if you don't want to define expected tags, don't create a function.
 */

/**
 * Configuration categories
 */
$HANDLER['grep']['categories'] = function($module) {
	$want = array();
	$res = Database::simpleQuery("SELECT catid FROM cat_setting ORDER BY catid ASC");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$want[] = 'cat_' . $row['catid'];
	}
	return $want;
};

/**
 * Configuration variables
 */
$HANDLER['grep']['settings'] = function($module) {
	$want = array();
	$res = Database::simpleQuery("SELECT setting FROM setting ORDER BY setting ASC");
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$want[] = $row['setting'];
	}
	return $want;
};
