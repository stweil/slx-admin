<?php

class GetData
{
	public static $from;
	public static $to;
	public static $lowerTimeBound = 0;
	public static $upperTimeBound = 24;


	// total
	public static function total($anonymize = false) {
		// total time online, average time online, total  number of logins
		$res = Queries::getOverallStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array('time' =>  self::formatSeconds($row['sum']), 'medianTime' =>  self::formatSeconds(self::calcMedian($row['median'])), 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);

		//total time offline
		$res = Queries::getTotalOfflineStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array_merge($data, array('totalOfftime' => self::formatSeconds($row['timeOff'])));

		return $data;
	}

	// per location
	public static function perLocation($anonymize = false) {
		$res = Queries::getLocationStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$loc = $anonymize ? 'locHash' : 'locName';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = self::calcMedian(self::calcMedian($row['medianTime']));
			$data[] = array('location' => $row[$loc], 'time' => self::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
				'medianTime' => self::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => self::formatSeconds($row['offlineSum']), 'offlineTimeInSeconds' => $row['offlineSum'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);
		}
		return $data;
	}

	// per client
	public static function perClient($anonymize = false) {
		$res = Queries::getClientStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$name = $anonymize ? 'clientHash' : 'clientName';
		$loc = $anonymize ? 'locHash' : 'locName';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = self::calcMedian(self::calcMedian($row['medianTime']));
			$data[] = array('hostname' => $row[$name], 'time' => self::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
				'medianTime' => self::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => self::formatSeconds($row['offlineSum']), 'offlineTimeInSeconds' => $row['offlineSum'], 'lastStart' => date(DATE_RSS,$row['lastStart']), 'lastStartUnixtime' => $row['lastStart'],
				'lastLogout' => date(DATE_RSS,$row['lastLogout']), 'lastLogoutUnixtime' => $row['lastLogout'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions'], 'locationName' => $row[$loc]);
		}
		return $data;
	}

	// per user
	public static function perUser($anonymize = false) {
		$res = Queries::getUserStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$user = $anonymize ? 'userHash' : 'name';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('user' => $row['name'], 'sessions' => $row['count']);
		}
		return $data;
	}


	// per vm
	public static function perVM() {
		$res = Queries::getVMStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('vm' => $row['name'], 'sessions' => $row['count']);
		}
		return $data;
	}



	// Format $seconds into ".d .h .m .s" format (day, hour, minute, second)
	private static function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}

	// Calculate Median
	private static function calcMedian($string) {
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
}