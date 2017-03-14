<?php

/**
 * Base class for course query backends
 */
abstract class CourseBackend
{

	/*
	 * Static part for handling interfaces
	 */

	/**
	 * @var array list of known backends
	 * $error boolean true if there was an error
	 * $errormsg string with the error message
	 */
	private static $backendTypes = false;
	public $error;
	public $errormsg;
	public $serverID;
	public $location;

	/**
	 * CourseBackend constructor.
	 */
	public final function __construct()
	{
		$this->location = "";
		$this->error = false;
		$this->errormsg = "";
	}

	/**
	 * Load all known backend types. This is done
	 * by including *.inc.php from inc/coursebackend/.
	 */
	public static function loadDb()
	{
		if (self::$backendTypes !== false)
			return;
		self::$backendTypes = array();
		foreach (glob(dirname(__FILE__) . '/coursebackend/coursebackend_*.inc.php', GLOB_NOSORT) as $file) {
			require_once $file;
			preg_match('#coursebackend_([^/\.]+)\.inc\.php$#i', $file, $out);
			if (!class_exists('coursebackend_' . $out[1])) {
				trigger_error("Backend type source unit $file doesn't seem to define class CourseBackend_{$out[1]}", E_USER_ERROR);
			}
			self::$backendTypes[$out[1]] = true;
		}
	}

	/**
	 * Get all known config module types.
	 *
	 * @return array list of modules
	 */
	public static function getList()
	{
		self::loadDb();
		return array_keys(self::$backendTypes);
	}

	/**
	 * Get fresh instance of ConfigModule subclass for given module type.
	 *
	 * @param string $moduleType name of module type
	 * @return \ConfigModule module instance
	 */
	public static function getInstance($moduleType)
	{
		self::loadDb();
		if (!isset(self::$backendTypes[$moduleType])) {
			error_log('Unknown module type: ' . $moduleType);
			return false;
		}
		if (!is_object(self::$backendTypes[$moduleType])) {
			$class = "coursebackend_$moduleType";
			self::$backendTypes[$moduleType] = new $class;
		}
		return self::$backendTypes[$moduleType];
	}


	/*
	 * TODO: Insert required methods here
	 */

	/**
	 * @return string return display name of backend
	 */
	public abstract function getDisplayName();


	/**
	 * @returns array with parameter name as key and type as value
	 */
	public abstract function getCredentials();

	/**
	 * @return boolean true if the connection works, false otherwise
	 */
	public abstract function checkConnection();

	/**
	 * uses json to setCredentials, the json must follow the form given in
	 * getCredentials
	 *
	 * @param string $data array with the credentials
	 * @param string $url address of the server
	 * @param int $serverID ID of the server
	 * @returns void
	 */
	public abstract function setCredentials($data, $url, $serverID);

	/**
	 * @return int desired caching time of results, in seconds. 0 = no caching
	 */
	public abstract function getCacheTime();

	/**
	 * @return int age after which timetables are no longer refreshed should be
	 * greater then CacheTime
	 */
	public abstract function getRefreshTime();

	/**
	 * Internal version of fetch, to be overridden by subclasses.
	 *
	 * @param $roomIds
	 * @return array a multidimensional array that uses the roomID as key
	 * and has the schedules as string in the value
	 */
	protected abstract function fetchSchedulesInternal($roomId);

	/**
	 * Method for fetching the schedule of the given rooms on a server.
	 *
	 * @param int $roomId int of room ID to fetch
	 * @param int $serverid id of the server
	 * @return string|bool some jsonstring as result, or false on error
	 */
	public final function fetchSchedule($roomIDs)
	{
		$sqlr = implode(",", $roomIDs);
		$sqlr = '(' . $sqlr . ')';
		$q = "SELECT locationid, calendar, serverroomid, lastcalendarupdate FROM location_info WHERE locationid IN " . $sqlr;
		$dbquery1 = Database::simpleQuery($q);
		$result = [];
		$sRoomIDs = [];
		foreach ($dbquery1->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$sRoomID = $row['serverroomid'];
			$lastUpdate = $row['lastcalendarupdate'];
			$calendar = $row['calendar'];
			//Check if in cache if lastUpdate is null then it is interpreted as 1970
			if (strtotime($lastUpdate) > strtotime("-" . $this->getCacheTime() . "seconds") && $this->getCacheTime() > 0) {
				$result[$row['locationid']] = json_decode($calendar);
			} else {
				$sRoomIDs[$row['locationid']] = $sRoomID;
			}

		}
		//Check if we should refresh other rooms recently requested by front ends
		if ($this->getCacheTime() > 0 && $this->getRefreshTime() > 0) {
			$dbquery4 = Database::simpleQuery("SELECT locationid ,serverroomid, lastcalendarupdate FROM location_info WHERE serverid= :id", array('id' => $this->serverID));
			foreach ($dbquery4->fetchAll(PDO::FETCH_COLUMN) as $row) {
				if (isset($row['lastcalendarupdate'])) {
					$lastUpdate = $row['lastcalendarupdate'];
					if (strtotime($lastUpdate) > strtotime("-" . $this->getRefreshTime() . "seconds") && strtotime($lastUpdate) > strtotime("-" . $this->getCacheTime() . "seconds")) {
						$sRoomIDs[$row['locationid']] = $row['serverroomid'];
					}
				}
			}
		}
		$results = $this->fetchSchedulesInternal($sRoomIDs);
		if ($results === false) {
			return false;
		}
		$newResult = [];
		foreach ($sRoomIDs as $location => $serverRoom) {
			$newResult[$location] = $results[$serverRoom];
		}

		if ($this->getCacheTime() > 0) {
			foreach ($newResult as $key => $value) {
				$value = json_encode($value);
				$now = strtotime('Now');
				Database::simpleQuery("UPDATE location_info SET calendar = :ttable, lastcalendarupdate = :now WHERE locationid = :id ", array('id' => $key, 'ttable' => $value, 'now' => $now));
			}
		}
		//get all schedules that are wanted from roomIDs
		foreach ($roomIDs as $id) {
			$result[$id] = $newResult[$id];
		}
		return $result;
	}

	/**
	 * @return false if there was no error string with error message if there was one
	 */
	public final function getError()
	{
		if ($this->error) {
			return $this->errormsg;
		}
		return false;
	}

}
