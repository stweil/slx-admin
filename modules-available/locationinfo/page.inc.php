<?php

class Page_LocationInfo extends Page
{

	private $action;

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

		$this->action = Request::post('action');
	 	if ($this->action === 'updateOpeningTime') {
			$this->updateOpeningTime();
		} elseif ($this->action === 'updateConfig') {
			$this->updateConfig();
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		$getAction = Request::get('action');
		if (empty($getAction)) {
			Util::redirect('?do=locationinfo&action=infoscreen');
		}

		if ($getAction === 'infoscreen') {
			$this->getInfoScreenTable();
		}

		if($getAction == 'updateroomdb') {
			$this->updateInfoscreenDb();
			Util::redirect('?do=locationinfo&action=infoscreen');
		}

		if ($getAction === 'hide') {
			$roomId = Request::get('id');
			$hiddenValue = Request::get('value');
			$this->toggleHidden($roomId, $hiddenValue);
			Util::redirect('?do=locationinfo&action=infoscreen');
		}
	}

	private function updateConfig()
	{
		$result = array();

		$locationid = Request::post('id', 0, 'int');
		$result['language'] = Request::post('language');
		$result['mode'] = Request::post('mode', 1, 'int');
		$result['vertical'] = Request::post('vertical', false, 'bool');
		$result['eco'] = Request::post('eco', false, 'bool');
		$result['daystoshow'] = Request::post('daystoshow', 7, 'int');
		$result['rotation'] = Request::post('rotation', 0, 'int');
		$result['scale'] = Request::post('scale', 50, 'int');
		$result['switchtime'] = Request::post('switchtime', 20, 'int');
		$result['calupdate'] = Request::post('calupdate', 0, 'int'); //TODO SET DEFAULT TIME INSTEAD OF 0
		$result['roomupdate'] = Request::post('roomupdate', 0, 'int'); //TODO SET DEFAULT TIME INSTEAD OF 0
		$result['configupdate'] = Request::post('configupdate', 0, 'int'); //TODO SET DEFAULT TIME INSTEAD OF 0

		Database::exec("INSERT INTO `location_info` VALUES (:id, :hidden, '', :config, '') ON DUPLICATE KEY UPDATE config=:config",
		 array('id' => $locationid, 'hidden' => false, 'config' => json_encode($result, true)));

		 Message::addSuccess('config-saved');
		 Util::redirect('?do=locationinfo');
	}

	private function updateOpeningTime()
	{
		$existingDays = Request::post('existingdays');
		$days = Request::post('days');
		$locationid = Request::post('id');
		$openingtime = Request::post('openingtime');
		$closingtime = Request::post('closingtime');
		$delete = Request::post('delete');
		$dontadd = Request::post('dontadd');
		$count = 0;
		$result = array();
		$resulttmp = array();
		$deleteCounter = 0;

		$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :id", array('id' => $locationid));
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$resulttmp = json_decode($dbdata['openingtime'], true);
		}

		$index = 0;

		foreach ($resulttmp as $day) {
			$skip = false;
			foreach ($delete as $del) {
				if ($del == $index) {
					$skip = true;
					break;
				}
			}
			if ($skip == true) {
				$index++;
				$deleteCounter++;
				continue;
			}

			$result[] = $day;
			$index++;
		}

		if (!empty($days) && !is_array($days)) {
			Message::addError('no-days-selected');
			Util::redirect('?do=locationinfo');
		} else{

			$dayz = array();
			$da = array();

			foreach ($days as $d) {
				if ($d != '-') {
					$da[] = $d;
				} else {
					$dayz[$count] = $da;
					$da = array();
					$count++;
				}
			}

			$optime = array();
			for ($x = 0; $x < $count; $x++) {
				if ($dontadd[$x] == 'dontadd') {
					continue;
				}
				$optime['days'] = $dayz[$x];
				$optime['openingtime'] = $openingtime[$x];
				$optime['closingtime'] = $closingtime[$x];
				$result[] = $optime;
			}
		}

		Database::exec("INSERT INTO `location_info` VALUES (:id, :hidden, :openingtime, '', '') ON DUPLICATE KEY UPDATE openingtime=:openingtime",
		 array('id' => $locationid, 'hidden' => false, 'openingtime' => json_encode($result, true)));

		if ($deleteCounter > 0) {
			Message::addSuccess('deleted-x-entries', $deleteCounter);
		}
		if ($count > 0) {
			Message::addSuccess('added-x-entries', $count);
		}

