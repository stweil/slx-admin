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
	 * @return int desired caching time of results, in seconds. 0 = no caching
	 */
	public abstract function getCacheTime();

	/**
	 * Internal version of fetch, to be overridden by subclasses.
	 * @param $roomIds
	 * @return mixed
	 */
	protected abstract function fetchSchedulesInternal($roomIds);

	/**
	 * Method for fetching the schedules of the given rooms.
	 * @param array $roomIds Array of room IDs to fetch
	 * @return array|bool some multidimensional array of rooms as result, or false on error
	 */
	public final function fetchSchedules($roomIds)
	{
		// TODO: Check if in cache
		// TODO: Check if we should refresh other rooms recently requested by front ends but not included in $roomIds
		$aggregatedRoomIds = $roomIds; // + extra rooms
		// ...
		// If not in cache:
		$result = $this->fetchSchedulesInternal($aggregatedRoomIds);
		// TODO: Place in cache if necessary
		// TODO: Remove entries from result that were not in $roomsIds
		return $result;
	}

}
