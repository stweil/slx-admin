<?php

class Dashboard
{
	
	private static $iconCache = array();
	
	public static function createMenu()
	{
		global $MENU_SETTING_SORT_ORDER, $MENU_CAT_SORT_ORDER;
		$modByCategory = array();
		$all = Module::getEnabled();
		foreach ($all as $module) {
			$cat = $module->getCategory();
			if ($cat === false)
				continue;
			$modByCategory[$cat][] = $module;
		}
		$categories = array();
		$catSort = array();
		foreach ($modByCategory as $catId => $modList) {
			$modules = array();
			$sectionSort = array();
			foreach ($modList as $module) {
				$modId = $module->getIdentifier();
				$modules[] = array(
					'displayName' => $module->getDisplayName(),
					'identifier' => $module->getIdentifier(),
					'className' => ($module->getIdentifier() === Page::getModule()->getIdentifier()) ? 'active' : ''
				);
				if (isset($MENU_SETTING_SORT_ORDER[$modId])) {
					$sectionSort[] = (string)($MENU_SETTING_SORT_ORDER[$modId] + 1000);
				} else {
					$sectionSort[] = '9999' . $modId;
				}
			}
			array_multisort($sectionSort, SORT_ASC, $modules);
			$categories[] = array(
				'icon' => self::getCategoryIcon($catId),
				'displayName' => Dictionary::getCategoryName($catId),
				'modules' => $modules
			);
			if (isset($MENU_CAT_SORT_ORDER[$catId])) {
				$catSort[] = (string)($MENU_CAT_SORT_ORDER[$catId] + 1000);
			} else {
				$catSort[] = '9999' . $catId;
			}
		}
		array_multisort($catSort, SORT_ASC, $categories);
		Render::setDashboard(array(
			'categories' => $categories,
			'url' => urlencode($_SERVER['REQUEST_URI']),
			'langs' => Dictionary::getLanguages(true),
			'dbupdate' => Database::needSchemaUpdate(),
			'user' => User::getName(),
			'warning' => User::getName() !== false && User::getLastSeenEvent() < Property::getLastWarningId(),
			'needsSetup' => User::getName() !== false && Property::getNeedsSetup()
		));
	}
	
	public static function getCategoryIcon($category)
	{
		if ($category === false) {
			return '';
		}
		if (!preg_match('/^(\w+)\.(\w+)$/', $category, $out)) {
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
	
}