		Util::redirect('?do=locationinfo');
	}

	private function removeOpeningTime() {
		Message::addError('wasd');
	}

	protected function toggleHidden($id, $val) {
		Database::exec("INSERT INTO `location_info` VALUES (:id, :hidden, '', '', '') ON DUPLICATE KEY UPDATE hidden=:hidden", array('id' => $id, 'hidden' => $val));
	}

	protected function getInfoScreenTable() {
		$dbquery = Database::simpleQuery("SELECT l.locationname, l.locationid, li.hidden, m.inUse, m.total FROM `location_info` AS li
		RIGHT JOIN `location` AS l ON li.locationid=l.locationid LEFT JOIN
		(SELECT locationid, Count(case m.logintime WHEN NOT 1 THEN null else 1 end) AS inUse, Count(*) AS total FROM `machine` AS m
		WHERE locationid IS NOT NULL GROUP BY locationid) AS m ON l.locationid=m.locationid");
		$pcs = array();

		if (Module::isAvailable('locations')) {
			foreach (Location::getLocations() as $loc) {
				$data = array();
				$data['locationid'] = (int)$loc['locationid'];
				$data['locationname'] = $loc['locationname'];
				$data['depth'] = $loc['depth'];
				$data['hidden'] = NULL;
				$locid = (int)$loc['locationid'];
				$pcs[$locid] = $data;
			}
		}

		while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$locid = (int)$roominfo['locationid'];

			if ($roominfo['hidden'] == NULL) {
				$pcs[$locid]['hidden'] = 0;
			} else {
				$pcs[$locid]['hidden'] = $roominfo['hidden'];
			}

			if ($roominfo['inUse'] != NULL) {
				$pcs[$locid]['inUse'] = $roominfo['inUse'];
			}
			if ($roominfo['total'] != NULL) {
				$pcs[$locid]['total'] = $roominfo['total'];
				$pcs[$locid]['hasPcs'] = true;
			} else {
				$pcs[$locid]['hasPcs'] = false;
			}
		}

		Render::addTemplate('location-info', array(
			'list' => array_values($pcs),
		));
	}

	protected function updateInfoscreenDb() {
		$dbquery = Database::simpleQuery("SELECT DISTINCT locationid FROM `machine` WHERE locationid IS NOT NULL");
		while($roominfo=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$this->updatePcInfos($roominfo['locationid']);
		}
	}

	private function getInUseStatus($logintime, $lastseen) {
		if ($logintime == 0) {
			return 0;
		} elseif ($logintime > 0) {
			return 1;
			// TODO lastseen > 610 = OFF  TODO DEFEKT! if ...
		} elseif ($lastseen > 610) {
			return 2;
		}
	}

	/**
 	 * AJAX
 	 */
	protected function doAjax()
	{
		User::load();
		if (!User::isLoggedIn()) {
			die('Unauthorized');
		}
		$action = Request::any('action');
		if ($action === 'pcsubtable') {
			$id = Request::any('id');
			$this->ajaxShowLocation($id);
		} elseif ($action === 'timetable') {
			$id = Request::any('id');
			$this->ajaxTimeTable($id);
		} elseif ($action === 'config') {
			$id = Request::any('id');
			$this->ajaxConfig($id);
		}
	}

	private function ajaxShowLocation($id)
	{
		$dbquery = Database::simpleQuery("SELECT machineuuid, clientip, position, logintime, lastseen  FROM `machine` WHERE locationid = :id", array('id' => $id));

		$data = array();

		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$pc = array();
			$pc['id'] = $dbdata['machineuuid'];
			$pc['ip'] = $dbdata['clientip'];
			$pc['inUse'] = $this->getInUseStatus($dbdata['logintime'], $dbdata['lastseen']);

			$position = array();
			$position = json_decode($dbdata['position'], true);
			$pc['x'] = $position['gridRow'];
			$pc['y'] = $position['gridCol'];

			$data[] = $pc;
		}

		echo Render::parse('pcsubtable', array(
			'list' => array_values($data)
		));
	}

	private function ajaxTimeTable($id) {
		$array = array();
		$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :id", array('id' => $id));
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$db = array();
			$db = json_decode($dbdata['openingtime'], true);
			$index = 0;
			foreach ($db as $key) {
				$str = "| ";
				foreach ($key['days'] as $val) {
					$str .= $val;
					$str .= " | ";
				}
				$ar = array();
				$ar['days'] = $str;
				$ar['openingtime'] = $key['openingtime'];
				$ar['closingtime'] = $key['closingtime'];
				$ar['index'] = $index;
				$array[] = $ar;
				$index++;
			}
		}
		echo Render::parse('timetable', array('id' => $id, 'openingtimes' => array_values($array)));
	}

	private function ajaxConfig($id) {
		$array = array();
		$dbquery = Database::simpleQuery("SELECT config FROM `location_info` WHERE locationid = :id", array('id' => $id));
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$array = json_decode($dbdata['config'], true);
		}
		echo Render::parse('config', array('id' => $id, 'language' => $array['language'], 'mode' => 'mode'.$array['mode'], 'vertical' => $array['vertical'],
													'eco' => $array['eco'], 'daystoshow' => 'day'.$array['daystoshow'], 'rotation' => 'rotation'.$array['rotation'],
													'scale' => $array['scale'], 'switchtime' => $array['switchtime'], 'calupdate' => $array['calupdate'],
													 'roomupdate' => $array['roomupdate'], 'configupdate' => $array['configupdate']));
	}
}
