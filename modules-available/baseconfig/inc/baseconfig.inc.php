<?php

class BaseConfig
{

	private static $modulesDone = [];

	/**
	 * @var array key-value-pairs of override vars, can be accessed by hooks
	 */
	private static $overrides = [];

	/**
	 * Fill the ConfigHolder with values from various hooks, while taking
	 * into account UUID and IP-address of the client making the current
	 * HTTP request.
	 */
	public static function prepareFromRequest()
	{
		$ip = $_SERVER['REMOTE_ADDR'];
		if (substr($ip, 0, 7) === '::ffff:') {
			$ip = substr($ip, 7);
		}
		$uuid = Request::any('uuid', false, 'string');
		if ($uuid !== false && strlen($uuid) !== 36) {
			$uuid = false;
		}
		// Handle any hooks by other modules first
		// other modules should generally only populate $configVars
		foreach (glob('modules/*/baseconfig/getconfig.inc.php') as $file) {
			preg_match('#^modules/([^/]+)/#', $file, $out);
			ConfigHolder::setContext($out[1]);
			self::handleModule($out[1], $ip, $uuid, false);
		}
		self::commonBase();
	}

	/**
	 * Fill the ConfigHolder with data from various hooks that supply
	 * static overrides for config variables. The overrides can be used
	 * to make the hooks behave in certain ways, by setting specific values like
	 * 'locationid'
	 * @param array $overrides key value pairs of overrides
	 */
	public static function prepareWithOverrides($overrides)
	{
		self::$overrides = $overrides;
		$ip = $uuid = false;
		if (self::hasOverride('ip')) {
			$ip = self::getOverride('ip');
		}
		if (self::hasOverride('uuid')) {
			$uuid = self::getOverride('uuid');
		}
		// Handle only static hooks that don't dynamically generate output
		foreach (glob('modules/*/baseconfig/hook.json') as $file) {
			preg_match('#^modules/([^/]+)/#', $file, $out);
			ConfigHolder::setContext($out[1]);
			self::handleModule($out[1], $ip, $uuid, true);
		}
		self::commonBase();
	}

	/**
	 * Just fill the ConfigHolder with the defaults from all the json files
	 * that define config variables.
	 */
	public static function prepareDefaults()
	{
		$defaults = BaseConfigUtil::getVariables();
		self::addDefaults($defaults);
	}

	private static function commonBase()
	{
		$defaults = BaseConfigUtil::getVariables();

		// Dump global config from DB
		ConfigHolder::setContext('<global>', function($id) {
			return [
				'name' => Dictionary::translate('source-global', true),
				'locationid' => 0,
			];
		});
		$res = Database::simpleQuery('SELECT setting, value, displayvalue FROM setting_global');
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($defaults[$row['setting']]))
				continue; // Setting is not defined in any <module>/baseconfig/settings.json
			ConfigHolder::add($row['setting'], $row, -1);
		}

		// Fallback to default values from json files
		self::addDefaults($defaults);

	}

	private static function addDefaults($defaults)
	{
		ConfigHolder::setContext('<default>', function($id) {
			return [
				'name' => Dictionary::translate('source-default', true),
				'locationid' => 0,
			];
		});
		foreach ($defaults as $setting => $value) {
			ConfigHolder::add($setting, $value['defaultvalue'], -1000);
		}
	}

	private static function handleModule($name, $ip, $uuid, $needJsonHook) // Pass ip and uuid instead of global to make them read only
	{
		if (isset(self::$modulesDone[$name]))
			return;
		self::$modulesDone[$name] = true;
		// Module has getconfig hook
		$file = 'modules/' . $name . '/baseconfig/getconfig.inc.php';
		if (!is_file($file))
			return;
		// We want only static hooks that have a json config (currently used for displaying inheritance tree)
		if ($needJsonHook && !is_file('modules/' . $name . '/baseconfig/hook.json'))
			return;
		// Properly registered and can be activated
		$mod = Module::get($name);
		if ($mod === false)
			return;
		if (!$mod->activate(1, false))
			return;
		// Process dependencies first
		foreach ($mod->getDependencies() as $dep) {
			self::handleModule($dep, $ip, $uuid, $needJsonHook);
		}
		ConfigHolder::setContext($name);
		(function ($file, $ip, $uuid) {
			include_once($file);
		})($file, $ip, $uuid);
	}

	public static function hasOverride($key)
	{
		return array_key_exists($key, self::$overrides);
	}

	public static function getOverride($key)
	{
		return self::$overrides[$key];
	}

}