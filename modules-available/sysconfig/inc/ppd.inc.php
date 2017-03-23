<?php

/**
 * Class Ppd for parsing PPD files. This class was developed around
 * the PPD spec v4.3. All comments in this class referring to sections of
 * the spec will refer to this version, if not stated otherwise.
 */
class Ppd
{

	const FILE = 0;
	const STRING = 1;

	const INCLUDE_UNKNOWN_MAIN_KEYWORDS = 1;

	/**
	 * regexp matching valid PPD keywords ASCII 33-126, excluding colon and slash.
	 * See section 3.2/3.3
	 */
	const EXP_KEYWORD = '[\x21-\x2e\x30-\x39\x3b-\x7e]+';

	const PPD_INT = '\-?\d+';

	const PPD_REAL = '\-?\d+(\.\d+)?';

	const PPD_BOOL = 'True|False';

	const PPD_RECTANGLE = '\-?\d+(\.\d+)?\s+\-?\d+(\.\d+)?\s+\-?\d+(\.\d+)?\s+\-?\d+(\.\d+)?';

	const PPD_DIMENSION = '\-?\d+(\.\d+)?\s+\-?\d+(\.\d+)?';

	private $REQUIRED_KEYWORDS = array(
		'PPD-Adobe' => '4\.[0123]',
		'FileVersion' => '.*',
		'FormatVersion' => '.*',
		'LanguageEncoding' => '.*',
		'LanguageVersion' => '.*',
		'Manufacturer' => '.*',
		'ModelName' => '.*',
		'NickName' => '.*',
		'PCFileName' => '.*',
		'Product' => '\(.*\)',
		'PSVersion' => '\(.*\)\s+\d+',
		'ShortNickName' => '.*'
	);

	private $LANGUAGE_MAPPINGS = array(
		'English' => 'ISOLatin1',
		'Chinese' => 'None',
		'Danish' => 'ISOLatin1',
		'Dutch' => 'ISOLatin1',
		'Finnish' => 'ISOLatin1',
		'French' => 'ISOLatin1',
		'German' => 'ISOLatin1',
		'Italian' => 'ISOLatin1',
		'Japanese' => 'JIS83-RKSJ',
		'Norwegian' => 'ISOLatin1',
		'Portuguese' => 'ISOLatin1',
		'Russian' => 'None',
		'Spanish' => 'ISOLatin1',
		'Swedish' => 'ISOLatin1',
		'Turkish' => 'None'
	);

	private $ENCODINGS = array(
		'ISOLatin1' => 'ISO-8859-1',
		'ISOLatin2' => 'ISO-8859-2',
		'ISOLatin5' => 'ISO-8859-5',
		'JIS83-RKSJ' => 'SJIS',
		'MacStandard' => 'MACINTOSH',
		'WindowsANSI' => 'Windows-1252'
	);

	/**
	 * @var string name of source charset (PPD)
	 */
	private $sourceEncoding;
	/**
	 * @var string 'mb' or 'iconv'
	 */
	private $encoder;


