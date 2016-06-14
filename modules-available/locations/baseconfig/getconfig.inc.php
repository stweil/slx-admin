<?php

// Location handling: figure out location
$locationId = false;
if (Request::any('force', 0, 'int') === 1 && Request::any('module', false, 'string') === 'locations') {
	// Force location for testing, but require logged in admin
	if (User::load()) {
		$locationId = Request::any('value', 0, 'int');
	}
}
// TODO: machine specific mapping
if ($locationId === false) {
	// Fallback to subnets
	$locationId = Location::getFromIp($ip);
}
$matchingLocations = array();
if ($locationId !== false) {
	// Get all parents
	$matchingLocations = Location::getLocationRootChain($locationId);
	$configVars["SLX_LOCATIONS"] = implode(' ', $matchingLocations);
}

// Query location specific settings (from bottom to top)
if (!empty($matchingLocations)) {
	// First get all settings for all locations we're in
	$list = implode(',', $matchingLocations);
	$res = Database::simpleQuery("SELECT locationid, setting, value FROM setting_location WHERE locationid IN ($list)");
	$tmp = array();
	while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
		$tmp[(int)$row['locationid']][$row['setting']] = $row['value'];
	}
	// $matchingLocations contains the location ids sorted from closest to furthest, so we use it to make sure the order
	// in which they override is correct (closest setting wins, e.g. room setting beats department setting)
	foreach ($matchingLocations as $lid) {
		if (!isset($tmp[$lid]))
			continue;
		foreach ($tmp[$lid] as $setting => $value) {
			if (!isset($configVars[$setting])) {
				$configVars[$setting] = $value;
			}
		}
	}
	unset($tmp);
}
