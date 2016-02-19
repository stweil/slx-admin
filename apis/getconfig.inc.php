<?php

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

/**
 * Escape given string so it is a valid string in sh that can be surrounded
 * by single quotes ('). This basically turns _'_ into _'"'"'_
 *
 * @param string $string input
 * @return string escaped sh string
 */
function escape($string)
{
	return str_replace("'", "'\"'\"'", $string);
}

// Location handling: figure out location
$locationId = false; // TODO: machine specific mapping
if ($locationId === false) {
	// Fallback to subnets
	$locationId = Location::getFromIp($ip);
}
$matchingLocations = array();
if ($locationId !== false) {
	// Get all parents
	settype($locationId, 'integer');
	$locations = Location::getLocationsAssoc();
	$find = $locationId;
	while (isset($locations[$find]) && !in_array($find, $matchingLocations)) {
		$matchingLocations[] = $find;
		$find = (int)$locations[$find]['parentlocationid'];
	}
	echo "SLX_LOCATIONS='" . escape(implode(' ', $matchingLocations)) . "'\n";
}

$configVars = array();
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
		if (!isset($tmp[$lid])) continue;
		foreach ($tmp[$lid] as $setting => $value) {
			if (!isset($configVars[$setting])) {
				$configVars[$setting] = $value;
			}
		}
	}
	unset($tmp);
}

// Dump config from DB
$res = Database::simpleQuery('SELECT setting.setting, setting.defaultvalue, tbl.value
	FROM setting
	LEFT JOIN setting_global AS tbl USING (setting)
	ORDER BY setting ASC'); // TODO: Add setting groups and sort order
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (isset($configVars[$row['setting']])) {
		$row['value'] = $configVars[$row['setting']];
	} elseif (is_null($row['value'])) {
		$row['value'] = $row['defaultvalue'];
	}
	echo $row['setting'] . "='" . escape($row['value']) . "'\n";
}

// Additional "intelligent" config

// Remote log URL
echo "SLX_REMOTE_LOG='http://" . escape($_SERVER['SERVER_ADDR']) . escape($_SERVER['SCRIPT_NAME']) . "?do=clientlog'\n";
// vm list url
echo "SLX_VMCHOOSER_BASE_URL='http://" . escape($_SERVER['SERVER_ADDR']) . "/vmchooser/'\n";

// VMStore path and type
$vmstore = Property::getVmStoreConfig();
if (is_array($vmstore)) {
	switch ($vmstore['storetype']) {
	case 'internal';
		echo "SLX_VM_NFS='" . escape($_SERVER['SERVER_ADDR']) . ":/srv/openslx/nfs'\n";
		break;
	case 'nfs';
		echo "SLX_VM_NFS='" . escape($vmstore['nfsaddr']) . "'\n";
		break;
	case 'cifs';
		echo "SLX_VM_NFS='" . escape($vmstore['cifsaddr']) . "'\n";
		echo "SLX_VM_NFS_USER='" . escape($vmstore['cifsuserro']) . "'\n";
		echo "SLX_VM_NFS_PASSWD='" . escape($vmstore['cifspasswdro']) . "'\n";
		break;
	}
}


// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) @include('client_config_additional.php');
