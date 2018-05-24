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
	public static function get($key, $default = false)
	{
		if (self::$cache === false) {
			$NOW = time();
			$res = Database::simpleQuery("SELECT name, dateline, value FROM property");
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				if ($row['dateline'] != 0 && $row['dateline'] < $NOW)
					continue;
				self::$cache[$row['name']] = $row['value'];
			}
		}
		if (!isset(self::$cache[$key]))
			return $default;
		return self::$cache[$key];
	}

	/**
	 * Set value in property store.
	 *
	 * @param string $key key of value to set
	 * @param string $value the value to store for $key
	 * @param int $maxAgeMinutes how long to keep this entry around at least, in minutes. 0 for infinite
	 */
	public static function set($key, $value, $maxAgeMinutes = 0)
	{
		if (self::$cache === false || self::get($key) != $value) { // Simple compare, so it works for numbers accidentally casted to string somewhere
			Database::exec("INSERT INTO property (name, value, dateline) VALUES (:key, :value, :dateline)"
				. " ON DUPLICATE KEY UPDATE value = VALUES(value), dateline = VALUES(dateline)", array(
				'key' => $key,
				'value' => $value,
				'dateline' => ($maxAgeMinutes === 0 ? 0 : time() + ($maxAgeMinutes * 60))
			));
		}
		if (self::$cache !== false) {
			self::$cache[$key] = $value;
		}
	}

	/**
	 * Retrieve property list from the store.
	 *
	 * @param string $key Key of list to get all items for
	 * @return array All the items matching the key
	 */
	public static function getList($key)
	{
		$res = Database::simpleQuery("SELECT dateline, value FROM property_list WHERE name = :key", compact('key'));
		$NOW = time();
		$return = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['dateline'] != 0 && $row['dateline'] < $NOW)
				continue;
			$return[] = $row['value'];
		}
		return $return;
	}

	/**
	 * Add item to property list.
	 *
	 * @param string $key key of value to set
	 * @param string $value the value to add for $key
	 * @param int $maxAgeMinutes how long to keep this entry around at least, in minutes. 0 for infinite
	 */
	public static function addToList($key, $value, $maxAgeMinutes = 0)
	{
		Database::exec("INSERT INTO property_list (name, value, dateline) VALUES (:key, :value, :dateline)", array(
			'key' => $key,
			'value' => $value,
			'dateline' => ($maxAgeMinutes === 0 ? 0 : time() + ($maxAgeMinutes * 60))
		));
	}

	/**
	 * Remove given item from property list. If the list contains this item
	 * multiple times, they will all be removed.
	 *
	 * @param string $key Key of list
	 * @param string $value item to remove
	 * @return int number of items removed
	 */
	public static function removeFromList($key, $value)
	{
		return Database::exec("DELETE FROM property_list WHERE name = :key AND value = :value", array(
			'key' => $key,
			'value' => $value,
		));
	}

	/**
	 * Delete entire list with given key.
	 *
	 * @param string $key Key of list
	 * @return int number of items removed
	 */
	public static function clearList($key)
	{
		return Database::exec("DELETE FROM property_list WHERE name = :key", compact('key'));
	}

	/*
	 * Legacy getters/setters
	 */

	public static function getServerIp()
	{
		return self::get('server-ip', 'none');
	}

	public static function setServerIp($value, $automatic = false)
	{
		if ($value === self::getServerIp())
			return false;
		EventLog::info('Server IP changed from ' . self::getServerIp() . ' to ' . $value . ($automatic ? ' (auto detected)' : ''));
		self::set('server-ip', $value);
		Event::serverIpChanged();
		return true;
	}

	public static function getBootMenu()
	{
		return json_decode(self::get('ipxe-menu'), true);
	}

	public static function setBootMenu($value)
	{
		self::set('ipxe-menu', json_encode($value));
	}

	public static function getVersionCheckTaskId()
	{
		return self::get('versioncheck-task');
	}

	public static function setVersionCheckTaskId($value)
	{
		self::set('versioncheck-task', $value);
	}

	public static function getVersionCheckInformation()
	{
		$data = json_decode(self::get('versioncheck-data', '[]'), true);
		if (isset($data['time']) && $data['time'] + 60 > time())
			return $data;
		$task = Taskmanager::submit('DownloadText', array(
				'url' => CONFIG_REMOTE_ML . '/list.php'
		));
		if (!isset($task['id']))
			return 'Could not start list download (' . Message::asString() . ')';
		if (!Taskmanager::isFinished($task)) {
			$task = Taskmanager::waitComplete($task['id'], 5000);
		}
		if ($task['statusCode'] !== Taskmanager::TASK_FINISHED || !isset($task['data']['content'])) {
			return isset($task['data']['error']) ? $task['data']['error'] : 'Timeout';
		}
		$data = json_decode($task['data']['content'], true);
		$data['time'] = time();
		self::setVersionCheckInformation($data);
		return $data;
	}

	public static function setVersionCheckInformation($value)
	{
		self::set('versioncheck-data', json_encode($value), 1);
	}

	public static function getVmStoreConfig()
	{
		return json_decode(self::get('vmstore-config'), true);
	}

	public static function getVmStoreUrl()
	{
		$store = self::getVmStoreConfig();
		if (!isset($store['storetype']))
			return false;
		if ($store['storetype'] === 'nfs')
			return $store['nfsaddr'];
		if ($store['storetype'] === 'cifs')
			return $store['cifsaddr'];
		if ($store['storetype'] === 'internal')
			return '<local>';
		return '<unknown>';
	}

	public static function setVmStoreConfig($value)
	{
		self::set('vmstore-config', json_encode($value));
	}

	public static function getDownloadTask($name)
	{
		return self::get('dl-' . $name);
	}

	public static function setDownloadTask($name, $taskId)
	{
		self::set('dl-' . $name, $taskId, 5);
	}

	public static function getCurrentSchemaVersion()
	{
		return self::get('webif-version');
	}

	public static function setLastWarningId($id)
	{
		self::set('last-warn-event-id', $id);
	}

	public static function getLastWarningId()
	{
		return self::get('last-warn-event-id', 0);
	}

	public static function setNeedsSetup($value)
	{
		self::set('needs-setup', $value);
	}

	public static function getNeedsSetup()
	{
		return self::get('needs-setup');
	}
	
	public static function setPasswordFieldType($value)
	{
		self::set('password-type', $value);
	}
	
	public static function getPasswordFieldType()
	{
		return self::get('password-type', 'password');
	}

	public static function getIpxeDefault()
	{
		return self::get('default-ipxe');
	}

}
