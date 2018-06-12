<?php

class IPxe
{

	public static function importPxeMenus($configPath)
	{
		foreach (glob($configPath . '/*', GLOB_NOSORT) as $file) {
			if (!is_file($file) || !preg_match('~/[A-F0-9]{1,8}$~', $file))
				continue;
			$content = file_get_contents($file);
			if ($content === false)
				continue;
			$file = basename($file);
			$start = hexdec(str_pad($file,8, '0'));
			$end =  hexdec(str_pad($file,8, 'f')); // TODO ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^PREFIX
			error_log('From ' . long2ip($start) . ' to ' . long2ip($end));
			$res = Database::simpleQuery("SELECT locationid, startaddr, endaddr FROM subnet
				WHERE startaddr >= :start AND endaddr <= :end", compact('start', 'end'));
			$locations = [];
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				foreach ($locations as &$loc) {
					if ($row['startaddr'] <= $loc['startaddr'] && $row['endaddr'] >= $loc['endaddr']) {
						$loc = false;
					} elseif ($row['startaddr'] >= $loc['startaddr'] && $row['endaddr'] <= $loc['endaddr']) {
						continue 2;
					}
				}
				unset($loc);
				$locations[] = $row;
			}
			$menuId = self::insertMenu($content, 'Imported', false, 0, [], []);
			if ($menuId === false)
				continue;
			foreach ($locations as $loc) {
				Database::exec('INSERT IGNORE INTO serversetup_menu_x_location (menuid, locationid)
						VALUES (:menuid, :locationid)', ['menuid' => $menuId, 'locationid' => $loc['locationid']]);
			}
		}
	}

	public static function importLegacyMenu($force = false)
	{
		if (!$force && false !== Database::queryFirst("SELECT entryid FROM serversetup_bootentry WHERE entryid = 'bwlp-default'"))
			return false; // Already exists
		// Now create the default entry
		self::createDefaultEntries();
		$prepend = ['bwlp-default' => false, 'localboot' => false];
		$defaultLabel = 'bwlp-default';
		$menuTitle = 'bwLehrpool Bootauswahl';
		$pxeConfig = '';
		$timeoutSecs = 60;
		// Try to import any customization
		$oldMenu = Property::getBootMenu();
		if (is_array($oldMenu)) {
			//
			if (isset($oldMenu['timeout'])) {
				$timeoutSecs = (int)$oldMenu['timeout'];
			}
			if (isset($oldMenu['defaultentry'])) {
				if ($oldMenu['defaultentry'] === 'net') {
					$defaultLabel = 'bwlp-default';
				} elseif ($oldMenu['defaultentry'] === 'hdd') {
					$defaultLabel = 'localboot';
				} elseif ($oldMenu['defaultentry'] === 'custom') {
					$defaultLabel = 'custom';
				}
			}
			if (!empty($oldMenu['custom'])) {
				$pxeConfig = $oldMenu['custom'];
			}
		}
		$append = [
			'',
			'bwlp-default-dbg' => false,
			'',
			'poweroff' => false,
		];
		return self::insertMenu($pxeConfig, $menuTitle, $defaultLabel, $timeoutSecs, $prepend, $append);
	}

	private static function insertMenu($pxeConfig, $menuTitle, $defaultLabel, $defaultTimeoutSeconds, $prepend, $append)
	{
		$timeoutMs = [];
		$menuEntries = $prepend;
		settype($menuEntries, 'array');
		if (!empty($pxeConfig)) {
			$pxe = PxeLinux::parsePxeLinux($pxeConfig);
			if (!empty($pxe->title)) {
				$menuTitle = $pxe->title;
			}
			if ($pxe->timeoutLabel !== null) {
				$defaultLabel = $pxe->timeoutLabel;
			}
			$timeoutMs[] = $pxe->timeoutMs;
			$timeoutMs[] = $pxe->totalTimeoutMs;
			foreach ($pxe->sections as $section) {
				if ($section->localBoot || preg_match('/chain.c32$/i', $section->kernel)) {
					$menuEntries['localboot'] = 'localboot';
					continue;
				}
				$section->mangle();
				if ($section->label === null) {
					if (!$section->isHidden && !empty($section->title)) {
						$menuEntries[] = $section->title;
					}
					continue;
				}
				if (empty($section->kernel)) {
					if (!$section->isHidden && !empty($section->title)) {
						$menuEntries[] = $section->title;
					}
					continue;
				}
				$entry = self::pxe2BootEntry($section);
				if ($entry === null)
					continue;
				$label = self::cleanLabelFixLocal($section);
				if ($defaultLabel === $section->label) {
					$defaultLabel = $label;
				}
				$hotkey = MenuEntry::filterKeyName($section->hotkey);
				// Create boot entry
				$data = $entry->toArray();
				Database::exec('INSERT IGNORE INTO serversetup_bootentry (entryid, hotkey, title, builtin, data)
				VALUES (:label, :hotkey, :title, 0, :data)', [
					'label' => $label,
					'hotkey' => $hotkey,
					'title' => self::sanitizeIpxeString($section->title),
					'data' => json_encode($data),
				]);
				$menuEntries[$label] = $section;
			}
		}
		if (is_array($append)) {
			$menuEntries += $append;
		}
		if (empty($menuEntries))
			return false;
		// Make menu
		$timeoutMs = array_filter($timeoutMs, 'is_int');
		if (empty($timeoutMs)) {
			$timeoutMs = (int)($defaultTimeoutSeconds * 1000);
		} else {
			$timeoutMs = min($timeoutMs);
		}
		$isDefault = (int)(Database::queryFirst('SELECT menuid FROM serversetup_menu WHERE isdefault = 1') === false);
		Database::exec("INSERT INTO serversetup_menu (timeoutms, title, defaultentryid, isdefault)
			VALUES (:timeoutms, :title, NULL, :isdefault)", [
			'title' => self::sanitizeIpxeString($menuTitle),
			'timeoutms' => $timeoutMs,
			'isdefault' => $isDefault,
		]);
		$menuId = Database::lastInsertId();
		if (!array_key_exists($defaultLabel, $menuEntries) && $timeoutMs > 0) {
			$defaultLabel = array_keys($menuEntries)[0];
		}
		// Link boot entries to menu
		$defaultEntryId = null;
		$order = 1000;
		foreach ($menuEntries as $label => $entry) {
			if (is_string($entry)) {
				// Gap entry
				Database::exec("INSERT INTO serversetup_menuentry
				(menuid, entryid, hotkey, title, hidden, sortval, plainpass, md5pass)
				VALUES (:menuid, :entryid, :hotkey, :title, :hidden, :sortval, '', '')", [
					'menuid' => $menuId,
					'entryid' => null,
					'hotkey' => '',
					'title' => self::sanitizeIpxeString($entry),
					'hidden' => 0,
					'sortval' => $order += 100,
				]);
				continue;
			}
			$data = Database::queryFirst("SELECT entryid, hotkey, title FROM serversetup_bootentry WHERE entryid = :entryid", ['entryid' => $label]);
			if ($data === false)
				continue;
			$data['pass'] = '';
			$data['hidden'] = 0;
			if ($entry instanceof PxeSection) {
				$data['hidden'] = (int)$entry->isHidden;
				// Prefer explicit data from this imported menu over the defaults
				$data['title'] = self::sanitizeIpxeString($entry->title);
				if (MenuEntry::getKeyCode($entry->hotkey) !== false) {
					$data['hotkey'] = $entry->hotkey;
				}
				if (!empty($entry->passwd)) {
					// Most likely it's a hash so we cannot recover; ask people to reset
					$data['pass'] ='please_reset';
				}
			}
			$data['menuid'] = $menuId;
			$data['sortval'] = $order += 100;
			$res = Database::exec("INSERT INTO serversetup_menuentry
				(menuid, entryid, hotkey, title, hidden, sortval, plainpass, md5pass)
				VALUES (:menuid, :entryid, :hotkey, :title, :hidden, :sortval, :pass, :pass)", $data);
			if ($res !== false && $label === $defaultLabel) {
				$defaultEntryId = Database::lastInsertId();
			}
		}
		// Now we can set default entry
		if (!empty($defaultEntryId)) {
			Database::exec("UPDATE serversetup_menu SET defaultentryid = :entryid WHERE menuid = :menuid",
				['menuid' => $menuId, 'entryid' => $defaultEntryId]);
		}
		// TODO: masterpw? rather pointless....
		//$oldMenu['masterpasswordclear'];
		return $menuId;
	}

	private static function createDefaultEntries()
	{
		$query = 'INSERT IGNORE INTO serversetup_bootentry (entryid, hotkey, title, builtin, data)
			VALUES (:entryid, :hotkey, :title, 1, :data)';
		Database::exec($query,
			[
				'entryid' => 'bwlp-default',
				'hotkey' => 'B',
				'title' => 'bwLehrpool-Umgebung starten',
				'data' => json_encode([
					'executable' => '/boot/default/kernel',
					'initRd' => '/boot/default/initramfs-stage31',
					'commandLine' => 'slxbase=boot/default quiet splash loglevel=5 rd.systemd.show_status=auto ${ipappend1} ${ipappend2}',
					'replace' => true,
					'autoUnload' => true,
					'resetConsole' => true,
				]),
			]);
		Database::exec($query,
			[
				'entryid' => 'bwlp-default-dbg',
				'hotkey' => '',
				'title' => 'bwLehrpool-Umgebung starten (nosplash, debug)',
				'data' => json_encode([
					'executable' => '/boot/default/kernel',
					'initRd' => '/boot/default/initramfs-stage31',
					'commandLine' => 'slxbase=boot/default loglevel=7 ${ipappend1} ${ipappend2}',
					'replace' => true,
					'autoUnload' => true,
					'resetConsole' => true,
				]),
			]);
		Database::exec($query,
			[
				'entryid' => 'localboot',
				'hotkey' => 'L',
				'title' => 'Lokales System starten',
				'data' => json_encode([
					'script' => 'goto slx_localboot || goto %fail% ||',
				]),
			]);
		Database::exec($query,
			[
				'entryid' => 'poweroff',
				'hotkey' => 'P',
				'title' => 'Power off',
				'data' => json_encode([
					'script' => 'poweroff || goto %fail% ||',
				]),
			]);
		Database::exec($query,
			[
				'entryid' => 'reboot',
				'hotkey' => 'R',
				'title' => 'Reboot',
				'data' => json_encode([
					'script' => 'reboot || goto %fail% ||',
				]),
			]);
	}

	/**
	 * Create unique label for a boot entry. It will try to figure out whether
	 * this is one of our default entries and if not, create a unique label
	 * representing the menu entry contents.
	 * Also it patches the entry if it's referencing the local bwlp install
	 * because side effects.
	 *
	 * @param PxeSection $section
	 * @return string
	 */
	private static function cleanLabelFixLocal($section)
	{
		$myip = Property::getServerIp();
		// Detect our "old" entry types
		if (count($section->initrd) === 1 && preg_match(",$myip/boot/default/kernel\$,", $section->kernel)
				&& preg_match(",$myip/boot/default/initramfs-stage31\$,", $section->initrd[0])) {
			// Kernel and initrd match, examine KCL
			if ($section->append === 'slxbase=boot/default vga=current quiet splash') {
				// Normal
				return 'bwlp-default';
			} elseif ($section->append === 'slxbase=boot/default') {
				// Debug output
				return 'bwlp-default-dbg';
			} else {
				// Transform to relative URL, leave KCL, fall through to generic label gen
				$section->kernel = '/boot/default/kernel';
				$section->initrd = ['/boot/default/initramfs-stage31'];
			}
		}
		// Generic -- "smart" hash of kernel, initrd and command line
		$str = $section->kernel . ' ' . implode(',', $section->initrd);
		$array = preg_split('/\s+/', $section->append, -1, PREG_SPLIT_NO_EMPTY);
		sort($array);
		$str .= ' ' . implode(' ', $array);

		return 'i-' . substr(md5($str), 0, 12);
	}

	/**
	 * @param PxeSection $section
	 * @return BootEntry|null The according boot entry, null if it's unparsable
	 */
	private static function pxe2BootEntry($section)
	{
		if (preg_match('/(pxechain.com|pxechn.c32)$/i', $section->kernel)) {
			// Chaining -- create script
			$args = preg_split('/\s+/', $section->append);
			$script = '';
			$file = false;
			for ($i = 0; $i < count($args); ++$i) {
				$arg = $args[$i];
				if ($arg === '-c') { // PXELINUX config file option
					++$i;
					$script .= "set 209:string {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-p') { // PXELINUX prefix path option
					++$i;
					$script .= "set 210:string {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-t') { // PXELINUX timeout option
					++$i;
					$script .= "set 211:int32 {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-o') { // Overriding various DHCP options
					++$i;
					if (preg_match('/^((?:0x)?[a-f0-9]{1,4})\.([bwlsh])=(.*)$/i', $args[$i], $out)) {
						// TODO: 'q' (8byte) unsupported for now
						$opt = intval($out[1], 0);
						if ($opt > 0 && $opt < 255) {
							static $optType = ['b' => 'uint8', 'w' => 'uint16', 'l' => 'int32', 's' => 'string', 'h' => 'hex'];
							$type = $optType[$out[2]];
							$script .= "set {$opt}:{$type} {$args[$i]} || goto %fail%\n";
						}
					}
				} elseif ($arg{0} === '-') {
					continue;
				} elseif ($file === false) {
					$file = self::parseFile($arg);
				}
			}
			if ($file !== false) {
				$url = parse_url($file);
				if (isset($url['host'])) {
					$script .= "set next-server {$url['host']} || goto %fail%\n";
				}
				if (isset($url['path'])) {
					$script .= "set filename {$url['path']} || goto %fail%\n";
				}
				$script .= "chain -ar {$file} || goto %fail%\n";
				return new CustomBootEntry(['script' => $script]);
			}
			return null;
		}
		// "Normal" entry that should be convertible into a StandardBootEntry
		$section->kernel = self::parseFile($section->kernel);
		foreach ($section->initrd as &$initrd) {
			$initrd = self::parseFile($initrd);
		}
		return BootEntry::newStandardBootEntry($section);
	}

	/**
	 * Parse PXELINUX file notion. Basically, turn
	 * server::file into tftp://server/file.
	 *
	 * @param string $file
	 * @return string
	 */
	private static function parseFile($file)
	{
		if (preg_match(',^([^:/]+)::(.*)$,', $file, $out)) {
			return 'tftp://' . $out[1] . '/' . $out[2];
		}
		return $file;
	}

	public static function sanitizeIpxeString($string)
	{
		return str_replace(['&', '|', ';', '$', "\r", "\n"], ['+', '/', ':', 'S', ' ', ' '], $string);
	}

	public static function makeMd5Pass($plainpass, $salt)
	{
		if (empty($plainpass))
			return '';
		return md5(md5($plainpass) . '-' . $salt);
	}

}
