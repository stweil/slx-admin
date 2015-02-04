<?php

if (defined('JSON_PRETTY_PRINT'))
	define('JSON_NATIVE', true);
else
	define('JSON_NATIVE', false);

/**
 * api:		php
 * title:	upgrade.php
 * description:	Emulates functions from new PHP versions on older interpreters.
 * version:	19
 * license:	Public Domain
 * url:		http://freshmeat.net/projects/upgradephp
 * type:	functions
 * category:	library
 * priority:	auto
 * load_if:     (PHP_VERSION<5.2)
 * sort:	-255
 * provides:	upgrade-php, api:php5, json
 *
 *
 * By loading this library you get PHP version independence. It provides
 * downwards compatibility to older PHP interpreters by emulating missing
 * functions or constants using IDENTICAL NAMES. So this doesn't slow down
 * script execution on setups where the native functions already exist. It
 * is meant as quick drop-in solution. It spares you from rewriting code or
 * using cumbersome workarounds instead of the more powerful v5 functions.
 * 
 * It cannot mirror PHP5s extended OO-semantics and functionality into PHP4
 * however. A few features are added here that weren't part of PHP yet. And
 * some other function collections are separated out into the ext/ directory.
 * It doesn't produce many custom error messages (YAGNI), and instead leaves
 * reporting to invoked functions or for native PHP execution.
 * 
 * And further this is PUBLIC DOMAIN (no copyright, no license, no warranty)
 * so therefore compatible to ALL open source licenses. You could rip this
 * paragraph out to republish this instead only under more restrictive terms
 * or your favorite license (GNU LGPL/GPL, BSDL, MPL/CDDL, Artistic/PHPL, ..)
 *
 * Any contribution is appreciated. <milky*users#sf#net>
 *
 */
/**
 *                                   -------------------------- FUTURE ---
 * @group SVN
 * @since future
 *
 * Following functions aren't implemented in current PHP versions, but
 * might already be in CVS/SVN.
 *
 * @removed
 *    setcookie2
 *
 */
/**
 * Converts PHP variable or array into a "JSON" (JavaScript value expression
 * or "object notation") string.
 *
 * @compat
 *    Output seems identical to PECL versions. "Only" 20x slower than PECL version.
 * @bugs
 *    Doesn't take care with unicode too much - leaves UTF-8 sequences alone.
 *
 * @param  $var mixed  PHP variable/array/object
 * @return string      transformed into JSON equivalent
 */
if (!defined("JSON_HEX_TAG")) {
	define("JSON_HEX_TAG", 1);
	define("JSON_HEX_AMP", 2);
	define("JSON_HEX_APOS", 4);
	define("JSON_HEX_QUOT", 8);
	define("JSON_FORCE_OBJECT", 16);
}
if (!defined("JSON_NUMERIC_CHECK")) {
	define("JSON_NUMERIC_CHECK", 32);		// 5.3.3
}
if (!defined("JSON_UNESCAPED_SLASHES")) {
	define("JSON_UNESCAPED_SLASHES", 64);  // 5.4.0
	define("JSON_PRETTY_PRINT", 128);		// 5.4.0
	define("JSON_UNESCAPED_UNICODE", 256); // 5.4.0
}

function up_json_encode($var, $options = 0, $_indent = "")
{
	if (defined('JSON_NATIVE') && JSON_NATIVE)
		return json_encode($var, $options);
	global ${'.json_last_error'};
	${'.json_last_error'} = JSON_ERROR_NONE;

	#-- prepare JSON string
	list($_space, $_tab, $_nl) = ($options & JSON_PRETTY_PRINT) ? array(" ", "    $_indent", "\n") : array("", "", "");
	$json = "$_indent";

	if (($options & JSON_NUMERIC_CHECK) and is_string($var) and is_numeric($var)) {
		$var = (strpos($var, ".") || strpos($var, "e")) ? floatval($var) : intval($var);
	}

	#-- add array entries
	if (is_array($var) || ($obj = is_object($var))) {
		$obj = is_object($var);
		#-- check if array is associative
		if (!$obj && !($options & JSON_FORCE_OBJECT)) {
			$keys = array_keys($var);
			sort($keys);
			for ($i = 0; $i < count($keys); ++$i) {
				if (!is_numeric($keys[$i]) || (int)$keys[$i] !== $i)
					$obj = true;
			}
		} else {
			$obj = true;
		}

		#-- concat individual entries
		$empty = 0;
		$json = "";
		foreach ((array) $var as $i => $v) {
			$json .= ($empty++ ? ",$_nl" : "")	 // comma separators
				. $_tab . ($obj ? (up_json_encode((string)$i, $options & ~JSON_NUMERIC_CHECK, $_tab) . ":$_space") : "")	// assoc prefix
				. (up_json_encode($v, $options, $_tab));	 // value
		}

		#-- enclose into braces or brackets
		$json = $obj ? "{" . "$_nl$json$_nl$_indent}" : "[$_nl$json$_nl$_indent]";
	}

	#-- strings need some care
	elseif (is_string($var)) {

		if (!empty($var) && mb_detect_encoding($var, 'UTF-8', true) === false) {
			trigger_error("up_json_encode: invalid UTF-8 encoding in string '$var', cannot proceed.", E_USER_WARNING);
			$var = NULL;
		}
		$rewrite = array(
			"\\" => "\\\\",
			"\"" => "\\\"",
			"\010" => "\\b",
			"\f" => "\\f",
			"\n" => "\\n",
			"\r" => "\\r",
			"\t" => "\\t",
			"/" => $options & JSON_UNESCAPED_SLASHES ? "/" : "\\/",
			"<" => $options & JSON_HEX_TAG ? "\\u003C" : "<",
			">" => $options & JSON_HEX_TAG ? "\\u003E" : ">",
			"'" => $options & JSON_HEX_APOS ? "\\u0027" : "'",
			"\"" => "\\u0022",
			"&" => $options & JSON_HEX_AMP ? "\\u0026" : "&",
		);
		$var = strtr($var, $rewrite);
		//@COMPAT control chars should probably be stripped beforehand, not escaped as here
		if (function_exists("iconv") && ($options & JSON_UNESCAPED_UNICODE) == 0) {
			$var = preg_replace("/[^\\x{0020}-\\x{007F}]/ue", "'\\u'.current(unpack('H*', iconv('UTF-8', 'UCS-2BE', '$0')))", $var);
		}
		$json = '"' . $var . '"';
	}

	#-- basic types
	elseif (is_bool($var)) {
		$json = $var ? "true" : "false";
	} elseif ($var === NULL) {
		$json = "null";
	} elseif (is_int($var)) {
		$json = "$var";
	} elseif (is_float($var)) {
		if (is_nan($var) || is_infinite($var)) {
			${'.json_last_error'} = JSON_ERROR_INF_OR_NAN;
			return;
		} else {
			$json = "$var";
		}
	}

	#-- something went wrong
	else {
		trigger_error("up_json_encode: don't know what a '" . gettype($var) . "' is.", E_USER_WARNING);
		${'.json_last_error'} = JSON_ERROR_UNSUPPORTED_TYPE;
		return;
	}

	#-- done
	return($json);
}
