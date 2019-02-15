<?php


class Ipxe
{

	/**
	 * Takes a (partial) pxelinux menu and parses it into
	 * a PxeMenu object.
	 * @param string $input The pxelinux menu to parse
	 * @return PxeMenu the parsed menu
	 */
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
		$menu = new PxeMenu;
		$sectionPropMap = [
			'menu label' => ['string', 'title'],
			'menu default' => ['true', 'isDefault'],
			'menu hide' => ['true', 'isHidden'],
			'menu disabled' => ['true', 'isDisabled'],
			'menu indent' => ['int', 'indent'],
			'kernel' => ['string', 'kernel'],
			'initrd' => ['string', 'initrd'],
			'append' => ['string', 'append'],
			'ipappend' => ['int', 'ipAppend'],
			'localboot' => ['int', 'localBoot'],
		];
		$globalPropMap = [
			'timeout' => ['int', 'timeoutMs', 100],
			'totaltimeout' => ['int', 'totalTimeoutMs', 100],
			'menu title' => ['string', 'title'],
			'menu clear' => ['true', 'menuClear'],
			'menu immediate' => ['true', 'immediateHotkeys'],
			'ontimeout' => ['string', 'timeoutLabel'],
		];
		$lines = preg_split('/[\r\n]+/', $input);
		$section = null;
		$count = count($lines);
		for ($li = 0; $li < $count; ++$li) {
			$line =& $lines[$li];
			if (!preg_match('/^\s*([^m]\S*|menu\s+\S+)(\s+.*?|)\s*$/i', $line, $out))
				continue;
			$key = trim($out[1]);
			$key = strtolower($key);
			$key = preg_replace('/\s+/', ' ', $key);
			if ($key === 'label') {
				if ($section !== null) {
					$menu->sections[] = $section;
				}
				$section = new PxeSection($out[2]);
			} elseif ($key === 'menu separator') {
				if ($section !== null) {
					$menu->sections[] = $section;
					$section = null;
				}
				$menu->sections[] = new PxeSection(null);
			} elseif (self::handleKeyword($key, $out[2], $globalPropMap, $menu)) {
				continue;
			} elseif ($section === null) {
				continue;
			} elseif ($key === 'text' && strtolower($out[2]) === 'help') {
				$text = '';
				while (++$li < $count) {
					$line =& $lines[$li];
					if (strtolower(trim($line)) === 'endtext')
						break;
					$text .= $line . "\n";
				}
				$section->helpText = $text;
			} elseif (self::handleKeyword($key, $out[2], $sectionPropMap, $section)) {
				continue;
			}
		}
		if ($section !== null) {
			$menu->sections[] = $section;
		}
		return $menu;
	}

	/**
	 * Check if keyword is valid and if so, add its interpreted value
	 * to the given object. The map to look up the keyword has to be passed
	 * as well as the object to set the value in. Map and object should
	 * obviously match.
	 * @param string $key keyword of parsed line
	 * @param string $val raw value of currently parsed line (empty if not present)
	 * @param array $map Map in which $key is looked up as key
	 * @param PxeMenu|PxeSection The object to set the parsed and sanitized value in
	 * @return bool true if the value was found in the map (and set in the object), false otherwise
	 */
	private static function handleKeyword($key, $val, $map, $object)
	{
		if (!isset($map[$key]))
			return false;
		$opt = $map[$key];
		// opt[0] is the type the value should be cast to; special case "true" means
		// this is a bool option that will be set as soon as the keyword is present,
		// as it doesn't have any parameters
		if ($opt[0] === 'true') {
			$val = true;
		} else {
			settype($val, $opt[0]);
		}
		// If opt[2] is present it's a multiplier for the value
		if (isset($opt[2])) {
			$val *= $opt[2];
		}
		$object->{$opt[1]} = $val;
		return true;
	}

}

/**
 * Class representing a parsed pxelinux menu. Members
 * will be set to their annotated type if present or
 * be null otherwise, except for present-only boolean
 * options, which will default to false.
 */
class PxeMenu
{
	/**
	 * @var string menu title, shown at the top of the menu
	 */
	public $title;
	/**
	 * @var int initial timeout after which $timeoutLabel would be executed
	 */
	public $timeoutMs;
	/**
	 * @var int if the user canceled the timeout by pressing a key, this timeout would still eventually
	 *          trigger and launch the $timeoutLabel section
	 */
	public $totalTimeoutMs;
	/**
	 * @var string label of section which will execute if the timeout expires
	 */
	public $timeoutLabel;
	/**
	 * @var bool hide menu and just show background after triggering an entry
	 */
	public $menuClear = false;
	/**
	 * @var bool boot the associated entry directly if its corresponding hotkey is presed instead of just highlighting
	 */
	public $immediateHotkeys = false;
	/**
	 * @var PxeSection[] list of sections the menu contains
	 */
	public $sections = [];
}

/**
 * Class representing a parsed pxelinux menu entry. Members
 * will be set to their annotated type if present or
 * be null otherwise, except for present-only boolean
 * options, which will default to false.
 */
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
	/**
	 * @var int Number of spaces to prefix the title with
	 */
	public $indent;
	/**
	 * @var string help text to display when the entry is highlighted
	 */
	public $helpText;
	/**
	 * @var string Kernel to load
	 */
	public $kernel;
	/**
	 * @var string initrd to load for the kernel
	 */
	public $initrd;
	/**
	 * @var string command line options to pass to the kernel
	 */
	public $append;
	/**
	 * @var int IPAPPEND from PXEMENU. Bitmask of valid options 1 and 2.
	 */
	public $ipAppend;
	/**
	 * @var string Password protecting the entry. This is most likely in crypted form.
	 */
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
	 * @var bool Disable this entry, making it unselectable
	 */
	public $isDisabled = false;
	/**
	 * @var int Value of the LOCALBOOT field
	 */
	public $localBoot;

	public function __construct($label) { $this->label = $label; }
}

