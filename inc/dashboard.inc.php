<?php

class Dashboard
{
	
	private static $iconCache = array();
	private static $subMenu = array();
	private static $disabled = false;

	public static function disable()
	{
		self::$disabled = true;
	}
	
	public static function createMenu()
	{
		if (self::$disabled)
			return;
		global $MENU_CAT_OVERRIDE;
		$modByCategory = array();
		$modById = array();
		if (isset($MENU_CAT_OVERRIDE)) {
			foreach ($MENU_CAT_OVERRIDE as $cat => $list) {
				foreach ($list as $mod) {
					$modByCategory[$cat][$mod] = false;
					$modById[$mod] =& $modByCategory[$cat][$mod];
				}
			}
		}
		$all = Module::getEnabled(true);
		foreach ($all as $module) {
			$cat = $module->getCategory();
			if ($cat === false)
				continue;
			$modId = $module->getIdentifier();
			if (isset($modById[$modId])) {
				$modById[$modId] = $module;
			} else {
				$modByCategory[$cat][$modId] = $module;
			}
		}
		$currentPage = Page::getModule()->getIdentifier();
		$categories = array();
		foreach ($modByCategory as $catId => $modList) {
			/* @var Module[] $modList */
			$modules = array();
			foreach ($modList as $modId => $module) {
				if ($module === false)
					continue; // Was set in $MENU_CAT_OVERRIDE, but is not enabled
				$newEntry = array(
					'displayName' => $module->getDisplayName(),
					'identifier' => $module->getIdentifier()
				);
				if ($module->getIdentifier() === $currentPage) {
					$newEntry['className'] = 'active';
					$newEntry['subMenu'] = self::$subMenu;
				}
				$modules[] = $newEntry;
			}
			$categories[] = array(
				'icon' => self::getCategoryIcon($catId),
				'displayName' => Dictionary::getCategoryName($catId),
				'modules' => $modules
			);
		}
		Render::setDashboard(array(
			'categories' => $categories,
			'url' => urlencode($_SERVER['REQUEST_URI']),
			'langs' => Dictionary::getLanguages(true),
			'user' => User::getName(),
			'warning' => User::getName() !== false && User::hasPermission('.eventlog.*') && User::getLastSeenEvent() < Property::getLastWarningId(),
			'needsSetup' => User::getName() !== false && Property::getNeedsSetup()
		));
	}
	
	public static function getCategoryIcon($category)
	{
		if ($category === false) {
			return '';
		}
		if (!preg_match('/^(\w+)\.(.*)$/', $category, $out)) {
			error_log('Requested category icon for invalid category "' . $category . '"');
			return '';
		}
		$module = $out[1];
		$icon = $out[2];
		if (!isset(self::$iconCache[$module])) {
			$path = 'modules/' . $module . '/category-icons.json';
			$data = json_decode(file_get_contents($path), true);
			if (!is_array($data)) {
				return '';
			}
			self::$iconCache[$module] =& $data;
		}
		if (!isset(self::$iconCache[$module][$icon])) {
			error_log('Icon "' . $icon . '" not found in module "' . $module . '"');
			return '';
		}
		return 'glyphicon glyphicon-' . self::$iconCache[$module][$icon];
	}

	public static function addSubmenu($url, $name)
	{
		self::$subMenu[] = array('url' => $url, 'name' => $name);
	}

	public static function getSubmenus()
	{
		return self::$subMenu;
	}
	
}