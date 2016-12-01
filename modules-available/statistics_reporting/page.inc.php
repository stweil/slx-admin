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
		// TODO: got empty machine with alot of logins. isn't shown in the inner-join hostname query below.
		// $res = Database::simpleQuery("SELECT machineuuid as 'hostname', COUNT(*) AS 'count' FROM statistic WHERE typeid='~session-length' GROUP BY machineuuid ORDER BY 2 DESC");

		// TODO: session-length liefert 3000 Einträge mehr als vmchooser-session? (logins ohne ausgewählter VM -> Session endet trotzdem mit session-length Meldung)

		// TODO change AVG to median


		// total time online, average time online, total  number of logins
		$res = StatisticReporting::getOverallStatistics();
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array('time' =>  StatisticReporting::formatSeconds($row[0]), 'avgTime' =>  StatisticReporting::formatSeconds($row[1]), 'totalLogins' => $row[2]);
		//total time offline
		$res = StatisticReporting::getTotalOffline();
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array_merge($data, array('totalOfftime' => StatisticReporting::formatSeconds($row[0])));

		// per location
		$res = StatisticReporting::getLocationStatistics();
		$data[] = array('perLocation' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perLocation'][] = array('location' => $row[0], 'time' => StatisticReporting::formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'avgTime' => StatisticReporting::formatSeconds($row[2]), 'avgTimeInSeconds' => $row[2], 'offTime' => StatisticReporting::formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'loginCount' => $row[4]);
		}

		// per client
		$res = StatisticReporting::getClientStatistics();
		$data[] = array('perClient' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perClient'][] = array('hostname' => $row[0], 'time' => StatisticReporting::formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'avgTime' => StatisticReporting::formatSeconds($row[2]), 'avgTimeInSeconds' => $row[2], 'offTime' => StatisticReporting::formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'loginCount' => $row[4],
				'lastLogout' => date(DATE_RSS,$row[5]), 'lastLogoutUnixtime' => $row[5], 'lastStart' => date(DATE_RSS,$row[6]), 'lastStartUnixtime' => $row[6]);
		}

		// per user
		$res = StatisticReporting::getUserStatistics();
		$data[] = array('perUser' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perUser'][] = array('user' => $row[0], 'loginCount' => $row[1]);
		}

		// per vm
		$res = StatisticReporting::getVMStatistics();
		$data[] = array('perVM' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perVM'][] = array('vm' => $row[0], 'loginCount' => $row[1]);
		}


		Render::addTemplate('columnChooser');
		Render::addTemplate('_page', $data);
	}
}
