<?php

abstract class BootEntry
{

	/** Supports both via same entry (stored in PCBIOS entry) */
	const AGNOSTIC = 'agnostic';
	/** Only valid for legacy BIOS boot */
	const BIOS = 'PCBIOS';
	/** Only valid for EFI boot */
	const EFI = 'EFI';
	/** Supports both via distinct entry */
	const BOTH = 'PCBIOS-EFI';

	public abstract function supportsMode($mode);

	public abstract function toScript($failLabel, $mode);

	public abstract function toArray();

	public abstract function addFormFields(&$array);

	/*
	 *
	 */

	/**
	 * Return a BootEntry instance from the serialized data.
	 *
	 * @param string $module module this entry belongs to, or special values .script/.exec
	 * @param string $jsonString serialized entry data
	 * @return BootEntry|null instance representing boot entry, null on error
	 */
	public static function fromJson($module, $data)
	{
		if ($module{0} !== '.') {
			// Hook from other module
			$hook = Hook::loadSingle($module, 'ipxe-bootentry');
			if ($hook === false) {
				error_log('Module ' . $module . ' doesnt have an ipxe-bootentry hook');
				return null;
			}
			$ret = $hook->run();
			if (!($ret instanceof BootEntryHook))
				return null;
			return $ret->getBootEntry($data);
		}
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if ($module === '.script') {
			return new CustomBootEntry($data);
		}
		if ($module === '.exec') {
			return new StandardBootEntry($data);
		}
		return null;
	}

	public static function forMenu($menuId)
	{
		return new MenuBootEntry($menuId);
	}

	public static function newStandardBootEntry($initData, $efi = false, $arch = false)
	{
		$ret = new StandardBootEntry($initData, $efi, $arch);
		$list = [];
		if ($ret->arch() !== self::EFI) {
			$list[] = self::BIOS;
		}
		if ($ret->arch() === self::EFI || $ret->arch() === self::BOTH) {
			$list[] = self::EFI;
		}
		$data = $ret->toArray();
		foreach ($list as $mode) {
			if (empty($data[$mode]['executable'])) {
				error_log('Incomplete stdbot: ' . print_r($initData, true));
				return null;
			}
		}
		return $ret;
	}

	public static function newCustomBootEntry($initData)
	{
		if (empty($initData['script']))
			return null;
		return new CustomBootEntry($initData);
	}

	/**
	 * Return a BootEntry instance from database with the given id.
	 *
	 * @param string $id
	 * @return BootEntry|null|false false == unknown id, null = unknown entry type, BootEntry instance on success
	 */
	public static function fromDatabaseId($id)
	{
		$row = Database::queryFirst("SELECT module, data FROM serversetup_bootentry
			WHERE entryid = :id LIMIT 1", ['id' => $id]);
		if ($row === false)
			return false;
		return self::fromJson($row['module'], $row['data']);
	}

	/**
	 * Get all existing BootEntries from database, skipping those of
	 * unknown type. Returned array is assoc, key is entryid
	 *
	 * @return BootEntry[] all existing BootEntries
	 */
	public static function getAll()
	{
		$res = Database::simpleQuery("SELECT entryid, data FROM serversetup_bootentry");
		$ret = [];
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$tmp = self::fromJson($row['module'], $row['data']);
			if ($tmp === null)
				continue;
			$ret[$row['entryid']] = $tmp;
		}
		return $ret;
	}

}

class StandardBootEntry extends BootEntry
{
	/**
	 * @var ExecData PCBIOS boot data
	 */
	protected $pcbios;
	/**
	 * @var ExecData same for EFI
	 */
	protected $efi;

	protected $arch; // Constants below

	const KEYS = ['executable', 'initRd', 'commandLine', 'replace', 'imageFree', 'autoUnload', 'resetConsole', 'dhcpOptions'];

	public function __construct($data, $efi = false, $arch = false)
	{
		$this->pcbios = new ExecData();
		$this->efi = new ExecData();
		if ($data instanceof PxeSection) {
			// Import from PXELINUX menu
			$this->fromPxeMenu($data);
		} elseif ($data instanceof ExecData && is_string($arch)) {
			if (!($efi instanceof ExecData)) {
				$efi = new ExecData();
			}
			$this->pcbios = $data;
			$this->efi = $efi;
			$this->arch = $arch;
		} elseif (is_array($data)) {
			// Serialized data
			if (!isset($data['arch'])) {
				error_log('Serialized data to StandardBootEntry doesnt contain arch: ' . json_encode($data));
			} else {
				$this->arch = $data['arch'];
			}
			if (isset($data[BootEntry::BIOS]) || isset($data[BootEntry::EFI])) {
				// Current format
				$this->fromCurrentFormat($data);
			} else {
				// Convert legacy DB format
				$this->fromLegacyFormat($data);
			}
		} elseif ($arch == BootEntry::EFI && $efi instanceof ExecData) {
			$this->efi = $efi;
			$this->arch = $arch;
		} else {
			error_log('Invalid StandardBootEntry constructor call');
		}
		if (!in_array($this->arch, [BootEntry::BIOS, BootEntry::EFI, BootEntry::BOTH, BootEntry::AGNOSTIC])) {
			$this->arch = BootEntry::AGNOSTIC;
		}
	}

	private function fromLegacyFormat($data)
	{
		$ok = false;
		foreach (self::KEYS as $key) {
			if (isset($data[$key][BootEntry::BIOS])) {
				$this->pcbios->{$key} = $data[$key][BootEntry::BIOS];
				$ok = true;
			}
			if (isset($data[$key][BootEntry::EFI])) {
				$this->efi->{$key} = $data[$key][BootEntry::EFI];
				$ok = true;
			}
		}
		if (!$ok) {
			// Very old entry
			foreach (self::KEYS as $key) {
				if (isset($data[$key])) {
					$this->pcbios->{$key} = $data[$key];
				}
			}
		}
	}

