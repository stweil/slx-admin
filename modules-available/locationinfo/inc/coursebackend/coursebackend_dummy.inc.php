<?php

class CourseBackend_Dummy extends CourseBackend
{
	private $pw;

	/**
	 * uses json to setCredentials, the json must follow the form given in
	 * getCredentials
	 *
	 * @param array $data with the credentials
	 * @param string $url address of the server
	 * @param int $serverId ID of the server
	 * @returns bool if the credentials were in the correct format
	 */
	public function setCredentialsInternal($json)
	{
		$x = $json;
		$this->pw = $x['password'];

		if ($this->pw === "mfg") {
			$this->error = false;
			return true;
		} else {
			$this->error = "USE mfg as password!";
			return false;
		}
	}

	/**
	 * @return boolean true if the connection works, false otherwise
	 */
	public function checkConnection()
	{
		if ($this->pw == "mfg") {
			$this->error = false;
			return true;
		} else {
			$this->error = "USE mfg as password!";
			return false;
		}
	}

	/**
	 * @returns array with parameter name as key and and an array with type, help text and mask  as value
	 */
	public function getCredentialDefinitions()
	{
		$options = ["opt1", "opt2", "opt3", "opt4", "opt5", "opt6", "opt7", "opt8"];
		return [
			new BackendProperty('username', 'string', 'default-user'),
			new BackendProperty('password', 'password'),
			new BackendProperty('integer', 'int', 7),
			new BackendProperty('option', $options),
			new BackendProperty('CheckTheBox', 'bool'),
			new BackendProperty('CB2t', 'bool', true)
		];
	}

	/**
	 * @return string return display name of backend
	 */
	public function getDisplayName()
	{
		return 'Dummy with array';
	}

	/**
	 * @return int desired caching time of results, in seconds. 0 = no caching
	 */
	public function getCacheTime()
	{
		return 0;
	}

	/**
	 * @return int age after which timetables are no longer refreshed should be
	 * greater then CacheTime
	 */
	public function getRefreshTime()
	{
		return 0;
	}

	/**
	 * Internal version of fetch, to be overridden by subclasses.
	 *
	 * @param $roomIds array with local ID as key and serverId as value
	 * @return array a recursive array that uses the roomID as key
	 * and has the schedule array as value. A shedule array contains an array in this format:
	 * ["start"=>'JJJJ-MM-DD HH:MM:SS',"end"=>'JJJJ-MM-DD HH:MM:SS',"title"=>string]
	 */
	public function fetchSchedulesInternal($roomId)
	{
		$a = array();
		foreach ($roomId as $id) {
			$x['id'] = $id;
			$calendar['title'] = "test exam";
			$calendar['start'] = "2017-3-08 13:00:00";
			$calendar['end'] = "2017-3-08 16:00:00";
			$calarray = array();
			$calarray[] = $calendar;
			$x['calendar'] = $calarray;
			$a[$id] = $calarray;
		}


		return $a;
	}

}

?>
