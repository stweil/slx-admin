<?php

abstract class BootEntry
{

	public function __construct($data = false)
	{
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				if (property_exists($this, $key)) {
					$this->{$key} = $value;
				}
			}
		}
	}

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
	 * @param string $jsonString serialized entry data
	 * @return BootEntry|null instance representing boot entry, null on error
	 */
	public static function fromJson($data)
	{
		if (is_string($data)) {
			$data = json_decode($data, true);
		}
		if (isset($data['script'])) {
			return new CustomBootEntry($data);
		}
		if (isset($data['executable'])) {
			return new StandardBootEntry($data);
		}
		return null;
	}

	public static function forMenu($menuId)
	{
		return new MenuBootEntry($menuId);
	}

	public static function newStandardBootEntry($initData)
	{
		$ret = new StandardBootEntry($initData);
		$list = [];
		if ($ret->arch() !== StandardBootEntry::EFI) {
			$list[] = StandardBootEntry::BIOS;
		}
		if ($ret->arch() === StandardBootEntry::EFI || $ret->arch() === StandardBootEntry::BOTH) {
			$list[] = StandardBootEntry::EFI;
		}
		foreach ($list as $mode) {
			if (empty($ret->toArray()['executable'][$mode])) {
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
		$row = Database::queryFirst("SELECT data FROM serversetup_bootentry
			WHERE entryid = :id LIMIT 1", ['id' => $id]);
		if ($row === false)
			return false;
		return self::fromJson($row['data']);
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
			$tmp = self::fromJson($row['data']);
			if ($tmp === null)
				continue;
			$ret[$row['entryid']] = $tmp;
		}
		return $ret;
	}

}

class StandardBootEntry extends BootEntry
{
	protected $executable;
	protected $initRd;
	protected $commandLine;
	protected $replace;
	protected $autoUnload;
	protected $resetConsole;
	protected $arch; // Constants below

	const BIOS = 'PCBIOS'; // Only valid for legacy BIOS boot
	const EFI = 'EFI'; // Only valid for EFI boot
	const BOTH = 'PCBIOS-EFI'; // Supports both via distinct entry
	const AGNOSTIC = 'agnostic'; // Supports both via same entry (PCBIOS entry)

	public function __construct($data = false)
	{
		if ($data instanceof PxeSection) {
			// Gets arrayfied below
			$this->executable = $data->kernel;
			$this->initRd = [self::BIOS => $data->initrd];
			$this->commandLine = ' ' . str_replace('vga=current', '', $data->append) . ' ';
			$this->resetConsole = true;
			$this->replace = true;
			$this->autoUnload = true;
			if (strpos($this->commandLine, ' quiet ') !== false) {
				$this->commandLine .= ' loglevel=5 rd.systemd.show_status=auto';
			}
			if ($data->ipAppend & 1) {
				$this->commandLine .= ' ${ipappend1}';
			}
			if ($data->ipAppend & 2) {
				$this->commandLine .= ' ${ipappend2}';
			}
			if ($data->ipAppend & 4) {
				$this->commandLine .= ' SYSUUID=${uuid}';
			}
			$this->commandLine = trim(preg_replace('/\s+/', ' ', $this->commandLine));
		} else {
			if (isset($data['initRd']) && is_array($data['initRd'])) {
				foreach ($data['initRd'] as &$initrd) {
					if (is_string($initrd)) {
						$initrd = preg_split('/\s*,\s*/', $initrd);
					}
				}
				unset($initrd);
			}
			parent::__construct($data);
		}
		// Convert legacy DB format
		foreach (['executable', 'initRd', 'commandLine', 'replace', 'autoUnload', 'resetConsole'] as $key) {
			if (!is_array($this->{$key})) {
				$this->{$key} = [ self::BIOS => $this->{$key}, self::EFI => '' ];
			}
		}
		foreach ($this->initRd as &$initrd) {
			if (!is_array($initrd)) {
				$initrd = [$initrd];
			}
			$initrd = array_filter($initrd, function($x) { return strlen(trim($x)) !== 0; });
		}
		unset($initrd);
		if ($this->arch === null) {
			$this->arch = self::AGNOSTIC;
		}
	}

	public function arch()
	{
		return $this->arch;
	}

	public function supportsMode($mode)
	{
		if ($mode === $this->arch || $this->arch === self::AGNOSTIC)
			return true;
		if ($mode === self::BIOS || $mode === self::EFI) {
			return $this->arch === self::BOTH;
		}
		error_log('Unknown iPXE platform: ' . $mode);
		return false;
	}

	public function toScript($failLabel, $mode)
	{
		if (!$this->supportsMode($mode)) {
			return "prompt Entry doesn't have an executable for mode $mode\n";
		}
		if ($this->arch === self::AGNOSTIC) {
			$mode = self::BIOS;
		}

		$script = '';
		if ($this->resetConsole[$mode]) {
			$script .= "console ||\n";
		}
		// TODO: Checkbox
		$script .= "imgfree ||\n";
		$initrds = [];
		if (!empty($this->initRd[$mode])) {
			foreach (array_values($this->initRd[$mode]) as $i => $initrd) {
				if (empty($initrd))
					continue;
				$script .= "initrd --name initrd$i $initrd || goto $failLabel\n";
				$initrds[] = "initrd$i";
			}
		}
		$script .= "boot ";
		if ($this->autoUnload[$mode]) {
			$script .= "-a ";
		}
		if ($this->replace[$mode]) {
			$script .= "-r ";
		}
		$script .= $this->executable[$mode];
		if (empty($initrds)) {
			$rdBase = '';
		} else {
			$rdBase = " initrd=" . implode(',', $initrds);
		}
		if (!empty($this->commandLine[$mode])) {
			$script .= "$rdBase {$this->commandLine[$mode]}";
		}
		$script .= " || goto $failLabel\n";
		if ($this->resetConsole[$mode]) {
			$script .= "goto start ||\n";
		}
		return $script;
	}

	public function addFormFields(&$array)
	{
		$array[$this->arch . '_selected'] = 'selected';
		foreach ([self::BIOS, self::EFI] as $mode) {
			$array['entries'][] = [
				'is' . $mode => true,
				'mode' => $mode,
				'executable' => $this->executable[$mode],
				'initRd' => implode(',', $this->initRd[$mode]),
				'commandLine' => $this->commandLine[$mode],
				'replace_checked' => $this->replace[$mode] ? 'checked' : '',
				'autoUnload_checked' => $this->autoUnload[$mode] ? 'checked' : '',
				'resetConsole_checked' => $this->resetConsole[$mode] ? 'checked' : '',
			];
		}
		$array['exec_checked'] = 'checked';
	}

	public function toArray()
	{
		return [
			'executable' => $this->executable,
			'initRd' => $this->initRd,
			'commandLine' => $this->commandLine,
			'replace' => $this->replace,
			'autoUnload' => $this->autoUnload,
			'resetConsole' => $this->resetConsole,
			'arch' => $this->arch,
		];
	}
}

class CustomBootEntry extends BootEntry
{
	protected $script;

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
		parent::__construct(false);
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

