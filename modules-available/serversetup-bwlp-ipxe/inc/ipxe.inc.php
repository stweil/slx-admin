<?php

class IPxe
{

	/**
	 * @var BootEntry[]|false Contains all known boot entries (for dup checking)
	 */
	private static $allEntries = false;

	/**
	 * Import all IP-Range based pxe menus from the given directory.
	 *
	 * @param string $configPath The pxelinux.cfg path where to look for menu files in hexadecimal IP format.
	 * @return Number of menus imported
	 */
	public static function importSubnetPxeMenus($configPath)
	{
		$res = Database::simpleQuery('SELECT menuid, entryid FROM serversetup_menuentry ORDER BY sortval ASC');
		$menus = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($menus[$row['menuid']])) {
				$menus[(int)$row['menuid']] = [];
			}
			$menus[(int)$row['menuid']][] = $row['entryid'];
		}
		$importCount = 0;
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
			// Get all subnets that lie within the range defined by the pxelinux filename
			$res = Database::simpleQuery("SELECT locationid, startaddr, endaddr FROM subnet
				WHERE startaddr >= :start AND endaddr <= :end", compact('start', 'end'));
			$locations = [];
			// Iterate over result, eliminate those that are dominated by others
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
			$menu = PxeLinux::parsePxeLinux($content, true);
			// Insert all entries first, so we can get the list of entry IDs
			$entries = [];
			self::importPxeMenuEntries($menu, $entries);
			$entries = array_keys($entries);
			$defId = null;
			// Look up entry IDs, if match, ref for this location
			if (($menuId = array_search($entries, $menus)) !== false) {
				error_log('Imported menu ' . $menu->title . ' exists, using for ' . count($locations) . ' locations.');
				// Figure out the default label, get its label name
				$defSection = null;
				foreach ($menu->sections as $section) {
					if ($section->isDefault) {
						$defSection = $section;
					} elseif ($defSection === null && $section->label === $menu->timeoutLabel) {
						$defSection = $section;
					}
				}
				if ($defSection !== null && ($defIdEntry = array_search(self::pxe2BootEntry($defSection), self::$allEntries)) !== false) {
					// Confirm it actually exists (it should since the menu seems identical) and get menuEntryId
					$me = Database::queryFirst('SELECT m.defaultentryid, me.menuentryid FROM serversetup_bootentry be
							INNER JOIN serversetup_menuentry me ON (be.entryid = me.entryid)
							INNER JOIN serversetup_menu m ON (m.menuid = me.menuid)
							WHERE be.entryid = :id AND me.menuid = :menuid',
						['id' => $defIdEntry, 'menuid' => $menuId]);
					if ($me !== false && $me['defaultentryid'] != $me['menuentryid']) {
						$defId = $me['menuentryid'];
					}
				}
			} else {
				error_log('Imported menu ' . $menu->title . ' is NEW, using for ' . count($locations) . ' locations.');
				// Insert new menu
				$menuId = self::insertMenu($menu, 'Auto Imported', false, 0, [], []);
				if ($menuId === false)
					continue;
				$menus[(int)$menuId] = $entries;
				$importCount++;
			}
			foreach ($locations as $loc) {
				if ($loc === false)
					continue;
				Database::exec('INSERT IGNORE INTO serversetup_menu_location (menuid, locationid, defaultentryid)
						VALUES (:menuid, :locationid, :def)', [
					'menuid' => $menuId,
					'locationid' => $loc['locationid'],
					'def' => $defId,
				]);
			}
		}
		return $importCount;
	}

	public static function importLegacyMenu($force = false)
	{
		// See if anything is there
		if (!$force && false !== Database::queryFirst("SELECT menuentryid FROM serversetup_menuentry LIMIT 1"))
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
		return self::insertMenu(PxeLinux::parsePxeLinux($pxeConfig, false), $menuTitle, $defaultLabel, $timeoutSecs, $prepend, $append);
	}

	/**
	 * @param PxeMenu $pxeMenu
	 * @param string $menuTitle
	 * @param string|false $defaultLabel Fallback for the default label, if PxeMenu doesn't set one
	 * @param int $defaultTimeoutSeconds Default timeout, if PxeMenu doesn't set one
	 * @param array $prepend
	 * @param array $append
	 * @return int|false
	 */
	public static function insertMenu($pxeMenu, $menuTitle, $defaultLabel, $defaultTimeoutSeconds, $prepend, $append)
	{
		$timeoutMs = [];
		$menuEntries = $prepend;
		settype($menuEntries, 'array');
		if (!empty($pxeMenu)) {
			$pxe =& $pxeMenu;
			if (!empty($pxe->title)) {
				$menuTitle = $pxe->title;
			}
			if ($pxe->timeoutLabel !== null && $pxe->hasLabel($pxe->timeoutLabel)) {
				$defaultLabel = $pxe->timeoutLabel;
			} elseif ($pxe->hasLabel($pxe->default)) {
				$defaultLabel = $pxe->default;
			}
			$timeoutMs[] = $pxe->timeoutMs;
			$timeoutMs[] = $pxe->totalTimeoutMs;
			self::importPxeMenuEntries($pxe, $menuEntries);
		}
		if (is_array($append)) {
			$menuEntries += $append;
		}
		if (empty($menuEntries))
			return false;
		// Make menu
		$timeoutMs = array_filter($timeoutMs, function($x) { return is_int($x) && $x > 0; });
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
		// Figure out entryid for default label
		// Fiddly diddly way of getting the mangled entryid for the wanted pxe menu label
		$defaultEntryId = false;
		foreach ($menuEntries as $entryId => $section) {
			if ($section instanceof PxeSection) {
				if ($section->isDefault) {
					$defaultEntryId = $entryId;
					break;
				}
				if ($section->label === $defaultLabel) {
					$defaultEntryId = $entryId;
				}
			}
		}
		if ($defaultEntryId === false) {
			$defaultEntryId = array_keys($menuEntries)[0];
		}
		// Link boot entries to menu
		$defaultMenuEntryId = null;
		$order = 1000;
		foreach ($menuEntries as $entryId => $entry) {
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
			$data = Database::queryFirst("SELECT entryid, hotkey, title FROM serversetup_bootentry WHERE entryid = :entryid", ['entryid' => $entryId]);
			if ($data === false)
				continue;
			$data['pass'] = '';
			$data['hidden'] = 0;
			if ($entry instanceof PxeSection) {
				$data['hidden'] = (int)$entry->isHidden;
				// Prefer explicit data from this imported menu over the defaults
				$title = self::sanitizeIpxeString($entry->title);
				if (!empty($title)) {
					$data['title'] = $title;
				}
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
			if ($res !== false && $entryId === $defaultEntryId) {
				$defaultMenuEntryId = Database::lastInsertId();
			}
		}
		// Now we can set default entry
		if (!empty($defaultMenuEntryId)) {
			Database::exec("UPDATE serversetup_menu SET defaultentryid = :menuentryid WHERE menuid = :menuid",
				['menuid' => $menuId, 'menuentryid' => $defaultMenuEntryId]);
		}
		// TODO: masterpw? rather pointless....
		//$oldMenu['masterpasswordclear'];
		return $menuId;
	}

	/**
	 * Import only the bootentries from the given PXELinux menu
	 * @param PxeMenu $pxe
	 * @param array $menuEntries Where to append the generated menu items to
	 */
	public static function importPxeMenuEntries($pxe, &$menuEntries)
	{
		if (self::$allEntries === false) {
			self::$allEntries = BootEntry::getAll();
		}
		foreach ($pxe->sections as $section) {
			if ($section->localBoot !== false || preg_match('/chain\.c32$/i', $section->kernel)) {
				$menuEntries['localboot'] = $section;
				continue;
			}
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
			$label = self::cleanLabelFixLocal($section);
			$entry = self::pxe2BootEntry($section);
			if ($entry === null)
				continue; // Error? Ignore
			if ($label !== false || ($label = array_search($entry, self::$allEntries))) {
				// Exact Duplicate, Do Nothing
				error_log('Ignoring duplicate boot entry ' . $section->label . ' (' . $section->kernel . ')');
			} else {
				// Seems new one; make sure label doesn't collide
				error_log('Adding new boot entry ' . $section->label . ' (' . $section->kernel . ')');
				$label = substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower($section->label)), 0, 16);
				while (empty($label) || array_key_exists($label, self::$allEntries)) {
					$label = 'i-' . substr(md5(microtime(true) . $section->kernel . mt_rand()), 0, 14);
				}
				self::$allEntries[$label] = $entry;
				$hotkey = MenuEntry::filterKeyName($section->hotkey);
				// Create boot entry
				$data = $entry->toArray();
				$title = self::sanitizeIpxeString($section->title);
				if (empty($title)) {
					$title = self::sanitizeIpxeString($section->label);
				}
				if (empty($title)) {
					$title = $label;
				}
				Database::exec('INSERT IGNORE INTO serversetup_bootentry (entryid, hotkey, title, builtin, data)
					VALUES (:label, :hotkey, :title, 0, :data)', [
					'label' => $label,
					'hotkey' => $hotkey,
					'title' => $title,
					'data' => json_encode($data),
				]);
			}
			$menuEntries[$label] = $section;
		}
	}

	public static function createDefaultEntries()
	{
		Database::exec( 'INSERT IGNORE INTO serversetup_bootentry (entryid, hotkey, title, builtin, data)
			VALUES (:entryid, :hotkey, :title, 1, :data) ON DUPLICATE KEY UPDATE data = VALUES(data)',
			[
				'entryid' => 'bwlp-default',
				'hotkey' => 'B',
				'title' => 'bwLehrpool-Umgebung starten',
				'data' => json_encode([
					'script' => '
imgfree ||
set slxextra initrd=logo ||
initrd /boot/default/initramfs-stage31 || goto fail
initrd --name logo /tftp/bwlp.cpio || clear slxextra
boot -a -r /boot/default/kernel initrd=initramfs-stage31 ${slxextra} slxbase=boot/default quiet splash loglevel=5 rd.systemd.show_status=auto intel_iommu=igfx_off ${ipappend1} ${ipappend2} || goto fail
',
				]),
			]);
		$query = 'INSERT IGNORE INTO serversetup_bootentry (entryid, hotkey, title, builtin, data)
			VALUES (:entryid, :hotkey, :title, 1, :data) ON DUPLICATE KEY UPDATE data = VALUES(data)';
		Database::exec($query,
			[
				'entryid' => 'bwlp-default-dbg',
				'hotkey' => 'D',
				'title' => 'bwLehrpool-Umgebung starten (nosplash, debug output)',
				'data' => json_encode([
					'executable' => ['PCBIOS' => '/boot/default/kernel'],
					'initRd' => ['PCBIOS' => ['/boot/default/initramfs-stage31']],
					'commandLine' => ['PCBIOS' => 'slxbase=boot/default loglevel=7 intel_iommu=igfx_off ${ipappend1} ${ipappend2}'],
					'replace' => true,
					'autoUnload' => true,
					'resetConsole' => true,
					'arch' => 'agnostic',
				]),
			]);
		Database::exec($query,
			[
				'entryid' => 'bwlp-default-sh',
				'hotkey' => 'D',
				'title' => 'bwLehrpool-Umgebung starten (nosplash, !!! debug shell !!!)',
				'data' => json_encode([
					'executable' => ['PCBIOS' => '/boot/default/kernel'],
					'initRd' => ['PCBIOS' => ['/boot/default/initramfs-stage31']],
					'commandLine' => ['PCBIOS' => 'slxbase=boot/default loglevel=7 debug=1 intel_iommu=igfx_off ${ipappend1} ${ipappend2}'],
					'replace' => true,
					'autoUnload' => true,
					'resetConsole' => true,
					'arch' => 'agnostic',
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
	 * Try to figure out whether this is one of our default entries and returns
	 * that according label.
	 * Also it patches the entry if it's referencing the local bwlp install
	 * but with different options.
	 *
	 * @param PxeSection $section
	 * @return string|false existing label if match, false otherwise
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
				// Transform to relative URL, leave KCL
				$section->kernel = '/boot/default/kernel';
				$section->initrd = ['/boot/default/initramfs-stage31'];
			}
		}
		return false;
	}

	/**
	 * @param PxeSection $section
	 * @return BootEntry|null The according boot entry, null if it's unparsable
	 */
	private static function pxe2BootEntry($section)
	{
		if (preg_match('/(pxechain\.com|pxechn\.c32)$/i', $section->kernel)) {
			// Chaining -- create script
			$args = preg_split('/\s+/', $section->append);
			$script = '';
			$file = false;
			for ($i = 0; $i < count($args); ++$i) {
				$arg = $args[$i];
				if ($arg === '-c') { // PXELINUX config file option
					++$i;
					$script .= "set netX/209:string {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-p') { // PXELINUX prefix path option
					++$i;
					$script .= "set netX/210:string {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-t') { // PXELINUX timeout option
					++$i;
					$script .= "set netX/211:int32 {$args[$i]} || goto %fail%\n";
				} elseif ($arg === '-o') { // Overriding various DHCP options
					++$i;
					if (preg_match('/^((?:0x)?[a-f0-9]{1,4})\.([bwlsh])=(.*)$/i', $args[$i], $out)) {
						// TODO: 'q' (8byte) unsupported for now
						$opt = intval($out[1], 0);
						if ($opt > 0 && $opt < 255) {
							static $optType = ['b' => 'uint8', 'w' => 'uint16', 'l' => 'int32', 's' => 'string', 'h' => 'hex'];
							$type = $optType[$out[2]];
							$script .= "set netX/{$opt}:{$type} {$args[$i]} || goto %fail%\n";
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
					$script .= "set netX/next-server {$url['host']} || goto %fail%\n";
				}
				if (isset($url['path'])) {
					$script .= "set netX/filename {$url['path']} || goto %fail%\n";
				}
				$script .= "chain -ar {$file} || goto %fail%\n";
				return BootEntry::newCustomBootEntry(['script' => $script]);
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