	/**
	 * List of known main keywords.
	 * Key is the keyword, value is either a regex for the value, if we don't care about the option format,
	 * or an array with [0] = regex for option keyword, and [1] = regex for value
	 *
	 * @var array
	 */
	private $KNOWN_KEYWORDS = array(
		/*
		 * Basic Device Capabilities, section 5.5
		 */
		'ColorDevice' => self::PPD_BOOL,
		'DefaultColorSpace' => 'CMY|CMYK|RGB|Gray',
		'Extensions' => '(DPS|CMYK|Composite|FileSystem)(\s+(DPS|CMYK|Composite|FileSystem))*',
		'FaxSupport' => 'Base',
		'FileSystem' => self::PPD_BOOL,
		'LanguageLevel' => self::PPD_INT,
		'Throughput' => '\d+(\.\d+)?',
		'TTRasterizer' => 'None|Accept68K|Type42|TrueImage',
		'1284Modes' => 'Compat|Nibble|Byte|ECP|EPP',
		'1284DeviceID' => '.*',
		/*
		 * System Management, section 5.6
		 */
		'PatchFile' => '.*',
		'JobPatchFile' => array(self::PPD_INT, '.*'),
		'FreeVM' => self::PPD_INT,
		'VMOption' => self::PPD_INT,
		'InstalledMemory' => '.*',
		'DefaultInstalledMemory' => '.*',
		'Reset' => '.*',
		'Password' => '.*',
		'ExitJamRecovery' => array(self::PPD_BOOL, '.*'),
		'DefaultExitJamRecovery' => 'True|False|Unknown',
		'ExitServer' => '.*',
		'SuggestedJobTimeout' => self::PPD_INT,
		'SuggestedManualFeedTimeout' => self::PPD_INT, // XXX: Typo in spec? It says "SuggestedManualfFeedTimeout"
		'SuggestedWaitTimeout' => self::PPD_INT,
		'PrintPSErrors' => self::PPD_BOOL,
		'DeviceAdjustMatrix' => '\[[\d\s]+\]',
		/*
		 * Emulations and Protocols, section 5.7
		 */
		'Protocols' => '(BCP|PJL|TBCP)(\s+(BCP|PJL|TBCP))*',
		'Emulators' => '\S+(\s+\S+)*', // TODO This requires matching *(Start|Stop)Emulator_(\S+): "code" main keywords
		/*
		 * JCL, section 5.8
		 */
		'JCLBegin' => '.*',
		'JCLToPSInterpreter' => '.*',
		'JCLEnd' => '.*',
		// TODO: The above three need to be either completely absent, or all three must be defined
		/*
		 * Resolution and Appearence Control, section 5.9
		 */
		/*
		 * Gray Levels and Halftoning, section 5.10
		 */
		'AccurateScreensSupport' => self::PPD_BOOL,
		'ContoneOnly' => self::PPD_BOOL,
		'DefaultHalftoneType' => self::PPD_INT,
		'ScreenAngle' => self::PPD_REAL,
		'ScreenFreq' => self::PPD_REAL,
		'ResScreenFreq' => self::PPD_REAL,
		'ResScreenAngle' => self::PPD_REAL,
		'DefaultScreenProc' => 'Dot|Line|Ellipse|Cross|Mezzo|DiamondDot',
		'ScreenProc' => array('Dot|Line|Ellipse|Cross|Mezzo|DiamondDot', '.*'),
		'DefaultTransfer' => 'Null|Factory', // XXX: Spec seems to allow only these two values as default, but why
		'Transfer' => array('Null|Factory|Normalized|Red|Green|Blue', '.*'),
		/*
		 * Color Adjustment, section 5.11
		 */
		'BlackSubstitution' => array(self::PPD_BOOL, '.*'),
		'DefaultBlackSubstitution' => 'True|False|Unknown',
		'ColorModel' => array('CMY|CMYK|RGB|Gray', '.*'),
		'DefaultColorModel' => 'CMY|CMYK|RGB|Gray|Unknown',
		'RenderingIntent' => '.*',
		'PageDeviceName' => '.*',
		'HalftoneName' => '.*',
		/*
		 * Media Selection, section 5.14
		 */
		'ManualFeed' => array(self::PPD_BOOL, '.*'),
		'DefaultManualFeed' => 'True|False|Unknown',
		/*
		 * Information About Media Sizes, section 5.15
		 */
		'ImageableArea' => self::PPD_RECTANGLE,
		'PaperDimension' => self::PPD_DIMENSION,
		'RequiresPageRegion' => self::PPD_BOOL,
		'LandscapeOrientation' => 'Plus90|Minus90|Any',
		/*
		 * Custom Page Size, section 5.16
		 */
		'CustomPageSize' => array('True', '.*'),
		'ParamCustomPageSize' => array('Width|Height|WidthOffset|HeightOffset|Orientation', '\d+\s+(int|real|points)\s+' . self::PPD_REAL . '\s+' . self::PPD_REAL),
		'MaxMediaWidth' => self::PPD_REAL,
		'MaxMediaHeight' => self::PPD_REAL,
		'CenterRegistered' => self::PPD_BOOL,
		'LeadingEdge' => array('Short|Long|PreferLong|Forced|Unknown', '\s*'),
		'DefaultLeadingEdge' => 'Short|Long|PreferLong|Forced|Unknown',
		'HWMargins' => self::PPD_RECTANGLE,
		'UseHWMargins' => array(self::PPD_BOOL, '\s*'),
		'DefaultUseHWMargins' => self::PPD_BOOL,
		/*
		 * Media Handling Features, section 5.17
		 */
		'OutputOrder' => array('Normal|Reverse', '.*'),
		'DefaultOutputOrder' => 'Normal|Reverse|Unknown',
		'PageStackOrder' => 'Normal|Reverse',
		'TraySwitch' => array(self::PPD_BOOL, '.*'),
		'DefaultTraySwitch' => 'True|False|Unknown',
		'Duplex' => array('DuplexTumble|DuplexNoTumble|SimplexTumble|None|False|SimplexNoTumble', '.*'),
		'DefaultDuplex' => 'DuplexTumble|DuplexNoTumble|SimplexTumble|None|False|SimplexNoTumble',
		/*
		 * Finishing Features, section 5.18ff
		 * TODO
		 */

		/*
		 * Font Related Keywords, section 5.20
		 */
		'FDirSize' => self::PPD_INT,
		'FCacheSize' => self::PPD_INT,
		// TODO: 'Font' = >
		/*
		 * Printer Messages, section 5.21
		 */
		'PrinterError' => '.*',
		'Status' => '.*',
		'Source' => '.*',
		'Message' => '.*',
		/*
		 * 5.22
		 */
		'InkName' => '.+',
	);

	/**
	 * Appendix A.1: UI Keywords.
	 * SORTED, so we can do a binary search.
	 *
	 * @var array list of UI keywords.
	 */
	private $UI_KEYWORDS = array('AdvanceMedia', 'BindColor', 'BindEdge', 'BindType', 'BindWhen', 'BitsPerPixel',
		'BlackSubstitution', 'Booklet', 'Collate', 'ColorModel', 'CutMedia', 'Duplex', 'ExitJamRecovery', 'FoldType',
		'FoldWhen', 'InputSlot', 'InstalledMemory', 'Jog', 'ManualFeed', 'MediaColor', 'MediaType', 'MediaWeight',
		'MirrorPrint', 'NegativePrint', 'OutputBin', 'OutputMode', 'OutputOrder', 'PageSize', 'PageRegion', 'Separations',
		'Signature', 'Slipsheet', 'Smoothing', 'Sorter', 'StapleLocation', 'StapleOrientation', 'StapleWhen', 'StapleX',
		'StapleY', 'TraySwitch'
	);

