<?php

$ip = $_SERVER['REMOTE_ADDR'];
if (substr($ip, 0, 7) === '::ffff:') {
	$ip = substr($ip, 7);
}

$uuid = Request::any('uuid', false, 'string');
if ($uuid !== false && strlen($uuid) !== 36) {
	$uuid = false;
}

class ConfigHolder
{
	private static $config = [];

	private static $context = '';

	private static $postHooks = [];

	public static function setContext($name)
	{
		self::$context = $name;
	}

	public static function addArray($array, $prio = 0)
	{
		foreach ($array as $key => $value) {
			self::add($key, $value, $prio);
		}
	}

	public static function add($key, $value, $prio = 0)
	{
		if (!isset(self::$config[$key])) {
			self::$config[$key] = [];
		}
		$new = [
			'prio' => $prio,
			'value' => $value,
			'context' => self::$context,
		];
		if (empty(self::$config[$key]) || self::$config[$key][0]['prio'] > $prio) {
			// Existing is higher, append new one
			array_push(self::$config[$key], $new);
		} else {
			// New one has highest prio or matches existing, put in front
			array_unshift(self::$config[$key], $new);
		}
	}

	public static function get($key)
	{
		if (!isset(self::$config[$key]))
			return false;
		return self::$config[$key][0]['value'];
	}

	/**
	 * @param callable $func
	 */
	public static function addPostHook($func)
	{
		self::$postHooks[] = array('context' => self::$context, 'function' => $func);
	}

	public static function applyPostHooks()
	{
		foreach (self::$postHooks as $hook) {
			self::$context = $hook['context'] . ':post';
			$hook['function']();
		}
		self::$postHooks = [];
	}

	public static function getConfig()
	{
		self::applyPostHooks();
		$ret = [];
		foreach (self::$config as $key => $list) {
			if ($list[0]['value'] === false)
				continue;
			$ret[$key] = $list[0]['value'];
		}
		return $ret;
	}

	public static function outputConfig()
	{
		self::applyPostHooks();
		foreach (self::$config as $key => $list) {
			echo '##', $key, "\n";
			foreach ($list as $pos => $item) {
				echo '# (', $item['context'], ':', $item['prio'], ')';
				if ($pos != 0 || $item['value'] === false) {
					if ($pos == 0) {
						echo " <disabled>\n";
					} else {
						echo " <overridden>\n";
					}
					continue;
				}
				echo "⤵\n", $key, "='", escape($item['value']), "'\n";
			}
		}
	}

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

function handleModule($file, $ip, $uuid) // Pass ip and uuid instead of global to make them read only
{
	$configVars = [];
	include_once $file;
	ConfigHolder::addArray($configVars, 0);
}

// Handle any hooks by other modules first
// other modules should generally only populate $configVars
foreach (glob('modules/*/baseconfig/getconfig.inc.php') as $file) {
	preg_match('#^modules/([^/]+)/#', $file, $out);
	$mod = Module::get($out[1]);
	if ($mod === false)
		continue;
	$mod->activate(1, false);
	foreach ($mod->getDependencies() as $dep) {
		$depFile = 'modules/' . $dep . '/baseconfig/getconfig.inc.php';
		if (file_exists($depFile) && Module::isAvailable($dep)) {
			ConfigHolder::setContext($dep);
			handleModule($depFile, $ip, $uuid);
		}
	}
	ConfigHolder::setContext($out[1]);
	handleModule($file, $ip, $uuid);
}

// Rest is handled by module
$defaults = BaseConfigUtil::getVariables();

// Dump global config from DB
ConfigHolder::setContext('<global>');
$res = Database::simpleQuery('SELECT setting, value, enabled FROM setting_global');
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (!isset($defaults[$row['setting']]))
		continue; // Setting is not defined in any <module>/baseconfig/settings.json
	if ($row['enabled'] != 1) {
		// Setting is disabled
		ConfigHolder::add($row['setting'], false, -1);
	} else {
		ConfigHolder::add($row['setting'], $row['value'], -1);
	}
}

// Fallback to default values from json files
ConfigHolder::setContext('<default>');
foreach ($defaults as $setting => $value) {
	ConfigHolder::add($setting, $value['defaultvalue'], -1000);
}

// All done, now output

if (Request::any('save') === 'true') {
	// output AND save to disk: Generate contents
	$lines = '';
	foreach (ConfigHolder::getConfig() as $setting => $value) {
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
			echo "SLX_NOW='", time(), "'\n";
		}
	}
	// Output to browser
	echo $lines;
} else {
	// Only output to client
	ConfigHolder::add('SLX_NOW', time(), PHP_INT_MAX);
	ConfigHolder::outputConfig();
}

// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) @include('client_config_additional.php');
