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
	 */
	private static $backendTypes = false;
	/**
	 * @var boolean|string false = no error, error message otherwise
	 */
	protected $error;
	/**
	 * @var int as internal serverId
	 */
	protected $serverId;
	/**
	 * @const int max number of additional locations to fetch (for backends that benefit from request coalesc.)
	 */
	const MAX_ADDIDIONAL_LOCATIONS = 5;

	/**
	 * CourseBackend constructor.
	 */
	public final function __construct()
	{
		$this->error = false;
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
	 * @return \CourseBackend module instance
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
	 * @returns \BackendProperty[] list of properties that need to be set
	 */
	public abstract function getCredentialDefinitions();

	/**
	 * @return boolean true if the connection works, false otherwise
	 */
	public abstract function checkConnection();

	/**
	 * uses json to setCredentials, the json must follow the form given in
	 * getCredentials
	 *
	 * @param array $data assoc array with data required by backend
	 * @returns bool if the credentials were in the correct format
	 */
	public abstract function setCredentialsInternal($data);

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
	 * @param $roomIds array with local ID as key and serverId as value
	 * @return array a recursive array that uses the roomID as key
	 * and has the schedule array as value. A shedule array contains an array in this format:
	 * ["start"=>'JJJJ-MM-DD HH:MM:SS',"end"=>'JJJJ-MM-DD HH:MM:SS',"title"=>string]
	 */
	protected abstract function fetchSchedulesInternal($roomId);

	/**
	 * Method for fetching the schedule of the given rooms on a server.
	 *
	 * @param array $roomId array of room ID to fetch
	 * @return array|bool array containing the timetables as value and roomid as key as result, or false on error
	 */
	public final function fetchSchedule($requestedLocationIds)
	{
		if (!is_array($requestedLocationIds)) {
			$this->error = 'No array of roomids was given to fetchSchedule';
			return false;
		}
		if (empty($requestedLocationIds))
			return array();
		$NOW = time();
		$dbquery1 = Database::simpleQuery("SELECT locationid, calendar, serverlocationid, lastcalendarupdate
				FROM locationinfo_locationconfig WHERE locationid IN (:locations)",
				array('locations' => array_values($requestedLocationIds)));
		$returnValue = [];
		$remoteIds = [];
		while ($row = $dbquery1->fetch(PDO::FETCH_ASSOC)) {
			//Check if in cache if lastUpdate is null then it is interpreted as 1970
			if ($row['lastcalendarupdate'] + $this->getCacheTime() > $NOW) {
				$returnValue[$row['locationid']] = json_decode($row['calendar']);
			} else {
				$remoteIds[$row['locationid']] = $row['serverlocationid'];
			}

		}
		// No need for additional round trips to backend
		if (empty($remoteIds)) {
			return $returnValue;
		}
		// Check if we should refresh other rooms recently requested by front ends
		if ($this->getRefreshTime() > $this->getCacheTime()) {
			$dbquery4 = Database::simpleQuery("SELECT locationid, serverlocationid FROM locationinfo_locationconfig
					WHERE serverid = :serverid AND serverlocationid NOT IN (:skiplist)
					AND lastcalendarupdate BETWEEN :lowerage AND :upperage
					LIMIT " . self::MAX_ADDIDIONAL_LOCATIONS, array(
						'serverid' => $this->serverId,
						'skiplist' => array_values($remoteIds),
						'lowerage' => $NOW - $this->getRefreshTime(),
						'upperage' => $NOW - $this->getCacheTime(),
			));
			while ($row = $dbquery4->fetch(PDO::FETCH_ASSOC)) {
				$remoteIds[$row['locationid']] = $row['serverlocationid'];
			}
		}
		$backendResponse = $this->fetchSchedulesInternal($remoteIds);
		if ($backendResponse === false) {
			return false;
		}

		if ($this->getCacheTime() > 0) {
			// Caching requested by backend, write to DB
			foreach ($backendResponse as $serverRoomId => $calendar) {
				$value = json_encode($calendar);
				Database::simpleQuery("UPDATE locationinfo_locationconfig SET calendar = :ttable, lastcalendarupdate = :now
					WHERE serverid = :serverid AND serverlocationid = :serverlocationid", array(
					'serverid' => $this->serverId,
					'serverlocationid' => $serverRoomId,
					'ttable' => $value,
					'now' => $NOW
				));
			}
		}
		// Add rooms that were requested to the final return value
		foreach ($remoteIds as $location => $serverRoomId) {
			if (isset($backendResponse[$serverRoomId]) && in_array($location, $requestedLocationIds)) {
				// Only add if we can map it back to our location id AND it was not an unsolicited coalesced refresh
				$returnValue[$location] = $backendResponse[$serverRoomId];
			}
		}

		return $returnValue;
	}

	public final function setCredentials($serverId, $data)
	{
		foreach ($this->getCredentialDefinitions() as $prop) {
			if (!isset($data[$prop->property])) {
				$data[$prop->property] = $prop->default;
			}
			if (in_array($prop->type, ['string', 'bool', 'int'])) {
				settype($data[$prop->property], $prop->type);
			} else {
				settype($data[$prop->property], 'string');
			}
		}
		if ($this->setCredentialsInternal($data)) {
			$this->serverId = $serverId;
			return true;
		}
		return false;
	}

	/**
	 * @return false if there was no error string with error message if there was one
	 */
	public final function getError()
	{
		return $this->error;
	}

	/**
	 * Query path in array-representation of XML document.
	 * e.g. 'path/syntax/foo/wanteditem'
	 * This works for intermediate nodes (that have more children)
	 * and leaf nodes. The result is always an array on success, or
	 * false if not found.
	 */
	protected function getArrayPath($array, $path)
	{
		if (!is_array($path)) {
			// Convert 'path/syntax/foo/wanteditem' to array for further processing and recursive calls
			$path = explode('/', $path);
		}
		if (isset($array[0])) {
			// The currently handled element of the path exists multiple times on the current level, so it is
			// wrapped in a plain array - recurse into each one of them and merge the results
			$return = [];
			foreach ($array as $item) {
				$test = $this->getArrayPath($item, $path);
				If (is_array($test)) {
					$return = array_merge($return, $test);
				}

			}
			return $return;
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
		// Non-leaf node - simple recursion
		return $this->getArrayPath($array[$element], $path);
	}

	/**
	 * @param string $response xml document to convert
	 * @return bool|array array representation of the xml if possible, false otherwise
	 */
	protected function xmlStringToArray($response)
	{
		$cleanresponse = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response);
		try {
			$xml = new SimpleXMLElement($cleanresponse);
		} catch (Exception $e) {
			$this->error = 'Could not parse reply as XML, got ' . get_class($e) . ': ' . $e->getMessage();
			return false;
		}
		$array = json_decode(json_encode((array)$xml), true);
		return $array;
	}

}