	/**
	 * Appendix A.2: Repeated Keywords.
	 * SORTED, so we can do a binary search.
	 *
	 * @var array list of repeated keywords
	 */
	private $REPEATED_KEYWORDS = array('HalftoneName', 'Include', 'InkName', 'Message', 'NonUIConstraints', 'NonUIOrderDependency',
		'OrderDependency', 'PageDeviceName', 'PrinterError', 'Product', 'PSVersion', 'QueryOrderDependency',
		'RenderingIntent', 'Source', 'Status', 'UIConstraints'
	);

	private $data;
	private $dataLen;

	private $error;
	private $warnings;

	private $knownKeywordMalformed;

	/**
	 * @var PpdSettingInternal[] known options of this ppd
	 */
	private $settings;

	private $requiredKeywords;

	function __construct($ppd, $type = self::FILE, $flags = 0)
	{
		if (empty($ppd)) {
			$this->error = 'Empty $ppd';
			return;
		}
		if ($type == self::FILE) {
			$this->data = file_get_contents($ppd);
			if ($this->data === false) {
				$this->error = 'Could not open ' . substr($ppd, 1);
				return;
			}
		} elseif ($type == self::STRING) {
			$this->data = $ppd;
		} else {
			$this->error = 'Invalid $type passed';
			return;
		}
		$this->parse();
	}

