<?php

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

// TODO: Handle UUID in appropriate modules (optional)
$uuid = Request::post('uuid', '', 'string');
if (strlen($uuid) !== 36) {
	// Probably invalid UUID. What to do? Set empty or ignore?
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

// Handle any hooks by other modules first
// other modules should generally only populate $configVars
foreach (glob('modules/*/baseconfig/getconfig.inc.php') as $file) {
	preg_match('#^modules/([^/]+)/#', $file, $out);
	if (!Module::isAvailable($out[1]))
		continue;
	include $file;
}

// Rest is handled by module
$defaults = BaseConfigUtil::getVariables();

// Dump global config from DB
$res = Database::simpleQuery('SELECT setting, value FROM setting_global'); // TODO: Add setting groups and sort order
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (isset($configVars[$row['setting']]) || !isset($defaults[$row['setting']]))
		continue;
	$configVars[$row['setting']] = $row['value'];
}

// Fallback to default values from json files
foreach ($defaults as $setting => $value) {
	if (isset($configVars[$setting]))
		continue;
	$configVars[$setting] = $value;
}

// Finally, output what we gathered
foreach ($configVars as $setting => $value) {
	echo $setting, "='", escape($value), "'\n";
}

// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) @include('client_config_additional.php');
