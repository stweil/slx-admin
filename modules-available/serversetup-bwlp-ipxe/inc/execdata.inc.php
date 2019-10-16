<?php

class ExecData
{

	/**
	 * @var string The binary to launch
	 */
	public $executable = '';

	/**
	 * @var string[] List of additional images to load (initrds)
	 */
	public $initRd = [];

	/**
	 * @var string Command line to pass to executable
	 */
	public $commandLine = '';

	/**
	 * @var bool Call imgfree before loading and executing this entry
	 */
	public $imageFree = false;

	/**
	 * @var bool Whether to completely replace the currently running iPXE stack
	 */
	public $replace = false;

	/**
	 * @var bool Whether to automatically unload the binary after execution
	 */
	public $autoUnload = false;

	/**
	 * @var bool Whether to reset the console before execution
	 */
	public $resetConsole = false;

	/**
	 * @var array DHCP options to override Maps OPTIONNUM -> Value
	 */
	public $dhcpOptions = [];

	/**
	 * Supported Options
	 */
	const DHCP_OPTIONS = [
		17 => [
			'name' => 'Root Path',
			'type' => 'string',
		],
		43 => [
			'name' => 'Vendor Specific',
			'type' => 'string',
		],
		66 => [
			'name' => 'Next Server',
			'type' => 'string',
		],
		67 => [
			'name' => 'Boot File',
			'type' => 'string',
		],
		209 => [
			'name' => 'Configuration File',
			'type' => 'string',
		],
		210 => [
			'name' => 'Path Prefix',
			'type' => 'string',
		],
	];

	public function sanitize()
	{
		settype($this->executable, 'string');
		settype($this->initRd, 'array');
		foreach ($this->initRd as &$entry) {
			settype($entry, 'string');
		}
		settype($this->commandLine, 'string');
		settype($this->imageFree, 'bool');
		settype($this->replace, 'bool');
		settype($this->autoUnload, 'bool');
		settype($this->resetConsole, 'bool');
		settype($this->dhcpOptions, 'array');
		foreach (array_keys($this->dhcpOptions) as $key) {
			$val =& $this->dhcpOptions[$key];
			if (!empty($val['override'])) {
				unset($val['override']);
				$val['opt'] = $key;
				if (isset($val['hex']) && isset($val['value'])) {
					$val['value'] = preg_replace('/[^0-9a-f]/i', '', $val['value']);
					$val['value'] = substr($val['value'], 0, floor(strlen($val['value']) / 2) * 2);
					$val['value'] = strtolower($val['value']);
				}
			}
			if (!isset($val['opt']) || !is_numeric($val['opt']) || $val['opt'] <= 0 || $val['opt'] >= 255) {
				unset($this->dhcpOptions[$key]);
				continue;
			}
			if (!array_key_exists($val['opt'], self::DHCP_OPTIONS))
				continue; // Not known...
			settype($val['value'], self::DHCP_OPTIONS[$val['opt']]['type']);
		}
		$this->dhcpOptions = array_values($this->dhcpOptions);
	}

	public function toArray()
	{
		$this->sanitize();
		return [
			'executable' => $this->executable,
			'initRd' => $this->initRd,
			'commandLine' => $this->commandLine,
			'imageFree' => $this->imageFree,
			'replace' => $this->replace,
			'autoUnload' => $this->autoUnload,
			'resetConsole' => $this->resetConsole,
			'dhcpOptions' => $this->dhcpOptions,
		];
	}

	public function toFormFields($arch)
	{
		$this->sanitize();
		$opts = [];
		foreach (self::DHCP_OPTIONS as $opt => $val) {
			$opts[$opt] = [
				'opt' => $opt,
				'name' => $val['name'],
			];
		}
		foreach ($this->dhcpOptions as $val) {
			if (!isset($opts[$val['opt']])) {
				$opts[$val['opt']] = [];
			}
			$opts[$val['opt']] += [
				'opt' => $val['opt'],
				'value' => $val['value'],
				'override_checked' => 'checked',
				'hex_checked' => empty($val['hex']) ? '' : 'checked',
			];
		}
		ksort($opts);
		return [
			'is' . $arch => true,
			'mode' => $arch,
			'executable' => $this->executable,
			'initRd' => implode(',', $this->initRd),
			'commandLine' => $this->commandLine,
			'imageFree_checked' => $this->imageFree ? 'checked' : '',
			'replace_checked' => $this->replace ? 'checked' : '',
			'autoUnload_checked' => $this->autoUnload ? 'checked' : '',
			'resetConsole_checked' => $this->resetConsole ? 'checked' : '',
			'opts' => array_values($opts),
		];
	}

}