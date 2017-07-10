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
	private $config = [];

	private $context = '';

	public function setContext($name)
	{
		$this->context = $name;
	}

	public function addArray($array, $prio = 0)
	{
		foreach ($array as $key => $value) {
			$this->add($key, $value, $prio);
		}
	}

	public function add($key, $value, $prio = 0)
	{
		if (!isset($this->config[$key])) {
			$this->config[$key] = [];
		}
		$new = [
			'prio' => $prio,
			'value' => $value,
			'context' => $this->context,
		];
		if (empty($this->config[$key]) || $this->config[$key][0]['prio'] > $prio) {
			// Existing is higher, append new one
			array_push($this->config[$key], $new);
		} else {
			// New one has highest prio or matches existing, put in front
			array_unshift($this->config[$key], $new);
		}
	}

	public function getConfig()
	{
		$ret = [];
		foreach ($this->config as $key => $list) {
			if ($list[0]['value'] === false)
				continue;
			$ret[$key] = $list[0]['value'];
		}
		return $ret;
	}

	public function outputConfig()
	{
		foreach ($this->config as $key => $list) {
			echo '##', $key, "\n";
			foreach ($list as $pos => $item) {
				echo '# (', $item['context'], ':', $item['prio'], ')';
				if ($pos != 0 || $item['value'] === false) {
					if ($pos == 0) {
						echo " <disabled>\n";
					} else {
						echo ': ', str_replace(array("\r", "\n"), array('\r', '\n'), $item['value']), "\n";
					}
					continue;
				}
				echo "⤵\n", $key, "='", escape($item['value']), "'\n";
			}
		}
	}

}

$CONFIG = new ConfigHolder();

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
	global $CONFIG;
	$configVars = [];
	include_once $file;
	$CONFIG->addArray($configVars, 1);
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
			$CONFIG->setContext($dep);
			handleModule($depFile, $ip, $uuid);
		}
	}
	$CONFIG->setContext($out[1]);
	handleModule($file, $ip, $uuid);
}

// Rest is handled by module
$defaults = BaseConfigUtil::getVariables();

// Dump global config from DB
$CONFIG->setContext('<global>');
$res = Database::simpleQuery('SELECT setting, value, enabled FROM setting_global');
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
	if (!isset($defaults[$row['setting']]))
		continue; // Setting is not defined in any <module>/baseconfig/settings.json
	if ($row['enabled'] != 1) {
		// Setting is disabled
		$CONFIG->add($row['setting'], false, 0);
	} else {
		$CONFIG->add($row['setting'], $row['value'], 0);
	}
}

// Fallback to default values from json files
$CONFIG->setContext('<default>');
foreach ($defaults as $setting => $value) {
	$CONFIG->add($setting, $value['defaultvalue'], -1000);
}

// All done, now output

if (Request::any('save') === 'true') {
	// output AND save to disk: Generate contents
	$lines = '';
	foreach ($CONFIG->getConfig() as $setting => $value) {
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
	$CONFIG->outputConfig();
}

// For quick testing or custom extensions: Include external file that should do nothing
// more than outputting more key-value-pairs. It's expected in the webroot of slxadmin
if (file_exists('client_config_additional.php')) @include('client_config_additional.php');