	private function fromCurrentFormat($data)
	{
		foreach (self::KEYS as $key) {
			if (isset($data[BootEntry::BIOS][$key])) {
				$this->pcbios->{$key} = $data[BootEntry::BIOS][$key];
			}
			if (isset($data[BootEntry::EFI][$key])) {
				$this->efi->{$key} = $data[BootEntry::EFI][$key];
			}
		}
	}

	/**
	 * @param PxeSection $data
	 */
	private function fromPxeMenu($data)
	{
		$bios = $this->pcbios;
		$bios->executable = $data->kernel;
		$bios->initRd = $data->initrd;
		$bios->commandLine = ' ' . str_replace('vga=current', '', $data->append) . ' ';
		$bios->resetConsole = true;
		$bios->replace = true;
		$bios->autoUnload = true;
		if (strpos($bios->commandLine, ' quiet ') !== false) {
			$bios->commandLine .= ' loglevel=5 rd.systemd.show_status=auto';
		}
		if ($data->ipAppend & 1) {
			$bios->commandLine .= ' ${ipappend1}';
		}
		if ($data->ipAppend & 2) {
			$bios->commandLine .= ' ${ipappend2}';
		}
		if ($data->ipAppend & 4) {
			$bios->commandLine .= ' SYSUUID=${uuid}';
		}
		$bios->commandLine = trim(preg_replace('/\s+/', ' ', $bios->commandLine));
	}

	public function arch()
	{
		return $this->arch;
	}

	public function supportsMode($mode)
	{
		if ($mode === $this->arch || $this->arch === BootEntry::AGNOSTIC)
			return true;
		if ($mode === BootEntry::BIOS || $mode === BootEntry::EFI) {
			return $this->arch === BootEntry::BOTH;
		}
		error_log('Unknown iPXE platform: ' . $mode);
		return false;
	}

	public function toScript($failLabel, $mode)
	{
		if (!$this->supportsMode($mode)) {
			return "prompt Entry doesn't have an executable for mode $mode\n";
		}
		if ($this->arch === BootEntry::AGNOSTIC || $mode == BootEntry::BIOS) {
			$entry = $this->pcbios;
		} else {
			$entry = $this->efi;
		}
		$entry->sanitize();

		$script = '';
		if ($entry->resetConsole) {
			$script .= "console ||\n";
		}
		if ($entry->imageFree) {
			$script .= "imgfree ||\n";
		}
		foreach ($entry->dhcpOptions as $opt) {
			if (empty($opt['value'])) {
				$val = '${}';
			} else {
				if (empty($opt['hex'])) {
					$val = bin2hex($opt['value']);
				} else {
					$val = $opt['value'];
				}
				preg_match_all('/[0-9a-f]{2}/', $val, $out);
				$val = implode(':', $out[0]);
			}
			$script .= 'set net${idx}/' . $opt['opt'] . ':hex ' . $val
				. ' || prompt Cannot override DHCP server option ' . $opt['opt'] . ". Press any key to continue anyways.\n";
		}
		$initrds = [];
		if (!empty($entry->initRd)) {
			foreach (array_values($entry->initRd) as $i => $initrd) {
				if (empty($initrd))
					continue;
				$script .= "initrd --name initrd$i $initrd || goto $failLabel\n";
				$initrds[] = "initrd$i";
			}
		}
		$script .= "boot ";
		if ($entry->autoUnload) {
			$script .= "-a ";
		}
		if ($entry->replace) {
			$script .= "-r ";
		}
		$script .= $entry->executable;
		if (empty($initrds)) {
			$rdBase = '';
		} else {
			$rdBase = " initrd=" . implode(',', $initrds);
		}
		if (!empty($entry->commandLine)) {
			$script .= "$rdBase {$entry->commandLine}";
		}
		$script .= " || goto $failLabel\n";
		if ($entry->resetConsole) {
			$script .= "goto start ||\n";
		}
		return $script;
	}

	public function addFormFields(&$array)
	{
		$array[$this->arch . '_selected'] = 'selected';
		$array['entries'][] = $this->pcbios->toFormFields(BootEntry::BIOS);
		$array['entries'][] = $this->efi->toFormFields(BootEntry::EFI);
		$array['exec_checked'] = 'checked';
	}

	public function toArray()
	{
		return [
			BootEntry::BIOS => $this->pcbios->toArray(),
			BootEntry::EFI => $this->efi->toArray(),
			'arch' => $this->arch,
		];
	}
}

class CustomBootEntry extends BootEntry
{
	protected $script;

	public function __construct($data)
	{
		if (is_array($data) && isset($data['script'])) {
			$this->script = $data['script'];
		}
	}

	public function supportsMode($mode)
	{
		return true;
	}

	public function toScript($failLabel, $mode)
	{
		return str_replace('%fail%', $failLabel, $this->script) . "\n";
	}

	public function addFormFields(&$array)
	{
		$array['entry'] = [
			'script' => $this->script,
		];
		$array['script_checked'] = 'checked';
	}

	public function toArray()
	{
		return ['script' => $this->script];
	}
}

class MenuBootEntry extends BootEntry
{
	protected $menuId;

	public function __construct($menuId)
	{
		$this->menuId = $menuId;
	}

	public function supportsMode($mode)
	{
		return true;
	}

	public function toScript($failLabel, $mode)
	{
		return 'chain -ar ${self}&menuid=' . $this->menuId . ' || goto ' . $failLabel . "\n";
	}

	public function toArray()
	{
		return [];
	}

	public function addFormFields(&$array)
	{
	}
}

