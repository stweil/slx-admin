<?php

/**
 * This class contains all the helper functions that
 * can be referenced by a config setting. Every function
 * here is supposed to validate the given config value
 * and wither return the validated and possibly sanitized
 * value, or false to indicate that the given value is invalid.
 */
class Validator
{

	public static function validate($condition, $value)
	{
		if (empty($condition)) return $value;
		$data = explode(':', $condition, 2);
		switch ($data[0]) {
		case 'regex':
			if (preg_match($data[1], $value)) return $value;
			return false;
		case 'list':
			return self::validateList($data[1], $value);
		case 'function':
			return self::$data[1]($value);
		default:
			Util::traceError('Unknown validation method: ' . $data[0]);
		}
	}

	/**
	 * Validate linux password. If already in $6$ hash form,
	 * the unchanged value will be returned.
	 * if empty, an empty string will also be returned.
	 * Otherwise it it assumed that the value is a plain text
	 * password that is supposed to be hashed.
	 */
	private static function linuxPassword($value)
	{
		if (empty($value)) return '';
		if (preg_match('/^\$6\$.+\$./', $value)) return $value;
		return Crypto::hash6($value);
	}
	
	/**
	 * Validate value against list.
	 * @param string $list The list as a string of items, separated by "|"
	 * @param string $value The value to validate
	 * @return boolean|string The value, if in list, false otherwise
	 */
	private static function validateList($list, $value)
	{
		$list = explode('|', $list);
		if (in_array($value, $list)) return $value;
		return false;
	}

}

