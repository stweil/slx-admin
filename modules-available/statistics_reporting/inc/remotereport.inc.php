<?php

class RemoteReport
{

	const ENABLED_ID = 'statistics-reporting-enabled';
	const NEXT_SUBMIT_ID = 'statistics-reporting-next';

	/**
	 * Enable or disable remote reporting of usage statistics.
	 *
	 * @param bool|string $isEnabled true or 'on' if reporting should be enabled
	 */
	public static function setReportingEnabled($isEnabled)
	{
		$value = ($isEnabled === true || $isEnabled === 'on') ? 'on' : 'off';
		Property::set(self::ENABLED_ID, $value);
	}

	/**
	 * Returns whether remote reporting is enabled or not.
	 * Defaults to on.
	 *
	 * @return bool true if reporting is on, false if off
	 */
	public static function isReportingEnabled()
	{
		return Property::get(self::ENABLED_ID, 'on') === 'on';
	}

	/**
	 * Get the timestamp of the end of the next 7 day interval to
	 * report statistics for. Usually if this is < time() you want
	 * to generate the report.
	 *
	 * @return int timestamp of the end of the reporting time frame
	 */
	public static function getReportingTimestamp()
	{
		$ts = Property::get(self::NEXT_SUBMIT_ID, 0);
		if ($ts === 0) {
			// No timestamp stored yet - might be a fresh install
			// schedule for next time
			self::writeNextReportingTimestamp();
			$ts = Property::get(self::NEXT_SUBMIT_ID, 0);
		} elseif ($ts < strtotime('last monday - 14 days')) {
			// Too long ago, move forward
			$ts = strtotime('last monday - 14 days');
		}
		return $ts;
	}

	/**
	 * Update the timestamp of the next scheduled statistics report.
	 * This sets the end of the next 7 day interval to the start of
	 * next monday (00:00).
	 */
	public static function writeNextReportingTimestamp()
	{
		Property::set(self::NEXT_SUBMIT_ID, strtotime('next monday'), 60 * 24 * 14);
	}

	/**
	 * Generate the multi-dimensional array containing the anonymized
	 * (weekly) statistics to report.
	 *
	 * @param int $to end timestamp
	 * @param int[] $days list of days to generate aggregated stats for
	 * @return array wrapped up statistics, ready for reporting
	 */
	public static function generateReport($to, $days = false) {
		if ($days === false) {
			$days = [7, 30, 90];
		}
		GetData::$salt = bin2hex(Util::randomBytes(20, false));
		GetData::$lowerTimeBound = 7;
		GetData::$upperTimeBound = 20;
		$result = array();
		foreach ($days as $day) {
			if (isset($result['days' . $day]))
				continue;
			$from = strtotime("-{$day} days", $to);
			GetData::$from = $from;
			GetData::$to = $to;
			$data = array('total' => GetData::total(GETDATA_ANONYMOUS));
			$data['perLocation'] = GetData::perLocation(GETDATA_ANONYMOUS);
			$data['perVM'] = GetData::perVM(GETDATA_ANONYMOUS);
			$data['tsFrom'] = $from;
			$data['tsTo'] = $to;
			$data['dozmod'] = Queries::getDozmodStats($from, $to);
			$data['machines'] = Queries::getAggregatedMachineStats($from);
			$result['days' . $day] = $data;
		}
		$result['server'] = self::getLocalHardware();
		$result['version'] = CONFIG_FOOTER;
		return $result;
	}

	private function getLocalHardware()
	{
		$cpuInfo = file_get_contents('/proc/cpuinfo');
		$uptime = file_get_contents('/proc/uptime');
		$memInfo = file_get_contents('/proc/meminfo');
		preg_match_all('/\b(\w+):\s+(\d+)\s/s', $memInfo, $out, PREG_SET_ORDER);
		$mem = array();
		foreach ($out as $e) {
			$mem[$e[1]] = $e[2];
		}
		//
		$data = array();
		$data['cpuCount'] = preg_match_all('/\bprocessor\s+:\s+(.*)$/m', $cpuInfo, $out);
		if ($data['cpuCount'] > 0) {
			$data['cpuModel'] = $out[1][0];
		}
		if (preg_match('/^(\d+)\D/', $uptime, $out)) {
			$data['uptime'] = $out[1];
		}
		if (isset($mem['MemTotal']) && isset($mem['MemFree']) && isset($mem['SwapTotal'])) {
			$data['memTotal'] = $mem['MemTotal'];
			$data['memFree'] = ($mem['MemFree'] + $mem['Buffers'] + $mem['Cached']);
			$data['swapTotal'] = $mem['SwapTotal'];
			$data['swapUsed'] = ($mem['SwapTotal'] - $mem['SwapFree']);
		}
		return $data;
	}

}