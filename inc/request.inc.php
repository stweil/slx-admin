<?php

/**
 * Wrapper for getting fields from the request (GET, POST, ...)
 */
class Request
{
	
	/**
	 * 
	 * @param string $key Key of field to get from $_GET
	 * @param string $default Value to return if $_GET does not contain $key
	 * @return mixed Field from $_GET, or $default if not set
	 */
	public static function get($key, $default = false)
	{
		if (!isset($_GET[$key])) return $default;
		return $_GET[$key];
	}
	
	/**
	 * 
	 * @param string $key Key of field to get from $_POST
	 * @param string $default Value to return if $_POST does not contain $key
	 * @return mixed Field from $_POST, or $default if not set
	 */
	public static function post($key, $default = false)
	{
		if (!isset($_POST[$key])) return $default;
		return $_POST[$key];
	}
	
	/**
	 * 
	 * @param string $key Key of field to get from $_REQUEST
	 * @param string $default Value to return if $_REQUEST does not contain $key
	 * @return mixed Field from $_REQUEST, or $default if not set
	 */
	public static function any($key, $default = false)
	{
		if (!isset($_REQUEST[$key])) return $default;
		return $_REQUEST[$key];
	}
	
}
