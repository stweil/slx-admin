<?php

class Page_LocationInfo extends Page
{

	private $action;

	/**
	 * Called before any page rendering happens - early hook to check parameters etc.
	 */
	protected function doPreprocess()
	{
		$show = Request::any('show', '', 'string');
		if ($show === 'panel') {
			$this->showPanel();
			exit(0);
		}
		User::load();
		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main'); // does not return
		}
		$this->action = Request::post('action');
		if ($this->action === 'writePanelConfig') {
			$this->writePanelConfig();
		} elseif ($this->action === 'writeLocationConfig') {
			$this->writeLocationConfig();
			$show = 'locations';
		} elseif ($this->action === 'deleteServer') {
			$this->deleteServer();
		} elseif ($this->action === 'deletePanel') {
			$this->deletePanel();
		} elseif ($this->action === 'checkConnection') {
			$this->checkConnection(Request::post('serverid', 0, 'int'));
			$show = 'backends';
		} elseif ($this->action === 'updateServerSettings') {
			$this->updateServerSettings();
			$show = 'backends';
		} elseif (Request::isPost()) {
			Message::addWarning('main.invalid-action', $this->action);
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
		// Do this here so we always see backend errors
		$backends = $this->loadBackends();
		$show = Request::get('show', '', 'string');
		Render::addTemplate('page-tabs', array('class-' . $show => 'active'));
		switch ($show) {
		case 'locations':
			$this->showLocationsTable();
			break;
		case 'backends':
			$this->showBackendsTable($backends);
			break;
		case 'edit-panel':
			$this->showPanelConfig();
			break;
		case '':
			$this->showPanelsTable();
			break;
		default:
			Util::redirect('?do=locationinfo');
		}
	}

	/**
	 * Deletes the server from the db.
	 */
	private function deleteServer()
	{
		$id = Request::post('serverid', false, 'int');
		if ($id === false) {
			Message::addError('server-id-missing');
			return;
		}
		$res = Database::exec("DELETE FROM `locationinfo_coursebackend` WHERE serverid=:id", array('id' => $id));
		if ($res !== 1) {
			Message::addWarning('invalid-server-id', $id);
		}
	}

