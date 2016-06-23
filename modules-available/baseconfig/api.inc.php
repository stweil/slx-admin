<?php

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

$uuid = Request::any('uuid', false, 'string');
if ($uuid !== false && strlen($uuid) !== 36) {
	$uuid = false;
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

/*
 * We gather all config variables here. First, let other modules generate
 * their desired config vars. Afterwards, add the global config vars from
 * db. If a variable is already set, it will not be overridden by the
 * global setting.
 */

$configVars = array();
function handleModule($file, $ip, $uuid) // Pass ip and uuid instead of global to make them read only
{
	global $configVars;
	include_once $file;
}

// Handle any hooks by other modules first
// other modules should generally only populate $configVars
foreach (glob('modules/*/baseconfig/getconfig.inc.php') as $file) {
	preg_match('#^modules/([^/]+)/#', $file, $out);
	$mod = Module::get($out[1]);
	if ($mod === false)
		continue;
	$mod->activate();
	foreach ($mod->getDependencies() as $dep) {
		$depFile = 'modules/' . $dep . '/baseconfig/getconfig.inc.php';
		if (file_exists($depFile) && Module::isAvailable($dep)) {
			handleModule($depFile, $ip, $uuid);
		}
	}
	handleModule($file, $ip, $uuid);
}

// Rest is handled by module
$defaults = BaseConfigUtil::getVariables();

// Dump global config from DB
$res = Database::simpleQuery('SELECT setting, value, enabled FROM setting_global');
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (isset($configVars[$row['setting']]) // Already set by a hook above, ignore
			|| !isset($defaults[$row['setting']])) // Setting is not defined in any <module>/baseconfig/settings.json
		continue;
	if ($row['enabled'] != 1) {
		// Setting is disabled
		$configVars[$row['setting']] = false;
	} else {
		$configVars[$row['setting']] = $row['value'];
	}
}

// Fallback to default values from json files
foreach ($defaults as $setting => $value) {
	if (isset($configVars[$setting])) {
		if ($configVars[$setting] === false) {
			unset($configVars[$setting]);
		}
	} else {
		$configVars[$setting] = $value['defaultvalue'];
	}
}

// All done, now output

if (Request::any('save') === 'true') {
	// output AND save to disk: Generate contents
	$lines = '';
	foreach ($configVars as $setting => $value) {
		$lines .= $setting . "='" . escape($value) . "'\n";
	}
	// Save to all the locations
	$data = Property::getVersionCheckInformation();
	if (is_array($data) && isset($data['systems'])) {
		foreach ($data['systems'] as $system) {
			$path = CONFIG_HTTP_DIR . '/' . $system['id'] . '/config';
			if (file_put_contents($path, $lines) > 0) {
				echo "# Saved config to $path\n";
			} else {
				echo "# Error saving config to $path\n";
			}
		}
	}
	// Output to browser
	echo $lines;
} else {
	// Only output to client
	foreach ($configVars as $setting => $value) {
		echo $setting, "='", escape($value), "'\n";
	}
}

// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) @include('client_config_additional.php');