	private function parse()
	{
		$r = substr_count($this->data, "\r");
		$n = substr_count($this->data, "\n");
		if ($r > 10 && abs($r - $n) < $r / 10) {
			if (substr($this->data, -2) !== "\r\n") {
				$this->data .= "\r\n";
			}
		} elseif ($r > $n) {
			if (substr($this->data, -1) !== "\r") {
				$this->data .= "\r";
			}
		} else {
			if (substr($this->data, -1) !== "\n") {
				$this->data .= "\n";
			}
		}

		$this->dataLen = strlen($this->data);
		$this->encoder = false;
		$this->sourceEncoding = false;
		$this->error = false;
		$this->warnings = array();
		$this->knownKeywordMalformed = false;
		$this->settings = array();
		$this->requiredKeywords = array();

		// Parse
		/* @var $rawOption \PpdOption */
		/* @var $currentBlock \PpdBlockInternal */
		$currentBlock = false;
		$inRawBlock = false; // True if in a multi-line InvocationValue or QuotedValue (3.6: Parsing Summary for Values)
		$wantsEnd = false;
		// For now we ignore values mostly while parsing. The spec says that InvocationValues must only contain printable
		// ASCII characters, so we should issue a warning if we encounter invalid chars in them.
		$lStart = -1;
		$lEnd = -1;
		$no = 0;
		while ($lStart < $this->dataLen && $lEnd !== false) {
			unset($mainKeyword, $optionKeyword, $optionTranslation, $option, $value, $valueTranslation);
			if ($no !== 0 && $this->data{$lEnd} === "\r" && $this->data{$lEnd + 1} === "\n") {
				$lEnd++;
			}
			if ($no === 1) {
				// The first line must be *PPD-Adobe, check if that was the case
				if (!isset($this->requiredKeywords['PPD-Adobe'])) {
					$this->error = 'First line does not contain *PPD-Adobe main keyword';
					return;
				}
			}
			$lStart = $lEnd + 1;
			$lEnd = $this->nextLineEnd($lStart);
			$no++;
			// Validate
			$len = $lEnd - $lStart;
			$line = substr($this->data, $lStart, $len);
			if ($len === 0) {
				continue;
			}
			if ($len > 255) {
				$this->warn($no, 'Exceeds length of 255');
			}
			if (!$inRawBlock && preg_match_all('/[^\x09\x0A\x0D\x20-\xFF]/', $line, $out)) {
				$chars = $this->escapeBinaryArray($out[0]);
				$this->warn($no, 'Contains invalid character(s) ' . $chars);
			}
			// Handle
			// 1) We're inside an InvocationValue or QuotedValue, need a single " at line end to close it
			if ($inRawBlock) {
				if (substr($line, -1) === '"') {
					$inRawBlock = false;
					$wantsEnd = true;
					if (isset($rawOption)) {
						$rawOption->lineLen = $lEnd - $rawOption->lineOffset;
					}
				}
				continue;
			}
			// 2) InvocationValue or QuotedValue just closed, an '*End' has to follow
			if ($wantsEnd) {
				$wantsEnd = false;
				if ($line !== '*End' && $line !== '*SymbolEnd') { // XXX: We don't properly check which one we expected...
					$this->warn($no, 'End of multi-line InvocationValue or QuotedValue not followed by "*(Symbol)End"');
					unset($rawOption);
				} else {
					if (isset($rawOption)) {
						$rawOption->lineLen = $lEnd - $rawOption->lineOffset;
					}
					unset($rawOption);
					continue;
				}
			}
			// 3) Handle "key [option]: value"
			if ($line{0} === '*') {
				if ($line{1} === '%') {
					// Skip comment
					continue;
				}
				$parts = preg_split('/\s*:\s*/', $line, 2); // TODO: UIConstrains
				if (count($parts) !== 2) {
					$this->warn($no, 'No colon found; not in "key [option]: value" format, ignoring line');
					continue;
				}
				// Now $parts[0] is "key[ option]" and $parts[1] is "value"
				// 3a) Determine key and option
				if (1 > preg_match(',^\*(' . self::EXP_KEYWORD . ')($|\s+([^/]+)(/.*)?$),', $parts[0], $out)) {
					$this->warn($no, 'Not a valid Main Keyword, "' . $parts[0] . '", line ignored');
					continue;
				}
				$mainKeyword = $out[1];
				$optionKeyword = isset($out[3]) ? $out[3] : false;
				$optionTranslation = isset($out[4]) ? $this->unhexTranslation($no, substr($out[4], 1)) : $optionKeyword; // If no translation given, fallback to option
				// 3b) Handle value
				$value = $parts[1];
				if ($value{0} === '"') {
					// Start of InvocationValue or QuotedValue
					if (preg_match(',^"([^"]*)"(/.*)?$,', $value, $vMatch)) {
						// Single line
						$value = $vMatch[1];
						$valueTranslation = isset($vMatch[2]) ? $this->unhexTranslation($no, substr($vMatch[2], 1)) : $value;
					} else {
						// Multi-line
						$inRawBlock = true;
						$value = '<TODO: Multiline>'; // TODO: Handle multi-line values properly
						$valueTranslation = '';
					}
				} elseif (preg_match(',^\^' . self::EXP_KEYWORD . '$,', $value)) {
					// SymbolValue TODO: Can be followed by translation?
					$valueTranslation = $value;
				} elseif (preg_match(',^([^"][^/]*)(/.*)?$,', $value, $vMatch)) {
					// StringValue
					$value = $vMatch[1];
					$valueTranslation = isset($vMatch[2]) ? $this->unhexTranslation($no, substr($vMatch[2], 1)) : $value;
				}
				// Key-value-pair parsed, now the fun part
				// Special cases for openening closing certain groups
				if ($mainKeyword === 'OpenGroup') {
					if ($currentBlock !== false) {
						$this->error = 'Line ' . $no . ': OpenGroup while other block (type=' . $currentBlock->type
							. ', id=' . $currentBlock->id . ') was not closed yet';
						return;
					}
					// TODO: Check unique
					$nb = new PpdBlockInternal($value, $valueTranslation, 'Group', $currentBlock, $lStart);
					if ($currentBlock !== false) {
						$currentBlock->childBlocks[] = $nb;
					}
					$currentBlock = $nb;
					continue;
				} elseif ($mainKeyword === 'OpenSubGroup') {
					if ($currentBlock === false || $currentBlock->type !== 'Group') {
						$this->error = 'Line ' . $no . ': OpenSubGroup with no preceeding OpenGroup';
						return;
					}
					// TODO: Check unique
					$nb = new PpdBlockInternal($value, $valueTranslation, 'SubGroup', $currentBlock, $lStart);
					if ($currentBlock !== false) {
						$currentBlock->childBlocks[] = $nb;
					}
					$currentBlock = $nb;
					continue;
				} elseif ($mainKeyword === 'OpenUI' || $mainKeyword === 'JCLOpenUI') {
					$type = $mainKeyword;
					if (substr($type, 0, 3) === 'JCL') {
						$type = 'JCL' . substr($type, 7);
					} else {
						$type = substr($type, 4);
					}
					if ($currentBlock !== false && $currentBlock->isUi()) {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . ' while previous ' . $type . ' "'
							. $currentBlock->id . '" was not closed yet';
						return;
					}
					if ($optionKeyword === false) {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . ' with no option keyword';
						return;
					}
					if ($optionKeyword{0} !== '*') {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . " with option keyword that doesn't start with asterisk (*).";
						return;
					}
					// TODO: Check unique
					$nb = new PpdBlockInternal($optionKeyword, $optionTranslation, $type, $currentBlock, $lStart);
					$nb->value = $value;
					if ($currentBlock !== false) {
						$currentBlock->childBlocks[] = $nb;
					}
					$currentBlock = $nb;
					$this->getOption(substr($optionKeyword, 1), $currentBlock); // ->type = $value; unused?
					continue;
				} elseif ($mainKeyword === 'CloseGroup' || $mainKeyword === 'CloseSubGroup' || $mainKeyword === 'CloseUI'
					|| $mainKeyword === 'JCLCloseUI'
				) {
					$type = $mainKeyword;
					if (substr($type, 0, 3) === 'JCL') {
						$type = 'JCL' . substr($type, 8);
					} else {
						$type = substr($type, 5);
					}
					if ($currentBlock === false) {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . ' with no Open' . $type;
						return;
					}
					if ($currentBlock->type !== $type) {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . ' after Open' . $currentBlock->type;
						return;
					}
					if ($currentBlock->id !== $value) {
						$this->error = 'Line ' . $no . ': ' . $mainKeyword . ' for "' . $value . '" while currently open '
							. $type . ' is "' . $currentBlock->id . '"';
						return;
					}
					$currentBlock->end = $lEnd;
					$currentBlock = $currentBlock->parent;
					continue;
				} elseif ($mainKeyword === 'OrderDependency') {
					if ($currentBlock === false || $currentBlock->isUi()) {
						$this->warn($no, 'OrderDependency outside OpenUI/CloseUI block');
					}
					continue;
				} elseif ($mainKeyword === 'Include') {
					$this->warn($no, 'PPD tries to include a file (' . $value
						. '), which is not supported. Will continue, but errors might occur');
					continue;
				} elseif ($mainKeyword === 'UIConstraints' || $mainKeyword === 'NonUIConstraints'
					|| $mainKeyword === 'SymbolLength' || $mainKeyword === 'SymbolValue'
					|| $mainKeyword === 'SymbolEnd' || $mainKeyword === 'NonUIOrderDependency'
				) {
					continue;
				}
				// General information keywords, which are required
				if (isset($this->REQUIRED_KEYWORDS[$mainKeyword])) {
					if (isset($this->requiredKeywords[$mainKeyword])) {
						if ($this->binary_in_array($mainKeyword, $this->REPEATED_KEYWORDS)) {
							$this->requiredKeywords[$mainKeyword][] = $value;
						} else {
							$this->warn($no, 'Required keyword ' . $mainKeyword . ' declared twice, ignoring');
							continue;
						}
					}
					$this->requiredKeywords[$mainKeyword] = array($value);
					if (($err = $this->validateLine($this->REQUIRED_KEYWORDS[$mainKeyword], $optionKeyword, $value)) !== true) {
						$this->warn($no, 'Required main keyword ' . $mainKeyword . ': ' . $err);
						$this->knownKeywordMalformed = true;
					}
					continue;
				}
				// Other well known keywords
				if (isset($this->KNOWN_KEYWORDS[$mainKeyword])) {
					if (($err = $this->validateLine($this->KNOWN_KEYWORDS[$mainKeyword], $optionKeyword, $value)) !== true) {
						$this->warn($no, 'Known main keyword ' . $mainKeyword . ': ' . $err);
						$this->knownKeywordMalformed = true;
					}
				}
				if (substr($mainKeyword, 0, 7) === 'Default') {
					// Default keyword
					$option = $this->getOption(substr($mainKeyword, 7), $currentBlock);
					$option->default = new PpdOption($lStart, $len, $value, $valueTranslation);
					continue;
				} elseif (substr($mainKeyword, 0, 17) === 'FoomaticRIPOption') {
					if ($optionKeyword === false) {
						$this->warn($no, "$mainKeyword with no option keyword");
					} elseif ($currentBlock !== false && isset($this->settings[$optionKeyword])) {
						$option = $this->getOption($optionKeyword, $currentBlock);
						$option->foomatic[substr($mainKeyword, 11)] = new PpdOption($lStart, $len, $value, $valueTranslation);
					} else {
						$this->warn($no, 'TODO: ' . $line);
					}
				} elseif (substr($mainKeyword, 0, 6) === 'Custom') {
					if ($optionKeyword === false) {
						$this->warn($no, "$mainKeyword with no option keyword");
					} elseif ($optionKeyword !== 'True') {
						$this->warn($no, "$mainKeyword with option keyword other than 'True'; ignored");
					} else {
						$option = $this->getOption(substr($mainKeyword, 6), $currentBlock);
						$option->custom = new PpdOption($lStart, $len, $value, $valueTranslation);
					}
				} elseif (substr($mainKeyword, 0, 11) === 'ParamCustom') {
					if ($optionKeyword === false) {
						$this->warn($no, "$mainKeyword with no option keyword");
					} elseif (substr($mainKeyword, 11) !== $optionKeyword) {
						$this->warn($no, "Don't know how to handle $mainKeyword with option keyword $optionKeyword "
							. "(expected '*ParamCustomSomething Something: <format>'");
					} else {
						$option = $this->getOption($optionKeyword, $currentBlock);
						$option->customParam = new PpdOption($lStart, $len, $value, $valueTranslation);
					}
				} elseif ($mainKeyword{0} === '?') {
					// Ignoring option query for now
				} elseif ($optionKeyword === false && !isset($this->KNOWN_KEYWORDS[$mainKeyword])) {
					// Must be a definition for an option
					$this->warn($no, "Don't know how to handle line with main keyword '$mainKeyword', no option keyword found.");
				} else {
					// Some option for some option ;)
					if ($optionKeyword === false) {
						// We know that this is a known main keyword otherwise we would have hit the previous elseif block
						$optionKeyword = $value;
						$optionTranslation = $valueTranslation;
					}
					$option = $this->getOption($mainKeyword, $currentBlock);
					$optionInstance = new PpdOption($lStart, $len, $optionKeyword, $optionTranslation);
					if ($this->binary_in_array($mainKeyword, $this->REPEATED_KEYWORDS)) {
						// This can occur multiple times, just pile them up
						$option->values[] = $optionInstance;
					} else {
						$key = "k$optionKeyword";
						if (isset($option->values[$key])) {
							$this->warn($no, "Ignoring re-definition of option '$optionKeyword' for Main Keyword '$mainKeyword'");
						} else {
							$option->values[$key] = $optionInstance;
						}
					}
					if ($inRawBlock) {
						$optionInstance->multiLine = true;
						$rawOption = $optionInstance;
					}
					unset($optionInstance);
				}
			} elseif (strlen(trim($line)) !== 0) {
				$this->warn($no, 'Invalid format; not empty and not starting with asterisk (*)');
			}
		}
		//
		if ($currentBlock !== false) {
			$this->error = 'Block ' . $currentBlock->id . ' (' . $currentBlock->type . ') was never closed.';
			return;
		}
		foreach ($this->REQUIRED_KEYWORDS as $kw => $regex) {
			if (!isset($this->requiredKeywords[$kw])) {
				$this->warn(0, "Required keyword '$kw' missing from file.'");
				$this->error = 'One or more required keywords missing';
			}
		}
		if ($this->error !== false) {
			return;
		}
		// All required keywords exist
		if (preg_match('/utf\-?8/i', $this->requiredKeywords['LanguageEncoding'][0])) {
			$this->sourceEncoding = false; // Would be a NOOP
		} elseif (isset($this->ENCODINGS[$this->requiredKeywords['LanguageEncoding'][0]])) {
			$this->sourceEncoding = $this->ENCODINGS[$this->requiredKeywords['LanguageEncoding'][0]];
		} else if (isset($this->LANGUAGE_MAPPINGS[$this->requiredKeywords['LanguageVersion'][0]])) {
			$this->sourceEncoding = $this->ENCODINGS[$this->LANGUAGE_MAPPINGS[$this->requiredKeywords['LanguageVersion'][0]]];
		} elseif (!empty($this->requiredKeywords['LanguageEncoding'][0])) {
			$this->sourceEncoding = $this->requiredKeywords['LanguageEncoding'][0];
		}
		if ($this->sourceEncoding !== false) {
			if (is_callable('iconv')) {
				$encoding = strtoupper($this->sourceEncoding);
				if (@iconv($encoding, 'UTF-8//TRANSLIT', 'test') === 'test') {
					$this->encoder = function ($string, $reverse = false) use ($encoding) {
						if ($reverse) {
							$retval = iconv('UTF-8', $encoding . '//TRANSLIT', $string);
						} else {
							$retval = iconv($encoding, 'UTF-8//TRANSLIT', $string);
						}
						if ($retval === false)
							return $string;
						return $retval;
					};
				}
			}
			if ($this->encoder === false && is_callable('mb_list_encodings')) {
				$encodings = mb_list_encodings();
				foreach ($encodings as $encoding) {
					if (strtolower($encoding) === $this->sourceEncoding) {
						$this->sourceEncoding = $encoding;
						$this->encoder = function ($string, $reverse = false) use ($encoding) {
							if ($reverse) {
								$retval = mb_convert_encoding($string, $encoding, 'UTF-8');
							} else {
								$retval = mb_convert_encoding($string, 'UTF-8', $encoding);
							}
							if ($retval === false)
								return $string;
							return $retval;
						};
						break;
					}
				}
			}
		}
		if ($this->encoder === false) {
			$this->encoder = function ($foo, $reverse = false) { return $foo; };
		}
	}

