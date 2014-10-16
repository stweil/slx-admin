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
	 * @param type $value the value to store for $key
	 * @param int $minage how long to keep this entry around at least, in minutes. 0 for infinite
	 */
	private static function set($key, $value, $minage = 0)
	{
		Database::exec("INSERT INTO property (name, value, dateline) VALUES (:key, :value, :dateline)"
			. " ON DUPLICATE KEY UPDATE value = VALUES(value), dateline = VALUES(dateline)", array(
			'key' => $key,
			'value' => $value,
			'dateline' => ($minage === 0 ? 0 : time() + ($minage * 60))
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
		if ($value !== self::getServerIp())
			Event::serverIpChanged();
		self::set('server-ip', $value);
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
		$data = json_decode(self::get('versioncheck-data'), true);
		if (isset($data['time']) && $data['time'] + 120 > time())
			return $data;
		$task = Taskmanager::submit('DownloadText', array(
				'url' => CONFIG_REMOTE_ML . '/list.php'
		));
		if (!isset($task['id']))
			return 'Could not start list download (' . Message::asString() . ')';
		if ($task['statusCode'] !== TASK_FINISHED) {
			$task = Taskmanager::waitComplete($task['id']);
		}
		if ($task['statusCode'] !== TASK_FINISHED || !isset($task['data']['content'])) {
			return $task['data']['error'];
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

}
