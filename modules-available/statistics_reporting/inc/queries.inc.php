<?php


class Queries
{

	// Client Data: Name, Time Online, Median Time Online, Time Offline, last start, last logout, Last Time Booted, Number of Sessions > 60Sec, Number of Sessions < 60Sec, name of location, id of location (anonymized), machine uuid (anonymized)
	public static function getClientStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24, $excludeToday = false) {
		$notassigned = Dictionary::translateFile('template-tags', 'lang_notassigned');
		$res = Database::simpleQuery("SELECT t1.name AS clientName, timeSum, medianTime, offlineSum, lastStart, lastLogout, longSessions, shortSessions, locName, MD5(CONCAT(locId, :salt)) AS locHash, MD5(CONCAT(t1.uuid, :salt)) AS clientHash FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianTime', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions',MAX(sessionTable.dateline + sessionTable.data) AS 'lastLogout', IFNULL(location.locationname, '$notassigned') AS 'locName', location.locationid AS 'locId'
													FROM ".self::getBoundedTableQueryString('~session-length', $from, $to, $lowerTimeBound, $upperTimeBound)." sessionTable
														INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid
														LEFT JOIN location ON machine.locationid = location.locationid
													GROUP BY machine.machineuuid
												) 	t1 
												INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum', MAX(offlineTable.dateline) AS 'lastStart'
													FROM ".self::getBoundedTableQueryString('~offline-length', $from, $to, $lowerTimeBound, $upperTimeBound)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid
													GROUP BY machine.machineuuid
												) 	t2 
												ON t1.uuid = t2.uuid", array("salt" => GetData::$salt));

		return $res;
	}

	// Location Data: Name, ID (anonymized), Time Online, Median Time Online, Time Offline, Number of Sessions > 60Sec, Number of Sessions < 60Sec
	public static function getLocationStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24, $excludeToday = false) {
		$notassigned = Dictionary::translateFile('template-tags', 'lang_notassigned');
		$res = Database::simpleQuery("SELECT t1.locName AS locName, MD5(CONCAT(locId, :salt)) AS locHash, timeSum, medianTime, offlineSum, longSessions, shortSessions FROM (
													SELECT IFNULL(location.locationname, '$notassigned') AS 'locName', location.locationid AS 'locId', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianTime', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions'
													FROM ".self::getBoundedTableQueryString('~session-length', $from, $to, $lowerTimeBound, $upperTimeBound)." sessionTable
												   	INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY location.locationname
												) 	t1 
												INNER JOIN (
											 		SELECT IFNULL(location.locationname, '$notassigned') AS 'locName', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum'
													FROM ".self::getBoundedTableQueryString('~offline-length', $from, $to, $lowerTimeBound, $upperTimeBound)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY location.locationname
												) 	t2 
												ON t1.locName = t2.locName", array("salt" => GetData::$salt));
		return $res;
	}

	// User Data: Name, Name(anonymized), Number of Logins
	public static function getUserStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT username AS name, IF(username = 'anonymous', 'anonymous', md5(CONCAT(username, :salt))) AS  userHash, COUNT(*) AS 'count'
												FROM statistic 
												WHERE typeid='.vmchooser-session-name' AND dateline+data >= $from and dateline <= $to AND (
														(@daysDiff := (TO_DAYS(FROM_UNIXTIME(@end := IF(dateline+data > $to, $to, dateline+data), '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(@start := IF(dateline < $from, $from, dateline), '%y-%m-%d'))) = 0
																			and (FROM_UNIXTIME(@end, '%H') >= $lowerTimeBound) AND (FROM_UNIXTIME(@start, '%H') < $upperTimeBound))
														or
														(@daysDiff = 1 and (FROM_UNIXTIME(@end, '%H') >= $lowerTimeBound) OR (FROM_UNIXTIME(@start, '%H') < $upperTimeBound))
														or
														@daysDiff >= 2
												)
												GROUP BY username 
												ORDER BY 2 DESC", array("salt" => GetData::$salt));
		return $res;
	}

	// Virtual Machine Data: Name, Number of Usages
	public static function getVMStatistics($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$res = Database::simpleQuery("SELECT data AS name, MD5(CONCAT(data, :salt)) AS vmHash, COUNT(*) AS 'count'
											 	FROM statistic
												WHERE typeid='.vmchooser-session-name' AND dateline+data >= $from and dateline <= $to AND (
														(@daysDiff := (TO_DAYS(FROM_UNIXTIME(@end := IF(dateline+data > $to, $to, dateline+data), '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(@start := IF(dateline < $from, $from, dateline), '%y-%m-%d'))) = 0
																			and (FROM_UNIXTIME(@end, '%H') >= $lowerTimeBound) AND (FROM_UNIXTIME(@start, '%H') < $upperTimeBound))
														or
														(@daysDiff = 1 and (FROM_UNIXTIME(@end, '%H') >= $lowerTimeBound) OR (FROM_UNIXTIME(@start, '%H') < $upperTimeBound))
														or
														@daysDiff >= 2
												)
												GROUP BY data 
												ORDER BY 2 DESC", array("salt" => GetData::$salt));
		return $res;
	}

	//Total Data: Time Online, Median Time Online, Number of Sessions > 60Sec, Number of Sessions < 60Sec
	public static function getOverallStatistics ($from, $to, $lowerTimeBound = 0, $upperTimeBound = 24) {
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
		$lowerFormat = "'%y-%m-%d $lowerTimeBound:00:00'";
		$upperFormat = "'%y-%m-%d ".($upperTimeBound-1).":59:59'";
		$queryString = "
			select

			@start := IF(dateline < $from, $from, dateline),
			@end := IF(dateline+data > $to, $to, dateline+data),
			@startLower := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $lowerFormat)),
			@startUpper := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat)),
			@endLower := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat)),
			@endUpper := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $upperFormat)),
			
			(CAST(@end-@start AS SIGNED)
			- IF(
					@start > @startUpper,
					UNIX_TIMESTAMP(FROM_UNIXTIME(@start, $lowerFormat) + INTERVAL 1 DAY) - @start,
					IF(
						 @start < @startLower,
						 @startLower - @start,
						 0
					)
			  )
			- IF(
					@end > @endUpper,
					@end - (@endUpper + 1),
					IF(
						 @end < @endLower,
						 @end - (UNIX_TIMESTAMP(FROM_UNIXTIME(@end, $upperFormat) - INTERVAL 1 DAY) + 1),
						 0
					)
			  )
			- (    TO_DAYS(FROM_UNIXTIME(@end, '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(@start, '%y-%m-%d'))
			 		 - 2
					 + IF(@start <= @startUpper, 1, 0)
					 + IF(@end >= @endLower, 1, 0)
			  ) * ((24 - ($upperTimeBound - $lowerTimeBound)) * 3600)
			  
			- IF(
					@leftBound := IF(@start <= @startUpper, @startUpper, UNIX_TIMESTAMP(FROM_UNIXTIME(@start, $upperFormat) + INTERVAL 1 DAY))
					< @rightBound := IF(@end >= @endLower, @endLower, UNIX_TIMESTAMP(FROM_UNIXTIME(@end, $lowerFormat) - INTERVAL 1 DAY)),
					IF(
						@timeDiff := (
						(date_format(from_unixtime(@leftBound), '%H') -
						date_format(convert_tz(from_unixtime(@leftBound), @@session.time_zone, '+00:00'), '%H') + 24) % 24
						-
						(date_format(from_unixtime(@rightBound), '%H') -
						date_format(convert_tz(from_unixtime(@rightBound), @@session.time_zone, '+00:00'), '%H') + 24) % 24) = 1
						AND ($lowerTimeBound >= 2 OR $upperTimeBound <= 2),
						3600,
						IF(@timeDiff = -1 AND ($lowerTimeBound >= 3 OR $upperTimeBound <= 3), -3600, 0)
					),
					0
			  )
			) as 'length',
			dateline,
			data,
			machineuuid
			
			from statistic
			where dateline+data >= $from and dateline <= $to and typeid = '$typeid' 
			and (
				(@daysDiff := (TO_DAYS(FROM_UNIXTIME(dateline+data, '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(dateline, '%y-%m-%d'))) = 0 and (dateline <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat)) and dateline+data >= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat))))
				or
				(@daysDiff = 1 and (dateline <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat)) or dateline+data >= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat))))
				or
				@daysDiff >= 2
	 		)
		";
		return "(".$queryString.")";
	}
}