	private function nextLineEnd($start)
	{
		if ($start >= $this->dataLen)
			return false;
		while ($start < $this->dataLen) {
			$char = $this->data{$start};
			if ($char === "\r" || $char === "\n")
				return $start;
			++$start;
		}
		return $this->dataLen;
	}

	private function warn($lineNo, $message)
	{
		$line = 'Line ' . $lineNo . ': ' . $message;
		$this->warnings[] = $line;
	}

	private function escapeBinaryArray($array)
	{
		$chars = array_reduce(array_unique($array), function ($carry, $item) {
			return $carry . '\x' . dechex(ord($item));
		}, '');
	}

	private function unhexTranslation($lineNo, $translation)
	{
		if (strpos($translation, '<') === false)
			return $translation;
		return preg_replace_callback('/<[^>]*>/', function ($match) use ($lineNo) {
			if (preg_match_all('/[^a-fA-F0-9\<\>\s]/', $match[0], $out)) {
				$this->warn($lineNo, 'Invalid character(s) in hex substring: ' . $this->escapeBinaryArray($out[0]));
			}
			$string = preg_replace('/[^a-fA-F0-9]/', '', $match[0]);
			if (strlen($string) % 2 !== 0) {
				$this->warn('Odd number of hex digits in hex substring');
				$string = substr($string, 0, -1);
			}
			return pack('H*', $string);
		}, $translation);
	}