	private function deletePanel()
	{
		$id = Request::post('uuid', false, 'string');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'uuid');
			return;
		}
		$res = Database::exec("DELETE FROM `locationinfo_panel` WHERE paneluuid = :id", array('id' => $id));
		if ($res !== 1) {
			Message::addWarning('invalid-panel-id', $id);
		}
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
			Message::addError('main.parameter-missing', 'locationid');
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

		$recursive = (Request::post('recursive', '', 'string') !== '');
		if (empty($serverlocationid) && !$recursive) {
			$insertServerId = null;
			$ignoreServer = 1;
		} else {
			$insertServerId = $serverid;
			$ignoreServer = 0;
		}

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

		Database::exec("INSERT INTO `locationinfo_locationconfig` (locationid, serverid, serverlocationid, openingtime, lastcalendarupdate, lastchange)
				VALUES (:id, :insertserverid, :serverlocationid, :openingtimes, 0, :now)
				ON DUPLICATE KEY UPDATE serverid = IF(:ignore_server AND serverid IS NULL, NULL, :serverid), serverlocationid = VALUES(serverlocationid),
					openingtime = VALUES(openingtime), lastcalendarupdate = 0, lastchange = VALUES(lastchange)", array(
			'id' => $locationid,
			'insertserverid' => $insertServerId,
			'serverid' => $serverid,
			'openingtimes' => $openingtimes,
			'serverlocationid' => $serverlocationid,
			'ignore_server' => $ignoreServer,
			'now' => time(),
		));

		if (!$recursive)
			return true;

		// Recursive overwriting of serverid
		$children = Location::getRecursiveFlat($locationid);
		$array = array();
		foreach ($children as $loc) {
			$array[] = $loc['locationid'];
		}
		if (!empty($array)) {
			Database::exec("UPDATE locationinfo_locationconfig
				SET serverid = :serverid, lastcalendarupdate = IF(serverid <> :serverid, 0, lastcalendarupdate), lastchange = :now
				WHERE locationid IN (:locations)", array(
					'serverid' => $serverid,
					'locations' => $array,
					'now' => time(),
			));
		}
		return true;
	}

	/**
	 * Get all location ids from the locationids parameter, which is comma separated, then split
	 * and remove any ids that don't exist. The cleaned list will be returned
	 * @param bool $failIfEmpty Show error and redirect to main page if parameter is missing or list is empty
	 * @return array list of locations from parameter
	 */
	private function getLocationIdsFromRequest($failIfEmpty)
	{
		$locationids = Request::post('locationids', false, 'string');
		if ($locationids === false) {
			if (!$failIfEmpty)
				return array();
			Message::addError('main.parameter-missing', 'locationids');
			Util::redirect('?do=locationinfo');
		}
		$locationids = explode(',', $locationids);
		$all = array_map(function ($item) { return $item['locationid']; }, Location::queryLocations());
		$locationids = array_filter($locationids, function ($item) use ($all) { return in_array($item, $all); });
		if ($failIfEmpty && empty($locationids)) {
			Message::addError('main.parameter-empty', 'locationids');
			Util::redirect('?do=locationinfo');
		}
		return $locationids;
	}

	/**
	 * Updated the config in the db.
	 */
	private function writePanelConfig()
	{
		// UUID - existing or new
		$paneluuid = Request::post('uuid', false, 'string');
		if (($paneluuid === false || strlen($paneluuid) !== 36) && $paneluuid !== 'new') {
			Message::addError('invalid-panel-id', $paneluuid);
			Util::redirect('?do=locationinfo');
		}
		// Check panel type
		$paneltype = Request::post('ptype', false, 'string');

		if ($paneltype === 'DEFAULT') {
			$params = $this->preparePanelConfigDefault();
		} elseif ($paneltype === 'URL') {
			$params = $this->preparePanelConfigUrl();
		}  elseif ($paneltype === 'SUMMARY') {
			$params = $this->preparePanelConfigSummary();
		} else {
			Message::addError('invalid-panel-type', $paneltype);
			Util::redirect('?do=locationinfo');
		}


		if ($paneluuid === 'new') {
			$paneluuid = Util::randomUuid();
			$query = "INSERT INTO `locationinfo_panel` (paneluuid, panelname, locationids, paneltype, panelconfig, lastchange)
				VALUES (:id, :name, :locationids, :type, :config, :now)";
		} else {
			$query = "UPDATE `locationinfo_panel`
				SET panelname = :name, locationids = :locationids, paneltype = :type, panelconfig = :config, lastchange = :now
				WHERE paneluuid = :id";
		}
		$params['id'] = $paneluuid;
		$params['name'] = Request::post('name', '-', 'string');
		$params['type'] = $paneltype;
		$params['now'] = time();
		$params['config'] = json_encode($params['config']);
		$params['locationids'] = implode(',', $params['locationids']);
		Database::exec($query, $params);

		Message::addSuccess('config-saved');
		Util::redirect('?do=locationinfo');
	}

	private function preparePanelConfigDefault()
	{
		// Check locations
		$locationids = self::getLocationIdsFromRequest(true);
		if (count($locationids) > 4) {
			$locationids = array_slice($locationids, 0, 4);
		}
		// Build json struct
		$conf = array(
			'language' => Request::post('language', 'en', 'string'),
			'mode' => Request::post('mode', 1, 'int'),
			'vertical' => Request::post('vertical', false, 'bool'),
			'eco' => Request::post('eco', false, 'bool'),
			'prettytime' => Request::post('prettytime', false, 'bool'),
			'scaledaysauto' => Request::post('scaledaysauto', false, 'bool'),
			'daystoshow' => Request::post('daystoshow', 7, 'int'),
			'rotation' => Request::post('rotation', 0, 'int'),
			'scale' => Request::post('scale', 50, 'int'),
			'switchtime' => Request::post('switchtime', 20, 'int'),
			'calupdate' => Request::post('calupdate', 120, 'int'),
			'roomupdate' => Request::post('roomupdate', 30, 'int'),
		);
		if ($conf['roomupdate'] < 15) {
			$conf['roomupdate'] = 15;
		}
		if ($conf['calupdate'] < 30) {
			$conf['calupdate'] = 30;
		}
		return array('config' => $conf, 'locationids' => $locationids);
	}

	private function preparePanelConfigUrl()
	{
		$conf = array(
			'url' => Request::post('url', 'https://www.bwlehrpool.de/', 'string'),
			'insecure-ssl' => Request::post('insecure-ssl', 0, 'int'),
		);
		return array('config' => $conf, 'locationids' => []);
	}

	private function preparePanelConfigSummary()
	{
		// Check locations
		$locationids = self::getLocationIdsFromRequest(true);
		return array('locationids' => $locationids);
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
			Message::addError('invalid-backend-type', $servertype);
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

	private function loadBackends()
	{
		// Get a list of all the backend types.
		$servertypes = array();
		$s_list = CourseBackend::getList();
		foreach ($s_list as $s) {
			$typeInstance = CourseBackend::getInstance($s);
			$servertypes[$s] = $typeInstance->getDisplayName();
		}
		// Build list of defined backends
		$serverlist = array();
		$dbquery2 = Database::simpleQuery("SELECT * FROM `locationinfo_coursebackend` ORDER BY servername ASC");
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
		return $serverlist;
	}

	/**
	 * Show the list of backends
	 */
	private function showBackendsTable($serverlist)
	{
		// Pass the data to the html and render it.
		Render::addTemplate('page-servers', array(
			'serverlist' => $serverlist,
		));
	}

	private function showLocationsTable()
	{
		$locations = Location::getLocations(0, 0, false, true);

		// Get hidden state of all locations
		$dbquery = Database::simpleQuery("SELECT li.locationid, li.serverid, li.serverlocationid, li.openingtime, li.lastcalendarupdate, cb.servername
			FROM `locationinfo_locationconfig` AS li
			LEFT JOIN `locationinfo_coursebackend` AS cb USING (serverid)");

		while ($row = $dbquery->fetch(PDO::FETCH_ASSOC)) {
			$locid = (int)$row['locationid'];
			if (!isset($locations[$locid]))
				continue;
			$glyph = !empty($row['openingtime']) ? 'ok' : '';
			$backend = '';
			if (!empty($row['serverid']) && !empty($row['serverlocationid'])) {
				$backend = $row['servername'] . '(' . $row['serverlocationid'] . ')';
			}
			$locations[$locid] += array(
				'openingGlyph' => $glyph,
				'backend' => $backend,
				'lastCalendarUpdate' => $row['lastcalendarupdate'], // TODO
			);
		}

		$stack = array();
		$depth = -1;
		foreach ($locations as &$location) {
			while ($location['depth'] <= $depth) {
				array_pop($stack);
				$depth--;
			}
			while ($location['depth'] > $depth) {
				$depth++;
				array_push($stack, empty($location['openingGlyph']) ? '' : 'arrow-up');
			}
			if ($depth > 0 && empty($location['openingGlyph'])) {
				$location['openingGlyph'] = $stack[$depth - 1];
			}
		}

		Render::addTemplate('page-locations', array(
			'list' => array_values($locations),
		));
	}

	private function showPanelsTable()
	{
		$res = Database::simpleQuery('SELECT p.paneluuid, p.panelname, p.locationids, p.panelconfig,
			p.paneltype FROM locationinfo_panel p
			ORDER BY panelname ASC');
		$hasRunmode = Module::isAvailable('runmode');
		if ($hasRunmode) {
			$runmodes = RunMode::getForModule(Page::getModule(), true);
		}
		$panels = array();
		$locations = Location::getLocationsAssoc();
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			if ($row['paneltype'] === 'URL') {
				$url = json_decode($row['panelconfig'], true)['url'];
				$row['locations'] = $row['locationurl'] = $url;
			} else {
				$lids = explode(',', $row['locationids']);
				$locs = array_map(function ($id) use ($locations) {
					return isset($locations[$id]) ? $locations[$id]['locationname'] : $id;
				}, $lids);
				$row['locations'] = implode(', ', $locs);
			}
			$len = mb_strlen($row['panelname']);
			if ($len < 5) {
				$row['panelname'] .= str_repeat('…', 5 - $len);
			}
			if ($hasRunmode && isset($runmodes[$row['paneluuid']])) {
				$row['assignedMachineCount'] = count($runmodes[$row['paneluuid']]);
			}
			$panels[] = $row;
		}
		Render::addTemplate('page-panels', compact('panels', 'hasRunmode'));
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
	 * @param int $id id of the location
	 */
	private function ajaxConfigLocation($id)
	{
		$locConfig = Database::queryFirst("SELECT serverid, serverlocationid, openingtime FROM `locationinfo_locationconfig` WHERE locationid = :id", array('id' => $id));
		if ($locConfig !== false) {
			$openingtimes = json_decode($locConfig['openingtime'], true);
		} else {
			$locConfig = array('serverid' => null, 'serverlocationid' => '');
		}
		if (!isset($openingtimes) || !is_array($openingtimes)) {
			$openingtimes = array();
		}

		// Preset serverid from parent if none is set
		if (is_null($locConfig['serverid'])) {
			$chain = Location::getLocationRootChain($id);
			if (!empty($chain)) {
				$res = Database::simpleQuery("SELECT serverid, locationid FROM locationinfo_locationconfig
					WHERE locationid IN (:locations) AND serverid IS NOT NULL", array('locations' => $chain));
				$chain = array_flip($chain);
				$best = false;
				while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
					if ($best === false || $chain[$row['locationid']] < $chain[$best['locationid']]) {
						$best = $row;
					}
				}
				if ($best !== false) {
					$locConfig['serverid'] = $best['serverid'];
				}
			}
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
	 * Ajax the config of a panel.
	 *
	 * @param $id Location ID
	 */
	private function showPanelConfig()
	{
		$id = Request::get('uuid', false, 'string');
		if ($id === false) {
			Message::addError('main.parameter-missing', 'uuid');
			return;
		}
		$config = false;
		if ($id === 'new-default') {
			// Creating new panel
			$panel = array(
				'panelname' => '',
				'locationids' => '',
				'paneltype' => 'DEFAULT',
			);
			$id = 'new';
		} elseif ($id === 'new-summary') {
			// Creating new panel
			$panel = array(
				'panelname' => '',
				'locationids' => '',
				'paneltype' => 'SUMMARY',
			);
			$id = 'new';
		} elseif ($id === 'new-url') {
			// Creating new panel
			$panel = array(
				'panelname' => '',
				'paneltype' => 'URL',
			);
			$id = 'new';
		} else {
			// Get Config data from db
			$panel = Database::queryFirst("SELECT panelname, locationids, paneltype, panelconfig
				FROM locationinfo_panel
				WHERE paneluuid = :id", array('id' => $id));
			if ($panel === false) {
				Message::addError('invalid-panel-id', $id);
				return;
			}

			$config = json_decode($panel['panelconfig'], true);
		}

		$def = LocationInfo::defaultPanelConfig($panel['paneltype']);
		if (!is_array($config)) {
			$config = $def;
		} else {
			$config += $def;
		}

		$langs = Dictionary::getLanguages(true);
		if (isset($config['language'])) {
			foreach ($langs as &$lang) {
				if ($lang['cc'] === $config['language']) {
					$lang['selected'] = 'selected';
				}
			}
		}

		if ($panel['paneltype'] === 'DEFAULT') {
			Render::addTemplate('page-config-panel-default', array(
				'new' => $id === 'new',
				'uuid' => $id,
				'panelname' => $panel['panelname'],
				'languages' => $langs,
				'mode' => $config['mode'],
				'vertical_checked' => $config['vertical'] ? 'checked' : '',
				'eco_checked' => $config['eco'] ? 'checked' : '',
				'prettytime_checked' => $config['prettytime'] ? 'checked' : '',
				'scaledaysauto_checked' => $config['scaledaysauto'] ? 'checked' : '',
				'daystoshow' => $config['daystoshow'],
				'rotation' => $config['rotation'],
				'scale' => $config['scale'],
				'switchtime' => $config['switchtime'],
				'calupdate' => $config['calupdate'],
				'roomupdate' => $config['roomupdate'],
				'locations' => Location::getLocations(),
				'locationids' => $panel['locationids'],
			));
		} elseif ($panel['paneltype'] === 'URL') {
			Render::addTemplate('page-config-panel-url', array(
				'new' => $id === 'new',
				'uuid' => $id,
				'panelname' => $panel['panelname'],
				'url' => $config['url'],
				'ssl_checked' => $config['insecure-ssl'] ? 'checked' : '',
			));
		} else {
			Render::addTemplate('page-config-panel-summary', array(
				'new' => $id === 'new',
				'uuid' => $id,
				'panelname' => $panel['panelname'],
				'languages' => $langs,
				'roomupdate' => $config['roomupdate'],
				'locations' => Location::getLocations(),
				'locationids' => $panel['locationids'],
			));
		}
	}

	private function showPanel()
	{
		$uuid = Request::get('uuid', false, 'string');
		if ($uuid === false) {
			http_response_code(400);
			die('Missing parameter uuid');
		}
		$type = InfoPanel::getConfig($uuid, $config);
		if ($type === false) {
			http_response_code(404);
			die('Panel with given uuid not found');
		}

		if ($type === 'URL') {
			Util::redirect($config['url']);
		}

		$data = array();
		preg_match('#^(.*)/#', $_SERVER['PHP_SELF'], $script);
		preg_match('#^([^?]+)/#', $_SERVER['REQUEST_URI'], $request);
		if ($script[1] !== $request[1]) {
			$data['dirprefix'] = $script[1] . '/';
		}

		if ($type === 'DEFAULT') {
			$data += array(
				'uuid' => $uuid,
				'config' => json_encode($config),
				'language' => $config['language'],
			);

			die(Render::parse('frontend-default', $data));
		}

		if ($type === 'SUMMARY') {
			$locations = LocationInfo::getLocationsOr404($uuid, false);
			$config['tree'] = Location::getRecursive($locations);
			$data += array(
				'uuid' => $uuid,
				'config' => json_encode($config),
				'language' => $config['language'],
			);

			die(Render::parse('frontend-summary', $data));
		}

		http_response_code(500);
		die('Unknown panel type ' . $type);
	}

}
