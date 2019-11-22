<?php

/**
 * Wrapper for getting fields from the request (GET, POST, ...)
 */
class Request
{

	const REQUIRED = "\0\1\2REQ\0\1\2";

	/**
	 *
	 * @param string $key Key of field to get from $_GET
	 * @param string $default Value to return if $_GET does not contain $key
	 * @param string $type if the parameter exists, cast it to given type
	 * @return mixed Field from $_GET, or $default if not set
	 */
	public static function get($key, $default = false, $type = false)
	{
		return self::handle($_GET, $key, $default, $type);
	}
	
	/**
	 * 
	 * @param string $key Key of field to get from $_POST
	 * @param string $default Value to return if $_POST does not contain $key
	 * @return mixed Field from $_POST, or $default if not set
	 */
	public static function post($key, $default = false, $type = false)
	{
		return self::handle($_POST, $key, $default, $type);
	}
	
	/**
	 * 
	 * @param string $key Key of field to get from $_REQUEST
	 * @param string $default Value to return if $_REQUEST does not contain $key
	 * @return mixed Field from $_REQUEST, or $default if not set
	 */
	public static function any($key, $default = false, $type = false)
	{
		return self::handle($_REQUEST, $key, $default, $type);
	}

	/**
	 * @return true iff the request is a POST request
	 */
	public static function isPost()
	{
		return $_SERVER['REQUEST_METHOD'] === 'POST';
	}

	/**
	 * @return true iff the request is a GET request
	 */
	public static function isGet()
	{
		return $_SERVER['REQUEST_METHOD'] === 'GET';
	}

	private static function handle(&$array, $key, $default, $type)
	{
		if (!isset($array[$key])) {
			if ($default === self::REQUIRED) {
				Message::addError('main.parameter-missing', $key);
				Util::redirect('?do=' . $_REQUEST['do']);
			}
			return $default;
		}
		if ($default === self::REQUIRED && empty($array[$key])) {
			Message::addError('main.parameter-empty', $key);
			Util::redirect('?do=' . $_REQUEST['do']);
		}
		if ($type !== false) settype($array[$key], $type);
		return $array[$key];
	}

}
