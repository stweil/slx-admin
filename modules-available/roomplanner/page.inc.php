<?php

class Page_Roomplanner extends Page
{

	/**
	 * @var int locationid of location we're editing
	 */
	private $locationid;

	/**
	 * @var string action to perform
	 */
	private $action;

	protected function doPreprocess()
	{
		User::load();

		if (!User::hasPermission('superadmin')) {
			Message::addError('main.no-permission');
			Util::redirect('?do=Main');
		}

		$this->locationid = Request::get('locationid', null, 'integer');
		$this->action = Request::any('action', 'show', 'string');

		if ($this->locationid === null) {
			Message::addError('need-locationid');
			Util::redirect('?do=locations');
		}

		if ($this->action === 'save') {
			$this->handleSaveRequest(false);
			Util::redirect("?do=roomplanner&locationid={$this->locationid}&action=show");
		}
	}

	protected function doRender()
	{
		if ($this->action === 'show') {
			/* do nothing */
			$furniture = $this->getFurniture();
			$subnetMachines = $this->getPotentialMachines();
			$machinesOnPlan = $this->getMachinesOnPlan();
			$roomConfig = array_merge($furniture, $machinesOnPlan);
			Render::addTemplate('page', [
				'subnetMachines' => json_encode($subnetMachines),
				'locationid' => $this->locationid,
				'roomConfiguration' => json_encode($roomConfig)]);
		} else {
			Message::addError('main.invalid-action', $this->action);
		}

	}

	protected function doAjax()
	{
		$this->action = Request::any('action', null, 'string');

		if ($this->action === 'getmachines') {
			$query = Request::get('query', null, 'string');

			/* the query could be anything: UUID, IP or macaddr */
//			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
//				. ', MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) AS relevance '
//				. 'FROM machine '
//				. 'WHERE MATCH (machineuuid, macaddr, clientip, hostname) AGAINST (:query) '
//				. 'ORDER BY relevance DESC '
//				. 'LIMIT 5'
//				, ['query' => $query]);
//
			$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
				. 'FROM machine '
				. 'WHERE machineuuid LIKE :query '
				. ' OR macaddr  	 LIKE :query '
				. ' OR clientip    LIKE :query '
				. ' OR hostname	 LIKE :query ', ['query' => "%$query%"]);

			$returnObject = ['machines' => []];

			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				$returnObject['machines'][] = $row;
			}
			echo json_encode($returnObject);
		} elseif ($this->action === 'save') {
			$this->locationid = Request::any('locationid', null, 'integer');
			if ($this->locationid === null) {
				die('Missing locationid in save data');
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
		$machinesOnPlan = $this->getMachinesOnPlan();
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
		$this->saveRoomConfig($config['furniture']);
		$this->saveComputerConfig($config['computers'], $machinesOnPlan);
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

			$position = json_encode(['gridRow' => $computer['gridRow'],
				'gridCol' => $computer['gridCol'],
				'itemlook' => $computer['itemlook']]);

			Database::exec('UPDATE machine SET position = :position, locationid = :locationid WHERE machineuuid = :muuid',
				['locationid' => $this->locationid, 'muuid' => $computer['muuid'], 'position' => $position]);
		}

		$toDelete = array_diff($oldUuids, $newUuids);

		foreach ($toDelete as $d) {
			Database::exec("UPDATE machine SET position = '', locationid = NULL WHERE machineuuid = :uuid", ['uuid' => $d]);
		}
	}

	protected function saveRoomConfig($furniture)
	{
		$obj = json_encode(['furniture' => $furniture]);
		Database::exec('INSERT INTO location_roomplan (locationid, roomplan) VALUES (:locationid, :roomplan) ON DUPLICATE KEY UPDATE roomplan=:roomplan',
			['locationid' => $this->locationid,
				'roomplan' => $obj]);
	}

	protected function getFurniture()
	{
		$config = Database::queryFirst('SELECT roomplan FROM location_roomplan WHERE locationid = :locationid', ['locationid' => $this->locationid]);
		if ($config === false) {
			return array();
		}
		$config = json_decode($config['roomplan'], true);
		return $config;
	}

	protected function getMachinesOnPlan()
	{
		$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname, position FROM machine WHERE locationid = :locationid',
			['locationid' => $this->locationid]);
		$machines = [];
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$machine = [];
			$pos = json_decode($row['position'], true);
			// TODO: Check if pos is valid (has required keys)

			$machine['muuid'] = $row['machineuuid'];
			$machine['ip'] = $row['clientip'];
			$machine['mac_address'] = $row['macaddr'];
			$machine['hostname'] = $row['hostname'];
			$machine['gridRow'] = (int)$pos['gridRow'];
			$machine['gridCol'] = (int)$pos['gridCol'];
			$machine['itemlook'] = $pos['itemlook'];
			$machine['data-width'] = 100;
			$machine['data-height'] = 100;
			$machines[] = $machine;
		}
		return ['computers' => $machines];
	}

	protected function getPotentialMachines()
	{
		$result = Database::simpleQuery('SELECT machineuuid, macaddr, clientip, hostname '
			. 'FROM machine INNER JOIN subnet ON (INET_ATON(clientip) BETWEEN startaddr AND endaddr) '
			. 'WHERE subnet.locationid = :locationid', ['locationid' => $this->locationid]);

		$machines = [];

		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$row['combined'] = implode(' ', array_values($row));
			$machines[] = $row;
		}

		return $machines;
	}
}
