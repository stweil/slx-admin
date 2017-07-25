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

	private function loadRequestedLocation()
	{
		$this->locationid = Request::get('locationid', false, 'integer');
		if ($this->locationid !== false) {
			$this->location = Location::get($this->locationid);
		}
	}

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
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
			$config = Database::queryFirst('SELECT roomplan, managerip, dedicatedmgr, tutoruuid FROM location_roomplan WHERE locationid = :locationid', ['locationid' => $this->locationid]);
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
			Render::addTemplate('page', [
				'location' => $this->location,
				'managerip' => $managerIp,
				'dediMgrChecked' => $dediMgr,
				'subnetMachines' => json_encode($subnetMachines),
				'locationid' => $this->locationid,
				'roomConfiguration' => json_encode($roomConfig)]);
		} else {
			Message::addError('main.invalid-action', $this->action);
		}

	}

	protected function doAjax()
	{
		$this->action = Request::any('action', false, 'string');

		if ($this->action === 'getmachines') {
			$query = Request::get('query', false, 'string');
			$aquery = preg_replace('/[^\x01-\x7f]+/', '%', $query);

			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
				. 'FROM machine '
				. 'WHERE machineuuid LIKE :aquery '
				. ' OR macaddr  	 LIKE :aquery '
				. ' OR clientip    LIKE :aquery '
				. ' OR hostname	 LIKE :query '
				. ' LIMIT 100', ['query' => "%$query%", 'aquery' => "%$aquery%"]);

			$returnObject = ['machines' => []];

			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
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
		/* save */
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
		Database::exec('INSERT INTO location_roomplan (locationid, roomplan, managerip, tutoruuid, dedicatedmgr)'
			. ' VALUES (:locationid, :roomplan, :managerip, :tutoruuid, :dedicatedmgr)'
			. ' ON DUPLICATE KEY UPDATE '
			. ' roomplan=VALUES(roomplan), managerip=VALUES(managerip), tutoruuid=VALUES(tutoruuid), dedicatedmgr=VALUES(dedicatedmgr)', [
			'locationid' => $this->locationid,
			'roomplan' => $obj,
			'managerip' => Request::post('managerip', '', 'string'),
			'dedicatedmgr' => (Request::post('dedimgr') === 'on' ? 1 : 0),
			'tutoruuid' => $tutorUuid
		]);
	}

	protected function getFurniture($config)
	{
		if ($config === false) {
			return array();
		}
		$config = json_decode($config['roomplan'], true);
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
		$result = Database::simpleQuery('SELECT m.machineuuid, m.macaddr, m.clientip, m.hostname, l.locationname AS otherroom 
			FROM machine m
			LEFT JOIN location l ON (m.fixedlocationid = l.locationid AND m.subnetlocationid <> m.fixedlocationid)
			WHERE subnetlocationid = :locationid', ['locationid' => $this->locationid]);

		$machines = [];

		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$machines[] = $row;
		}

		return $machines;
	}
}
