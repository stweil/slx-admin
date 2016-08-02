<?php

/**
 * This class contains all the helper functions that
 * can be referenced by a config setting. Every function
 * here is supposed to validate the given config value
 * and either return the validated and possibly sanitized
 * value, or false to indicate that the given value is invalid.
 * The passed value is a reference, as it can also be modified
 * by the validator to tweak the value that is being
 * displayed in the web interface, compared to the returned
 * value, which will only be used by the client directly,
 * and is not displayed by the web interface.
 */
class Validator
{

	public static function validate($condition, &$displayValue)
	{
		if (empty($condition))
			return $displayValue;
		$data = explode(':', $condition, 2);
		switch ($data[0]) {
			case 'regex':
				if (preg_match($data[1], $displayValue))
					return $displayValue;
				return false;
			case 'list':
				return self::validateList($data[1], $displayValue);
			case 'function':
				return self::$data[1]($displayValue);
			case 'multilist':
				return self::validateMultiList($data[1], $displayValue);
			case 'multiinput':
				return self::validateMultiInput($data[1], $displayValue);
			default:
				Util::traceError('Unknown validation method: ' . $data[0]);
		}
		return false; // make code inspector happy - doesn't know traceError doesn't return
	}


	/**
	 * Validate linux password. If already in $6$ hash form,
	 * the unchanged value will be returned.
	 * if empty, an empty string will also be returned.
	 * Otherwise it it assumed that the value is a plain text
	 * password that is supposed to be hashed.
	 */
	private static function linuxPassword(&$displayValue)
	{
		if (empty($displayValue))
			return '';
		if (preg_match('/^\$[156]\$.+\$./', $displayValue))
			return $displayValue;
		return Crypto::hash6($displayValue);
	}

	/**
	 * "Fix" network share path for SMB shares where a backslash
	 * is used instead of a slash.
	 * @param string $displayValue network path
	 * @return string cleaned up path
	 */
	private static function networkShare(&$displayValue)
	{
		$displayValue = trim($displayValue);
		if (substr($displayValue, 0, 2) === '\\\\')
			$displayValue = str_replace('\\', '/', $displayValue);
		$returnValue = $displayValue;
		if (substr($returnValue, 0, 2) === '//')
			$returnValue = str_replace(' ', '\\040', $returnValue);
		return $returnValue;
	}

	/**
	 * Validate value against list.
	 * @param string $list The list as a string of items, separated by "|"
	 * @param string $displayValue The value to validate
	 * @return boolean|string The value, if in list, false otherwise
	 */
	private static function validateList($list, &$displayValue)
	{
		$list = explode('|', $list);
		if (in_array($displayValue, $list))
			return $displayValue;
		return false;
	}
	private static function validateMultiList($list, &$displayValue)
	{
		$allowedValues = explode('|', $list);
		$values = [];
		foreach ($displayValue as $v) {
			if (in_array($v, $allowedValues)) {
				$values[] = $v;
			}
		}
		$displayValue = implode(' ', $values);
		return $displayValue;
	}

	private static function validateMultiInput(&$list, &$displayValue)
	{
		return $displayValue;
	}
}
