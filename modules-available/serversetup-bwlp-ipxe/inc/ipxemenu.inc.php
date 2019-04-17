<?php

class IPxeMenu
{

	protected $menuid;
	protected $timeoutMs;
	protected $title;
	protected $defaultEntryId;
	/**
	 * @var MenuEntry[]
	 */
	protected $items = [];

	public function __construct($menu)
	{
		if (!is_array($menu)) {
			$menu = Database::queryFirst("SELECT menuid, timeoutms, title, defaultentryid FROM serversetup_menu
				WHERE menuid = :menuid LIMIT 1", ['menuid' => $menu]);
			if (!is_array($menu)) {
				$menu = ['menuid' => 'foo', 'title' => 'Invalid Menu ID: ' . (int)$menu];
			}
		}
		$this->menuid = (int)$menu['menuid'];
		$this->timeoutMs = (int)$menu['timeoutms'];
		$this->title = $menu['title'];
		$this->defaultEntryId = $menu['defaultentryid'];
		$res = Database::simpleQuery("SELECT e.menuentryid, e.entryid, e.refmenuid, e.hotkey, e.title, e.hidden, e.sortval, e.md5pass,
			b.data AS bootentry
			FROM serversetup_menuentry e
			LEFT JOIN serversetup_bootentry b USING (entryid)
			WHERE e.menuid = :menuid
			ORDER BY e.sortval ASC, e.title ASC", ['menuid' => $menu['menuid']]);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$this->items[] = new MenuEntry($row);
		}
		// Make sure we have a default entry if the menu isn't empty
		if ($this->defaultEntryId === null && !empty($this->items)) {
			$this->defaultEntryId = $this->items[0]->menuEntryId();
		}
	}

	public function getMenuDefinition($targetVar, $mode, $slxExtensions)
	{
		$str = "menu -- {$this->title}\n";
		foreach ($this->items as $item) {
			$str .= $item->getMenuItemScript("m_{$this->menuid}", $this->defaultEntryId, $mode, $slxExtensions);
		}
		if ($this->defaultEntryId === null) {
			$defaultLabel = "mx_{$this->menuid}_poweroff";
		} else {
			$defaultLabel = "m_{$this->menuid}_{$this->defaultEntryId}";
		}
		$str .= "choose";
		if ($this->timeoutMs > 0) {
			$str .= " --timeout {$this->timeoutMs}";
		}
		$str .= " $targetVar || goto $defaultLabel || goto fail\n";
		if ($this->defaultEntryId === null) {
			$str .= "goto skip_{$defaultLabel}\n"
				. ":{$defaultLabel}\n"
				. "poweroff || goto fail\n"
				. ":skip_{$defaultLabel}\n";
		}
		return $str;
	}

	public function getItemsCode($mode)
	{
		$str = '';
		foreach ($this->items as $item) {
			$str .= $item->getBootEntryScript("m_{$this->menuid}", 'fail', $mode);
			$str .= "goto slx_menu\n";
		}
		return $str;
	}

	public function title()
	{
		return $this->title;
	}

	public function timeoutMs()
	{
		return $this->timeoutMs;
	}

	/**
	 * @return int Number of items in this menu
	 */
	public function itemCount()
	{
		return count($this->items);
	}

	/**
	 * @return string|false Return script label of default entry, false if not set
	 */
	public function getDefaultScriptLabel()
	{
		if ($this->defaultEntryId !== null)
			return "m_{$this->menuid}_{$this->defaultEntryId}";
		return false;
	}

	/**
	 * @return MenuEntry|null Return preselected menu entry
	 */
	public function defaultEntry()
	{
		foreach ($this->items as $item) {
			if ($item->menuEntryId() == $this->defaultEntryId)
				return $item;
		}
		return null;
	}

	/*
	 *
	 */

	public static function forLocation($locationId)
	{
		$chain = null;
		if (Module::isAvailable('locations')) {
			$chain = Location::getLocationRootChain($locationId);
		}
		if (!empty($chain)) {
			$res = Database::simpleQuery("SELECT m.menuid, m.timeoutms, m.title, IFNULL(ml.defaultentryid, m.defaultentryid) AS defaultentryid, ml.locationid
			FROM serversetup_menu m
			INNER JOIN serversetup_menu_location ml USING (menuid)
			WHERE ml.locationid IN (:chain)", ['chain' => $chain]);
			if ($res->rowCount() > 0) {
				// Make the location id key, preserving order (closest location is first)
				$chain = array_flip($chain);
				while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
					// Overwrite the value (numeric ascending values, useless) with menu array of according location
					$chain[(int)$row['locationid']] = $row;
				}
				// Use first one that was found
				foreach ($chain as $menu) {
					if (is_array($menu)) {
						return new IPxeMenu($menu);
					}
				}
				// Should never end up here, but we'd just fall through and use the default
			}
		}
		// We're here, no specific menu, use default
		$menu = Database::queryFirst("SELECT menuid, timeoutms, title, defaultentryid
			FROM serversetup_menu
			ORDER BY isdefault DESC LIMIT 1");
		if ($menu === false) {
			return new EmptyIPxeMenu;
		}
		return new IPxeMenu($menu);
	}

	public static function forClient($ip, $uuid)
	{
		$locationId = 0;
		if (Module::isAvailable('locations')) {
			$locationId = Location::getFromIpAndUuid($ip, $uuid);
		}
		return self::forLocation($locationId);
	}

}

class EmptyIPxeMenu extends IPxeMenu
{

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct()
	{
		$this->title = 'No menu defined';
		$this->menuid = -1;
		$this->items[] = new MenuEntry([
			'title' => 'Please create a menu in Server-Setup first'
		]);
		$this->items[] = new MenuEntry([
			'title' => 'Bitte erstellen Sie zunächst ein Menü'
		]);
	}

}
