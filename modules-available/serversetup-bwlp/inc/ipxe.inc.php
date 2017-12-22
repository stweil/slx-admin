<?php


class Ipxe
{

	public static function parsePxeLinux($input)
	{
		/*
		LABEL openslx-debug
			MENU LABEL ^bwLehrpool-Umgebung starten (nosplash, debug)
			KERNEL http://IPADDR/boot/default/kernel
			INITRD http://IPADDR/boot/default/initramfs-stage31
			APPEND slxbase=boot/default
			IPAPPEND 3
		 */
		$propMap = [
			'menu label' => ['string', 'title'],
			'menu default' => ['true', 'isDefault'],
			'menu hide' => ['true', 'isHidden'],
			'kernel' => ['string', 'kernel'],
			'initrd' => ['string', 'initrd'],
			'append' => ['string', 'append'],
			'ipappend' => ['int', 'ipAppend'],
			'localboot' => ['int', 'localBoot'],
		];
		$sections = array();
		$lines = preg_split('/[\r\n]+/', $input);
		$section = null;
		foreach ($lines as $line) {
			if (!preg_match('/^\s*([^m\s]+|menu\s+\S+)\s+(.*?)\s*$/i', $line, $out))
				continue;
			$key = strtolower($out[1]);
			$key = preg_replace('/\s+/', ' ', $key);
			if ($key === 'label') {
				if ($section !== null) {
					$sections[] = $section;
				}
				$section = new PxeSection($out[2]);
			} elseif ($section === null) {
				continue;
			} elseif (isset($propMap[$key])) {
				$opt = $propMap[$key];
				if ($opt[0] === 'true') {
					$val = true;
				} else {
					$val = $out[2];
					settype($val, $opt[0]);
				}
				$section->{$opt[1]} = $val;
			}
		}
		if ($section !== null) {
			$sections[] = $section;
		}
		return $sections;
	}

}

class PxeMenu
{
	public $title;
	public $timeoutMs;
	public $totalTimeoutMs;
	public $timeoutLabel;
}

class PxeSection
{
	/**
	 * @var string label used internally in PXEMENU definition to address this entry
	 */
	public $label;
	/**
	 * @var string MENU LABEL of PXEMENU - title of entry displayed to the user
	 */
	public $title;
	public $kernel;
	public $initrd;
	public $append;
	/**
	 * @var int IPAPPEND from PXEMENU. Bitmask of valid options 1 and 2.
	 */
	public $ipAppend;
	public $passwd;
	/**
	 * @var bool whether this section is marked as default (booted after timeout)
	 */
	public $isDefault = false;
	/**
	 * @var bool Menu entry is not visible (can only be triggered by timeout)
	 */
	public $isHidden = false;
	/**
	 * @var int Value of the LOCALBOOT field
	 */
	public $localBoot;

	public function __construct($label) { $this->label = $label; }
}

