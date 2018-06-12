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

	public abstract function toScript($failLabel);

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

	public static function newStandardBootEntry($initData)
	{
		if (empty($initData['executable']))
			return null;
		return new StandardBootEntry($initData);
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

}

class StandardBootEntry extends BootEntry
{
	protected $executable;
	protected $initRd;
	protected $commandLine;
	protected $replace;
	protected $autoUnload;
	protected $resetConsole;

	public function __construct($data = false)
	{
		if ($data instanceof PxeSection) {
			$this->executable = $data->kernel;
			$this->initRd = $data->initrd;
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
			parent::__construct($data);
		}
	}

	public function toScript($failLabel)
	{
		$script = '';
		if ($this->resetConsole) {
			$script .= "console ||\n";
		}
		if (!empty($this->initRd)) {
			$script .= "imgfree ||\n";
			if (!is_array($this->initRd)) {
				$script .= "initrd {$this->initRd} || goto $failLabel\n";
			} else {
				foreach ($this->initRd as $initrd) {
					$script .= "initrd $initrd || goto $failLabel\n";
				}
			}
		}
		$script .= "boot ";
		if ($this->autoUnload) {
			$script .= "-a ";
		}
		if ($this->replace) {
			$script .= "-r ";
		}
		$script .= "{$this->executable}";
		if (!empty($this->commandLine)) {
			$script .= " {$this->commandLine}";
		}
		$script .= " || goto $failLabel\n";
		if ($this->resetConsole) {
			$script .= "goto start ||\n";
		}
		return $script;
	}

	public function addFormFields(&$array)
	{
		$array['entry'] = [
			'executable' => $this->executable,
			'initRd' => $this->initRd,
			'commandLine' => $this->commandLine,
			'replace_checked' => $this->replace ? 'checked' : '',
			'autoUnload_checked' => $this->autoUnload ? 'checked' : '',
			'resetConsole_checked' => $this->resetConsole ? 'checked' : '',
		];
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
		];
	}
}

class CustomBootEntry extends BootEntry
{
	protected $script;

	public function toScript($failLabel)
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
