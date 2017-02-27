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
         * uses json to setCredentials, the json must follow the form given in
         * getCredentials
         * @param json $json jsonarray with the credentials
         * @param string $url adress of the server
         * @param int $serverID ID of the server
         * @returns void 
         */
        public abstract function setCredentials($json, $url, $serverID);

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
	 * Method for fetching the schedule of the given rooms on a server.
	 * @param int $roomId int of room ID to fetch
         * @param int $serverid id of the server
	 * @return string|bool some jsonstring as result, or false on error
	 */
	public final function fetchSchedule($roomIDs)
	{
            $sqlr=implode(",", $roomIDs);
            $sqlr = '('.$sqlr.')';
            $dbquery1 = Database::simpleQuery("SELECT locationid, calendar, serverroomid, lastcalenderupdate FROM location_info WHERE locationid In :ids", array('ids' => $sqlr));
            foreach ($dbquery1->fetchAll(PDO::FETCH_ASSOC) as $row){
                $sroomID = $row['serverroomid'];
                $lastUpdate = $row['lastcalenderupdate'];
                $calendar = $row['calendar'];
                //Check if in cache if lastUpdate is null then it is interpreted as 1970
                if(strtotime($lastUpdate) > strtotime("-".$this->getCacheTime()."seconds") && $this->getCacheTime()>0) {
                    $result[$row['locationid']]=$calendar;
                } 
                else {
                    $sroomIDs[$row['locationid']] = $sroomID;
                }
                
            }
            //Check if we should refresh other rooms recently requested by front ends
            if ($this->getCacheTime()>0&&$this->RefreshTime()>0) {
                    $dbquery4 = Database::simpleQuery("SELECT locationid ,serverroomid, lastcalenderupdate FROM location_info WHERE serverid= :id", array('id' => $this->serverID));
                    foreach($dbquery4->fetchAll(PDO::FETCH_COLUMN) as $row){
                        if(strtotime($row['lastcalenderupdate'])>strtotime("-".$this->getRefreshTime()."seconds")&&strtotime($row['lastcalenderupdate'])> strtotime("-".$this->getCacheTime()."seconds")){
                            $sroomIDs[$row['locationid']] = $row['serverroomid'];
                            }
                    }
            }
            $results = $this->fetchSchedulesInternal($sroomIDs);
            foreach ($sroomIDs as $location => $serverroom){
                $newresult[$location] = $results[$serverroom];
            }
            
            if($this->getCacheTime()>0){
                foreach ($newresult as $key => $value) {
                    $now = strtotime('Now');
                    $dbquery1 = Database::simpleQuery("UPDATE location_info SET calendar = :ttable, lastcalenderupdate = :now WHERE locationid = :id ", array('id' => $key,'ttable' => $value,'now'=> $now));
                }
            }
            //get all sheduls that are wanted from roomIDs
            foreach($roomIDs as $id){
                $result[$id] = $newresult[$id];
            }
            return $result[$roomID];
	}
        
        /**
         * @return false if there was no error string with error message if there was one
         */
        public final function getError(){
            if($this->error){
                return $this->errormsg;
            }
            return false;
        }

}
