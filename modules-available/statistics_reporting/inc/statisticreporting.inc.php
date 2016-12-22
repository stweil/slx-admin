<?php


class StatisticReporting
{

	public static function getClientStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT t1.name, timeSum, medianTime, offlineSum, longSessions, lastLogout, lastStart, shortSessions FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianTime', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions',MAX(sessionTable.dateline + sessionTable.data) AS 'lastLogout'
													FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound, $queryTime)." sessionTable
														INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid
													GROUP BY machine.machineuuid
												) 	t1 
												INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum', MAX(offlineTable.dateline) AS 'lastStart'
													FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound, $queryTime)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid
													GROUP BY machine.machineuuid
												) 	t2 
												ON t1.uuid = t2.uuid");
		return $res;
	}

	// IFNULL(location.locationname, '') - emptry string can be replaced with anything (name of the null-ids in the table)
	public static function getLocationStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;

		$res = Database::simpleQuery("SELECT t1.locName, timeSum, medianTime, offlineSum, longSessions, shortSessions FROM (
													SELECT IFNULL(location.locationname, '') AS 'locName', SUM(CAST(sessionTable.length AS UNSIGNED)) AS 'timeSum', GROUP_CONCAT(sessionTable.length) AS 'medianTime', SUM(sessionTable.length >= 60) AS 'longSessions', SUM(sessionTable.length < 60) AS 'shortSessions'
													FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound, $queryTime)." sessionTable
												   	INNER JOIN machine ON sessionTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY location.locationname
												) 	t1 
												INNER JOIN (
											 		SELECT IFNULL(location.locationname, '') AS 'locName', SUM(CAST(offlineTable.length AS UNSIGNED)) AS 'offlineSum'
													FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound, $queryTime)." offlineTable
														INNER JOIN machine ON offlineTable.machineuuid = machine.machineuuid 
														LEFT JOIN location ON machine.locationid = location.locationid 
													GROUP BY location.locationname
												) 	t2 
												ON t1.locName = t2.locName");
		return $res;
	}

	// logins between bounds
	public static function getUserStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' 
												FROM statistic 
												WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
														AND ((FROM_UNIXTIME(dateline+data, '%H')*1 >= $lowerTimeBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperTimeBound))
												GROUP BY username ORDER BY 2 DESC");
		return $res;
	}

	// vm logins between bounds
	public static function getVMStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
												AND ((FROM_UNIXTIME(dateline+data, '%H')*1 >= $lowerTimeBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperTimeBound)) GROUP BY data ORDER BY 2 DESC");
		return $res;
	}

	public static function getOverallStatistics ($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT SUM(CAST(sessionTable.length AS UNSIGNED)) AS sum, GROUP_CONCAT(sessionTable.length) AS median, SUM(sessionTable.length >= 60) AS longSessions, SUM(sessionTable.length < 60) AS shortSessions
											 		FROM ".self::getBoundedTableQueryString('~session-length', $lowerTimeBound, $upperTimeBound, $queryTime)." sessionTable");
		return $res;
	}

	public static function getTotalOfflineStatistics($cutOffTimeInSeconds, $lowerTimeBound = 0, $upperTimeBound = 24) {
		$queryTime = time() - $cutOffTimeInSeconds;
		$res = Database::simpleQuery("SELECT SUM(CAST(offlineTable.length AS UNSIGNED))
												FROM ".self::getBoundedTableQueryString('~offline-length', $lowerTimeBound, $upperTimeBound, $queryTime)." offlineTable");
		return $res;
	}

	public static function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}

	public static function calcMedian($string) {
		$arr = explode(",", $string);
		sort($arr, SORT_NUMERIC);
		$count = count($arr); //total numbers in array
		$middleval = floor(($count-1)/2); // find the middle value, or the lowest middle value
		if($count % 2) { // odd number, middle is the median
			$median = $arr[(int) $middleval];
		} else { // even number, calculate avg of 2 medians
			$low = $arr[(int) $middleval];
			$high = $arr[(int) $middleval+1];
			$median = (($low+$high)/2);
		}
		return round($median);
	}

	private static function getBoundedTableQueryString($typeid, $lowerTimeBound, $upperTimeBound, $cutoffTime)
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
			where dateline >= $cutoffTime and typeid = '$typeid' and (
			(UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $lowerFormat)) <= dateline and dateline <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline, $upperFormat))) or
			(UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $lowerFormat)) <= dateline+data and dateline+data <= UNIX_TIMESTAMP(FROM_UNIXTIME(dateline+data, $upperFormat)))
	 		)
		";
		return "(".$queryString.")";
	}
}

