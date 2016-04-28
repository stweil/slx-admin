<?php

class Module
{
	/*
	 * Static
	 */

	/**
	 * @var \Module[]
	 */
	private static $modules = false;
	
	public static function get($name)
	{
		if (!isset(self::$modules[$name]))
			return false;
		if (!self::resolveDeps(self::$modules[$name]))
			return false;
		return self::$modules[$name];
	}
	
	private static function resolveDepsByName($name)
	{
		if (!isset(self::$modules[$name]))
			return false;
		return self::resolveDeps(self::$modules[$name]);
	}

	private static function resolveDeps($mod)
	{
		if (!$mod->depsChecked) {
			$mod->depsChecked = true;
			foreach ($mod->dependencies as $dep) {
				if (!self::resolveDepsByName($dep)) {
					if ($mod->enabled) {
						error_log("Disabling module $name: Dependency $dep failed.");
					}
					$mod->enabled = false;
					$mod->depsMissing = true;
					return false;
				}
			}
		}
		return $mod->enabled;
	}

	/**
	 * @return \Module[] List of enabled modules
	 */
	public static function getEnabled()
	{
		$ret = array();
		foreach (self::$modules as $module) {
			if (self::resolveDeps($module))
				$ret[] = $module;
		}
		return $ret;
	}

	public static function init()
	{
		if (self::$modules !== false)
			return;
		$dh = opendir('modules');
		if ($dh === false)
			return;
		self::$modules = array();
		while (($dir = readdir($dh)) !== false) {
			if (empty($dir) || preg_match('/[^a-zA-Z0-9]/', $dir))
				continue;
			if (!is_file('modules/' . $dir . '/config.json'))
				continue;
			$name = strtolower($dir);
			self::$modules[$name] = new Module($dir);
		}
		closedir($dh);
	}

	/*
	 * Non-static
	 */

	private $enabled = false;
	private $category = false;
	private $depsMissing = false;
	private $depsChecked = false;
	private $activated = false;
	private $dependencies = array();
	private $name;

	private function __construct($name)
	{
		$file = 'modules/' . $name . '/config.json';
		$json = @json_decode(@file_get_contents($file), true);
		$this->enabled = isset($json['enabled']) && ($json['enabled'] === true || $json['enabled'] === 'true');
		if (isset($json['dependencies']) && is_array($json['dependencies'])) {
			$this->dependencies = $json['dependencies'];
		}
		if (isset($json['category']) && is_string($json['category'])) {
			$this->category = $json['category'];
		}
		$this->name = $name;
	}
	
	public function newPage()
	{
		$modulePath = 'modules/' . $this->name . '/page.inc.php';
		if (!file_exists($modulePath)) {
			Util::traceError("Module doesn't have a page: " . $modulePath);
		}
		require_once $modulePath;
		$class = 'Page_' . $this->name;
		return new $class();
	}

	public function activate()
	{
		if ($this->activated || !$this->enabled)
			return;
		$this->activated = true;
		spl_autoload_register(function($class) {
			$file = 'modules/' . $this->name . '/inc/' . preg_replace('/[^a-z0-9]/', '', strtolower($class)) . '.inc.php';
			if (!file_exists($file))
				return;
			require_once $file;
		});
		foreach ($this->dependencies as $dep) {
			$get = self::get($dep);
			if ($get !== false) {
				$get->activate();
			}
		}
	}
	
	public function getIdentifier()
	{
		return $this->name;
	}

	public function getDisplayName()
	{
		$string = Dictionary::translate($this->name, 'module', 'module_name');
		if ($string === false) {
			return $this->name;
		}
		return $string;
	}
	
	public function getCategory()
	{
		return $this->category;
	}
	
	public function getCategoryName()
	{
		return Dictionary::getCategoryName($this->category);
	}
	
	public function translate($tag, $section = 'module')
	{
		$string = Dictionary::translate($this->name, $section, $tag);
		if ($string === false) {
			$string = Dictionary::translate('core', $section, $tag);
		}
		if ($string === false) {
			error_log('Translation not found. Module: ' . $this->name . ', section: ' . $section . ', tag: ' . $tag);
			$string = '!!' . $tag . '!!';
		}
		return $string;
	}

}
