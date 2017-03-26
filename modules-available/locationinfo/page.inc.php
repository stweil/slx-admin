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
	 	if ($this->action === 'updateOpeningTimeExpert') {
			$this->updateOpeningTimeExpert();
		} elseif($this->action === 'updateOpeningTimeEasy') {
			$this->updateOpeningTimeEasy();
		} elseif ($this->action === 'updateConfig') {
			$this->updateConfig();
		} elseif ($this->action === 'updateServer') {
			$this->updateServer();
		} elseif ($this->action === 'deleteServer') {
			$this->deleteServer();
		} elseif ($this->action === 'checkConnection') {
			$this->checkConnection();
		} elseif ($this->action === 'updateServerSettings') {
			$this->updateServerSettings();
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
			$roomId = Request::get('id', 0, 'int');
			$hiddenValue = Request::get('value', 0, 'int');
			$this->toggleHidden($roomId, $hiddenValue);
			Util::redirect('?do=locationinfo&action=infoscreen#row' . $roomId);
		}

	}

	/**
	 * Updates the server in the db.
	 */
	private function updateServer() {
		$id = Request::post('id', 0, 'int');
		if ($id == 0) {
			Database::exec("INSERT INTO `setting_location_info` (servername, serverurl, servertype) VALUES (:name, :url, :type)",
			 array('name' => Request::post('name', '', 'string'), 'url' => Request::post('url', '', 'string'), 'type' => Request::post('type', '', 'string')));
		} else {
			Database::exec("INSERT INTO `setting_location_info` (serverid, servername, servertype, serverurl) VALUES (:id, :name, :type, :url)
			ON DUPLICATE KEY UPDATE servername=:name, serverurl=:url, servertype=:type",
			 array('id' => $id, 'name' => Request::post('name', '', 'string'), 'url' => Request::post('url', '', 'string'), 'type' => Request::post('type', '', 'string')));
		}

		$this->checkConnection();
	}

	/**
	 * Deletes the server from the db.
	 */
	private function deleteServer() {
		$id = Request::post('id', 0, 'int');
		Database::exec("DELETE FROM `setting_location_info` WHERE serverid=:id", array('id' => $id));
	}

	/**
	 * Updated the config in the db.
	 */
	private function updateConfig()
	{
		$result = array();

		$locationid = Request::post('id', 0, 'int');
		$result['language'] = Request::post('language');
		$result['mode'] = Request::post('mode', 1, 'int');
		$result['vertical'] = Request::post('vertical', false, 'bool');
		$result['eco'] = Request::post('eco', false, 'bool');
		$result['scaledaysauto'] = Request::post('autoscale', false, 'bool');
		$result['daystoshow'] = Request::post('daystoshow', 7, 'int');
		$result['rotation'] = Request::post('rotation', 0, 'int');
		$result['scale'] = Request::post('scale', 50, 'int');
		$result['switchtime'] = Request::post('switchtime', 20, 'int');
		$result['calupdate'] = Request::post('calupdate', 30, 'int');
		$result['roomupdate'] = Request::post('roomupdate', 30, 'int');
		$result['configupdate'] = Request::post('configupdate', 180, 'int');
		$serverid = Request::post('serverid', 0, 'int');
		$serverroomid = Request::post('serverroomid','', 'string');

		Database::exec("INSERT INTO `location_info` (locationid, serverid, serverroomid, config, lastcalendarupdate) VALUES (:id, :serverid, :serverroomid, :config, :lastcalendarupdate)
		 ON DUPLICATE KEY UPDATE config=:config, serverid=:serverid, serverroomid=:serverroomid, lastcalendarupdate=:lastcalendarupdate",
		 array('id' => $locationid, 'config' => json_encode($result, true), 'serverid' => $serverid, 'serverroomid' => $serverroomid, 'lastcalendarupdate' => 0));

		 Message::addSuccess('config-saved');
		 Util::redirect('?do=locationinfo');
	}

	/**
	 * Updates the server settings in the db.
	 */
	private function updateServerSettings() {
		$serverid = Request::post('id', -1, 'int');
		$servername = Request::post('name', 'unnamed', 'string');
		$serverurl = Request::post('url', '', 'string');
		$servertype = Request::post('type', '', 'string');

		$backend = CourseBackend::getInstance($servertype);
		$tmptypeArray = $backend->getCredentials();

		$credentialsJson = array();
		$counter = 0;
		foreach ($tmptypeArray as $key => $value) {
			$credentialsJson[$key] = Request::post($counter);
			$counter++;
		}
		if ($serverid == 0) {
			Database::exec('INSERT INTO `setting_location_info` (servername, serverurl, servertype, credentials) VALUES (:name, :url, :type, :credentials)',
			array('name' => $servername, 'url' => $serverurl, 'type' => $servertype, 'credentials' => json_encode($credentialsJson, true)));

			$dbresult = Database::queryFirst('SELECT serverid FROM `setting_location_info` WHERE servername = :name AND serverurl = :url AND servertype = :type AND credentials = :credentials',
			array('name' => $servername, 'url' => $serverurl, 'type' => $servertype, 'credentials' => json_encode($credentialsJson, true)));

			$this->checkConnection($dbresult['serverid']);
		} else {
			Database::exec('UPDATE `setting_location_info` SET servername = :name, serverurl = :url, servertype = :type, credentials = :credentials WHERE serverid = :id',
			 array('id' => $serverid, 'name' => $servername, 'url' => $serverurl, 'type' => $servertype, 'credentials' => json_encode($credentialsJson, true)));
			 $this->checkConnection();
		}
	}

	/**
	 * Updates the opening time in the db from the expert mode.
	 */
	private function updateOpeningTimeExpert()
	{
		$days = Request::post('days', array(), 'array');
		$locationid = Request::post('id', 0, 'int');
		$openingtime = Request::post('openingtime', array(), 'array');
		$closingtime = Request::post('closingtime', array(), 'array');
		$easyMode = Request::post('easyMode', false, 'bool');
		$delete = Request::post('delete', array(), 'array');
		$dontadd = Request::post('dontadd', array(), 'array');
		$count = 0;
		$result = array();
		$resulttmp = array();
		$deleteCounter = 0;

		if (!$easyMode) {
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

		Database::exec("INSERT INTO `location_info` (locationid, openingtime) VALUES (:id, :openingtime) ON DUPLICATE KEY UPDATE openingtime=:openingtime",
		 array('id' => $locationid, 'openingtime' => json_encode($result, true)));

		if ($deleteCounter > 0) {
			Message::addSuccess('deleted-x-entries', $deleteCounter);
		}
		if ($count > 0) {
			Message::addSuccess('added-x-entries', $count);
		}

		Util::redirect('?do=locationinfo');
	}

	/**
	 * Updates the opening time in the db from the easy mode.
	 */
	private function updateOpeningTimeEasy() {
		$locationid = Request::post('id', 0, 'int');
		$openingtime = Request::post('openingtime');
		$closingtime = Request::post('closingtime');
		$result = array();

		$opt0['days'] = array ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday");
		if (isset($openingtime[0]) && isset($closingtime[0])) {
			if ($closingtime[0] != "" && $openingtime[0] != "") {
				$opt0['openingtime'] = $openingtime[0];
				$opt0['closingtime'] = $closingtime[0];
				$result[] = $opt0;
			}
		}

		$opt1['days'] = array ("Saturday");
		if (isset($openingtime[1]) && isset($closingtime[1])) {
			if ($closingtime[1] != "" && $closingtime[1] != "") {
				$opt1['openingtime'] = $openingtime[1];
				$opt1['closingtime'] = $closingtime[1];
				$result[] = $opt1;
			}
		}

		$opt2['days'] = array ("Sunday");
		if (isset($openingtime[2]) && isset($closingtime[2])) {
			if ($closingtime[2] != "" && $openingtime[2] != "") {
				$opt2['openingtime'] = $openingtime[2];
				$opt2['closingtime'] = $closingtime[2];
				$result[] = $opt2;
			}
		}

		Database::exec("INSERT INTO `location_info` (locationid, openingtime) VALUES (:id, :openingtime) ON DUPLICATE KEY UPDATE openingtime=:openingtime",
		 array('id' => $locationid, 'openingtime' => json_encode($result, true)));

		Message::addSuccess('openingtime-updated');
		Util::redirect('?do=locationinfo');
	}

	/**
	 * Checks if the server connection to a backend is valid.
	 *
	 * @param int $id Server id which connection should be checked.
	 */
	private function checkConnection($id = 0) {
		$serverid = Request::post('id', 0, 'int');
		if ($id != 0) {
			$serverid = $id;
		}

		if ($serverid != 0) {
			$dbresult = Database::queryFirst("SELECT * FROM `setting_location_info` WHERE serverid = :serverid", array('serverid' => $serverid));

			$serverInstance = CourseBackend::getInstance($dbresult['servertype']);
			$setCredentials = $serverInstance->setCredentials(json_decode($dbresult['credentials'], true), $dbresult['serverurl'], $serverid);

			if ($setCredentials) {
				$setCred = $serverInstance->checkConnection();
			}

			if (!$setCredentials || !$setCred) {
				$error['timestamp'] = time();
				$error['error'] = $serverInstance->getError();
				Database::exec("UPDATE `setting_location_info` Set error=:error WHERE serverid=:id", array('id' => $serverid, 'error' => json_encode($error, true)));
		  } else {
				Database::exec("UPDATE `setting_location_info` Set error=NULL WHERE serverid=:id", array('id' => $serverid));
			}
		}
	}

	/**
	 * Sets the new hidden value and checks childs and parents.
	 *
	 * @param $id The location id which was toggled
	 * @param $val The hidden value true / false
	 */
	protected function toggleHidden($id, $val) {
		Database::exec("INSERT INTO `location_info` (locationid, hidden) VALUES (:id, :hidden) ON DUPLICATE KEY UPDATE hidden=:hidden", array('id' => $id, 'hidden' => $val));

		$this->checkChildRecursive($id, $val);
		$this->checkParentRecursive($id);

	}

	/**
	 * Recursivly sets all hidden values to all childs.
	 *
	 * @param $id The location id which childs should be checked
	 * @param $val The hidden value
	 */
	protected function checkChildRecursive($id, $val) {
		$dbquery = Database::simpleQuery("SELECT locationid FROM `location` WHERE parentlocationid = :locationid", array('locationid' => $id));
		$childs = array();
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$childs[] = $dbdata['locationid'];
		}

		foreach ($childs as $key) {
			Database::exec("INSERT INTO `location_info` (locationid, hidden) VALUES (:id, :hidden) ON DUPLICATE KEY UPDATE hidden=:hidden", array('id' => $key, 'hidden' => $val));

			$this->checkChildRecursive($key, $val);
		}
	}

	/**
	 * Recursively check all parent locations and updates the hidden values if necessary
	 *
	 * @param $id The id of the location which was toggled.
	 */
	protected function checkParentRecursive($id) {
		$dbquery = Database::simpleQuery("SELECT parentlocationid FROM `location` WHERE locationid = :locationid", array('locationid' => $id));
		$parent = 0;
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$parent = (int)$dbdata['parentlocationid'];
		}
		if ($parent === 0) {
			return;
		} else {
			$dbq = Database::simpleQuery("SELECT COUNT(case li.hidden when '0' then 1 else null end) AS '0',
			 															COUNT(case li.hidden when '1' then 1 else null end) AS '1',
																		 COUNT(*) - COUNT(case li.hidden when '0' then 1 else null end) - COUNT(case li.hidden when '1' then 1 else null end) AS 'NULL'
																		 FROM `location` AS l LEFT JOIN `location_info` AS li ON l.locationid=li.locationid
																		 WHERE parentlocationid = :parentId;", array('parentId' => $parent));
			$amountofzero = 0;
			$amountofnull = 0;

			while($dbd=$dbq->fetch(PDO::FETCH_ASSOC)) {
				$amountofzero = (int)$dbd['0'];
				$amountofnull = (int)$dbd['NULL'];
			}

			if ($amountofzero == 0 AND $amountofnull == 0) {
				Database::exec("INSERT INTO `location_info` (locationid, hidden) VALUES (:id, :hidden) ON DUPLICATE KEY UPDATE hidden=:hidden", array('id' => $parent, 'hidden' => 1));
			} else {
				Database::exec("INSERT INTO `location_info` (locationid, hidden) VALUES (:id, :hidden) ON DUPLICATE KEY UPDATE hidden=:hidden", array('id' => $parent, 'hidden' => 0));
			}

			$this->checkParentRecursive($parent);
		}
	}

	/**
	 * Loads the Infoscreen page in the admin-panel and passes all needed information.
	 */
	protected function getInfoScreenTable() {

		// Get a table with the needed location info. name, id, hidden, pcState (Count of pcs that are in use), total pcs
		$dbquery = Database::simpleQuery("SELECT l.locationname, l.locationid, li.hidden, m.pcState, m.total FROM `location_info` AS li
		RIGHT JOIN `location` AS l ON li.locationid=l.locationid LEFT JOIN
		(SELECT locationid, Count(case m.logintime WHEN NOT 1 THEN null else 1 end) AS pcState, Count(*) AS total FROM `machine` AS m
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

			if ($roominfo['pcState'] != NULL) {
				$pcs[$locid]['pcState'] = $roominfo['pcState'];
			}
			if ($roominfo['total'] != NULL) {
				$pcs[$locid]['total'] = $roominfo['total'];
				$pcs[$locid]['hasPcs'] = true;
			} else {
				$pcs[$locid]['hasPcs'] = false;
			}
		}

		// Get a list of all the backend types.
		$servertypes = array();
		$s_list = CourseBackend::getList();
		foreach ($s_list as $s) {
			$t['type'] = $s;
			$typeInstance = CourseBackend::getInstance($s);
			$t['display'] = $typeInstance->getDisplayName();
			$servertypes[] = $t;
		}

		// Get the Serverlist from the DB and make it mustache accesable
		$serverlist = array();
		$dbquery2 = Database::simpleQuery("SELECT * FROM `setting_location_info`");
		while($db=$dbquery2->fetch(PDO::FETCH_ASSOC)) {
			$server['id'] = $db['serverid'];
			$server['name'] = $db['servername'];
			$server['type'] = $db['servertype'];
			foreach ($servertypes as $type) {
				if ($server['type'] == $type['type']) {
					$server['display'] = $type['display'];
					break;
				}
			}

			if ($db['error'] == NULL) {
				$server['auth'] = true;
			} else {
				$server['auth'] = false;
				$error = json_decode($db['error'], true);

				$time = date('Y/m/d H:i:s', $error['timestamp']);

				Message::addError('auth-failed', $server['name'], $time, $error['error']);
			}

			$server['url'] = $db['serverurl'];
			$serverlist[] = $server;
		}

		// Pass the data to the html and render it.
		Render::addTemplate('location-info', array(
			'list' => array_values($pcs), 'serverlist' => array_values($serverlist),
		));
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
		if ($action === 'timetable') {
			$id = Request::any('id', 0, 'int');
			$this->ajaxTimeTable($id);
		} elseif ($action === 'config') {
			$id = Request::any('id', 0, 'int');
			$this->ajaxConfig($id);
		} elseif ($action === 'serverSettings') {
			$id = Request::any('id', 0, 'int');
			$this->ajaxServerSettings($id);
		}
	}

	/**
	 * Ajax the server settings.
	 *
	 * @param $id Serverid
	 */
	private function ajaxServerSettings($id) {
		$dbresult = Database::queryFirst('SELECT servername, serverurl, servertype, credentials FROM `setting_location_info` WHERE serverid = :id', array('id' => $id));

		// Credentials stuff.
		$dbcredentials = json_decode($dbresult['credentials'], true);

		// Get a list of all the backend types.
		$serverBackends = array();
		$s_list = CourseBackend::getList();
		foreach ($s_list as $s) {
			$backend['typ'] = $s;
			$backendInstance = CourseBackend::getInstance($s);
			$backend['display'] = $backendInstance->getDisplayName();

			if ($backend['typ'] == $dbresult['servertype']) {
				$backend['active'] = true;
			} else {
				$backend['active'] = false;
			}

			$credentials = $backendInstance->getCredentials();
			$backend['credentials'] = array();

			$counter = 0;
			foreach ($credentials as $key => $value) {
				$credential['uid'] = $counter;
				$credential['name'] = Dictionary::translateFile($s, $key);;
				$credential['type'] = $value;
				$credential['title'] = Dictionary::translateFile($s, $key."_title");

				if (Property::getPasswordFieldType() === 'text') {
					$credential['mask'] = false;
				} else {
					if ($value == "password") {
						$credential['mask'] = true;
					}
				}

				if ($backend['typ'] == $dbresult['servertype']) {
					foreach ($dbcredentials as $k => $v) {
						if($k == $key) {
							$credential['value'] = $v;
							break;
						}
					}
				}

				$selection = array();

				if (is_array($value)) {

					$selfirst = true;
					foreach ($value as $opt) {
						$option['option'] = $opt;
						if (isset($credential['value'])) {
							if ($opt == $credential['value']) {
								$option['active'] = true;
							} else {
								$option['active'] = false;
							}
						} else {
							if ($selfirst) {
								$option['active'] = true;
								$selfirst = false;
							} else {
								$option['active'] = false;
							}
						}

						$selection[] = $option;
					}
					$credential['type'] = "array";
					$credential['array'] = $selection;
				}

				$backend['credentials'][] = $credential;

				$counter++;
			}
			$serverBackends[] = $backend;
		}

		echo Render::parse('server-settings', array('id' => $id, 'name' => $dbresult['servername'], 'url' => $dbresult['serverurl'], 'servertype' => $dbresult['servertype'], 'backendList' => array_values($serverBackends)));
	}

	/**
	 * Ajax the time table
	 *
	 * @param $id id of the location
	 */
	private function ajaxTimeTable($id) {
		$array = array();
		$dbquery = Database::simpleQuery("SELECT openingtime FROM `location_info` WHERE locationid = :id", array('id' => $id));
		$dbresult = array();
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$dbresult[] = json_decode($dbdata['openingtime'], true);
		}
		if($this->isEasyMode($dbresult)) {
			echo Render::parse('timetable', array('id' => $id, 'openingtime0' => $dbresult[0][0]['openingtime'],
			 'closingtime0' => $dbresult[0][0]['closingtime'], 'openingtime1' => $dbresult[0][1]['openingtime'],
			  'closingtime1' => $dbresult[0][1]['closingtime'], 'openingtime2' => $dbresult[0][2]['openingtime'],
				 'closingtime2' => $dbresult[0][2]['closingtime'], 'easyMode' => true, 'expertMode' => false));

		} else{
			foreach($dbresult as $db) {
				$index = 0;
				foreach ($db as $key) {
					$str = "";

					$first = true;
					foreach ($key['days'] as $val) {
						if ($first) {
							$first = false;
						} else {
							$str .= ", ";
						}
						$str .= $val;
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
			echo Render::parse('timetable', array('id' => $id, 'openingtimes' => array_values($array), 'easyMode' => false, 'expertMode' => true));
		}
	}

	/**
	 * Checks if easymode or expert mode is active.
	 *
	 * @param $array Array of the saved openingtimes.
	 * @return bool True if easy mode, false if expert mode
	 */
	private function isEasyMode($array) {
		if(count($array[0]) == 3) {
			if ($array[0][0]['days'] == array ("Monday","Tuesday","Wednesday","Thursday","Friday")
			&& $array[0][1]['days'] == array ("Saturday") && $array[0][2]['days'] == array ("Sunday")) {
				return true;
			} else {
				return false;
			}
		} elseif ($array[0] == 0) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Ajax the config of a location.
	 *
	 * @param $id Location ID
	 */
	private function ajaxConfig($id) {
		$array = array();

		// Get Config data from db
		$dbquery = Database::simpleQuery("SELECT config, serverid, serverroomid FROM `location_info` WHERE locationid = :id", array('id' => $id));
		$serverid;
		$serverroomid;
		while($dbdata=$dbquery->fetch(PDO::FETCH_ASSOC)) {
			$array = json_decode($dbdata['config'], true);
			$serverid = $dbdata['serverid'];
			$serverroomid = $dbdata['serverroomid'];
		}

		// get Server / ID list
		$dbq = Database::simpleQuery("SELECT serverid, servername FROM `setting_location_info`");
		$serverList = array();
		while($dbd=$dbq->fetch(PDO::FETCH_ASSOC)) {
			$d['sid'] = $dbd['serverid'];
			$d['sname'] = $dbd['servername'];
			$serverList[] = $d;
		}

		echo Render::parse('config', array('id' => $id, 'language' => $array['language'], 'mode' => 'mode'.$array['mode'], 'vertical' => $array['vertical'],
													'eco' => $array['eco'], 'daystoshow' => 'day'.$array['daystoshow'], 'scaledaysauto' => $array['scaledaysauto'], 'rotation' => 'rotation'.$array['rotation'],
													'scale' => $array['scale'], 'switchtime' => $array['switchtime'], 'calupdate' => $array['calupdate'],
													 'roomupdate' => $array['roomupdate'], 'configupdate' => $array['configupdate'],
													 'serverlist' => array_values($serverList), 'serverid' => $serverid, 'serverroomid' => $serverroomid));
	}

}
