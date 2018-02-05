<?php


class Queries
{

	private static function keepKeys(&$array, $list)
	{
		foreach (array_keys($array) as $key) {
			if (!in_array($key, $list)) {
				unset($array[$key]);
			}
		}
	}

	public static function getClientStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24)
	{
		$res = Database::simpleQuery("SELECT m.machineuuid, m.hostname, m.clientip,
				m.locationid, m.firstseen -- , m.lastboot, m.logintime, m.state
 				FROM machine m WHERE firstseen <= $to"); // " WHERE lastseen >= :from", compact('from'));
		$machines = self::getStats3($res, $from, $to, $lowerTimeBound, $upperTimeBound);
		foreach ($machines as &$machine) {
			$machine['medianSessionLength'] = self::calcMedian($machine['sessions']);
			unset($machine['sessions']);
			$machine['clientName'] = $machine['hostname'] ? $machine['hostname'] : $machine['clientip'];
		}
		return $machines;
	}

	public static function getLocationStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24)
	{
		$res = Database::simpleQuery("SELECT m.machineuuid, m.hostname, m.clientip,
				m.locationid, m.firstseen -- , m.lastboot, m.logintime, m.state
 				FROM machine m WHERE firstseen <= $to"); // " WHERE lastseen >= :from", compact('from'));
		$machines = self::getStats3($res, $from, $to, $lowerTimeBound, $upperTimeBound);
		$locations = [];
		$keys = ['locationid', 'totalTime', 'totalOffTime', 'totalSessionTime', 'totalStandbyTime', 'totalIdleTime', 'totalIdleTime', 'longSessions', 'shortSessions', 'sessions'];
		while ($machine = array_pop($machines)) {
			if (!isset($locations[$machine['locationid']])) {
				self::keepKeys($machine, $keys);
				$locations[$machine['locationid']] = $machine;
			} else {
				$l =& $locations[$machine['locationid']];
				$l['totalTime'] += $machine['totalTime'];
				$l['totalOffTime'] += $machine['totalOffTime'];
				$l['totalSessionTime'] += $machine['totalSessionTime'];
				$l['totalStandbyTime'] += $machine['totalStandbyTime'];
				$l['totalIdleTime'] += $machine['totalIdleTime'];
				$l['longSessions'] += $machine['longSessions'];
				$l['shortSessions'] += $machine['shortSessions'];
				$l['sessions'] = array_merge($l['sessions'], $machine['sessions']);
			}
		}
		foreach ($locations as &$location) {
			$location['medianSessionLength'] = self::calcMedian($location['sessions']);
			unset($location['sessions']);
		}
		return $locations;
	}

	public static function getOverallStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24)
	{
		$res = Database::simpleQuery("SELECT m.machineuuid, m.hostname, m.clientip,
				m.locationid, m.firstseen -- , m.lastboot, m.logintime, m.state
 				FROM machine m WHERE firstseen <= $to"); // " WHERE lastseen >= :from", compact('from'));
		$machines = self::getStats3($res, $from, $to, $lowerTimeBound, $upperTimeBound);
		$total = false;
		$keys = ['totalTime', 'totalOffTime', 'totalSessionTime', 'totalStandbyTime', 'totalIdleTime', 'totalIdleTime', 'longSessions', 'shortSessions', 'sessions'];
		while ($machine = array_pop($machines)) {
			if ($total === false) {
				self::keepKeys($machine, $keys);
				$total = $machine;
			} else {
				$total['totalTime'] += $machine['totalTime'];
				$total['totalOffTime'] += $machine['totalOffTime'];
				$total['totalSessionTime'] += $machine['totalSessionTime'];
				$total['totalStandbyTime'] += $machine['totalStandbyTime'];
				$total['totalIdleTime'] += $machine['totalIdleTime'];
				$total['longSessions'] += $machine['longSessions'];
				$total['shortSessions'] += $machine['shortSessions'];
				$total['sessions'] = array_merge($total['sessions'], $machine['sessions']);
			}
		}
		$total['medianSessionLength'] = self::calcMedian($total['sessions']);
		unset($total['sessions']);
		return $total;
	}

	/**
	 * @param \PDOStatement $res
	 * @param int $from
	 * @param int $to
	 * @param int $lowerTimeBound
	 * @param int $upperTimeBound
	 * @return array
	 */
	private static function getStats3($res, $from, $to, $lowerTimeBound, $upperTimeBound)
	{
		//$debug = false;
		if ($lowerTimeBound === 0 && $upperTimeBound === 24 || $upperTimeBound <= $lowerTimeBound) {
			$bounds = false;
		} else {
			$bounds = [$lowerTimeBound, $upperTimeBound];
		}
		$machines = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$row['firstseen'] = max($row['firstseen'], $from);
			$row += array(
				'totalTime' => self::timeDiff($row['firstseen'], $to, $bounds),
				'totalOffTime' => 0,
				'totalSessionTime' => 0,
				'totalStandbyTime' => 0,
				'sessions' => [],
				'lastStart' => 0,
				'lastLogout' => 0,
				'longSessions' => 0,
				'shortSessions' => 0,
				'active' => false,
			);
			$machines[$row['machineuuid']] = $row;
		}
		// Don't filter by typeid in the query, still faster by being able to use the machineuuid/dateline index and filtering later
		$last = $from - 86400; // Start 24h early to catch sessions already in progress
		$dups = [];
		// Fetch in batches of 1000 rows (for current 50 machines)
		do {
			$res = Database::simpleQuery("SELECT logid, dateline, typeid, machineuuid, data
					FROM statistic WHERE dateline >= :last AND dateline <= :to AND machineuuid IS NOT NULL
					ORDER BY dateline ASC LIMIT 1000", compact('last', 'to'));
			$last = false;
			$count = 0;
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$count += 1; // Update count first, as we use it as a condition in outer loop. No continue before this
				settype($row['logid'], 'int');
				// Update for next query
				$last = $row['dateline'];
				// Ignore dups, we query >= last dateline as we can have multiple events at the same second, but
				// only some of them got returned because of LIMIT. Skip here because excluding in query directly
				// would make the query itself rather inefficient. We also cannot use logid > X because the logid
				// is not strictly ascending with time, as dateline gets backdated to event start on insert
				if ($count === 150) {
					$dups = [];
				} elseif ($count > 900) {
					$dups[] = $row['logid'];
				} elseif ($count < 150 && array_key_exists($row['logid'], $dups)) {
					continue;
				}
				if (!isset($machines[$row['machineuuid']]))
					continue;
				if ($row['typeid'] !== '~offline-length' && $row['typeid'] !== '~suspend-length' && $row['typeid'] !== '~session-length')
					continue;
				settype($row['dateline'], 'int');
				settype($row['data'], 'int');
				if ($row['data'] <= 0)
					continue;
				// Clamp to $from and $to
				if ($row['dateline'] < $from) {
					$diff = $row['dateline'] + $row['data'] - $from;
					if ($diff <= 0)
						continue;
					$row['dateline'] += $diff;
					$row['data'] -= $diff;
				}
				if ($row['dateline'] + $row['data'] > $to) {
					$row['data'] = $to - $row['dateline'];
					if ($row['data'] < 0)
						continue;
				}
				$machine =& $machines[$row['machineuuid']];
				// Process event if applicable
				if ($row['typeid'] === '~session-length') { // SESSION timespan
					$row['typeid'] = 'totalSessionTime';
					$machine['lastLogout'] = $row['dateline'] + $row['data'];
				} elseif ($row['typeid'] === '~offline-length') { // OFFLINE timespan
					$row['typeid'] = 'totalOffTime';
					$machine['lastStart'] = $row['dateline'] + $row['data'];
				} else { // STANDBY timespan
					$row['typeid'] = 'totalStandbyTime';
				}
				self::addTime($machine, $row, $bounds);
			}
			$dups = array_flip($dups);
		} while ($last !== false && $count === 1000); // Check if we need to fetch more rows for current batch
		foreach ($machines as &$machine) {
			if (!$machine['active']) {
				$machine['totalOffTime'] = $machine['totalTime'];
			}
			$machine['totalIdleTime'] = $machine['totalTime'] - ($machine['totalOffTime'] + $machine['totalStandbyTime'] + $machine['totalSessionTime']);
		}
		return $machines;
	}

	private static function addTime(&$machine, $row, $bounds)
	{
		// First event, handle difference
		if (!$machine['active'] && $row['dateline'] + $row['data']  >= $machine['firstseen']) {
			if ($row['dateline'] > $machine['firstseen']) {
				$s = $machine['firstseen'];
				$e = $row['dateline'];
				/*
				if ($debug) {
					error_log('Initial offline time += ' . self::timeDiff($s, $e, $bounds, true));
				}
				*/
				$machine['totalOffTime'] += self::timeDiff($s, $e, $bounds);
				$machine['active'] = true;
				if ($machine['lastStart'] < $row['dateline']) {
					$machine['lastStart'] = $row['dateline'];
				}
			} else {
				// Not offline at beginning of period, do nothing
				$machine['active'] = true;
			}
		}
		// Current row
		if ($bounds === false) {
			// Simple case: No bounds
			$machine[$row['typeid']] += $row['data'];
		} else {
			$start = $row['dateline'];
			$end = $row['dateline'] + $row['data'];
			/*
			if ($debug) {
				error_log('Adding ' . $row['typeid'] . ' += ' . self::timeDiff($start, $end, $bounds, true));
			}
			*/
			$machine[$row['typeid']] += self::timeDiff($start, $end, $bounds);
			$sh = date('G', $start);
		}
		if ($row['typeid'] === 'totalSessionTime' && ($bounds === false || ($sh >= $bounds[0] && $sh < $bounds[1]))) {
			if ($row['data'] >= 60) {
				$machine['longSessions'] += 1;
				$machine['sessions'][] = $row['data'];
			} else {
				$machine['shortSessions'] += 1;
			}
		}
	}

	private static function timeDiff($start, $end, $bounds)
	{
		// Put given timespan into bounds
		/*
		if ($debug) {
			$os = $start;
			$oe = $end;
		}
		*/
		if ($bounds !== false) {
			// Put start time into bounds
			if ($start !== null) {
				$sh = date('G', $start);
				if ($sh < $bounds[0]) {
					$start = strtotime($bounds[0] . ':00:00', $start);
				} elseif ($sh >= $bounds[1]) {
					$start = strtotime($bounds[0] . ':00:00 +1day', $start);
				}
			}
			// Put end time into bounds
			if ($end !== null && $end > $start) {
				$eh = date('G', $end);
				if ($eh < $bounds[0]) {
					$end = strtotime($bounds[1] . ':00:00 -1day', $end);
				} elseif ($eh >= $bounds[1]) {
					$end = strtotime($bounds[1] . ':00:00', $end);
				}
			}
		}
		if ($end !== null && $start !== null && $end < $start) {
			$end = $start;
		}
		/*
		if ($debug) {
			if ($start >= $end) {
				error_log('END < START: ' . date('d.m.Y H:i:s', $start) . ' - ' . date('d.m.Y H:i:s', $end));
			} else {
				if ($os != $start) {
					error_log('Corrected start: ' . date('d.m.Y H:i:s', $os) . ' to ' . date('d.m.Y H:i:s', $start));
				}
				if ($oe != $end) {
					error_log('Corrected end  : ' . date('d.m.Y H:i:s', $oe) . ' to ' . date('d.m.Y H:i:s', $end));
				}
			}
		}
		*/
		// Calc time excluding out of range hours
		return ($end - $start) - self::getIgnoredTime($start, $end, $bounds);
	}

	private static function getIgnoredTime($start, $end, $bounds)
	{
		if ($bounds === false || $start >= $end)
			return 0;
		$end = strtotime('00:00:00', $end);
		if ($start >= $end)
			return 0;
		/*
		if ($debug) {
			error_log('From ' . date('d.m.Y H:i:s', $start) . ' to ' . date('d.m.Y H:i:s', $end) . ' = ' . ceil(($end - $start) / 86400) * (24 - ($bounds[1] - $bounds[0])));
		}
		*/
		return (int)ceil(($end - $start) / 86400) * (24 - ($bounds[1] - $bounds[0])) * 3600;
	}

	/**
	 * Get median of array.
	 * @param int[] list of values
	 * @return int The median
	 */
	private static function calcMedian($array) {
		if (empty($array))
			return 0;
		sort($array, SORT_NUMERIC);
		$count = count($array); //total numbers in array
		$middleval = (int)floor(($count-1) / 2); // find the middle value, or the lowest middle value
		if($count % 2 === 1) { // odd number, middle is the median
			return (int)$array[$middleval];
		}
		// even number, calculate avg of 2 medians
		$low = $array[$middleval];
		$high = $array[$middleval+1];
		return (int)round(($low+$high) / 2);
	}

	// User Data: Name, Name(anonymized), Number of Logins
	public static function getUserStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT username AS name, COUNT(*) AS 'count'
												FROM statistic 
												WHERE typeid='.vmchooser-session-name' AND dateline >= $from and dateline <= $to
													AND FROM_UNIXTIME(dateline, '%H') >= $lowerTimeBound AND FROM_UNIXTIME(dateline, '%H') < $upperTimeBound
												GROUP BY username");
		return $res;
	}

	// Virtual Machine Data: Name, Number of Usages
	public static function getVMStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT data AS name, COUNT(*) AS 'count'
											 	FROM statistic
												WHERE typeid='.vmchooser-session-name' AND dateline >= $from and dateline <= $to
													AND FROM_UNIXTIME(dateline, '%H') >= $lowerTimeBound AND FROM_UNIXTIME(dateline, '%H') < $upperTimeBound
												GROUP BY data");
		return $res;
	}

	public static function getDozmodStats($from, $to)
	{
		if (!Module::isAvailable('dozmod'))
			return array('disabled' => true);

		$return = array();
		$return['vms'] = Database::queryFirst("SELECT Count(*) AS `total`, Sum(If(createtime >= $from, 1, 0)) AS `new`,
			Sum(If(updatetime >= $from, 1, 0)) AS `updated`, Sum(If(latestversionid IS NOT NULL, 1, 0)) AS `valid`
			FROM sat.imagebase
			WHERE createtime <= $to");
		$return['lectures'] = Database::queryFirst("SELECT Count(*) AS `total`, Sum(If(createtime >= $from, 1, 0)) AS `new`,
			Sum(If(updatetime >= $from, 1, 0)) AS `updated`,
			Sum(If((($from BETWEEN starttime AND endtime) OR ($to BETWEEN starttime AND endtime)) AND isenabled <> 0, 1, 0)) AS `valid`
			FROM sat.lecture
			WHERE createtime <= $to");
		$return['users'] = Database::queryFirst("SELECT Count(*) AS `total`, Count(DISTINCT organizationid) AS `organizations`
			FROM sat.user
			WHERE lastlogin >= $from");
		return $return;
	}

	public static function getAggregatedMachineStats($from)
	{
		$return = array();
		$return['location'] = Database::queryAll("SELECT MD5(CONCAT(locationid, :salt)) AS `location`, Count(*) AS `count`
			FROM machine
			WHERE lastseen >= $from
			GROUP BY locationid",
			array('salt' => GetData::$salt));
		$prev = 0;
		$str = ' ';
		foreach (array(0.5, 1, 1.5, 2, 3, 4, 6, 8, 10, 12, 16, 20, 24, 28, 32, 40, 48, 64, 72, 80, 88, 96, 128, 192, 256) as $val) {
			$str .= 'WHEN mbram < ' . round(($val + $prev) * 512) . " THEN '" . $prev . "' ";
			$prev = $val;
		}
		$return['ram'] = Database::queryAll("SELECT CASE $str ELSE 'OVER 9000' END AS `gbram`, Count(*) AS `total`
			FROM machine
			WHERE lastseen >= $from
			GROUP BY gbram");
		foreach (array('cpumodel', 'systemmodel', 'realcores', 'kvmstate') as $key) {
			$return[$key] = Database::queryAll("SELECT $key, Count(*) AS `total`
				FROM machine
				WHERE lastseen >= $from
				GROUP BY $key");
		}
		return $return;
	}

	/**
	 * @param int $from start timestamp
	 * @param int $to end timestamp
	 * @return int count of user active in timespan
	 */
	public static function getUniqueUserCount($from, $to)
	{
		$res = Database::queryFirst("SELECT Count(DISTINCT username) as `total`
			FROM statistic
			WHERE (dateline BETWEEN $from AND $to) AND typeid = '.vmchooser-session-name'
			GROUP BY username");
		return (int)$res['total'];
	}

}

