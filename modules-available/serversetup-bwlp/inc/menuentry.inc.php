<?php

class MenuEntry
{
	/**
	 * @var int id of entry, used for pw
	 */
	private $menuentryid;
	/**
	 * @var false|string key code as expected by iPXE
	 */
	private $hotkey;
	/**
	 * @var string
	 */
	private $title;
	/**
	 * @var bool
	 */
	private $hidden;
	/**
	 * @var bool
	 */
	private $gap;
	/**
	 * @var int
	 */
	private $sortval;
	/**
	 * @var BootEntry
	 */
	private $bootEntry = null;

	private $md5pass = null;

	/**
	 * MenuEntry constructor.
	 *
	 * @param array $row row from database
	 */
	public function __construct($row)
	{
		if (is_array($row)) {
			foreach ($row as $key => $value) {
				if (property_exists($this, $key)) {
					$this->{$key} = $value;
				}
			}
			$this->hotkey = self::getKeyCode($row['hotkey']);
			if (!empty($row['bootentry'])) {
				$this->bootEntry = BootEntry::fromJson($row['bootentry']);
			}
			$this->gap = (array_key_exists('entryid', $row) && $row['entryid'] === null);
		}
		settype($this->hidden, 'bool');
		settype($this->gap, 'bool');
		settype($this->sortval, 'int');
		settype($this->menuentryid, 'int');
	}

	public function getMenuItemScript($lblPrefix, $requestedDefaultId, $mode)
	{
		if ($this->bootEntry !== null && !$this->bootEntry->supportsMode($mode))
			return '';
		$str = 'item ';
		if ($this->gap) {
			$str .= '--gap ';
		} else {
			if ($this->hidden) {
				if ($this->hotkey === false)
					return ''; // Hidden entries without hotkey are illegal
				$str .= '--hidden ';
			}
			if ($this->hotkey !== false) {
				$str .= '--key ' . $this->hotkey . ' ';
			}
			if ($this->menuentryid == $requestedDefaultId) {
				$str .= '--default ';
			}
			$str .= "{$lblPrefix}_{$this->menuentryid} ";
		}
		$str .= $this->title;
		return $str . " || prompt Could not create menu item for {$lblPrefix}_{$this->menuentryid}\n";
	}

	public function getBootEntryScript($lblPrefix, $failLabel, $mode)
	{
		if ($this->bootEntry === null || !$this->bootEntry->supportsMode($mode))
			return '';
		$str = ":{$lblPrefix}_{$this->menuentryid}\n";
		if (!empty($this->md5pass)) {
			$str .= "set slx_hash {$this->md5pass} || goto $failLabel\n"
				. "set slx_salt {$this->menuentryid} || goto $failLabel\n"
				. "set slx_pw_ok {$lblPrefix}_ok || goto $failLabel\n"
				. "set slx_pw_fail slx_menu || goto $failLabel\n"
				. "goto slx_pass_check || goto $failLabel\n"
				. ":{$lblPrefix}_ok\n";
		}
		return $str . $this->bootEntry->toScript($failLabel, $mode);
	}

	/*
	 *
	 */

	private static function getKeyArray()
	{
		static $data = false;
		if ($data === false) {
			$data = [
				'F5' => 0x107e,
				'F6' => 0x127e,
				'F7' => 0x137e,
				'F8' => 0x147e,
				'F9' => 0x157e,
				'F10' => 0x167e,
				'F11' => 0x187e,
				'F12' => 0x197e,
			];
			for ($i = 1; $i <= 26; ++$i) {
				$letter = chr(0x40 + $i);
				$data['SHIFT_' . $letter] = 0x40 + $i;
				if ($letter !== 'C') {
					$data['CTRL_' . $letter] = $i;
				}
				$data[$letter] = 0x60 + $i;
			}
			for ($i = 0; $i <= 9; ++$i) {
				$data[chr(0x30 + $i)] = 0x30 + $i;
			}
			asort($data, SORT_NUMERIC);
		}
		return $data;
	}

	/**
	 * Get all the known/supported keys, usable for menu items.
	 *
	 * @return string[] list of known key names
	 */
	public static function getKeyList()
	{
		return array_keys(self::getKeyArray());
	}

	/**
	 * Get the key code ipxe expects for the given named
	 * key. Returns false if the key name is unknown.
	 *
	 * @param string $keyName
	 * @return false|string Key code as hex string, or false if not found
	 */
	public static function getKeyCode($keyName)
	{
		$data = self::getKeyArray();
		if (isset($data[$keyName]))
			return '0x' . dechex($data[$keyName]);
		return false;
	}

	/**
	 * @param string $keyName desired key name
	 * @return string $keyName if it's known, empty string otherwise
	 */
	public static function filterKeyName($keyName)
	{
		$data = self::getKeyArray();
		if (isset($data[$keyName]))
			return $keyName;
		return '';
	}

}
