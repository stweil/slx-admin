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

		// TODO: session-length liefert 3000 Einträge mehr als vmchooser-session?

		// TODO change AVG to median


		// total time online, average time online, total  number of logins
		$res = Database::simpleQuery("SELECT SUM(CAST(data AS UNSIGNED)), AVG(CAST(data AS UNSIGNED)), COUNT(*) FROM statistic WHERE typeid = '~session-length'");
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array('time' =>  $this->formatSeconds($row[0]), 'avgTime' =>  $this->formatSeconds($row[1]), 'totalLogins' => $row[2]);
		//total time offline
		$res = Database::simpleQUery("SELECT SUM(CAST(data AS UNSIGNED)) FROM statistic WHERE typeid='~offline-length'");
		$row = $res->fetch(PDO::FETCH_NUM);
		$data = array_merge($data, array('totalOfftime' => $this->formatSeconds($row[0])));

		// per location
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
		$data[] = array('perLocation' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perLocation'][] = array('location' => $row[0], 'time' => $this->formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'avgTime' => $this->formatSeconds($row[2]), 'avgTimeInSeconds' => $row[2], 'offTime' => $this->formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'loginCount' => $row[4]);
		}

		// per client
		$res = Database::simpleQuery("SELECT t1.name, timeSum, avgTime, offlineSum, loginCount, lastLogout, lastStart FROM (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'timeSum', AVG(CAST(statistic.data AS UNSIGNED)) AS 'avgTime', COUNT(*) AS 'loginCount', MAX(statistic.dateline) AS 'lastLogout'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~session-length' GROUP BY machine.machineuuid
												) t1 INNER JOIN (
													SELECT machine.hostname AS 'name', machine.machineuuid AS 'uuid', SUM(CAST(statistic.data AS UNSIGNED)) AS 'offlineSum', MAX(statistic.dateline) AS 'lastStart'
													FROM statistic INNER JOIN machine ON statistic.machineuuid = machine.machineuuid
													WHERE typeid = '~offline-length' GROUP BY machine.machineuuid
												) t2 ON t1.uuid = t2.uuid");
		$data[] = array('perClient' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perClient'][] = array('hostname' => $row[0], 'time' => $this->formatSeconds($row[1]), 'timeInSeconds' => $row[1],
				'avgTime' => $this->formatSeconds($row[2]), 'avgTimeInSeconds' => $row[2], 'offTime' => $this->formatSeconds($row[3]), 'offlineTimeInSeconds' => $row[3], 'loginCount' => $row[4],
				'lastLogout' => date(DATE_RSS,$row[5]), 'lastLogoutUnixtime' => $row[5], 'lastStart' => date(DATE_RSS,$row[6]), 'lastStartUnixtime' => $row[6]);
		}

		// per user
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY username ORDER BY 2 DESC");
		$data[] = array('perUser' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perUser'][] = array('user' => $row[0], 'loginCount' => $row[1]);
		}

		// per vm
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY data ORDER BY 2 DESC");
		$data[] = array('perVM' => array());
		while ($row = $res->fetch(PDO::FETCH_NUM)) {
			$data['perVM'][] = array('vm' => $row[0], 'loginCount' => $row[1]);
		}




		Render::addTemplate('columnChooser');
		Render::addTemplate('_page', $data);
	}


	protected function formatSeconds($seconds)
	{
		return intdiv($seconds, 3600*24).'d '.intdiv($seconds%(3600*24), 3600).'h '.intdiv($seconds%3600, 60).'m '.($seconds%60).'s';
	}
}
