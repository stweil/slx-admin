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
		} elseif ($this->action === 'updateOpeningTimeEasy') {
			$this->updateOpeningTimeEasy();
		} elseif ($this->action === 'updateConfig') {
			$this->updateLocationConfig();
		} elseif ($this->action === 'deleteServer') {
			$this->deleteServer();
		} elseif ($this->action === 'checkConnection') {
			$this->checkConnection(Request::post('serverid', 0, 'int'));
		} elseif ($this->action === 'updateServerSettings') {
			$this->updateServerSettings();
		} elseif (Request::isPost()) {
			Messages::addWarning('main.invalid-action', $this->action);
		}
		if (Request::isPost()) {
			Util::redirect('?do=locationinfo');
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		$this->getInfoScreenTable();
	}

	/**
	 * Deletes the server from the db.
	 */
	private function deleteServer()
	{
		$id = Request::post('serverid', false, 'int');
		if ($id === false) {
			Messages::addError('server-id-missing');
			return;
		}
		Database::exec("DELETE FROM `locationinfo_coursebackend` WHERE serverid=:id", array('id' => $id));
	}

	/**
	 * Updated the config in the db.
	 */
	private function updateLocationConfig()
	{
		$result = array();

		$locationid = Request::post('id', 0, 'int');
		if ($locationid <= 0) {
			Message::addError('location.invalid-location-id', $locationid);
			Util::redirect('?do=locationinfo');
		}
		$serverid = Request::post('serverid', 0, 'int');
		if ($serverid === 0) {
			Message::addError('main.value-invalid', 'serverid', 0);
			Util::redirect('?do=locationinfo');
		}
		$result['language'] = Request::post('language', 'en', 'string');
		$result['mode'] = Request::post('mode', 1, 'int');
		$result['vertical'] = Request::post('vertical', false, 'bool');
		$result['eco'] = Request::post('eco', false, 'bool');
		$result['scaledaysauto'] = Request::post('scaledaysauto', false, 'bool');
		$result['daystoshow'] = Request::post('daystoshow', 7, 'int');
		$result['rotation'] = Request::post('rotation', 0, 'int');
		$result['scale'] = Request::post('scale', 50, 'int');
		$result['switchtime'] = Request::post('switchtime', 20, 'int');
		$result['calupdate'] = Request::post('calupdate', 120, 'int');
		$result['roomupdate'] = Request::post('roomupdate', 30, 'int');
		$result['configupdate'] = Request::post('configupdate', 180, 'int');
		if ($result['roomupdate'] < 30) {
			$result['roomupdate'] = 30;
		}
		if ($result['calupdate'] < 120) {
			$result['calupdate'] = 120;
		}
		$serverlocationid = Request::post('serverlocationid', '', 'string');

		Database::exec("INSERT INTO `locationinfo_locationconfig` (locationid, serverid, serverlocationid, config, lastcalendarupdate)
				VALUES (:id, :serverid, :serverlocationid, :config, 0)
				ON DUPLICATE KEY UPDATE config = VALUES(config), serverid = VALUES(serverid),
					serverlocationid = VALUES(serverlocationid), lastcalendarupdate = 0", array(
			'id' => $locationid,
			'config' => json_encode($result),
			'serverid' => $serverid,
			'serverlocationid' => $serverlocationid,
		));

		Message::addSuccess('config-saved');
		Util::redirect('?do=locationinfo');
	}

	/**
	 * Updates the server settings in the db.
	 */
	private function updateServerSettings()
	{
		$serverid = Request::post('id', -1, 'int');
		$servername = Request::post('name', 'unnamed', 'string');
		$servertype = Request::post('type', '', 'string');
		$backend = CourseBackend::getInstance($servertype);

		if ($backend === false) {
			Messages::addError('invalid-backend-type', $servertype);
			Util::redirect('?do=locationinfo');
		}

		$tmptypeArray = $backend->getCredentialDefinitions();

		$credentialsJson = array();
		foreach ($tmptypeArray as $cred) {
			$credentialsJson[$cred->property] = Request::post('prop-' . $cred->property);
		}
		$params = array(
			'name' => $servername,
			'type' => $servertype,
			'credentials' => json_encode($credentialsJson)
		);
		if ($serverid === 0) {
			Database::exec('INSERT INTO `locationinfo_coursebackend` (servername, servertype, credentials)
					VALUES (:name, :type, :credentials)', $params);
			$this->checkConnection(Database::lastInsertId());
		} else {
			$params['id'] = $serverid;
			Database::exec('UPDATE `locationinfo_coursebackend`
					SET servername = :name, servertype = :type, credentials = :credentials
					WHERE serverid = :id', $params);
			$this->checkConnection($serverid);
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
			$resulttmp = Database::queryFirst("SELECT openingtime FROM `locationinfo_locationconfig` WHERE locationid = :id", array('id' => $locationid));
			if ($resulttmp !== false) {
				$resulttmp = json_decode($resulttmp['openingtime'], true);
			}
			if (!is_array($resulttmp)) {
				$resulttmp = array();
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
				if ($skip) {
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
		} else {

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

		Database::exec("INSERT INTO `locationinfo_locationconfig` (locationid, openingtime)
				VALUES (:id, :openingtime)
				ON DUPLICATE KEY UPDATE openingtime = VALUES(openingtime)",
			array('id' => $locationid, 'openingtime' => json_encode($result)));

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
	private function updateOpeningTimeEasy()
	{
		$locationid = Request::post('id', 0, 'int');
		$openingtime = Request::post('openingtime', array(), 'array');
		$closingtime = Request::post('closingtime', array(), 'array');
		$result = array();

		$blocks = array(
			0 => array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday"),
			1 => array("Saturday"),
			2 => array("Sunday"),
		);
		foreach ($blocks as $idx => $days) {
			//if (!empty($openingtime[$idx]) && !empty($closingtime[$idx])) {
				$result[] = array(
					'days' => $days,
					'openingtime' => $openingtime[$idx],
					'closingtime' => $closingtime[$idx],
				);
			//}
		}

		Database::exec("INSERT INTO `locationinfo_locationconfig` (locationid, openingtime)
				VALUES (:id, :openingtime)
				ON DUPLICATE KEY UPDATE openingtime = VALUES(openingtime)",
			array('id' => $locationid, 'openingtime' => json_encode($result)));

		Message::addSuccess('openingtime-updated');
		Util::redirect('?do=locationinfo');
	}

	/**
	 * Checks if the server connection to a backend is valid.
	 *
	 * @param int $id Server id which connection should be checked.
	 */
	private function checkConnection($serverid = 0)
	{
		if ($serverid === 0) {
			Util::traceError('checkConnection called with no server id');
		}

		$dbresult = Database::queryFirst("SELECT servertype, credentials
				FROM `locationinfo_coursebackend`
				WHERE serverid = :serverid", array('serverid' => $serverid));

		$serverInstance = CourseBackend::getInstance($dbresult['servertype']);
		if ($serverInstance === false) {
			LocationInfo::setServerError($serverid, 'Unknown backend type: ' . $dbresult['servertype']);
			return;
		}
		$credentialsOk = $serverInstance->setCredentials($serverid, json_decode($dbresult['credentials'], true));

		if ($credentialsOk) {
			$connectionOk = $serverInstance->checkConnection();
		}

		LocationInfo::setServerError($serverid, $serverInstance->getError());
	}


	/**
	 * Sets the new hidden value and checks childs and parents.
	 *
	 * @param int $id The location id which was toggled
	 * @param bool $hidden The hidden value true / false
	 */
	protected function toggleHidden($id, $hidden)
	{
		$locs = Location::getLocationsAssoc();
		if (!isset($locs[$id]))
			die('Invalid location id');
		$loc = $locs[$id];

		// The JSON to return, telling the client which locationids to update in the view
		$return = array();
		$return[] = array('locationid' => $id, 'hidden' => $hidden);

		// Update the location, plus all child locations
		$qs = '(?,?)' . str_repeat(',(?,?)', count($loc['children']));
		$params = array($id, $hidden);
		foreach ($loc['children'] as $child) {
			$params[] = $child;
			$params[] = $hidden;
			$return[] = array('locationid' => $child, 'hidden' => $hidden);
		}
		Database::exec("INSERT INTO locationinfo_locationconfig (locationid, hidden)
				VALUES $qs ON DUPLICATE KEY UPDATE hidden = VALUES(hidden)", $params);

		// Handle parents - uncheck if not all children are checked
		while ($loc['parentlocationid'] != 0) {
			$stats = Database::queryFirst('SELECT Count(*) AS total, Sum(li.hidden > 0) AS hidecount FROM location l
					LEFT JOIN locationinfo_locationconfig li USING (locationid)
					WHERE l.parentlocationid = :parent', array('parent' => $loc['parentlocationid']));
			$hidden = ($stats['total'] == $stats['hidecount']) ? 1 : 0;
			$params = array('locationid' => $loc['parentlocationid'], 'hidden' => $hidden);
			Database::exec('INSERT INTO locationinfo_locationconfig (locationid, hidden)
					VALUES (:locationid, :hidden) ON DUPLICATE KEY UPDATE hidden = VALUES(hidden)', $params);
			$return[] = $params;
			$loc = $locs[$loc['parentlocationid']];
		}
		return $return;
	}

	/**
	 * Loads the Infoscreen page in the admin-panel and passes all needed information.
	 */
	protected function getInfoScreenTable()
	{
		$locations = Location::getLocations(0, 0, false, true);

		// Get hidden state of all locations
		$dbquery = Database::simpleQuery("SELECT li.locationid, li.hidden FROM `locationinfo_locationconfig` AS li");

		while ($row = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			$locid = (int)$row['locationid'];
			$locations[$locid]['hidden_checked'] = $row['hidden'] != 0 ? 'checked' : '';
		}

		// Get a list of all the backend types.
		$servertypes = array();
		$s_list = CourseBackend::getList();
		foreach ($s_list as $s) {
			$typeInstance = CourseBackend::getInstance($s);
			$servertypes[$s] = $typeInstance->getDisplayName();
		}

		// Get the Serverlist from the DB and make it mustache accessable
		$serverlist = array();
		$dbquery2 = Database::simpleQuery("SELECT * FROM `locationinfo_coursebackend`");
		while ($row = $dbquery2->fetch(PDO::FETCH_ASSOC)) {
			if (isset($servertypes[$row['servertype']])) {
				$row['typename'] = $servertypes[$row['servertype']];
			} else {
				$row['typename'] = '[' . $row['servertype'] . ']';
				$row['disabled'] = 'disabled';
			}

			if (!empty($row['error'])) {
				$row['autherror'] = true;
				$error = json_decode($row['error'], true);
				if (isset($error['timestamp'])) {
					$time = date('Y/m/d H:i:s', $error['timestamp']);
				} else {
					$time = '???';
				}
				Message::addError('auth-failed', $row['servername'], $time, $error['error']);
			}
			$serverlist[] = $row;
		}

		// Pass the data to the html and render it.
		Render::addTemplate('location-info', array(
			'list' => array_values($locations),
			'serverlist' => $serverlist,
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
		$id = Request::any('id', 0, 'int');
		if ($action === 'timetable') {
			$this->ajaxTimeTable($id);
		} elseif ($action === 'config') {
			$this->ajaxLoadLocationConfig($id);
		} elseif ($action === 'serverSettings') {
			$this->ajaxServerSettings($id);
		} elseif ($action === 'hide') {
			$this->ajaxHideLocation();
		}
	}

	/**
	 * Request to deny displaying the door sign for the
	 * given location. Sends a list of all affected
	 * locations, so the client can update its view.
	 */
	private function ajaxHideLocation()
	{
		$locationId = Request::post('locationid', 0, 'int');
		$hidden = Request::post('hidden', 0, 'int');
		Header('Content-Type: application/json; charset=utf-8');
		$ret = $this->toggleHidden($locationId, $hidden);
		echo json_encode(array('changed' => $ret));
	}

	/**
	 * Ajax the server settings.
	 *
	 * @param int $id Serverid
	 */
	private function ajaxServerSettings($id)
	{
		$oldConfig = Database::queryFirst('SELECT servername, servertype, credentials
				FROM `locationinfo_coursebackend` WHERE serverid = :id', array('id' => $id));

		// Credentials stuff.
		if ($oldConfig !== false) {
			$oldCredentials = json_decode($oldConfig['credentials'], true);
		} else {
			$oldCredentials = array();
		}

		// Get a list of all the backend types.
		$serverBackends = array();
		$s_list = CourseBackend::getList();
		foreach ($s_list as $s) {
			$backendInstance = CourseBackend::getInstance($s);
			$backend = array(
				'backendtype' => $s,
				'display' => $backendInstance->getDisplayName(),
				'active' => ($oldConfig !== false && $s === $oldConfig['servertype']),
			);
			$backend['credentials'] = $backendInstance->getCredentialDefinitions();
			foreach ($backend['credentials'] as $cred) {
				if ($backend['active'] && isset($oldCredentials[$cred->property])) {
					$cred->initForRender($oldCredentials[$cred->property]);
				} else {
					$cred->initForRender();
				}
				$cred->title = Dictionary::translateFile('backend-' . $s, $cred->property, true);
				$cred->helptext = Dictionary::translateFile('backend-' . $s, $cred->property . "_helptext");
				$cred->credentialsHtml = Render::parse('server-prop-' . $cred->template, (array)$cred);
			}
			$serverBackends[] = $backend;
		}
		echo Render::parse('server-settings', array('id' => $id,
			'name' => $oldConfig['servername'],
			'currentbackend' => $oldConfig['servertype'],
			'backendList' => $serverBackends,
			'defaultBlank' => $oldConfig === false));
	}

	/**
	 * Ajax the time table
	 *
	 * @param $id id of the location
	 */
	private function ajaxTimeTable($id)
	{
		$row = Database::queryFirst("SELECT openingtime FROM `locationinfo_locationconfig` WHERE locationid = :id", array('id' => $id));
		if ($row !== false) {
			$openingtimes = json_decode($row['openingtime'], true);
		}
		if (!is_array($openingtimes)) {
			$openingtimes = array();
		}
		if ($this->isEasyMode($openingtimes)) {
			echo Render::parse('timetable', array('id' => $id,
				'openingtime0' => $openingtimes[0]['openingtime'],
				'closingtime0' => $openingtimes[0]['closingtime'],
				'openingtime1' => $openingtimes[1]['openingtime'],
				'closingtime1' => $openingtimes[1]['closingtime'],
				'openingtime2' => $openingtimes[2]['openingtime'],
				'closingtime2' => $openingtimes[2]['closingtime'],
				'easyMode' => true,
				'expertMode' => false));

		} else {
			$index = 0;
			foreach ($openingtimes as &$entry) {
				$entry['days'] = implode(', ', $entry['days']);
				$entry['index'] = $index++;
			}
			echo Render::parse('timetable', array('id' => $id,
				'openingtimes' => array_values($openingtimes),
				'easyMode' => false,
				'expertMode' => true));
		}
	}

	/**
	 * Checks if easymode or expert mode is active.
	 *
	 * @param $array Array of the saved openingtimes.
	 * @return bool True if easy mode, false if expert mode
	 */
	private function isEasyMode($array)
	{
		if (empty($array))
			return true;
		if (count($array) === 3
				&& $array[0]['days'] == array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday")
				&& $array[1]['days'][0] == "Saturday" && $array[2]['days'][0] == "Sunday"
		) {
			return true;
		}
		return false;
	}

	/**
	 * Ajax the config of a location.
	 *
	 * @param $id Location ID
	 */
	private function ajaxLoadLocationConfig($id)
	{
		// Get Config data from db
		$location = Database::queryFirst("SELECT lc.config, lc.serverid, lc.serverlocationid
			FROM location l
			LEFT JOIN `locationinfo_locationconfig` lc USING (locationid)
			WHERE l.locationid = :id", array('id' => $id));
		if ($location === false) {
			die("Invalid location id: $id");
		}

		$config = json_decode($location['config'], true); // TODO: Validate we got an array, fill with defaults otherwise

		// get Server / ID list
		$dbq = Database::simpleQuery("SELECT serverid, servername FROM locationinfo_coursebackend ORDER BY servername ASC");
		$serverList = array();
		while ($row = $dbq->fetch(PDO::FETCH_ASSOC)) {
			if ($row['serverid'] == $location['serverid']) {
				$row['selected'] = 'selected';
			}
			$serverList[] = $row;
		}
		$langs = Dictionary::getLanguages(true);
		foreach ($langs as &$lang) {
			if ($lang['cc'] === $config['language']) {
				$lang['selected'] = 'selected';
			}
		}

		echo Render::parse('config', array(
			'id' => $id,
			'languages' => $langs,
			'mode' => $config['mode'],
			'vertical_checked' => $config['vertical'] ? 'checked' : '',
			'eco_checked' => $config['eco'] ? 'checked' : '',
			'scaledaysauto_checked' => $config['scaledaysauto'] ? 'checked' : '',
			'daystoshow' => $config['daystoshow'],
			'rotation' => $config['rotation'],
			'scale' => $config['scale'],
			'switchtime' => $config['switchtime'],
			'calupdate' => $config['calupdate'],
			'roomupdate' => $config['roomupdate'],
			'configupdate' => $config['configupdate'],
			'serverlist' => $serverList,
			'serverid' => $location['serverid'],
			'serverlocationid' => $location['serverlocationid']
		));
	}

}