	private function hexTranslation($translation)
	{
		return preg_replace_callback('/[\x00-\x1f\x7b-\xff\:\<\>]+/', function ($match) {
			return '<' . unpack('H*', $match[0])[1] . '>';
		}, $translation);
	}

	/**
	 * Get option object
	 *
	 * @param string $name option name
	 * @param \PpdBlockInternal $block which block this option is defined in
	 * @return \PpdSettingInternal the option object
	 */
	private function getOption($name, $block = false)
	{
		if (!isset($this->settings[$name])) {
			$this->settings[$name] = new PpdSettingInternal();
			$this->settings[$name]->block = $block;
		} elseif ($block !== false) {
			if ($this->settings[$name]->block === false || $block->isChildOf($this->settings[$name]->block)) {
				$this->settings[$name]->block = $block;
			}
		}
		return $this->settings[$name];
	}

	private function binary_in_array($elem, $array)
	{
		$top = sizeof($array) - 1;
		$bot = 0;
		while ($top >= $bot) {
			$p = floor(($top + $bot) / 2);
			if ($array[$p] < $elem)
				$bot = $p + 1;
			elseif ($array[$p] > $elem)
				$top = $p - 1;
			else return true;
		}
		return false;
	}

	private function validateLine($validator, $option, $value)
	{
		if (is_array($validator)) {
			$oExp = $validator[0];
			$vExp = $validator[1];
		} else {
			$oExp = false;
			$vExp = $validator;
		}
		$regex = '/^\s*' . $vExp . '\s*$/s';
		if (!preg_match($regex, $value)) {
			return "Value '$value' does not match $regex";
		}
		if ($oExp !== false) {
			if ($option === false) {
				return 'Option keyword required, but not present';
			}
			$regex = '/^\s*' . $oExp . '\s*$/s';
			if (!preg_match($regex, $option)) {
				return "Option keyword '$option' does not match $regex";
			}
		}
		return true;
	}

