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
		//counting the total  number of logins
		$res = Database::simpleQuery("SELECT COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name'");
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$datax = array('totalLogins' => $row['count']);

		//counting logins per vm/event
		$res = Database::simpleQuery("SELECT data, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY data ORDER BY 2 DESC");
		$datax[] = array('vmLogins' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datax['vmLogins'][] = array('vmname' => $row['data'], 'numLogins' => $row['count']);
		}

		//counting the logins per user
		$res = Database::simpleQuery("SELECT username, COUNT(*) AS 'count' FROM statistic WHERE typeid='.vmchooser-session-name' GROUP BY username ORDER BY 2 DESC");
		$datax[] = array('userLogins' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datax['userLogins'][] = array('username' => $row['username'], 'numLogins' => $row['count']);
		}

		// TODO: got empty machine with alot of logins. isn't shown in the inner-join hostname query below.
		// $res = Database::simpleQuery("SELECT machineuuid as 'hostname', COUNT(*) AS 'count' FROM statistic WHERE typeid='~session-length' GROUP BY machineuuid ORDER BY 2 DESC");

		// TODO: session-length liefert 3000 Einträge mehr als vmchooser-session?
		//counting the logins per client
		$res = Database::simpleQuery("SELECT machine.hostname AS 'hostname', COUNT(*) AS 'count' FROM statistic 
											 	INNER JOIN machine ON statistic.machineuuid=machine.machineuuid
												WHERE typeid='~session-length' GROUP BY machine.hostname ORDER BY 2 DESC");
		$datax[] = array('machineLogins' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datax['machineLogins'][] = array('client' => $row['hostname'], 'numLogins' => $row['count']);
		}



		//total time offline overall
		$res = Database::simpleQUery("SELECT sum(cast(data AS UNSIGNED)) AS totalOfftime FROM statistic WHERE typeid='~offline-length'");
		$row = $res->fetch(PDO::FETCH_ASSOC);
		$datay= array('totalOfftime' => $row['totalOfftime']);

		//total offline time per client
		$res = Database::simpleQuery("SELECT machine.hostname AS 'hostname', statistic.data as time FROM statistic 
											 	INNER JOIN machine ON statistic.machineuuid=machine.machineuuid
												WHERE typeid='~offline-length' GROUP BY machine.hostname ORDER BY cast(time AS UNSIGNED) DESC");
		$datay[] = array('totalOfflineTimeClient' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datay['totalOfflineTimeClient'][] = array('client' => $row['hostname'], 'offTime' => $row['time']);
		}

		// last logout of client
		$res = Database::simpleQuery("SELECT machine.hostname AS 'hostname', max(statistic.dateline) as datetime, statistic.data as loginTime
												FROM statistic INNER JOIN machine ON statistic.machineuuid=machine.machineuuid
												WHERE typeid='~session-length' GROUP BY machine.hostname ORDER BY datetime DESC");
		$datay[] = array('lastLogout' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datay['lastLogout'][] = array('client' => $row['hostname'], 'lastlogout' => date(DATE_RSS,$row['datetime']+$row['loginTime']), 'howLongOff' => (time() - ($row['datetime']+$row['loginTime'])));
		}

		// last start of client
		$res = Database::simpleQuery("SELECT machine.hostname AS 'hostname', max(statistic.dateline) as datetime FROM statistic 
											 	INNER JOIN machine ON statistic.machineuuid=machine.machineuuid
												WHERE typeid='~offline-length' GROUP BY machine.hostname ORDER BY datetime DESC");
		$datay[] = array('lastLogin' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datay['lastLogin'][] = array('client' => $row['hostname'], 'lastlogin' => date(DATE_RSS,$row['datetime']));
		}

		//total time offline per room
		$res = Database::simpleQuery("SELECT location.locationname AS 'room', statistic.data as time FROM statistic 
											 	INNER JOIN machine ON statistic.machineuuid=machine.machineuuid
											 	INNER JOIN location ON machine.locationid=location.locationid
												WHERE typeid='~offline-length' GROUP BY room ORDER BY cast(time AS UNSIGNED) DESC");
		$datay[] = array('offTimeRoom' => array());
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$datay['offTimeRoom'][] = array('room' => $row['room'], 'offTime' => $this->formatSeconds($row['time']));
		}

		$data = array_merge($datax, $datay);
		Render::addTemplate('_page', $data);
	}



	function formatSeconds($seconds) {
		$seconds = $seconds * 1;

		$minutes = floor($seconds / 60);
		$hours = floor($minutes / 60);
		$days = floor($hours / 24);

		$seconds = $seconds % 60;
		$minutes = $minutes % 60;
		$hours = $hours % 24;

		$format = '%u:%u:%02u:%02u';
		$time = sprintf($format, $days, $hours, $minutes, $seconds);
		return rtrim($time, '0');
	}


}
