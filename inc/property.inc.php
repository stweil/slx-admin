<?php

/**
 * Get or set simple key-value-pairs, backed by the database
 * to make them persistent.
 */
class Property
{
	private static $cache = false;
	
	/**
	 * Retrieve value from property store.
	 *
	 * @param string $key key to retrieve the value of
	 * @param mixed $default value to return if $key does not exist in the property store
	 * @return mixed the value attached to $key, or $default if $key does not exist
	 */
	private static function get($key, $default = false)
	{
		if (self::$cache === false) {
			$res = Database::simpleQuery("SELECT name, value FROM property");
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				self::$cache[$row['name']] = $row['value'];
			}
		}
		if (!isset(self::$cache[$key])) return $default;
		return self::$cache[$key];
	}
	
	/**
	 * Set value in property store.
	 *
	 * @param string $key key of value to set
	 * @param type $value the value to store for $key
	 */
	private static function set($key, $value)
	{
		Database::exec("INSERT INTO property (name, value) VALUES (:key, :value)"
			. " ON DUPLICATE KEY UPDATE value = VALUES(value)", array(
				'key' => $key,
				'value' => $value
			));
		if (self::$cache !== false) {
			self::$cache[$key] = $value;
		}
	}
	
	public static function getServerIp()
	{
		return self::get('server-ip', 'none');
	}
	
	public static function setServerIp($value)
	{
		self::set('server-ip', $value);
	}
	
	public static function getIPxeIp()
	{
		return self::get('ipxe-ip', 'none');
	}
	
	public static function setIPxeIp($value)
	{
		self::set('ipxe-ip', $value);
	}
	
	public static function getIPxeTaskId()
	{
		return self::get('ipxe-task');
	}
	
	public static function setIPxeTaskId($value)
	{
		self::set('ipxe-task', $value);
	}
	
	public static function getBootMenu()
	{
		return json_decode(self::get('ipxe-menu'), true);
	}
	
	public static function setBootMenu($value)
	{
		self::set('ipxe-menu', json_encode($value));
	}

}
