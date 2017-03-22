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
	 * @var boolean  true if there was an error
	 * @var string with the error message
	 * @var int as internal serverID
	 * @var string url of the service
	 */
	private static $backendTypes = false;
	public $error;
	public $errormsg;
	public $serverID;
	public $location;
	const nrOtherRooms = 5;

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

	/**
	 * @return string return display name of backend
	 */
	public abstract function getDisplayName();


	/**
	 * @returns array with parameter name as key and and an array with type, help text and mask  as value
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
	 * @param array $data with the credentials
	 * @param string $url address of the server
	 * @param int $serverID ID of the server
	 * @returns bool if the credentials were in the correct format
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
	 * @param $roomIds array with local ID as key and serverID as value
	 * @return array a recursive array that uses the roomID as key
	 * and has the schedule array as value. A shedule array contains jsons in this format:
	 * {"start":JJJJ-MM-DD HH:MM:SS,"end":JJJJ-MM-DD HH:MM:SS,"title":string}
	 */
	protected abstract function fetchSchedulesInternal($roomId);

	/**
	 * Method for fetching the schedule of the given rooms on a server.
	 *
	 * @param array $roomId array of room ID to fetch
	 * @return array|bool array containing the timetables as value and roomid as key as result, or false on error
	 */
	public final function fetchSchedule($roomIDs)
	{
		$sqlr = implode(",", $roomIDs);
		$sqlr = '(' . $sqlr . ')';
		$q = "SELECT locationid, calendar, serverroomid, lastcalendarupdate FROM location_info WHERE locationid IN " . $sqlr;
		$dbquery1 = Database::simpleQuery($q);
		$result = [];
		$sRoomIDs = [];
		$newResult = [];
		foreach ($dbquery1->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$sRoomID = $row['serverroomid'];
			$lastUpdate = $row['lastcalendarupdate'];
			$calendar = $row['calendar'];
			//Check if in cache if lastUpdate is null then it is interpreted as 1970
			if ($lastUpdate > strtotime("-" . $this->getCacheTime() . "seconds")) {
				$result[$row['locationid']] = json_decode($calendar);
			} else {
				$sRoomIDs[$row['locationid']] = $sRoomID;
			}

		}
		//Check if we should refresh other rooms recently requested by front ends
		if ($this->getCacheTime() > 0) {
			$i = 0; //number of rooms getting refreshed
			$dbquery4 = Database::simpleQuery("SELECT locationid ,serverroomid, lastcalendarupdate FROM location_info WHERE serverid= :id", array('id' => $this->serverID));
			foreach ($dbquery4->fetchAll(PDO::FETCH_COLUMN) as $row) {
				if (isset($row['lastcalendarupdate'])) {
					$lastUpdate = $row['lastcalendarupdate'];
					if ($lastUpdate < strtotime("-" . $this->getRefreshTime() . "seconds") && $lastUpdate > strtotime("-" . $this->getCacheTime() . "seconds"&& $i<self::nrOtherRooms)) {
						$sRoomIDs[$row['locationid']] = $row['serverroomid'];
						$i = $i +1;
					}
				}
			}
		}
		$results = $this->fetchSchedulesInternal($sRoomIDs);
		if ($results === false) {
			return false;
		}

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
			if(isset($newResult[$id])){
				$result[$id] = $newResult[$id];
			}
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
	/**
	 * Query path in array-representation of XML document.
	 * e.g. 'path/syntax/foo/wanteditem'
	 * This works for intermediate nodes (that have more children)
	 * and leaf nodes. The result is always an array on success, or
	 * false if not found.
	 */
	function getAttributes($array, $path)
	{
		if (!is_array($path)) {
			// Convert 'path/syntax/foo/wanteditem' to array for further processing and recursive calls
			$path = explode('/', $path);
		}
		do {
			// Get next element from array, loop to ignore empty elements (so double slashes in the path are allowed)
			$element = array_shift($path);
		} while (empty($element) && !empty($path));
		if (!isset($array[$element])) {
			// Current path element does not exist - error
			return false;
		}
		if (empty($path)) {
			// Path is now empty which means we're at 'wanteditem' from out example above
			if (!is_array($array[$element]) || !isset($array[$element][0])) {
				// If it's a leaf node of the array, wrap it in plain array, so the function will
				// always return an array on success
				return array($array[$element]);
			}
			// 'wanteditem' is not a unique leaf node, return as is
			// This means it's either a plain array, in case there are multiple 'wanteditem' elements on the same level
			// or it's an associative array if 'wanteditem' has any sub-nodes
			return $array[$element];
		}
		// Recurse
		if (!is_array($array[$element])) {
			// We're in the middle of the requested path, but the current element is already a leaf node with no
			// children - error
			return false;
		}
		if (isset($array[$element][0])) {
			// The currently handled element of the path exists multiple times on the current level, so it is
			// wrapped in a plain array - recurse into each one of them and merge the results
			$return = [];
			foreach ($array[$element] as $item) {
				$test = $this->getAttributes($item, $path);
				If(gettype($test) == "array" ){
					$return = array_merge($return, $test);
				}

			}
			return $return;
		}
		// Unique non-leaf node - simple recursion
		return $this->getAttributes($array[$element], $path);
	}
}
