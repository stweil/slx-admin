<?php


class StatisticReporting
{

	public static function getClientStatistics($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT t1.name, timeSum, avgTime, offlineSum, loginCount, lastLogout, lastStart FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'timeSum', AVG(CAST(statistic.data AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount', MAX(statistic.dateline + (statistic.data *1)) AS 'lastLogout'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~session-length' AND dateline>=$queryTime AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))
													GROUP BY machine.machineuuid
												) t1 INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'offlineSum', MAX(statistic.dateline) AS 'lastStart'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~offline-length' AND dateline>=$queryTime AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))
													GROUP BY machine.machineuuid
												) t2 ON t1.uuid = t2.uuid");
		return $res;
	}

	public static function getLocationStatistics($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT t1.locName, timeSum, avgTime, offlineSum, loginCount FROM (
													SELECT location.locationname AS 'locName', SUM(CAST(statistic.data AS UNSIGNED)) AS 'timeSum', AVG(CAST(statistic.data AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid 
																		INNER JOIN location ON machine.locationid = location.locationid 
													WHERE statistic.typeid = '~session-length' AND dateline>=$queryTime AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))
													GROUP By location.locationname
												) t1 INNER JOIN (
											 		SELECT location.locationname AS 'locName', SUM(CAST(statistic.data AS UNSIGNED)) AS 'offlineSum'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid 
																		INNER JOIN location ON machine.locationid = location.locationid 
													WHERE statistic.typeid = '~offline-length' AND dateline>=$queryTime AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))
													GROUP By location.locationname
												) t2 ON t1.locName = t2.locName");
		return $res;
	}

	public static function getUserStatistics($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
												AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound)) GROUP BY username ORDER BY 2 DESC");
		return $res;
	}

	public static function getVMStatistics($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' AND dateline>=$queryTime 
												AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound)) GROUP BY data ORDER BY 2 DESC");
		return $res;
	}

	public static function getOverallStatistics ($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT SUM(CAST(data AS UNSIGNED)), AVG(CAST(data AS UNSIGNED)), COUNT(*) FROM statistic WHERE typeid = '~session-length' AND dateline>=$queryTime
												AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))");
		return $res;
	}

	public static function getTotalOfflineStatistics($cutOffTimeInSeconds) {
		$queryTime = time() - $cutOffTimeInSeconds;
		// time bounds (8,22 means from 8 o clock to 22 o clock)
		$lowerBound = 8;
		$upperBound = 22;
		$res = Database::simpleQuery("SELECT SUM(CAST(data AS UNSIGNED)) FROM statistic WHERE typeid='~offline-length' AND dateline>=$queryTime
												AND ((FROM_UNIXTIME(dateline, '%H')*1 >= $lowerBound) AND (FROM_UNIXTIME(dateline, '%H')*1 < $upperBound))");
		return $res;
	}

	public static function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}

}