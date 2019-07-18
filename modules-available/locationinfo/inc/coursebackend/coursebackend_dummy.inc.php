<?php

class CourseBackend_Dummy extends CourseBackend
{
	private $pw;

	const DEBUG = true;

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
			return true;
		}
		$this->addError("USE mfg as password!", true);
		return false;
	}

	/**
	 * @return boolean true if the connection works, false otherwise
	 */
	public function checkConnection()
	{
		if ($this->pw == "mfg") {
			return true;
		}
		$this->addError("USE mfg as password!", true);
		return false;
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
	 * and has the schedule array as value. A schedule array contains an array in this format:
	 * ["start"=>'YYYY-MM-DD<T>HH:MM:SS',"end"=>'YYYY-MM-DD<T>HH:MM:SS',"title"=>string]
	 */
	public function fetchSchedulesInternal($roomId)
	{
		$a = array();
		foreach ($roomId as $id) {
			if ($id == 1) {
				$now = time();
				return array($id => array(
					array(
						'title' => 'Lange',
						'start' => date('Y-m-d', $now) . 'T0:00:00',
						'end' => date('Y-m-d', $now + 86400 * 3) . 'T0:00:00',
					)
				));
			}
			// Normal
			$x = array();
			$time = strtotime('last Monday');
			$end = strtotime('+14 days', $time);
			srand(crc32($id) ^ $time);
			$last = $time;
			do {
				do {
					$time += rand(4, 10) * 900;
					$h = date('G', $time);
				} while ($h < 7 || $h > 19);
				$today = strtotime('today', $time);
				if ($today !== $last) {
					srand(crc32($id) ^ $today);
					$last = $today;
				}
				$dur = rand(2,6) * 1800;
				$x[] = array(
					'title' => 'Test ' . rand(1000,9999),
					'start' => date('Y-m-d\TH:i:s', $time),
					'end' => date('Y-m-d\TH:i:s', $time + $dur),
				);
				$time += $dur;
			} while ($time < $end);
			$a[$id] = $x;
		}

		return $a;
	}

}
