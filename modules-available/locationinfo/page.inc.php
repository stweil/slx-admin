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

		$show = Request::any('show', '', 'string');
		$this->action = Request::post('action');
		if ($this->action === 'writePanelConfig') {
			$this->writePanelConfig();
		} elseif ($this->action === 'writeLocationConfig') {
			$this->writeLocationConfig();
			$show = 'locations';
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
			if (!empty($show)) {
				$show = '&show=' . $show;
			}
			Util::redirect('?do=locationinfo' . $show);
		}
	}

	/**
	 * Menu etc. has already been generated, now it's time to generate page content.
	 */
	protected function doRender()
	{
		$show = Request::get('show', '', 'string');
		switch ($show) {
		case 'locations':
			$this->showLocationsTable();
			break;
		case 'edit-panel':
			$this->showConfigPanel();
			break;
		case '':
			$this->showInfoScreenTable();
			break;
		default:
			Message::addError('main.value-invalid', 'show', $show);
		}
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

	private function getTime($str)
	{
		$str = explode(':', $str);
		if (count($str) !== 2) return false;
		if ($str[0] < 0 || $str[0] > 23 || $str[1] < 0 || $str[1] > 59) return false;
		return $str[0] * 60 + $str[1];
	}

	private function writeLocationConfig()
	{
		// Check locations
		$locationid = Request::post('locationid', false, 'int');
		if ($locationid === false) {
			Message::addError('main.paramter-missing', 'locationid');
			return false;
		}
		if (Location::get($locationid) === false) {
			Message::addError('location.invalid-location-id', $locationid);
			return false;
		}
		$serverid = Request::post('serverid', 0, 'int');
		if ($serverid === 0) {
			$serverid = null;
		}
		$serverlocationid = Request::post('serverlocationid', '', 'string');

		// Opening times
		$openingtimes = Request::post('openingtimes', '', 'string');
		if ($openingtimes !== '') {
			$openingtimes = json_decode($openingtimes, true);
			if (!is_array($openingtimes)) {
				$openingtimes = '';
			} else {
				$mangled = array();
				foreach (array_keys($openingtimes) as $key) {
					$entry = $openingtimes[$key];
					if (!isset($entry['days']) || !is_array($entry['days']) || empty($entry['days'])) {
						Message::addError('ignored-line-no-days');
						continue;
					}
					$s = $this->getTime($entry['openingtime']);
					$e = $this->getTime($entry['closingtime']);
					if ($s === false) {
						Message::addError('ignored-invalid-start', $entry['openingtime']);
						continue;
					}
					if ($e === false) {
						Message::addError('ignored-invalid-end', $entry['closingtime']);
						continue;
					}
					if ($e <= $s) {
						Message::addError('ignored-invalid-range', $entry['openingtime'], $entry['closingtime']);
						continue;
					}
					unset($entry['tag']);
					$mangled[] = $entry;
				}
				if (empty($mangled)) {
					$openingtimes = '';
				} else {
					$openingtimes = json_encode($mangled);
				}
			}
		}

		Database::exec("INSERT INTO `locationinfo_locationconfig` (locationid, serverid, serverlocationid, openingtime, lastcalendarupdate)
				VALUES (:id, :serverid, :serverlocationid, :openingtimes, 0)
				ON DUPLICATE KEY UPDATE serverid = VALUES(serverid), serverlocationid = VALUES(serverlocationid),
					openingtime = VALUES(openingtime), lastcalendarupdate = 0", array(
			'id' => $locationid,
			'serverid' => $serverid,
			'openingtimes' => $openingtimes,
			'serverlocationid' => $serverlocationid,
		));
		return true;
	}

	/**
	 * Updated the config in the db.
	 */
	private function writePanelConfig()
	{
		// UUID - existing or new
		$paneluuid = Request::post('paneluuid', false, 'string');
		if (($paneluuid === false || strlen($paneluuid) !== 36) && $paneluuid !== 'new') {
			Message::addError('invalid-panel-id', $paneluuid);
			Util::redirect('?do=locationinfo');
		}
		// Check locations
		$locationids = Request::post('locationids', false, 'array');
		if ($locationids === false) {
			Message::addError('main.paramter-missing', 'locationids');
			Util::redirect('?do=locationinfo');
		}
		$all = array_map(function ($item) { return $item['locationid']; }, Location::queryLocations());
		$locationids = array_filter($locationids, function ($item) use ($all) { return in_array($item, $all); });
		if (empty($locationids)) {
			Message::addError('main.paramter-empty', 'locationids');
			Util::redirect('?do=locationinfo');
		}
		if (count($locationids) > 4) {
			$locationids = array_slice($locationids, 0, 4);
		}
		// Build json struct
		$conf = array(
			'ts' => time(),
			'language' => Request::post('language', 'en', 'string'),
			'mode' => Request::post('mode', 1, 'int'),
			'vertical' => Request::post('vertical', false, 'bool'),
			'eco' => Request::post('eco', false, 'bool'),
			'scaledaysauto' => Request::post('scaledaysauto', false, 'bool'),
			'daystoshow' => Request::post('daystoshow', 7, 'int'),
			'rotation' => Request::post('rotation', 0, 'int'),
			'scale' => Request::post('scale', 50, 'int'),
			'switchtime' => Request::post('switchtime', 20, 'int'),
			'calupdate' => Request::post('calupdate', 120, 'int'),
			'roomupdate' => Request::post('roomupdate', 30, 'int'),
			'configupdate' => Request::post('configupdate', 180, 'int'),
		);
		if ($conf['roomupdate'] < 30) {
			$conf['roomupdate'] = 30;
		}
		if ($conf['calupdate'] < 120) {
			$conf['calupdate'] = 120;
		}

		if ($paneluuid === 'new') {
			$paneluuid = Util::randomUuid();
			$query = "INSERT INTO `locationinfo_panel` (paneluuid, locationids, paneltype, panelconfig)
				VALUES (:id, :locationids, :type, :config)";
		} else {
			$query = "UPDATE `locationinfo_panel`
				SET locationids = :locationids, paneltype = :type, panelconfig = :config
				WHERE paneluuid = :id";
		}
		Database::exec($query, array(
			'id' => $paneluuid,
			'locationids' => implode(',', $locationids),
			'type' => 'DEFAULT', // TODO
			'config' => json_encode($conf),
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
	 * Loads the Infoscreen page in the admin-panel and passes all needed information.
	 */
	private function showInfoScreenTable()
	{
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
		Render::addTemplate('page-servers', array(
			'serverlist' => $serverlist,
		));
	}

	private function showLocationsTable()
	{
		$locations = Location::getLocations(0, 0, false, true);

		// Get hidden state of all locations
		$dbquery = Database::simpleQuery("SELECT li.locationid, li.serverid, li.serverlocationid, li.openingtime, li.lastcalendarupdate
			FROM `locationinfo_locationconfig` AS li");

		while ($row = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			$locid = (int)$row['locationid'];
			$hasTable = is_array(json_decode($row['openingtime'], true));
			$hasBackend = !empty($row['serverid']) && !empty($row['serverlocationid']);
			$locations[$locid] += array(
				'hasTable' => $hasTable,
				'hasBackend' => $hasBackend,
				'lastCalendarUpdate' => $row['lastcalendarupdate'], // TODO
			);
		}

		Render::addTemplate('page-locations', array(
			'list' => array_values($locations),
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
		if ($action === 'config-location') {
			$this->ajaxConfigLocation($id);
		} elseif ($action === 'serverSettings') {
			$this->ajaxServerSettings($id);
		}
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
		echo Render::parse('ajax-config-server', array('id' => $id,
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
	private function ajaxConfigLocation($id)
	{
		$locConfig = Database::queryFirst("SELECT serverid, serverlocationid, openingtime FROM `locationinfo_locationconfig` WHERE locationid = :id", array('id' => $id));
		if ($locConfig !== false) {
			$openingtimes = json_decode($locConfig['openingtime'], true);
		}
		if (!isset($openingtimes) || !is_array($openingtimes)) {
			$openingtimes = array();
		}

		// get Server / ID list
		$res = Database::simpleQuery("SELECT serverid, servername FROM locationinfo_coursebackend ORDER BY servername ASC");
		$serverList = array();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['serverid'] == $locConfig['serverid']) {
				$row['selected'] = 'selected';
			}
			$serverList[] = $row;
		}

		$data = array(
			'id' => $id,
			'serverlist' => $serverList,
			'serverlocationid' => $locConfig['serverlocationid'],
		);
		$data['expertMode'] = !$this->isSimpleMode($openingtimes);
		// !! isSimpleMode might have changed $openingtimes, so order is important here...
		$data['schedule_data'] = json_encode($openingtimes);

		echo Render::parse('ajax-config-location', $data);
	}

	/**
	 * Checks if simple mode or expert mode is active.
	 * Tries to merge/compact the opening times schedule, and
	 * will actually modify the passed array iff it can be
	 * transformed into easy opening times.
	 *
	 * @param array $array of the saved openingtimes.
	 * @return bool True if simple mode, false if expert mode
	 */
	private function isSimpleMode(&$array)
	{
		if (empty($array))
			return true;
		// Decompose by day
		$new = array();
		foreach ($array as $row) {
			$s = $this->getTime($row['openingtime']);
			$e = $this->getTime($row['closingtime']);
			if ($s === false || $e === false || $e <= $s)
				continue;
			foreach ($row['days'] as $day) {
				$this->addDay($new, $day, $s, $e);
			}
		}
		// Merge by timespan, but always keep saturday and sunday separate
		$merged = array();
		foreach ($new as $day => $ranges) {
			foreach ($ranges as $range) {
				if ($day === 'Saturday' || $day === 'Sunday') {
					$add = $day;
				} else {
					$add = '';
				}
				$key = '#' . $range[0] . '#' . $range[1] . '#' . $add;
				if (!isset($merged[$key])) {
					$merged[$key] = array();
				}
				$merged[$key][$day] = true;
			}
		}
		// Check if it passes as simple mode
		if (count($merged) > 3)
			return false;
		foreach ($merged as $days) {
			if (count($days) === 5) {
				$res = array_keys($days);
				$res = array_intersect($res, array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday"));
				if (count($res) !== 5)
					return false;
			} elseif (count($days) === 1) {
				if (!isset($days['Saturday']) && !isset($days['Sunday'])) {
					return false;
				}
			} else {
				return false;
			}
		}
		// Valid simple mode, finally transform back to what we know
		$new = array();
		foreach ($merged as $span => $days) {
			preg_match('/^#(\d+)#(\d+)#/', $span, $out);
			$new[] = array(
				'days' => array_keys($days),
				'openingtime' => floor($out[1] / 60) . ':' . ($out[1] % 60),
				'closingtime' => floor($out[2] / 60) . ':' . ($out[2] % 60),
			);
		}
		$array = $new;
		return true;
	}

	private function addDay(&$array, $day, $s, $e)
	{
		if (!isset($array[$day])) {
			$array[$day] = array(array($s, $e));
			return;
		}
		foreach (array_keys($array[$day]) as $key) {
			$current = $array[$day][$key];
			if ($s <= $current[0] && $e >= $current[1]) {
				// Fully dominated
				unset($array[$day][$key]);
				continue; // Might partially overlap with additional ranges, keep going
			}
			if ($current[0] <= $s && $current[1] >= $s) {
				// $start lies within existing range
				if ($current[0] <= $e && $current[1] >= $e)
					return; // Fully in existing range, do nothing
				// $end seems to extend range we're checking against but $start lies within this range, update and keep going
				$s = $current[0];
				unset($array[$day][$key]);
				continue;
			}
			// Last possibility: $start is before range, $end within range
			if ($current[0] <= $e && $current[1] >= $e) {
				// $start must lie before range start, otherwise we'd have hit the case above
				$e = $current[1];
				unset($array[$day][$key]);
				continue;
			}
		}
		$array[$day][] = array($s, $e);
	}

	/**
	 * Ajax the config of a location.
	 *
	 * @param $id Location ID
	 */
	private function showConfigPanel()
	{
		$id = Request::get('paneluuid', false, 'string');
		// Get Config data from db
		$panel = Database::queryFirst("SELECT locationids, paneltype, panelconfig
			FROM locationinfo_panel
			WHERE paneluuid = :id", array('id' => $id));
		if ($panel === false) {
			die("Invalid panel id: $id");
		}

		$config = json_decode($panel['panelconfig'], true); // TODO: Validate we got an array, fill with defaults otherwise

		$langs = Dictionary::getLanguages(true);
		foreach ($langs as &$lang) {
			if ($lang['cc'] === $config['language']) {
				$lang['selected'] = 'selected';
			}
		}

		Render::addTemplate('page-config-panel', array(
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
		));
	}

}
