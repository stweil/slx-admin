<?php

class Page_Roomplanner extends Page
{

	/**
	 * @var int locationid of location we're editing
	 */
	private $locationid = false;

	/**
	 * @var array location data from location table
	 */
	private $location = false;

	/**
	 * @var string action to perform
	 */
	private $action = false;

	/**
	 * @var bool is this a leaf node, with a real room plan, or something in between with a composed plan
	 */
	private $isLeaf;

	private function loadRequestedLocation()
	{
		$this->locationid = Request::get('locationid', false, 'integer');
		if ($this->locationid !== false) {
			$locs = Location::getLocationsAssoc();
			if (isset($locs[$this->locationid])) {
				$this->location = $locs[$this->locationid];
				$this->isLeaf = empty($this->location['children']);
			}
		}
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::isLoggedIn()) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->action = Request::any('action', 'show', 'string');
		$this->loadRequestedLocation();
		if ($this->locationid === false) {
			Message::addError('need-locationid');
			Util::redirect('?do=locations');
		}
		if ($this->location === false) {
			Message::addError('locations.invalid-location-id', $this->locationid);
			Util::redirect('?do=locations');
		}

		if ($this->action === 'save') {
			$this->handleSaveRequest(false);
			Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
		}
		Render::setTitle($this->location['locationname']);
	}

	protected function doRender()
	{
		if ($this->action === 'show') {
			/* do nothing */
			Dashboard::disable();
			if ($this->isLeaf) {
				$this->showLeafEditor();
			} else {
				$this->showComposedEditor();
			}
		} else {
			Message::addError('main.invalid-action', $this->action);
		}
	}

	private function showLeafEditor()
	{
		$config = Database::queryFirst('SELECT roomplan, managerip, tutoruuid FROM location_roomplan WHERE locationid = :locationid', ['locationid' => $this->locationid]);
		$runmode = RunMode::getForMode(Page::getModule(), $this->locationid, true);
		if (empty($runmode)) {
			$config['dedicatedmgr'] = false;
		} else {
			$runmode = array_pop($runmode);
			$config['managerip'] = $runmode['clientip'];
			$config['manageruuid'] = $runmode['machineuuid'];
			$data = json_decode($runmode['modedata'], true);
			$config['dedicatedmgr'] = (isset($data['dedicatedmgr']) && $data['dedicatedmgr']);
		}
		if ($config !== false) {
			$managerIp = $config['managerip'];
			$dediMgr = $config['dedicatedmgr'] ? 'checked' : '';
		} else {
			$dediMgr = $managerIp = '';
		}
		$furniture = $this->getFurniture($config);
		$subnetMachines = $this->getPotentialMachines();
		$machinesOnPlan = $this->getMachinesOnPlan($config['tutoruuid']);
		$roomConfig = array_merge($furniture, $machinesOnPlan);
		$canEdit = User::hasPermission('edit', $this->locationid);
		$params = [
			'location' => $this->location,
			'managerip' => $managerIp,
			'dediMgrChecked' => $dediMgr,
			'subnetMachines' => json_encode($subnetMachines),
			'locationid' => $this->locationid,
			'roomConfiguration' => json_encode($roomConfig),
			'edit_disabled' => $canEdit ? '' : 'disabled',
			'statistics_disabled' => Module::get('statistics') !== false && User::hasPermission('.statistics.machine.view-details') ? '' : 'disabled',
		];
		Render::addTemplate('header', $params);
		if ($canEdit) {
			Render::addTemplate('item-selector', $params);
		}
		Render::addTemplate('main-roomplan', $params);
		Render::addTemplate('footer', $params);
	}

	private function showComposedEditor()
	{
		// Load settings
		$row = Database::queryFirst("SELECT roomplan FROM location_roomplan WHERE locationid = :lid", [
			'lid' => $this->locationid,
		]);
		$room = new ComposedRoom($row);
		$params = [
			'location' => $this->location,
			'locations' => [],
			$room->orientation . '_checked' => 'checked',
		];
		if ($room->enabled) {
			$params['enabled_checked'] = 'checked';
		}
		$inverseList = array_flip($room->list);
		$sortList = [];
		// Load locations
		$locs = Location::getLocationsAssoc();
		foreach ($this->location['children'] as $loc) {
			if (isset($locs[$loc])) {
				$data = $locs[$loc];
				if (isset($inverseList[$loc])) {
					$sortList[] = $inverseList[$loc];
				} else {
					$sortList[] = 1000 + $loc;
				}
				if ($loc === $room->controlRoom) {
					$data['checked'] = 'checked';
				}
				$params['locations'][] = $data;
			}
		}
		array_multisort($sortList, SORT_ASC | SORT_NUMERIC, $params['locations']);
		Render::addTemplate('edit-composed-room', $params);
	}

	protected function doAjax()
	{
		$this->action = Request::any('action', false, 'string');

		if ($this->action === 'getmachines') {

			User::load();
			$locations = User::getAllowedLocations('edit');
			if (empty($locations)) {
				die('{"machines":[]}');
			}

			$query = Request::get('query', false, 'string');
			$aquery = preg_replace('/[^\x01-\x7f]+/', '%', $query);
			if (strlen(str_replace('%', '', $aquery)) < 2) {
				$aquery = $query;
			}

			$condition = 'locationid IN (:locations)';
			if (in_array(0, $locations)) {
				$condition .= ' OR locationid IS NULL';
			}

			$result = Database::simpleQuery("SELECT machineuuid, macaddr, clientip, hostname, fixedlocationid
				FROM machine
				WHERE ($condition) AND machineuuid LIKE :aquery
				 OR macaddr  	 LIKE :aquery
				 OR clientip    LIKE :aquery
				 OR hostname	 LIKE :query
				 LIMIT 100", ['query' => "%$query%", 'aquery' => "%$aquery%", 'locations' => $locations]);

			$returnObject = ['machines' => []];

			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				if (empty($row['hostname'])) {
					$row['hostname'] = $row['clientip'];
				}
				$returnObject['machines'][] = $row;
			}
			echo json_encode($returnObject);
		} elseif ($this->action === 'save') {
			$this->loadRequestedLocation();
			if ($this->locationid === false) {
				die('Missing locationid in save data');
			}
			if ($this->location === false) {
				die('Location with id ' . $this->locationid . ' does not exist.');
			}
			$this->handleSaveRequest(true);
			die('SUCCESS');
		} else {
			echo 'Invalid AJAX action';
		}
	}

	private function handleSaveRequest($isAjax)
	{
		User::assertPermission('edit', $this->locationid);
		$leaf = (bool)Request::post('isleaf', 1, 'int');
		if ($leaf !== $this->isLeaf) {
			if ($isAjax) {
				die('Leaf mode mismatch. Did you restructure locations while editing this room?');
			} else {
				Message::addError('leaf-mode-mismatch');
				Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
			}
			return;
		}
		if ($this->isLeaf) {
			$this->saveLeafRoom($isAjax);
		} else {
			$this->saveComposedRoom($isAjax);
		}
	}

	private function saveLeafRoom($isAjax)
	{
		$machinesOnPlan = $this->getMachinesOnPlan('invalid');
		$config = Request::post('serializedRoom', null, 'string');
		$config = json_decode($config, true);
		if (!is_array($config) || !isset($config['furniture']) || !isset($config['computers'])) {
			if ($isAjax) {
				die('JSON data incomplete');
			} else {
				Message::addError('json-data-invalid');
				Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
			}
		}
		$tutorUuid = Request::post('tutoruuid', '', 'string');
		if (empty($tutorUuid)) {
			$tutorUuid = null;
		} else {
			$ret = Database::queryFirst('SELECT machineuuid FROM machine WHERE machineuuid = :uuid', ['uuid' => $tutorUuid]);
			if ($ret === false) {
				if ($isAjax) {
					die('Invalid tutor UUID');
				} else {
					Message::addError('invalid-tutor-uuid');
					Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
				}
			}
		}
		$this->saveRoomConfig($config['furniture'], $tutorUuid);
		$this->saveComputerConfig($config['computers'], $machinesOnPlan);
	}

	private function saveComposedRoom($isAjax)
	{
		$room = new ComposedRoom(null);
		$room->orientation = Request::post('orientation', 'horizontal', 'string');
		$room->enabled = (bool)Request::post('enabled', 0, 'int');
		$room->controlRoom = Request::post('controlroom', 0, 'int');
		$vals = Request::post('sort', [], 'array');
		asort($vals, SORT_ASC | SORT_NUMERIC);
		$room->list = array_keys($vals);
		$res = Database::exec('INSERT INTO location_roomplan (locationid, roomplan)
				VALUES (:lid, :plan) ON DUPLICATE KEY UPDATE roomplan = VALUES(roomplan)',
			['lid' => $this->locationid, 'plan' => $room->serialize()]);
		if (!$res) {
			if ($isAjax) {
				die('Error writing config to DB');
			} else {
				Message::addError('db-error');
				Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
			}
		}
	}

	private function sanitizeNumber(&$number, $lower, $upper)
	{
		if (!is_numeric($number) || $number < $lower) {
			$number = $lower;
		} elseif ($number > $upper) {
			$number = $upper;
		}
	}

	protected function saveComputerConfig($computers, $oldComputers)
	{

		$oldUuids = [];
		/* collect all uuids  from the old computers */
		foreach ($oldComputers['computers'] as $c) {
			$oldUuids[] = $c['muuid'];
		}

		$newUuids = [];
		foreach ($computers as $computer) {
			$newUuids[] = $computer['muuid'];

			// Fix/sanitize properties
			// TODO: The list of items, computers, etc. in general is copied and pasted in multiple places. We need a central definition with generators for the various formats we need it in
			if (!isset($computer['itemlook']) || !in_array($computer['itemlook'], ['pc-north', 'pc-south', 'pc-west', 'pc-east', 'copier', 'telephone'])) {
				$computer['itemlook'] = 'pc-north';
			}
			if (!isset($computer['gridRow'])) {
				$computer['gridRow'] = 0;
			} else {
				$this->sanitizeNumber($computer['gridRow'], 0, 32 * 4);
			}
			if (!isset($computer['gridCol'])) {
				$computer['gridCol'] = 0;
			} else {
				$this->sanitizeNumber($computer['gridCol'], 0, 32 * 4);
			}

			$position = json_encode(['gridRow' => $computer['gridRow'],
				'gridCol' => $computer['gridCol'],
				'itemlook' => $computer['itemlook']]);

			Database::exec('UPDATE machine SET position = :position, fixedlocationid = :locationid WHERE machineuuid = :muuid',
				['locationid' => $this->locationid, 'muuid' => $computer['muuid'], 'position' => $position]);
		}

		$toDelete = array_diff($oldUuids, $newUuids);

		foreach ($toDelete as $d) {
			Database::exec("UPDATE machine SET position = '', fixedlocationid = NULL WHERE machineuuid = :uuid", ['uuid' => $d]);
		}
	}

	protected function saveRoomConfig($furniture, $tutorUuid)
	{
		$obj = json_encode(['furniture' => $furniture]);
		$managerIp = Request::post('managerip', '', 'string');
		Database::exec('INSERT INTO location_roomplan (locationid, roomplan, managerip, tutoruuid)'
			. ' VALUES (:locationid, :roomplan, :managerip, :tutoruuid)'
			. ' ON DUPLICATE KEY UPDATE '
			. ' roomplan=VALUES(roomplan), managerip=VALUES(managerip), tutoruuid=VALUES(tutoruuid)', [
			'locationid' => $this->locationid,
			'roomplan' => $obj,
			'managerip' => $managerIp,
			'tutoruuid' => $tutorUuid
		]);
		// See if the client is known, set run-mode
		if (empty($managerIp)) {
			RunMode::deleteMode(Page::getModule(), $this->locationid);
		} else {
			RunMode::deleteMode(Page::getModule(), $this->locationid);
			$pc = Statistics::getMachinesByIp($managerIp, Machine::NO_DATA, 'lastseen DESC');
			if (!empty($pc)) {
				$dedicated = (Request::post('dedimgr') === 'on');
				$pc = array_shift($pc);
				RunMode::setRunMode($pc->machineuuid, Page::getModule()->getIdentifier(), $this->locationid, json_encode([
					'dedicatedmgr' => $dedicated
				]), !$dedicated);
			}
		}
	}

	protected function getFurniture($config)
	{
		if ($config === false)
			return array();
		$config = json_decode($config['roomplan'], true);
		if (!is_array($config))
			return array();
		return $config;
	}

	protected function getMachinesOnPlan($tutorUuid)
	{
		$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname, position FROM machine WHERE fixedlocationid = :locationid',
			['locationid' => $this->locationid]);
		$machines = [];
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$machine = [];
			$pos = json_decode($row['position'], true);
			if ($pos === false || !isset($pos['gridRow']) || !isset($pos['gridCol'])) {
				// Missing/incomplete position information - reset
				Database::exec("UPDATE machine SET fixedlocationid = NULL, position = '' WHERE machineuuid = :uuid",
					array('uuid' => $row['machineuuid']));
				continue;
			}

			$machine['muuid'] = $row['machineuuid'];
			$machine['ip'] = $row['clientip'];
			$machine['mac_address'] = $row['macaddr'];
			$machine['hostname'] = $row['hostname'];
			$machine['gridRow'] = (int)$pos['gridRow'];
			$machine['gridCol'] = (int)$pos['gridCol'];
			$machine['itemlook'] = $pos['itemlook'];
			$machine['data-width'] = 100;
			$machine['data-height'] = 100;
			if ($row['machineuuid'] === $tutorUuid) {
				$machine['istutor'] = 'true';
			}
			$machines[] = $machine;
		}
		return ['computers' => $machines];
	}

	protected function getPotentialMachines()
	{
		$result = Database::simpleQuery('SELECT m.machineuuid, m.macaddr, m.clientip, m.hostname, l.locationname AS otherroom, m.fixedlocationid
			FROM machine m
			LEFT JOIN location l ON (m.fixedlocationid = l.locationid AND m.subnetlocationid <> m.fixedlocationid)
			WHERE subnetlocationid = :locationid', ['locationid' => $this->locationid]);

		$machines = [];

		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			if (empty($row['hostname'])) {
				$row['hostname'] = $row['clientip'];
			}
			$machines[] = $row;
		}

		return $machines;
	}
}