	private function getEolChar()
	{
		$rn = substr_count("\r\n", $this->data);
		$r = substr_count("\r", $this->data) - $rn;
		$n = substr_count("\n", $this->data) - $rn;
		if ($rn > $r && $rn > $n) {
			$eol = "\r\n";
		} elseif ($r > $n) {
			$eol = "\r";
		} else {
			$eol = "\n";
		}
		return $eol;
	}

	/*
	 *
	 */

	public function getError()
	{
		return $this->error;
	}

	public function getWarnings()
	{
		return $this->warnings;
	}

	public function getUISettings()
	{
		$result = array();
		foreach ($this->settings as $mk => $option) {
			$isUi = ($option->block !== false && $option->block->isUi()) || isset($this->UI_KEYWORDS[$mk]);
			if ($isUi) {
				$result[] = $mk;
			}
		}
		return $result;
	}

	public function getSetting($name)
	{
		if (!isset($this->settings[$name]))
			return false;
		return new PpdSetting($this->settings[$name], isset($this->UI_KEYWORDS[$name]), $this->encoder);
	}

	public function removeSetting($name)
	{
		if (!isset($this->settings[$name]))
			return false;
		$setting = $this->settings[$name];
		$ranges = array();
		$this->mergeRanges($ranges, $setting->default);
		$this->mergeRanges($ranges, $setting->custom);
		$this->mergeRanges($ranges, $setting->customParam);
		foreach ($setting->foomatic as $obj) {
			$this->mergeRanges($ranges, $obj);
		}
		foreach ($setting->values as $obj) {
			$this->mergeRanges($ranges, $obj);
		}
		if ($setting->block !== false && $setting->block->isUi()) {
			$this->mergeRanges($ranges, $setting->block->start, $setting->block->end);
		}
		$tmp = array_map(function ($e) { return $e[0]; }, $ranges);
		array_multisort($tmp, SORT_NUMERIC, $ranges);
		$new = '';
		$last = 0;
		foreach ($ranges as $range) {
			$new .= substr($this->data, $last, $range[0] - $last);
			$last = $range[1];
			if ($this->data{$last} === "\r") {
				$last++;
			}
			if ($this->data{$last} === "\n") {
				$last++;
			}
		}
		$new .= substr($this->data, $last);
		$this->data = $new;
		$this->parse();
		return $this->error === false;
	}

	public function addEmptyOption($settingName, $option, $translation = false, $prepend = true)
	{
		if (!isset($this->settings[$settingName]))
			return false;
		$setting = $this->settings[$settingName];
		$pos = false;
		if (!empty($setting->values)) {
			if ($prepend) {
				$pos = array_reduce($setting->values, function ($carry, $option) { return min($carry, $option->lineOffset); }, PHP_INT_MAX);
			} else {
				$pos = array_reduce($setting->values, function ($carry, $option) { return max($carry, $option->lineOffset); }, 0);
			}
		} elseif ($setting->default !== false) {
			$pos = $setting->default->lineOffset;
		} elseif ($setting->block !== false && $setting->block->isUi()) {
			$pos = $this->nextLineEnd($setting->block->start);
			while ($pos !== false && $pos < $this->dataLen && ($this->data{$pos} === "\r" || $this->data{$pos} === "\n")) {
				$pos++;
			}
		}
		if ($pos === false) {
			return false;
		}
		$line = '*' . $settingName . ' ' . $option;
		if ($translation !== false) {
			$line .= '/' . $this->hexTranslation(($this->encoder)($translation, true));
		}
		$eol = $this->getEolChar();
		$line .= ': ""' . $eol;
		$this->data = substr($this->data, 0, $pos) . $line . substr($this->data, $pos);
		$this->parse();
		return $this->error === false;
	}

	public function setDefaultOption($settingName, $optionName)
	{
		if (!isset($this->settings[$settingName]))
			return false;
		$setting = $this->settings[$settingName];
		$line = '*Default' . $settingName . ': ' . $optionName;
		if ($setting->default !== false) {
			$start = $setting->default->lineOffset;
			$end = $start + $setting->default->lineLen;
		} elseif (empty($setting->values)) {
			return false;
		} else {
			$option = reset($setting->values);
			$end = $start = $option->lineOffset;
			$line .= $this->getEolChar();
		}
		$this->data = substr($this->data, 0, $start) . $line . substr($this->data, $end);
		$this->parse();
		return $this->error === false;
	}

	public function write($file)
	{
		return file_put_contents($file, $this->data);
	}

