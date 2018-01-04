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
	private static $TS_LIST = false;
	private static $SECS_LIST = false;

	private static function fillLocation(&$entry, $anonymize)
	{
		$locations = Location::getLocationsAssoc();
		if ($anonymize) {
			$entry['locationname'] = md5($entry['locationid'] . self::$salt);
		} elseif (isset($locations[$entry['locationid']])) {
			$entry['locationname'] = $locations[$entry['locationid']]['locationname'];
			$entry['parentlocations'] = array_reduce($locations[$entry['locationid']]['parents'], function ($carry, $item) {
				return $carry . sprintf("%04d", $item);
			}) . sprintf("%04d", $entry['locationid']);
		} else {
			$entry['locationname'] = Dictionary::translate('notAssigned', true);
		}
		if ($anonymize) {
			unset($entry['locationid']);
		}
	}

	private static function addPrintables(&$entry)
	{
		if (self::$SECS_LIST === false) {
			self::$SECS_LIST = ['totalTime', 'totalOffTime', 'totalIdleTime', 'totalSessionTime', 'totalStandbyTime', 'medianSessionLength'];
		}
		if (self::$TS_LIST === false) {
			self::$TS_LIST = ['lastStart', 'lastLogout'];
		}
		$perc = isset($entry['totalTime']) && $entry['totalTime'] > 0;
		foreach (self::$SECS_LIST as $k) {
			if (isset($entry[$k])) {
				$entry[$k . '_s'] = self::formatSeconds($entry[$k]);
				if ($perc && $k !== 'totalTime') {
					$entry[$k . '_p'] = round($entry[$k] / $entry['totalTime'] * 100);
				}
			}
		}
		foreach (self::$TS_LIST as $k) {
			if (isset($entry[$k])) {
				$entry[$k . '_s'] = Util::prettyTime($entry[$k]);
			}
		}
	}

	// total
	public static function total($flags = 0) {
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		// total time online, average time online, total  number of logins
		$data = Queries::getOverallStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);

		if ($printable) {
			self::addPrintables($data);
		}
		$data['uniqueUsers'] = Queries::getUniqueUserCount(self::$from, self::$to);

		return $data;
	}

	// per location
	public static function perLocation($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		$data = Queries::getLocationStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		foreach ($data as &$entry) {
			//self::nullToZero($entry);
			self::fillLocation($entry, $anonymize);
			if ($printable) {
				self::addPrintables($entry);
			}
		}
		return $data;
	}

	// per client
	public static function perClient($flags = 0, $new = false) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$printable = 0 !== ($flags & GETDATA_PRINTABLE);
		$data = Queries::getClientStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		foreach ($data as &$entry) {
			//self::nullToZero($entry);
			$entry['hostname'] = ($anonymize ? md5($entry['clientName'] . self::$salt) : $entry['clientName']);
			self::fillLocation($entry, $anonymize);
			if ($printable) {
				self::addPrintables($entry);
			}
		}
		return $data;
	}

	// per user
	public static function perUser($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$res = Queries::getUserStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($anonymize && $row['name'] !== 'anonymous') {
				$row['name'] = md5($row['name'] . self::$salt);
			}
			$data[] = array('user' => $row['name'], 'sessions' => $row['count']);
		}
		return $data;
	}


	// per vm
	public static function perVM($flags = 0) {
		$anonymize = 0 !== ($flags & GETDATA_ANONYMOUS);
		$res = Queries::getVMStatistics(self::$from, self::$to, self::$lowerTimeBound, self::$upperTimeBound);
		$data = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			self::nullToZero($row);
			if ($anonymize) {
				$row['name'] = md5($row['name'] . self::$salt);
			}
			$data[] = array('vm' => $row['name'], 'sessions' => $row['count']);
		}
		return $data;
	}

	private static function nullToZero(&$row)
	{
		foreach ($row as &$field) {
			if (is_null($field)) {
				$field = 0;
			}
		}
	}

	// Format $seconds into ".d .h .m .s" format (day, hour, minute, second)
	private static function formatSeconds($seconds)
	{
		return sprintf('%dd, %02d:%02d:%02d', $seconds / (3600*24), ($seconds % (3600*24)) / 3600, ($seconds%3600) / 60, $seconds%60);
	}

}