/**
 * Class BackendProperty describes a property a backend requires to define its functionality
 */
class BackendProperty {
	public $property;
	public $type;
	public $default;
	public function __construct($property, $type, $default = '')
	{
		$this->property = $property;
		$this->type = $type;
		$this->default = $default;
	}

	/**
	 * Initialize additional fields of this class that are only required
	 * for rendering the server configuration dialog.
	 *
	 * @param string $backendId target backend id
	 * @param mixed $current current value of this property.
	 */
	public function initForRender($current = null) {
		if (is_array($this->type)) {
			$this->template = 'dropdown';
			$this->select_list = [];
			foreach ($this->type as $item) {
				$this->select_list[] = [
					'option' => $item,
					'active' => $item == $current,
				];
			}
		} elseif ($this->type === 'bool') {
			$this->template = $this->type;
		} else {
			$this->template = 'generic';
		}
		if ($this->type === 'string') {
			$this->inputtype = 'text';
		} elseif ($this->type === 'int') {
			$this->inputtype = 'number';
		} elseif ($this->type === 'password') {
			$this->inputtype = Property::getPasswordFieldType();
		}
		$this->currentvalue = $current === null ? $this->default : $current;
	}
	public $inputtype;
	public $template;
	public $title;
	public $helptext;
	public $currentvalue;
	public $select_list;
	public $credentialsHtml;
}
