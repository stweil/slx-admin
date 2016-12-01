<?php


class StatisticReporting
{
	public static function getClientStatistics() {
		$res = Database::simpleQuery("SELECT t1.name, timeSum, avgTime, offlineSum, loginCount, lastLogout, lastStart FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'timeSum', AVG(CAST(statistic.data AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount', MAX(statistic.dateline + (statistic.data *1)) AS 'lastLogout'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~session-length' GROUP BY machine.machineuuid
												) t1 INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'offlineSum', MAX(statistic.dateline) AS 'lastStart'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~offline-length' GROUP BY machine.machineuuid
												) t2 ON t1.uuid = t2.uuid");
		return $res;
	}

	public static function getLocationStatistics() {
		$res = Database::simpleQuery("SELECT t1.ln, timeSum, avgTime, offlineSum, loginCount FROM (
													SELECT location.locationname AS 'ln', SUM(CAST(statistic.data AS UNSIGNED)) AS 'timeSum', AVG(CAST(statistic.data AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid 
																		INNER JOIN location ON machine.locationid = location.locationid 
													WHERE statistic.typeid = '~session-length' GROUP By location.locationname
												) t1 INNER JOIN (
											 		SELECT location.locationname AS 'ln', SUM(CAST(statistic.data AS UNSIGNED)) AS 'offlineSum'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid 
																		INNER JOIN location ON machine.locationid = location.locationid 
													WHERE statistic.typeid = '~offline-length' GROUP By location.locationname
												) t2 ON t1.ln = t2.ln");
		return $res;
	}

	public static function getUserStatistics() {
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY username ORDER BY 2 DESC");
		return $res;
	}

	public static function getVMStatistics() {
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY data ORDER BY 2 DESC");
		return $res;
	}

	public static function getOverallStatistics () {
		$res = Database::simpleQuery("SELECT SUM(CAST(data AS UNSIGNED)), AVG(CAST(data AS UNSIGNED)), COUNT(*) FROM statistic WHERE typeid = '~session-length'");
		return $res;
	}

	public static function getTotalOffline() {
		$res = Database::simpleQuery("SELECT SUM(CAST(data AS UNSIGNED)) FROM statistic WHERE typeid='~offline-length'");
		return $res;
	}






	public static function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}

}