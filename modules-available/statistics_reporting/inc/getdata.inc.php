<?php

define('GETDATA_ANONYMOUS', 1);
define('GETDATA_PRINTABLE', 2);

class GetData
{
	public static $from;
	public static $to;
	public static $lowerTimeBound = 0;
	public static $upperTimeBound = 24;
	public static $salt;

	// total
	public static function total($flags = 0) {
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		// total time online, average time online, total  number of logins
		$res = Queries::getOverallStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array('time' =>  $row['sum'], 'medianTime' =>  self::calcMedian($row['median']), 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);

		//total time offline
		$res = Queries::getTotalOfflineStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array_merge($data, array('totalOfftime' => $row['timeOff']));

		if ($printable) {
			$data["time_s"] = self::formatSeconds($data["time"]);
			$data["medianTime_s"] = self::formatSeconds($data["medianTime"]);
			$data["totalOfftime_s"] = self::formatSeconds($data["totalOfftime"]);
		}

		return $data;
	}

	// per location
	public static function perLocation($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		$res = Queries::getLocationStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = self::calcMedian(self::calcMedian($row['medianTime']));
			$entry = array(
				'location' => ($anonymize ? $row['locHash'] : $row['locName']),
				'time' => $row['timeSum'],
				'medianTime' => $median,
				'offTime' => $row['offlineSum'],
				'sessions' => $row['longSessions'],
				'shortSessions' => $row['shortSessions']
			);
			if ($printable) {
				$entry['time_s'] = self::formatSeconds($row['timeSum']);
				$entry['medianTime_s'] = self::formatSeconds($median);
				$entry['offTime_s'] = self::formatSeconds($row['offlineSum']);
			}
			$data[] = $entry;
		}
		return $data;
	}

	// per client
	public static function perClient($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		$res = Queries::getClientStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = self::calcMedian(self::calcMedian($row['medianTime']));
			$entry = array(
				'hostname' => ($anonymize ? $row['clientHash'] : $row['clientName']),
				'time' => $row['timeSum'],
				'medianTime' => $median,
				'offTime' => $row['offlineSum'],
				'lastStart' => $row['lastStart'],
				'lastLogout' => $row['lastLogout'],
				'sessions' => $row['longSessions'],
				'shortSessions' => $row['shortSessions'],
				'location' => ($anonymize ? $row['locHash'] : $row['locName']),
			);
			if ($printable) {
				$entry['time_s'] = self::formatSeconds($row['timeSum']);
				$entry['medianTime_s'] = self::formatSeconds($median);
				$entry['offTime_s'] = self::formatSeconds($row['offlineSum']);
				$entry['lastStart_s'] = date(DATE_RSS, $row['lastStart']);
				$entry['lastLogout_s'] = date(DATE_RSS, $row['lastLogout']);
			}
			$data[] = $entry;
		}
		return $data;
	}

	// per user
	public static function perUser($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$res = Queries::getUserStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		$user = $anonymize ? 'userHash' : 'name';
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data[] = array('user' => $row[$user], 'sessions' => $row['count']);
		}
		return $data;
	}


	// per vm
	public static function perVM($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
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
		return sprintf('%dd, %02d:%02d:%02d', $seconds / (3600*24), ($seconds % (3600*24)) / 3600, ($seconds%3600) / 60, $seconds%60);
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