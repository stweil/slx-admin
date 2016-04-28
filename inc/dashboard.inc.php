<?php

class Dashboard
{
	
	private static $iconCache = array();
	
	public static function createMenu()
	{
		$modulesAssoc = array();
		$all = Module::getEnabled();
		foreach ($all as $module) {
			$cat = $module->getCategory();
			if ($cat === false)
				continue;
			$modulesAssoc[$cat][] = $module;
		}
		$modulesArray = array();
		foreach ($modulesAssoc as $id => $list) {
			$momomo = array();
			foreach ($list as $module) {
				$momomo[] = array(
					'displayName' => $module->getDisplayName(),
					'identifier' => $module->getIdentifier(),
					'className' => ($module->getIdentifier() === Page::getModule()->getIdentifier()) ? 'active' : ''
				);
			}
			$modulesArray[] = array(
				'icon' => self::getCategoryIcon($id),
				'displayName' => Dictionary::getCategoryName($id),
				'modules' => $momomo
			);
		}
		Render::setDashboard(array(
			'categories' => $modulesArray,
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