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
	
	public static function get($name, $ignoreDepFail = false)
	{
		if (!isset(self::$modules[$name]))
			return false;
		if (!self::resolveDeps(self::$modules[$name]) && !$ignoreDepFail)
			return false;
		return self::$modules[$name];
	}

	/**
	 * Check whether given module is available, that is, all dependencies are
	 * met. If the module is available, it will be activated, so all its classes
	 * are available through the auto-loader, and any js or css is added to the
	 * final page output.
	 *
	 * @param string $moduleId module to check
	 * @return bool true if module is available and activated
	 */
	public static function isAvailable($moduleId)
	{
		$module = self::get($moduleId);
		if ($module === false)
			return false;
		$module->activate();
		return !$module->hasMissingDependencies();
	}
	
	private static function resolveDepsByName($name)
	{
		if (!isset(self::$modules[$name]))
			return false;
		return self::resolveDeps(self::$modules[$name]);
	}

	/**
	 * 
	 * @param \Module $mod the module to check
	 * @return boolean true iff module deps are all found and enabled
	 */
	private static function resolveDeps($mod)
	{
		if (!$mod->depsChecked) {
			$mod->depsChecked = true;
			foreach ($mod->dependencies as $dep) {
				if (!self::resolveDepsByName($dep)) {
					error_log("Disabling module {$mod->name}: Dependency $dep failed.");
					$mod->depsMissing = true;
					return false;
				}
			}
		}
		return !$mod->depsMissing;
	}

	/**
	 * @return \Module[] List of valid, enabled modules
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

	/**
	 * @return \Module[] List of all modules, including with missing deps
	 */
	public static function getAll()
	{
		foreach (self::$modules as $module) {
			self::resolveDeps($module);
		}
		return self::$modules;
	}

	/**
	 * @return \Module[] List of modules that have been activated
	 */
	public static function getActivated()
	{
		$ret = array();
		$i = 0;
		foreach (self::$modules as $module) {
			if ($module->activated !== false) {
				$ret[sprintf('%05d_%d', $module->activated, $i++)] = $module;
			}
		}
		ksort($ret);
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
			if (empty($dir) || preg_match('/[^a-zA-Z0-9_]/', $dir))
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
		if (isset($json['dependencies']) && is_array($json['dependencies'])) {
			$this->dependencies = $json['dependencies'];
		}
		if (isset($json['category']) && is_string($json['category'])) {
			$this->category = $json['category'];
		}
		$this->name = $name;
	}
	
	public function hasMissingDependencies()
	{
		return $this->depsMissing;
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

	public function activate($depth = 1)
	{
		if ($this->activated !== false || $this->depsMissing)
			return;
		$this->activated = $depth;
		spl_autoload_register(function($class) {
			$file = 'modules/' . $this->name . '/inc/' . preg_replace('/[^a-z0-9]/', '', strtolower($class)) . '.inc.php';
			if (!file_exists($file))
				return;
			require_once $file;
		});
		foreach ($this->dependencies as $dep) {
			$get = self::get($dep);
			if ($get !== false) {
				$get->activate($depth + 1);
			}
		}
	}

	public function getDependencies()
	{
		$deps = array();
		$this->getDepsInternal($deps);
		return array_keys($deps);
	}

	private function getDepsInternal(&$deps)
	{
		if (!is_array($this->dependencies))
			return array();
		foreach ($this->dependencies as $dep) {
			if (isset($deps[$dep])) // Handle cyclic dependencies
				continue;
			$deps[$dep] = true;
			$mod = self::get($dep);
			if ($mod === false)
				continue;
			$mod->getDepsInternal($deps);
		}
	}
	
	public function getIdentifier()
	{
		return $this->name;
	}

	public function getDisplayName()
	{
		$string = Dictionary::translateFileModule($this->name, 'module', 'module_name');
		if ($string === false) {
			return '!!' . $this->name . '!!';
		}
		return $string;
	}

	public function getPageTitle()
	{
		$val = Dictionary::translateFileModule($this->name, 'module', 'page_title');
		if ($val !== false)
			return $val;
		return $this->getDisplayName();
	}
	
	public function getCategory()
	{
		return $this->category;
	}
	
	public function getCategoryName()
	{
		return Dictionary::getCategoryName($this->category);
	}

	public function getDir()
	{
		return 'modules/' . $this->name;
	}

}
