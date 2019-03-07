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
	 * @var boolean|string legacy, do not use
	 */
	protected $error = false;
	/**
	 * @var array list of errors that occured, fill using addError()
	 */
	private $errors;
	/**
	 * @var int as internal serverId
	 */
	protected $serverId;
	/**
	 * @const int try to fetch this many locations from one backend if less are requested (for backends that benefit from request coalesc.)
	 */
	const TRY_NUM_LOCATIONS = 6;

	/**
	 * CourseBackend constructor.
	 */
	public final function __construct()
	{
		$this->errors = [];
	}

	protected final function addError($message, $fatal)
	{
		$this->errors[] = ['time' => time(), 'message' => $message, 'fatal' => $fatal];
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
			$className = 'CourseBackend_' . $out[1];
			if (!class_exists($className)) {
				trigger_error("Backend type source unit $file doesn't seem to define class $className", E_USER_ERROR);
			}
			if (!CONFIG_DEBUG && defined("$className::DEBUG") && constant("$className::DEBUG"))
				continue;
			self::$backendTypes[$out[1]] = true;
		}
	}

	/**
	 * Get all known backend types.
	 *
	 * @return array list of backends
	 */
	public static function getList()
	{
		self::loadDb();
		return array_keys(self::$backendTypes);
	}

	public static function exists($backendType)
	{
		self::loadDb();
		return isset(self::$backendTypes[$backendType]);
	}

	/**
	 * Get fresh instance of CourseBackend subclass for given backend type.
	 *
	 * @param string $backendType name of module type
	 * @return \CourseBackend|false module instance
	 */
	public static function getInstance($backendType)
	{
		self::loadDb();
		if (!isset(self::$backendTypes[$backendType])) {
			error_log('Unknown module type: ' . $backendType);
			return false;
		}
		if (!is_object(self::$backendTypes[$backendType])) {
			$class = "coursebackend_$backendType";
			self::$backendTypes[$backendType] = new $class;
		}
		return self::$backendTypes[$backendType];
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
	 * @param $roomIds array with remote IDs for wanted rooms
	 * @return array a recursive array that uses the roomID as key
	 * and has the schedule array as value. A schedule array contains an array in this format:
	 * ["start"=>'JJJJ-MM-DD"T"HH:MM:SS',"end"=>'JJJJ-MM-DD"T"HH:MM:SS',"title"=>string]
	 */
	protected abstract function fetchSchedulesInternal($roomId);

	private static function fixTime(&$start, &$end)
	{
		if (!preg_match('/^\d+-\d+-\d+T\d+:\d+:\d+$/', $start) || !preg_match('/^\d+-\d+-\d+T\d+:\d+:\d+$/', $end))
			return false;
		$start = strtotime($start);
		$end = strtotime($end);
		if ($start >= $end)
			return false;
		$start = date('Y-m-d\TH:i:s', $start);
		$end = date('Y-m-d\TH:i:s', $end);
		return true;
	}

	/**
	 * Method for fetching the schedule of the given rooms on a server.
	 *
	 * @param array $requestedLocationIds array of room ID to fetch
	 * @return array|bool array containing the timetables as value and roomid as key as result, or false on error
	 */
	public final function fetchSchedule($requestedLocationIds)
	{
		if (!is_array($requestedLocationIds)) {
			$this->addError('No array of roomids was given to fetchSchedule', false);
			return false;
		}
		if (empty($requestedLocationIds))
			return array();
		$requestedLocationIds = array_values($requestedLocationIds);
		$NOW = time();
		$dbquery1 = Database::simpleQuery("SELECT locationid, calendar, serverlocationid, lastcalendarupdate
				FROM locationinfo_locationconfig WHERE locationid IN (:locations)",
				array('locations' => $requestedLocationIds));
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
		// Mark requested locations as used
		Database::exec("UPDATE locationinfo_locationconfig SET lastuse = :now WHERE locationid IN (:locations)",
			['locations' => $requestedLocationIds, 'now' => $NOW]);
		// Check if we should refresh other rooms recently requested by front ends
		$extraLocs = self::TRY_NUM_LOCATIONS - count($remoteIds);
		if ($this->getRefreshTime() > $this->getCacheTime() && $extraLocs > 0) {
			$dbquery4 = Database::simpleQuery("SELECT locationid, serverlocationid FROM locationinfo_locationconfig
					WHERE serverid = :serverid AND serverlocationid NOT IN (:skiplist)
					AND lastcalendarupdate < :minage AND lastuse > :lastuse
					LIMIT $extraLocs", array(
						'serverid' => $this->serverId,
						'skiplist' => array_values($remoteIds),
						'lastuse' => $NOW - $this->getRefreshTime(),
						'minage' => $NOW - $this->getCacheTime(),
			));
			while ($row = $dbquery4->fetch(PDO::FETCH_ASSOC)) {
				$remoteIds[$row['locationid']] = $row['serverlocationid'];
			}
		}
		if ($this->getCacheTime() > 0) {
			// Update the last update timestamp of the ones we are working on, so they won't be queried in parallel
			// if another request comes in while we're in fetchSchedulesInternal. Currently done without locking the
			// table. I think it's unlikely enough that we still get a race during those three queries here, and even
			// if, nothing bad will happen...
			Database::exec("UPDATE locationinfo_locationconfig SET lastcalendarupdate = :time
						WHERE lastcalendarupdate < :time AND serverid = :serverid AND serverlocationid IN (:slocs)", [
							'time' => $NOW - $this->getCacheTime() / 2,
							'serverid' => $this->serverId,
							'slocs' => array_values($remoteIds),
			]);
		}
		$backendResponse = $this->fetchSchedulesInternal(array_unique($remoteIds));
		if ($backendResponse === false) {
			return false;
		}

		// Fetching might have taken a while, get current time again
		$NOW = time();
		foreach ($backendResponse as $serverRoomId => &$calendar) {
			$calendar = array_values($calendar);
			for ($i = 0; $i < count($calendar); ++$i) {
				if (empty($calendar[$i]['title'])) {
					$calendar[$i]['title'] = '-';
				}
				if (!self::fixTime($calendar[$i]['start'], $calendar[$i]['end'])) {
					error_log("Ignoring calendar entry '{$calendar[$i]['title']}' with bad time format");
					unset($calendar[$i]);
				}
			}
			$calendar = array_values($calendar);
			if ($this->getCacheTime() > 0) {
				// Caching requested by backend, write to DB
				$value = json_encode($calendar);
				Database::simpleQuery("UPDATE locationinfo_locationconfig SET calendar = :ttable, lastcalendarupdate = :now
						WHERE serverid = :serverid AND serverlocationid = :serverlocationid", array(
					'serverid' => $this->serverId,
					'serverlocationid' => $serverRoomId,
					'ttable' => $value,
					'now' => $NOW
				));
			}

			unset($calendar);
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
		trigger_error('getError() is legacy; use getErrors()');
		return $this->error;
	}

	/**
	 * @return array list of errors that occured during processing.
	 */
	public final function getErrors()
	{
		return $this->errors;
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
	protected function xmlStringToArray($response, &$error)
	{
		$cleanresponse = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $response);
		try {
			$xml = @new SimpleXMLElement($cleanresponse); // This spams before throwing exception
		} catch (Exception $e) {
			$error = get_class($e) . ': ' . $e->getMessage();
			if (CONFIG_DEBUG) {
				error_log($cleanresponse);
			}
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
		if ($current === null) {
			$current = $this->default;
		}
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
		$this->currentvalue = $current;
	}
	public $inputtype;
	public $template;
	public $title;
	public $helptext;
	public $currentvalue;
	public $select_list;
	public $credentialsHtml;
}
