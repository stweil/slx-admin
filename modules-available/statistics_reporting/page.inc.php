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
		$cutOff = Request::get('cutoff', 14, 'int') - 1;

		$lowerTimeBound = Request::get('lower', 0, 'int');

		$upperTimeBound = Request::get('upper', 24, 'int');


		// total time online, average time online, total  number of logins
		$res = StatisticReporting::getOverallStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array('time' =>  StatisticReporting::formatSeconds($row['sum']), 'medianTime' =>  StatisticReporting::formatSeconds(StatisticReporting::calcMedian($row['median'])), 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);

		//total time offline
		$res = StatisticReporting::getTotalOfflineStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$data = array_merge($data, array('totalOfftime' => StatisticReporting::formatSeconds($row['timeOff'])));

		// per location
		$res = StatisticReporting::getLocationStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perLocation' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = StatisticReporting::calcMedian(StatisticReporting::calcMedian($row['medianTime']));
			$data['perLocation'][] = array('location' => $row['locName'], 'time' => StatisticReporting::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
				'medianTime' => StatisticReporting::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => StatisticReporting::formatSeconds($row['offlineSum']), 'offlineTimeInSeconds' => $row['offlineSum'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions']);
		}

		// per client
		$res = StatisticReporting::getClientStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perClient' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$median = StatisticReporting::calcMedian(StatisticReporting::calcMedian($row['medianTime']));
			$data['perClient'][] = array('hostname' => $row['clientName'], 'time' => StatisticReporting::formatSeconds($row['timeSum']), 'timeInSeconds' => $row['timeSum'],
				'medianTime' => StatisticReporting::formatSeconds($median), 'medianTimeInSeconds' => $median, 'offTime' => StatisticReporting::formatSeconds($row['offlineSum']), 'offlineTimeInSeconds' => $row['offlineSum'], 'lastStart' => date(DATE_RSS,$row['lastStart']), 'lastStartUnixtime' => $row['lastStart'],
				'lastLogout' => date(DATE_RSS,$row['lastLogout']), 'lastLogoutUnixtime' => $row['lastLogout'], 'sessions' => $row['longSessions'], 'shortSessions' => $row['shortSessions'], 'locationName' => $row['locName']);
		}

		// per user
		$res = StatisticReporting::getUserStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perUser' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data['perUser'][] = array('user' => $row['name'], 'sessions' => $row['count']);
		}

		// per vm
		$res = StatisticReporting::getVMStatistics($cutOff, $lowerTimeBound, $upperTimeBound);
		$data[] = array('perVM' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$data['perVM'][] = array('vm' => $row['name'], 'sessions' => $row['count']);
		}

		Render::addTemplate('columnChooser');
		Render::addTemplate('_page', $data);
	}
}