	private function mergeRanges(&$ranges, $start, $end = false)
	{
		if (is_object($start) && get_class($start) === 'PpdOption') {
			$end = $start->lineOffset + $start->lineLen;
			$start = $start->lineOffset;
		}
		if ($start === false || $end === false)
			return;
		if ($start >= $end)
			return; // Don't even bother
		foreach (array_keys($ranges) as $key) {
			if ($start <= $ranges[$key][0] && $end >= $ranges[$key][1]) {
				// Fully dominated
				unset($ranges[$key]);
				continue; // Might partially overlap with additional ranges, keep going
			}
			if ($ranges[$key][0] <= $start && $ranges[$key][1] >= $start) {
				// $start lies within existing range
				if ($ranges[$key][0] <= $end && $ranges[$key][1] >= $end)
					return; // Fully in existing range, do nothing
				// $end seems to extend range we're checking against but $start lies within this range, update and keep going
				$start = $ranges[$key][0];
				unset($ranges[$key]);
				continue;
			}
			// Last possibility: $start is before range, $end within range
			if ($ranges[$key][0] <= $end && $ranges[$key][1] >= $end) {
				// $start must lie before range start, otherwise we'd have hit the case above
				$end = $ranges[$key][1];
				unset($ranges[$key]);
				continue;
			}
		}
		$ranges[] = array($start, $end);
	}

	/**
	 * @return bool whether there was at least one known option with format restriction violated.
	 */
	public function hasInvalidOption()
	{
		return $this->knownKeywordMalformed;
	}

}

/*
 * Helper classes
 */

/**
 * Class PpdOption represents a ppd option
 */
class PpdSetting
{

	/**
	 * @var string default value for this option, or false if not set
	 */
	public $default = false;
	/**
	 * @var string|bool what type of block this is in.
	 * Format: Group<groupname>/SubGroup<subgroupname>
	 */
	public $group = false;
	/**
	 * @var bool true if this is a ui option
	 */
	public $isUi;
	/**
	 * @var string[] list of options mapping optionKeyword => translation
	 */
	public $options = array();
	/**
	 * @var bool|string FoomaticRIPOption (format of option) if set, false otherwise
	 */
	public $foomaticOption = false;

	/**
	 * @var bool|string PickOne, Boolean or PickMany
	 */
	public $uiOptionType = false;

	public $uiOptionTranslation = false;

	/**
	 * PpdSetting constructor.
	 *
	 * @param \PpdSettingInternal $setting
	 */
	public function __construct($setting, $isUi, $enc)
	{
		if ($setting->default !== false) {
			$this->default = $setting->default->option;
		}
		if ($setting->block !== false && $setting->block->isUi()) {
			$this->uiOptionType = $setting->block->value;
			$this->uiOptionTranslation = $enc($setting->block->translation);
			$this->isUi = true;
		} else if ($isUi) {
			$this->uiOptionType = 'PickOne'; // Kinda our fallback
			$this->isUi = true;
		} else {
			$this->isUi = false;
		}
		$block = $setting->block;
		while ($block !== false) {
			if ($block->isUi()) {
				if ($this->group === false) {
					$this->group = $block->type . $block->id;
				} else {
					$this->group = $block->type . $block->id . '/' . $this->group;
				}
			}
			$block = $block->parent;
		}
		foreach ($setting->values as $value) {
			$this->options[$value->option] = $enc($value->optionTranslation);
		}
		if (isset($setting->foomatic['Option'])) {
			$this->foomaticOption = $setting->foomatic['Option']->option;
		}
	}

}

class PpdSettingInternal
{
	/**
	 * @var \PpdOption
	 */
	public $default = false;
	/**
	 * @var \PpdOption[]
	 */
	public $values = array();
	/**
	 * @var \PpdOption[]
	 */
	public $foomatic = array();
	/**
	 * @var \PpdOption
	 */
	public $custom = false;
	/**
	 * @var \PpdOption
	 */
	public $customParam = false;
	/**
	 * @var \PpdBlockInternal the innermost block this option resides in
	 */
	public $block = false;
}

class PpdOption
{
	public $option;
	public $optionTranslation;
	public $lineOffset;
	public $lineLen;
	public $multiLine = false;

	public function __construct($lineOffset, $lineLen, $option, $optionTranslation)
	{
		$this->option = $option;
		$this->optionTranslation = $optionTranslation;
		$this->lineOffset = $lineOffset;
		$this->lineLen = $lineLen;
	}
}

/**
 * Class PpdBlock represents a Group, SubGroup, or UI block
 */
class PpdBlockInternal
{
	public $id;
	public $translation;
	public $type;
	/**
	 * @var \PpdBlockInternal[]
	 */
	public $childBlocks = array();
	/**
	 * @var \PpdBlockInternal
	 */
	public $parent;

	/**
	 * @var int start byte in ppd
	 */
	public $start;

	/**
	 * @var int|bool end byte in ppd, false if block is not closed
	 */
	public $end = false;

	/**
	 * @var string value of opening line for block, e.g. 'PickOne' for OpenUI
	 */
	public $value = false;

	public function __construct($id, $translation, $type, $parent, $start)
	{
		$this->id = $id;
		$this->translation = $translation;
		$this->type = $type;
		$this->parent = $parent;
		$this->start = $start;
	}

	/**
	 * @return bool true if this is a UI block
	 */
	public function isUi()
	{
		return $this->type == 'UI' || $this->type === 'JCLUI';
	}

	/**
	 * @param \PpdBlockInternal $block some other PpdBlock instance
	 * @return bool true if this is a child of $block
	 */
	public function isChildOf($block)
	{
		$parent = $this->parent;
		while ($parent !== false) {
			if ($parent === $block) {
				return true;
			}
			$parent = $parent->parent;
		}
		return false;
	}

}
