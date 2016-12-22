<?php


class Page_Statistics_Reporting extends Page
{

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		// timespan you want to see = Days selected * seconds per Day (86400)
		// default = 14 days
		$cutOff = Request::get('cutoff', 14, 'int');
		$cutOffTimer = $cutOff * 86400;

		$lowerTimeBound = Request::get('lower', 0, 'int');

		$upperTimeBound = Request::get('upper', 24, 'int');


		// total time online, average time online, total  number of logins
		$res = StatisticReporting::getOverallStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array('time' =>  StatisticReporting::formatSeconds($row[0]), 'medianTime' =>  StatisticReporting::formatSeconds(StatisticReporting::calcMedian($row[1])), 'sessions' => $row[2], 'shortSessions' => $row[3]);

		//total time offline
		$res = StatisticReporting::getTotalOfflineStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array_merge($data, array('totalOfftime' => StatisticReporting::formatSeconds($row[0])));

		// per location
		$res = StatisticReporting::getLocationStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perLocation' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$median = StatisticReporting::calcMedian(StatisticReporting::calcMedian($row[2]));
			$data['perLocation'][] = array('location' => $row[0], 'time' => StatisticReporting::formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'medianTime' => StatisticReporting::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => StatisticReporting::formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'sessions' => $row[4], 'shortSessions' => $row[5]);
		}

		// per client
		$res = StatisticReporting::getClientStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perClient' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$median = StatisticReporting::calcMedian(StatisticReporting::calcMedian($row[2]));
			$data['perClient'][] = array('hostname' => $row[0], 'time' => StatisticReporting::formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'medianTime' => StatisticReporting::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => StatisticReporting::formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'sessions' => $row[4],
				'lastLogout' => date(DATE_RSS,$row[5]), 'lastLogoutUnixtime' => $row[5], 'lastStart' => date(DATE_RSS,$row[6]), 'lastStartUnixtime' => $row[6], 'shortSessions' => $row[7]);
		}

		// per user
		$res = StatisticReporting::getUserStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perUser' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perUser'][] = array('user' => $row[0], 'sessions' => $row[1]);
		}

		// per vm
		$res = StatisticReporting::getVMStatistics($cutOffTimer, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perVM' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perVM'][] = array('vm' => $row[0], 'sessions' => $row[1]);
		}

		Render::addTemplate('columnChooser');
		Render::addTemplate('_page', $data);
	}
}
