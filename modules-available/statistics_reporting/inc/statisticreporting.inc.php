<?php


class StatisticReporting
{

	public static function getClientStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT t1.name, timeSum, avgTime, offlineSum, loginCount, lastLogout, lastStart FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', AVG(CAST(sessionTable.length AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount', MAX(sessionTable.dateline + sessionTable.data) AS 'lastLogout'
													FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound)." sessionTable
														INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid
													WHERE sessionTable.dateline>=$queryTime
													GROUP BY machine.machineuuid
												) t1 INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum', MAX(offlineTable.dateline) AS 'lastStart'
													FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid
													WHERE offlineTable.dateline>=$queryTime
													GROUP BY machine.machineuuid
												) t2 ON t1.uuid = t2.uuid");
		return $res;
	}

	public static function getLocationStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;

		$res = Database::simpleQuery("SELECT t1.locName, timeSum, avgTime, offlineSum, loginCount FROM (
													SELECT location.locationname AS 'locName', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', AVG(CAST(sessionTable.length AS UNSIGNED)) AS 'avgTime', COUNT(sessionTable.length) AS 'loginCount'
													FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound)." sessionTable
												   	INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid 
														INNER JOIN location ON machine.locationid = location.locationid 
													WHERE sessionTable.dateline >= $queryTime
													GROUP By location.locationname
												) t1 INNER JOIN (
											 		SELECT location.locationname AS 'locName', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum'
													FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid 
														INNER JOIN location ON machine.locationid = location.locationid 
													WHERE offlineTable.dateline >= $queryTime
													GROUP By location.locationname
												) t2 ON t1.locName = t2.locName");
		return $res;
	}

	// logins between bounds
	public static function getUserStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
												AND ((FROM_UNIXTIME(dateline+data, '%H')*1 >= $lowerTimeBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperTimeBound)) GROUP BY username ORDER BY 2 DESC");
		return $res;
	}

	// vm logins between bounds
	public static function getVMStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
												AND ((FROM_UNIXTIME(dateline+data, '%H')*1 >= $lowerTimeBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperTimeBound)) GROUP BY data ORDER BY 2 DESC");
		return $res;
	}

	// AND(betweenBounds OR <lower but goes into bound OR > upper but begins in bound					)
	public static function getOverallStatistics ($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT SUM(CAST(sessionTable.length AS UNSIGNED)), AVG(CAST(sessionTable.length AS UNSIGNED)), COUNT(*)
											 	FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound)." sessionTable
											 	WHERE sessionTable.dateline>=$queryTime");
		return $res;
	}

	public static function getTotalOfflineStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT SUM(CAST(offlineTable.length AS UNSIGNED))
												FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound)." offlineTable
												WHERE offlineTable.dateline>=$queryTime");
		return $res;
	}

	public static function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}


	private static function getBoundedTableQueryString($typeid, $lowerTimeBound, $upperTimeBound)
	{
		$lowerFormat = "'%y-%m-%d $lowerTimeBound:00:00'";
		$upperFormat = "'%y-%m-%d ".($upperTimeBound-1).":59:59'";
		$queryString = "
			select
			
			@startLower := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $lowerFormat)),
			@startUpper := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat)),
			@endLower := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat)),
			@endUpper := UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $upperFormat)),
			
			(CAST(data AS SIGNED) 
			- IF(
					dateline > @startUpper,
					UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $lowerFormat) + INTERVAL 1 DAY) - dateline,
					IF(
						 dateline < @startLower,
						 @startLower - dateline,
						 0
					)
			  )
			- IF(
					dateline+data > @endUpper,
					dateline+data - (@endUpper + 1),
					IF(
						 dateline+data < @endLower,
						 dateline+data - (UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $upperFormat) - INTERVAL 1 DAY) + 1),
						 0
					)
			  )
			- (    TO_DAYS(FROM_UNIXTIME(dateline+data, '%y-%m-%d')) - TO_DAYS(FROM_UNIXTIME(dateline, '%y-%m-%d'))
					 - 2
					 + IF(dateline <= @startUpper, 1, 0)
					 + IF(dateline+data >= @endLower, 1, 0)
			  ) * ((24 - ($upperTimeBound - $lowerTimeBound)) * 3600)
			  
			- IF(
					@leftBound := IF(dateline <= @startUpper, @startUpper, UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat) + INTERVAL 1 DAY))
					< @rightBound := IF(dateline+data >= @endLower, @endLower, UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat) - INTERVAL 1 DAY)),
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
			where typeid = '$typeid' and (
			(UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $lowerFormat)) <= dateline and dateline <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat))) or
			(UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat)) <= dateline+data and dateline+data <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $upperFormat)))
	 		)
		";
		return "(".$queryString.")";
	}


}

