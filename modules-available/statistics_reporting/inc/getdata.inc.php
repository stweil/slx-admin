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
		$data = array('time' =>  $row['sum'], 'medianTime' =>  self::calcMedian($row['median']), 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);

		//total time offline
		$res = Queries::getTotalOfflineStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array_merge($data, array('totalOfftime' => $row['timeOff']));

		if (!$anonymize) {
			$data["time"] = self::formatSeconds($data["time"]);
			$data["medianTime"] = self::formatSeconds($data["time"]);
			$data["totalOfftime"] = self::formatSeconds($data["time"]);
		}

		return $data;
	}

	// per location
	public static function perLocation($anonymize = false) {
		$res = Queries::getLocationStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		if (!$anonymize) {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$median = self::calcMedian(self::calcMedian($row['medianTime']));
				$data[] = array('location' => $row['locName'], 'time' => self::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
					'medianTime' => self::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => self::formatSeconds($row['offlineSum']),
					'offlineTimeInSeconds' => $row['offlineSum'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);
			}
		} else {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$median = self::calcMedian(self::calcMedian($row['medianTime']));
				$data[] = array('location' => $row['locHash'], 'time' => $row['timeSum'], 'medianTime' => $median, 'offTime' => $row['offlineSum'],
									'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);
			}
		}
		return $data;
	}

	// per client
	public static function perClient($anonymize = false) {
		$res = Queries::getClientStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		if (!$anonymize) {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$median = self::calcMedian(self::calcMedian($row['medianTime']));
				$data[] = array('hostname' => $row['clientName'], 'time' => self::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
					'medianTime' => self::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => self::formatSeconds($row['offlineSum']), 'offlineTimeInSeconds' => $row['offlineSum'], 'lastStart' => date(DATE_RSS, $row['lastStart']), 'lastStartUnixtime' => $row['lastStart'],
					'lastLogout' => date(DATE_RSS, $row['lastLogout']), 'lastLogoutUnixtime' => $row['lastLogout'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions'], 'locationName' => $row['locName']);
			}
		} else {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$median = self::calcMedian(self::calcMedian($row['medianTime']));
				$data[] = array('hostname' => $row['clientHash'], 'time' => $row['timeSum'], 'medianTime' => $median, 'offTime' => $row['offlineSum'], 'lastStart' => $row['lastStart'],
					'lastLogout' => $row['lastLogout'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions'], 'locationName' => $row['locHash']);
			}
		}
		return $data;
	}

	// per user
	public static function perUser($anonymize = false) {
		$res = Queries::getUserStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$user = $anonymize ? 'userHash' : 'name';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('user' => $row[$user], 'sessions' => $row['count']);
		}
		return $data;
	}


	// per vm
	public static function perVM($anonymize = false) {
		$res = Queries::getVMStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$vm = $anonymize ? 'vmHash' : 'name';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('vm' => $row[$vm], 'sessions' => $row['count']);
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