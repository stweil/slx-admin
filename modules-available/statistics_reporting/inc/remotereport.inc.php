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
		$value = ($isEnabled === true || $isEnabled === 'on') ? 'on' : '';
		Property::set(self::ENABLED_ID, $value, 60 * 24 * 14);
	}

	/**
	 * Returns whether remote reporting is enabled or not.
	 *
	 * @return bool true if reporting is on, false if off
	 */
	public static function isReportingEnabled()
	{
		return Property::get(self::ENABLED_ID, false);
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
			self::updateNextReportingTimestamp();
			$ts = Property::get(self::NEXT_SUBMIT_ID, 0);
		} elseif ($ts < strtotime('last monday')) {
			// Too long ago, move forward to last monday
			$ts = strtotime('last monday');
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
	 * @param $from start timestamp
	 * @param $to end timestamp
	 * @return array wrapped up statistics, ready for reporting
	 */
	public static function generateReport($from, $to) {
		GetData::$from = $from;
		GetData::$to = $to;
		GetData::$salt = bin2hex(Util::randomBytes(20));
		$data = GetData::total(true);
		$data['perLocation'] = GetData::perLocation(true);
		$data['perClient'] = GetData::perClient(true);
		$data['perUser'] = GetData::perUser(true);
		$data['perVM'] = GetData::perVM(true);
		$data['tsFrom'] = $from;
		$data['tsTo'] = $to;
		return $data;
	}

}