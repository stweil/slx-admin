<?php


class Queries
{

	// Client Data: Name, Time Online, Median Time Online, Time Offline, last start, last logout, Last Time Booted, Number of Sessions > 60Sec, Number of Sessions < 60Sec, name of location, id of location (anonymized), machine uuid (anonymized)
	public static function getClientStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24, $excludeToday = false) {
		$notassigned = Dictionary::translate('notAssigned', true);
		Database::exec("SET SESSION group_concat_max_len = 1000000000");
		$res = Database::simpleQuery("SELECT t2.name AS clientName, timeSum, medianSessionLength, offlineSum, IFNULL(lastStart, 0) as lastStart, IFNULL(lastLogout, 0) as lastLogout, longSessions, shortSessions, t2.locId, t2.locName, MD5(CONCAT(t2.locId, :salt)) AS locHash, MD5(CONCAT(t2.uuid, :salt)) AS clientHash FROM (
													SELECT machine.machineuuid AS 'uuid', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianSessionLength', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions', MAX(sessionTable.endInBound) AS 'lastLogout'
													FROM ".self::getBoundedTableQueryString('~session-length', $from, $to, $lowerTimeBound, $upperTimeBound)." sessionTable
														RIGHT JOIN machine ON sessionTable.machineuuid = machine.machineuuid
													GROUP BY machine.machineuuid
												) 	t1 
												RIGHT JOIN (
													SELECT IF(machine.hostname = '', machine.clientip, machine.hostname) AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum', MAX(offlineTable.endInBound) AS 'lastStart', IFNULL(location.locationname, '$notassigned') AS 'locName', location.locationid AS 'locId'
													FROM ".self::getBoundedTableQueryString('~offline-length', $from, $to, $lowerTimeBound, $upperTimeBound)." offlineTable
														RIGHT JOIN machine ON offlineTable.machineuuid = machine.machineuuid
														LEFT JOIN location ON machine.locationid = location.locationid
													GROUP BY machine.machineuuid
												) 	t2 
												ON t1.uuid = t2.uuid", array("salt" => GetData::$salt));

		return $res;
	}

	// Location Data: Name, ID (anonymized), Time Online, Median Time Online, Time Offline, Number of Sessions > 60Sec, Number of Sessions < 60Sec
	public static function getLocationStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24, $excludeToday = false) {
		$notassigned = Dictionary::translate('notAssigned', true);
		Database::exec("SET SESSION group_concat_max_len = 1000000000");
		$res = Database::simpleQuery("SELECT t2.locId, t2.locName, MD5(CONCAT(t2.locId, :salt)) AS locHash, timeSum, medianSessionLength, offlineSum, longSessions, shortSessions FROM (
													SELECT location.locationid AS 'locId', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianSessionLength', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions'
													FROM ".self::getBoundedTableQueryString('~session-length', $from, $to, $lowerTimeBound, $upperTimeBound)." sessionTable
												   	RIGHT JOIN machine ON sessionTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY machine.locationid
												) 	t1 
												RIGHT JOIN (
													SELECT IFNULL(location.locationname, '$notassigned') AS 'locName', location.locationid AS 'locId', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum'
													FROM ".self::getBoundedTableQueryString('~offline-length', $from, $to, $lowerTimeBound, $upperTimeBound)." offlineTable
														RIGHT JOIN machine ON offlineTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY machine.locationid
												) 	t2 
												ON t1.locId = t2.locId", array("salt" => GetData::$salt));
		return $res;
	}

	// User Data: Name, Name(anonymized), Number of Logins
	public static function getUserStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT username AS name, IF(username = 'anonymous', 'anonymous', md5(CONCAT(username, :salt))) AS  userHash, COUNT(*) AS 'count'
												FROM statistic 
												WHERE typeid='.vmchooser-session-name' AND dateline >= $from and dateline <= $to
													AND FROM_UNIXTIME(dateline, '%H') >= $lowerTimeBound AND FROM_UNIXTIME(dateline, '%H') < $upperTimeBound
												GROUP BY username 
												ORDER BY 2 DESC", array("salt" => GetData::$salt));
		return $res;
	}

	// Virtual Machine Data: Name, Number of Usages
	public static function getVMStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT data AS name, MD5(CONCAT(data, :salt)) AS vmHash, COUNT(*) AS 'count'
											 	FROM statistic
												WHERE typeid='.vmchooser-session-name' AND dateline >= $from and dateline <= $to
													AND FROM_UNIXTIME(dateline, '%H') >= $lowerTimeBound AND FROM_UNIXTIME(dateline, '%H') < $upperTimeBound
												GROUP BY data 
												ORDER BY 2 DESC", array("salt" => GetData::$salt));
		return $res;
	}

	//Total Data: Time Online, Median Time Online, Number of Sessions > 60Sec, Number of Sessions < 60Sec
	public static function getOverallStatistics ($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		Database::exec("SET SESSION group_concat_max_len = 1000000000");
		$res = Database::simpleQuery("SELECT SUM(CAST(sessionTable.length AS UNSIGNED)) AS sum, GROUP_CONCAT(sessionTable.length) AS median, SUM(sessionTable.length >= 60) AS longSessions, SUM(sessionTable.length < 60) AS shortSessions
											 	FROM ".self::getBoundedTableQueryString('~session-length', $from, $to, $lowerTimeBound, $upperTimeBound)." sessionTable");
		return $res;
	}

	// Total Data(2): Time Offline
	public static function getTotalOfflineStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT SUM(CAST(offlineTable.length AS UNSIGNED)) AS timeOff
												FROM ".self::getBoundedTableQueryString('~offline-length', $from, $to, $lowerTimeBound, $upperTimeBound)." offlineTable");
		return $res;
	}

	// query string which provides table with time-cutoff and time-bounds
	private static function getBoundedTableQueryString($typeid, $from, $to, $lowerTimeBound, $upperTimeBound)
	{
		// get Clients that are currently oflfine (the offline time is not yet recorded in the statistic table)
		$union = $typeid == '~offline-length' ?
			"union
			 select 	CAST(IF(lastseen < $from, $from, lastseen) as UNSIGNED) as start, $to as end,
			 			'~offline-length' as typeid, machineuuid, 'machine' 
			 from machine where lastseen <= $to and UNIX_TIMESTAMP() - lastseen >= 600" : "";


		$lowerFormat = "'%y-%m-%d $lowerTimeBound:00:00'";
		$upperFormat = "'%y-%m-%d ".($upperTimeBound-1).":59:59'";
		$queryString = "
			select
			
			# The whole length of the session/offline time.
			(end-start
			
			# Now the time that is not within the daily time bounds is subtracted.
			# This includes the time before the first daily bound, the time after the last daily bound
			# and the time between the daily bounds (if a session/offline time spans multiple days)
			
			# Time before the first daily bound is subtracted.
			- IF(
					start > startUpper,
					UNIX_TIMESTAMP(FROM_UNIXTIME(start, $lowerFormat) + INTERVAL 1 DAY) - start,
					IF(
						 start < startLower,
						 startLower - start,
						 0
					)
			  )
			  
			# Time after the last daily bound is subtracted.
			- IF(
					end > endUpper,
					end - (endUpper + 1),
					IF(
						 end < endLower,
						 end - (UNIX_TIMESTAMP(FROM_UNIXTIME(end, $upperFormat) - INTERVAL 1 DAY) + 1),
						 0
					)
			  )
			  
			# Time between the daily bounds is subtracted.
			- (    daysDiff - 2
					 + IF(start <= startUpper, 1, 0)
					 + IF(end >= endLower, 1, 0)
			  ) * ((24 - ($upperTimeBound - $lowerTimeBound)) * 3600)
		
			# If the session crossed a clock change (to/from daylight saving time), the last subtraction may have subtracted
			# one hour too much or too little. This IF will correct this.
			- IF(
					innerStart < innerEnd,
					IF(
						timeDiff = 1 AND ($lowerTimeBound >= 2 OR $upperTimeBound <= 2),
						3600,
						IF(timeDiff = -1 AND ($lowerTimeBound >= 3 OR $upperTimeBound <= 3), -3600, 0)
					),
					0
			  )
			  
			) as 'length',
			
			IF(end < endUpper AND end > endLower AND end < $to, end, 0) as endInBound,
			
			machineuuid
			
			
			# These nested selects are necessary because some things need to be calculated before others.
			# (e.g. start is needed to calculate startLower)
			from (
				select 
					*,
					
					# timeDiff is the clock change between innerStart and innerEnd. ( 0 = no clock change)
					((CAST(date_format(from_unixtime(innerStart), '%H') as SIGNED) -
					CAST(date_format(convert_tz(from_unixtime(innerStart), @@session.time_zone, '+00:00'), '%H') as SIGNED) + 24) % 24
					-
					(CAST(date_format(from_unixtime(innerEnd), '%H') as SIGNED) -
					CAST(date_format(convert_tz(from_unixtime(innerEnd), @@session.time_zone, '+00:00'), '%H') as SIGNED) + 24) % 24) as timeDiff
				from (
					select 
						*,
						
						# innerStart and innerEnd are start and end but excluding the time before the first daily upper bound and after the last daily lower bound.
						CAST(IF(start <= startUpper, startUpper, UNIX_TIMESTAMP(FROM_UNIXTIME(start, $upperFormat) + INTERVAL 1 DAY)) as UNSIGNED) as innerStart,
						CAST(IF(end >= endLower, endLower, UNIX_TIMESTAMP(FROM_UNIXTIME(end, $lowerFormat) - INTERVAL 1 DAY)) as UNSIGNED) as innerEnd
					from (
						select 
							*,
							
							# daysDiff = how many different days the start and end are apart (0 = start and end on the same day)
							(TO_DAYS(FROM_UNIXTIME(end, '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(start, '%y-%m-%d'))) as daysDiff,
							
							# startLower = lower daily time bound on the starting day
							CAST(UNIX_TIMESTAMP(FROM_UNIXTIME(start, $lowerFormat)) as UNSIGNED) as startLower,
							# startUpper = upper daily time bound on the starting day
							CAST(UNIX_TIMESTAMP(FROM_UNIXTIME(start, $upperFormat)) as UNSIGNED) as startUpper,
							# endLower = lower daily time bound on the ending day
							CAST(UNIX_TIMESTAMP(FROM_UNIXTIME(end, $lowerFormat)) as UNSIGNED) as endLower,
							# endUpper = upper daily time bound on the ending day
							CAST(UNIX_TIMESTAMP(FROM_UNIXTIME(end, $upperFormat)) as UNSIGNED) as endUpper
						from (
							# Statistic logs (combined with currently offline machines if offline times are requested) .
							select 	CAST(IF(dateline < $from, $from, dateline) as UNSIGNED) as start,
										CAST(IF(dateline+data > $to, $to, dateline+data) as UNSIGNED) as end,
										typeid, machineuuid, 'statistic'
							from statistic where dateline+data >= $from and dateline <= $to and typeid = '$typeid' 
							$union
						) t
					) t
				) t
			) t
			
			
			# Filter out the session that are at least overlapping with the time bounds.
			where (
				(daysDiff = 0 and (start <= UNIX_TIMESTAMP(FROM_UNIXTIME(start, $upperFormat)) and end >= UNIX_TIMESTAMP(FROM_UNIXTIME(end, $lowerFormat))))
				or
				(daysDiff = 1 and (start <= UNIX_TIMESTAMP(FROM_UNIXTIME(start, $upperFormat)) or end >= UNIX_TIMESTAMP(FROM_UNIXTIME(end, $lowerFormat))))
				or
				daysDiff >= 2
	 		)
		";
		return "(".$queryString.")";
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

