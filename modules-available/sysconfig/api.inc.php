<?php

// Called after updates to rebuild all configs
if (Request::any('action') === 'rebuild' && isLocalExecution()) {
	ConfigTgz::rebuildAllConfigs();
	die('OK');
}

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

$uuid = Request::any('uuid', false, 'string');
if ($uuid !== false && strlen($uuid) !== 36) {
	$uuid = false;
}

// What we do if we can't supply the requested config
function deliverEmpty($message)
{
	EventLog::failure($message);
	Header('HTTP/1.1 404 Not found');
	die('Config file could not be found or read!');
}

$runmode = false;
if (Module::isAvailable('runmode')) {
	$runmode = RunMode::getRunMode($uuid);
	if ($runmode !== false) {
		$runmode = RunMode::getModuleConfig($runmode['module']);
	}
}
if ($runmode !== false && $runmode->noSysconfig && file_exists(SysConfig::GLOBAL_MINIMAL_CONFIG)) {
	$row = array('filepath' => SysConfig::GLOBAL_MINIMAL_CONFIG, 'title' => 'config');
} else {
	$locationId = false;
	if (Module::isAvailable('locations')) {
		$locationId = Location::getFromIpAndUuid($ip, $uuid);
		if ($locationId !== false) {
			$locationChain = Location::getLocationRootChain($locationId);
			$locationChain[] = 0;
		}
	}
	if ($locationId === false) {
		$locationId = 0;
		$locationChain = array(0);
	}

	// Get config module path

	// We get all the configs for the whole location chain up to root
	$res = Database::simpleQuery("SELECT c.title, c.filepath, c.status, cl.locationid FROM configtgz c"
		. " INNER JOIN configtgz_location cl USING (configid)"
		. " WHERE cl.locationid IN (" . implode(',', $locationChain) . ")");

	$best = 1000;
	$row = false;
	while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
		settype($r['locationid'], 'int');
		$index = array_search($r['locationid'], $locationChain);
		if ($index === false || $index > $best)
			continue;
		if (!file_exists($r['filepath'])) {
			if ($r['locationid'] === 0) {
				EventLog::failure("The global config.tgz '{$r['title']}' was not found at '{$r['filepath']}'. Please regenerate the system configuration");
			} else {
				EventLog::warning("config.tgz '{$r['title']}' for location $locationId not found at '{$r['filepath']}', trying fallback....");
			}
			continue;
		}
		$best = $index;
		$row = $r;
	}

	if ($row === false) {
		// TODO Not found in DB
		deliverEmpty("No config.tgz for location $locationId found (src $ip)");
	}
}

Header('Content-Type: application/gzip');
Header('Content-Disposition: attachment; filename=' . Util::sanitizeFilename($row['title']) . '.tgz');
$ret = readfile($row['filepath']);

if ($ret === false || $ret === 0) {
	// TODO didn't send anything/everything
	// Cannot deliver empty, don't know what has been send already
	EventLog::warning("Could not deliver config.tgz to client $ip: readfile() returned " . ($ret === false ? 'false' : $ret));
}

exit;