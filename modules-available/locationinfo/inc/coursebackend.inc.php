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
         * initializes the class with url it needs to connect to.
         */
        public abstract function __contruct($url);

        
        /**
         * @returns array with parameter name as key and type as value
         */
        public abstract function getCredentials();
        
        /**
         * uses json to setCredentials, the json must follow the form given in
         * getCredentials
         * @returns void 
         */
        public abstract function setCredentials($json);

        /**
	 * @return int desired caching time of results, in seconds. 0 = no caching
	 */
	public abstract function getCacheTime();
        
        /**
         * @return int age after which ttables are no longer refreshed should be
         * greater then CacheTime
         */
        public abstract function getRefreshTime();

        /**
	 * Internal version of fetch, to be overridden by subclasses.
	 * @param $roomIds
	 * @return array a multidemensional array that uses the roomID as key
         * and has the sheduls as string in the value
	 */
	protected abstract function fetchSchedulesInternal($roomId);

	/**
	 * Method for fetching the schedule of the given room.
	 * @param int $roomId int of room ID to fetch
	 * @return string|bool some jsonstring as result, or false on error
	 */
	public final function fetchSchedule($roomID)
	{
            $dbquery1 = Database::simpleQuery("SELECT servertype, serverid, serverroomid, lastcalenderupdate FROM location_info WHERE locationid = :id", array('id' => $roomID));
            $dbd1=$dbquery1->fetch(PDO::FETCH_ASSOC);
            $serverID = $dbd1['serverid'];
            $sroomID = $dbd1['serverroomid'];
            $lastUpdate = $dbd1['lastcalenderupdate'];
            //Check if in cache
            if(strtotime($lastUpdate) > strtotime("-".$this->getCachedTime()."seconds") && $this->getCachedTime()>0) {
                $dbquery3 = Database::simpleQuery("SELECT calendar FROM location_info WHERE locationid = :id", array('id' => $sroomID));
                $dbd3=$dbquery3->fetch(PDO::FETCH_ASSOC);
                return $dbd3['callendar'];
            }
            //Check if we should refresh other rooms recently requested by front ends
            elseif ($this->getCachedTime()>0) {
                $dbquery4 = Database::simpleQuery("SELECT serverroomid, lastcalenderupdate FROM location_info WHERE serverid= :id", array('id' => $serverID));
                $roomIDs[] = $sroomID;
                foreach($dbquery4->fetchAll(PDO::FETCH_COLUMN) as $row){
                    if($row['lastcalenderupdate']<$this->getRefreshTime()){
                        $roomIDs[] = $row['serverroomid'];
                    }
                }
                $roomIDs = array_unique($roomIDs);
            }
            else {
                $roomIDs[] = $sroomID;
            }
            $dbquery2 = Database::simpleQuery("SELECT serverurl, credentials FROM `setting_location_info` WHERE serverid = :id", array('id' => $serverID));
            $dbd2=$dbquery2->fetch(PDO::FETCH_ASSOC);
            $this->setCredentials($dbd2['credentials']);
            $result = $this->getJsons($roomIDs);
            
            if($this->getCachedTime()>0){
                foreach ($result as $key => $value) {
                    $now = strtotime('Now');
                    $dbquery1 = Database::simpleQuery("UPDATE location_info SET calendar = :ttable, lastcalenderupdate = :now WHERE locationid = :id ", array('id' => $key,'ttable' => $result[$key],'now'=> $now));
                }
            }
            return $result[$roomID];
	}

}
